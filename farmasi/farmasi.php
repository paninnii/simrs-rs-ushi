<?php
/*
 * MODUL 10: PHARMACY (Farmasi)
 *   Apoteker: Order Baru -> Terverifikasi (telaah resep) -> Dispensing (stok dipotong FEFO) -> Siap Diserahkan (label) -> Selesai (serah + edukasi)
 *   Retur obat yang tidak terpakai (stok kembali ke batch asal, tagihan menyesuaikan).
 *   Kasir & dokter: hanya melihat.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('farmasi');
$pdo = getPDO();
$TAHAP = ['Order Baru', 'Terverifikasi', 'Dispensing', 'Siap Diserahkan', 'Selesai'];
$apt = role_is('apoteker') && boleh('farmasi', true);

function ambil_resep(int $id): ?array
{
    $s = getPDO()->prepare('SELECT r.*, k.id_rs, k.id_pasien, d.nama_lengkap AS dokter_resep, a.nama_lengkap AS apoteker FROM resep r JOIN kunjungan k ON k.id = r.id_kunjungan JOIN pegawai d ON d.id = r.id_dokter LEFT JOIN pegawai a ON a.id = r.diproses_oleh WHERE r.id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}
function item_resep(int $id): array
{
    $s = getPDO()->prepare('SELECT ri.*, o.nama_obat, o.satuan, o.stok, o.harga_satuan, o.high_alert, o.lasa FROM resep_item ri JOIN obat o ON o.id = ri.id_obat WHERE ri.id_resep = ? ORDER BY ri.id');
    $s->execute([$id]);
    return $s->fetchAll();
}

if (is_post()) {
    if (!wajib_role_aksi(['apoteker'], 'Proses resep hanya dapat dilakukan Apoteker.', 'farmasi')) kembali();
    $r = ambil_resep((int) post('id'));
    if (!$r || (int) $r['id_rs'] !== rs_user()) { flash('error', 'Resep tidak ditemukan di cabang Anda.'); kembali(); }
    $aksi = post('aksi');
    $k = ambil_kunjungan((int) $r['id_kunjungan']);
    $label = "Resep #{$r['id']} {$k['nomor_rm']}";
    $ubah = function (string $dari, string $ke, array $set = []) use ($pdo, $r) {
        $cols = array_merge(['status' => $ke], $set);
        $u = $pdo->prepare('UPDATE resep SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols))) . ' WHERE id = ? AND status = ?');
        $u->execute([...array_values($cols), $r['id'], $dari]);
        return $u->rowCount() > 0;
    };
    try {
        if ($aksi === 'verifikasi') {
            $cek = post('telaah');
            if (!is_array($cek) || count($cek) < 4) { flash('error', 'Centang semua poin telaah resep (identitas, obat, dosis, interaksi/alergi) sebelum verifikasi.'); kembali(); }
            if (!$ubah('Order Baru', 'Terverifikasi', ['catatan_farmasi' => post('catatan') ?: 'Telaah resep OK', 'diproses_oleh' => uid()])) throw new Exception('Status resep sudah berubah.');
            $pesan = 'resep terverifikasi (telaah lengkap)';
        } elseif ($aksi === 'batal') {
            if (mb_strlen(post('alasan')) < 5) { flash('error', 'Alasan pembatalan wajib diisi (min. 5 karakter).'); kembali(); }
            if (!$ubah('Order Baru', 'Batal', ['catatan_farmasi' => 'Dibatalkan: ' . post('alasan'), 'diproses_oleh' => uid()]) && !$ubah('Terverifikasi', 'Batal', ['catatan_farmasi' => 'Dibatalkan: ' . post('alasan')]))
                throw new Exception('Resep yang sudah didispensing tidak bisa dibatalkan, gunakan retur.');
            $pesan = 'resep dibatalkan, dokter perlu membuat resep pengganti';
        } elseif ($aksi === 'dispensing') {
            $pdo->beginTransaction();
            if (!$ubah('Terverifikasi', 'Dispensing')) throw new Exception('Status resep sudah berubah.');
            foreach (item_resep((int) $r['id']) as $it) potong_stok((int) $it['id_obat'], (int) $it['jumlah'], 'Dispensing', "$label - {$it['nama_obat']}", (int) $it['id']);
            $pdo->commit();
            $pesan = 'obat disiapkan, stok terpotong otomatis (FEFO per batch)';
        } elseif ($aksi === 'siap') {
            if (!$ubah('Dispensing', 'Siap Diserahkan')) throw new Exception('Status resep sudah berubah.');
            $pesan = 'label dicetak, obat siap diserahkan';
        } elseif ($aksi === 'serahkan') {
            $cek = post('serah');
            if (!is_array($cek) || count($cek) < 3) { flash('error', 'Pastikan identitas (nama + tanggal lahir/No. RM) dicek dan edukasi sudah diberikan.'); kembali(); }
            if (!$ubah('Siap Diserahkan', 'Selesai', ['waktu_selesai' => date('Y-m-d H:i:s')])) throw new Exception('Status resep sudah berubah.');
            $pesan = 'obat diserahkan ke pasien/keluarga dan masuk ke tagihan';
        } elseif ($aksi === 'retur') {
            $it = $pdo->prepare('SELECT ri.*, o.nama_obat FROM resep_item ri JOIN obat o ON o.id = ri.id_obat WHERE ri.id = ? AND ri.id_resep = ?');
            $it->execute([(int) post('id_item'), $r['id']]);
            $it = $it->fetch();
            $n = (int) post('jumlah');
            $maks = $it ? $it['jumlah'] - $it['jumlah_retur'] : 0;
            if (!$it || $r['status'] !== 'Selesai') throw new Exception('Retur hanya untuk resep yang sudah diserahkan.');
            if ($n < 1 || $n > $maks) throw new Exception("Jumlah retur harus 1 sampai $maks.");
            if (mb_strlen(post('alasan')) < 5) throw new Exception('Alasan retur wajib diisi.');
            $tg = $pdo->prepare('SELECT id, status FROM tagihan WHERE id_kunjungan = ?');
            $tg->execute([$r['id_kunjungan']]);
            $tg = $tg->fetch();
            if ($tg && in_array($tg['status'], ['Dibayar', 'Final'], true)) throw new Exception('Tagihan sudah dibayar/final, retur tidak bisa diproses di sistem.');
            $pdo->beginTransaction();
            // retur dicatat di mutasi_stok (jenis Retur) dan terhubung ke item resep yang sama
            $b = $pdo->prepare("SELECT id_batch FROM mutasi_stok WHERE id_resep_item = ? AND jenis = 'Dispensing' AND id_batch IS NOT NULL ORDER BY id DESC LIMIT 1");
            $b->execute([$it['id']]);
            $pdo->prepare('UPDATE resep_item SET jumlah_retur = jumlah_retur + ? WHERE id = ?')->execute([$n, $it['id']]);
            tambah_stok((int) $it['id_obat'], $n, ($x = $b->fetchColumn()) ? (int) $x : null, 'Retur', mb_substr("Retur $label - {$it['nama_obat']}: " . post('alasan'), 0, 255), (int) $it['id']);
            if ($tg && $tg['status'] === 'Dihitung') $pdo->prepare("UPDATE tagihan SET status = 'Terverifikasi', catatan_verifikasi = 'Hitung ulang: ada retur obat' WHERE id = ?")->execute([$tg['id']]);
            $pdo->commit();
            $pesan = "retur $n {$it['nama_obat']} diterima, stok kembali";
        } else throw new Exception('Aksi tidak dikenal.');
        audit('Pharmacy', ucfirst($aksi), $label);
        flash('sukses', "$label: $pesan.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e instanceof PDOException ? 'Gagal menyimpan: ' . $e->getMessage() : $e->getMessage());
    }
    kembali();
}

// ---------- DETAIL RESEP ----------
if (get('id') !== '') {
    $r = ambil_resep((int) get('id'));
    if (!$r) { flash('error', 'Resep tidak ditemukan.'); redirect('farmasi/farmasi.php'); }
    wajib_pasien_sendiri((int) $r['id_pasien']);
    $k = ambil_kunjungan((int) $r['id_kunjungan']);
    $items = item_resep((int) $r['id']);
    $cetak = get('label') === '1';
    tampil_header($cetak ? 'Label Obat' : 'Resep #' . $r['id'], $k['poli'] . ' · ' . aman($r['dokter_resep']) . ' · ' . tgl_jam($r['tanggal']),
        ($cetak ? '<button onclick="window.print()" class="btn btn-primary">Cetak Label</button> ' : '') . '<a href="' . ($cetak ? '?id=' . $r['id'] : 'farmasi.php') . '" class="btn btn-secondary">Kembali</a>');
    if ($cetak) {
        echo '<div class="print-area">';
        foreach ($items as $it) echo '<div class="label-obat"><b>' . aman(nama_cabang((int) $r['id_rs'])) . ' · Farmasi</b><div class="muted">' . tgl($r['tanggal']) . ' · R/' . $r['id'] . '</div><hr class="my-1">'
            . '<div><b>' . aman($k['nama_lengkap']) . '</b> (' . aman($k['nomor_rm']) . ')</div><div>Tgl lahir: ' . tgl($k['tanggal_lahir']) . '</div>'
            . '<div class="mt-1 text-sm"><b>' . aman($it['nama_obat']) . '</b> · ' . (int) $it['jumlah'] . ' ' . aman($it['satuan']) . '</div>'
            . '<div>' . aman($it['dosis']) . ' · <b>' . aman($it['frekuensi']) . '</b></div><div>' . aman($it['aturan_pakai']) . '</div>'
            . ($it['high_alert'] ? '<div class="badge badge-red mt-1">HIGH ALERT</div>' : '') . '</div>';
        echo '</div>';
        tampil_footer();
        exit;
    }
    echo kartu_pasien($k, aman($k['penjamin']) . ' · ' . ($k['jenis'] === 'rawat_inap' ? 'Rawat Inap' : ($k['jenis'] === 'igd' ? 'IGD' : 'Rawat Jalan')) . ' · ' . aman($k['poli']));
    $total = 0;
    ?>
    <div class="card mb-4"><div class="card-head"><b>Status</b><?= $r['status'] === 'Batal' ? badge('Batal') : stepper($TAHAP, $r['status']) ?></div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Obat</th><th>Dosis / Aturan</th><th class="num">Jumlah</th><th class="num">Stok</th><th class="num">Subtotal</th><?= $apt && $r['status'] === 'Selesai' ? '<th class="num">Retur</th>' : '' ?></tr></thead><tbody>
    <?php foreach ($items as $it): $qty = $it['jumlah'] - $it['jumlah_retur']; $total += $qty * $it['harga_satuan']; $al = cek_alergi($k['alergi'], $it['nama_obat']); ?>
      <tr><td><b><?= aman($it['nama_obat']) ?></b> <?= $it['high_alert'] ? badge('HIGH ALERT') : '' ?> <?= $it['lasa'] ? '<span class="badge badge-amber">LASA</span>' : '' ?>
          <?php if ($al): ?><div class="alert alert-error text-xs mt-1 p-2"><b>Peringatan alergi:</b> <?= aman($al) ?><br>Alasan dokter: <?= aman($it['alasan_alergi'] ?: '-') ?></div><?php endif; ?></td>
        <td><?= aman($it['dosis']) ?> · <?= aman($it['frekuensi']) ?><div class="muted"><?= aman($it['aturan_pakai']) ?></div></td>
        <td class="num"><?= (int) $it['jumlah'] ?> <?= aman($it['satuan']) ?><?= $it['jumlah_retur'] ? '<div class="muted">retur ' . (int) $it['jumlah_retur'] . '</div>' : '' ?></td>
        <td class="num <?= $it['stok'] < $it['jumlah'] && in_array($r['status'], ['Order Baru', 'Terverifikasi'], true) ? 'text-red-600 font-bold' : '' ?>"><?= (int) $it['stok'] ?></td>
        <td class="num"><?= rupiah($qty * $it['harga_satuan']) ?></td>
        <?php if ($apt && $r['status'] === 'Selesai'): ?><td class="num"><?php if ($qty > 0): ?>
          <button class="btn btn-secondary btn-sm" data-open="ret<?= $it['id'] ?>">Retur</button>
          <dialog class="modal" id="ret<?= $it['id'] ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="retur"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="id_item" value="<?= $it['id'] ?>">
            <div class="modal-head"><h3>Retur <?= aman($it['nama_obat']) ?></h3></div><div class="modal-body text-left form-grid">
            <div class="field"><label>Jumlah (maks. <?= $qty ?>)</label><input type="number" name="jumlah" min="1" max="<?= $qty ?>" value="1" required></div>
            <div class="field"><label>Alasan</label><input name="alasan" required placeholder="contoh: Pasien pulang, obat tidak terpakai"></div></div>
            <div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Batal</button><button class="btn btn-primary">Proses Retur</button></div></form></dialog>
        <?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
      <tr><td colspan="4" class="num"><b>Total obat</b></td><td class="num"><b><?= rupiah($total) ?></b></td><?= $apt && $r['status'] === 'Selesai' ? '<td></td>' : '' ?></tr>
    </tbody></table></div>
    <div class="card-body text-sm grid md:grid-cols-2 gap-3"><div><b>Catatan dokter:</b> <?= aman($r['catatan_dokter'] ?: '-') ?></div><div><b>Catatan farmasi:</b> <?= aman($r['catatan_farmasi'] ?: '-') ?><?= $r['apoteker'] ? ' <span class="muted">(' . aman($r['apoteker']) . ')</span>' : '' ?></div></div></div>

    <?php if ($apt): ?><div class="card"><div class="card-body">
      <?php if ($r['status'] === 'Order Baru'): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><div class="section-label">Telaah resep (wajib)</div>
          <?php foreach (['Identitas pasien & dokter sesuai', 'Nama obat, kekuatan, jumlah jelas', 'Dosis & frekuensi sesuai umur/berat', 'Tidak ada interaksi berbahaya / alergi sudah dikonfirmasi'] as $i => $t): ?>
            <label class="flex gap-2 items-center text-sm mb-1"><input type="checkbox" name="telaah[]" value="<?= $i ?>"> <?= $t ?></label><?php endforeach; ?>
          <div class="field mt-2"><label>Catatan farmasi</label><input name="catatan" placeholder="opsional"></div>
          <div class="flex gap-2"><button name="aksi" value="verifikasi" class="btn btn-primary">Verifikasi Resep</button></div></form>
      <?php elseif ($r['status'] === 'Terverifikasi'): ?>
        <form method="post" data-confirm="Siapkan obat? Stok akan dipotong otomatis."><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button name="aksi" value="dispensing" class="btn btn-primary">Mulai Dispensing (potong stok)</button></form>
      <?php elseif ($r['status'] === 'Dispensing'): ?>
        <a href="?id=<?= $r['id'] ?>&label=1" class="btn btn-secondary" target="_blank">Cetak Label Obat</a>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button name="aksi" value="siap" class="btn btn-primary">Tandai Siap Diserahkan</button></form>
      <?php elseif ($r['status'] === 'Siap Diserahkan'): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><div class="section-label">Penyerahan obat</div>
          <?php foreach (['Identitas dicek: nama + tanggal lahir / No. RM', 'Edukasi cara pakai, dosis, efek samping', 'Pasien/keluarga memahami & menerima obat'] as $i => $t): ?>
            <label class="flex gap-2 items-center text-sm mb-1"><input type="checkbox" name="serah[]" value="<?= $i ?>"> <?= $t ?></label><?php endforeach; ?>
          <button name="aksi" value="serahkan" class="btn btn-success mt-2">Serahkan Obat</button> <a href="?id=<?= $r['id'] ?>&label=1" class="btn btn-secondary mt-2" target="_blank">Cetak Ulang Label</a></form>
      <?php elseif ($r['status'] === 'Selesai'): ?><div class="notice">Obat sudah diserahkan <?= tgl_jam($r['waktu_selesai']) ?>. Jika ada obat tidak terpakai gunakan tombol <b>Retur</b> pada baris obat.</div>
      <?php else: ?><div class="notice">Resep dibatalkan.</div><?php endif; ?>
      <?php if (in_array($r['status'], ['Order Baru', 'Terverifikasi'], true)): ?>
        <button class="btn btn-danger btn-sm mt-3" data-open="batal">Batalkan Resep</button>
        <dialog class="modal" id="batal"><form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="batal"><input type="hidden" name="id" value="<?= $r['id'] ?>"><div class="modal-head"><h3>Batalkan resep</h3></div>
          <div class="modal-body"><input name="alasan" class="input" required placeholder="contoh: Obat kosong, sudah dikonfirmasi ke dokter untuk diganti"></div><div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Tutup</button><button class="btn btn-danger">Batalkan</button></div></form></dialog>
      <?php endif; ?>
    </div></div><?php endif;
    tampil_footer();
    exit;
}

// ---------- DAFTAR ----------
$tab = get('tab', 'antrean');
$batas = date('Y-m-d', strtotime('-7 days'));
$where = ['antrean' => "r.status IN ('Order Baru','Terverifikasi','Dispensing','Siap Diserahkan')", 'selesai' => "r.status IN ('Selesai','Batal') AND r.tanggal >= '$batas'"][$tab] ?? null;
$scope = role_is('dokter') ? ' AND r.id_dokter = ' . (int) uid() : sql_rs('k.id_rs');
tampil_header('Farmasi', ($apt ? 'Telaah, dispensing, dan penyerahan obat sesuai resep elektronik dokter.' : 'Status resep pasien (lihat saja).') . (rs_aktif() ? ' · ' . nama_cabang(rs_aktif()) : ''));
$st = $pdo->query("SELECT r.status, COUNT(*) n FROM resep r JOIN kunjungan k ON k.id = r.id_kunjungan WHERE (r.status <> 'Selesai' OR r.tanggal >= '" . date('Y-m-d') . "')$scope GROUP BY r.status")->fetchAll(PDO::FETCH_KEY_PAIR);
$menipis = (int) $pdo->query('SELECT COUNT(*) FROM obat WHERE aktif = 1 AND stok <= stok_minimum')->fetchColumn();
echo kartu([['Resep Baru', $st['Order Baru'] ?? 0, 'amber'], ['Terverifikasi / Dispensing', ($st['Terverifikasi'] ?? 0) + ($st['Dispensing'] ?? 0), 'teal'], ['Siap Diserahkan', $st['Siap Diserahkan'] ?? 0, 'purple'], ['Selesai Hari Ini', $st['Selesai'] ?? 0, 'green'], ['Stok Menipis / Habis', $menipis, 'red']]);
?>
<div class="tabs"><a class="tab<?= $tab === 'antrean' ? ' active' : '' ?>" href="?tab=antrean">Antrean resep</a><a class="tab<?= $tab === 'selesai' ? ' active' : '' ?>" href="?tab=selesai">Selesai / batal (7 hari)</a><a class="tab<?= $tab === 'stok' ? ' active' : '' ?>" href="?tab=stok">Stok obat</a></div>
<?php if ($tab === 'stok'):
    $obat = $pdo->query("SELECT o.*, (SELECT MIN(tgl_kedaluwarsa) FROM stok_batch b WHERE b.id_obat = o.id AND b.qty_sisa > 0) AS ed FROM obat o WHERE o.aktif = 1 ORDER BY (o.stok <= o.stok_minimum) DESC, o.nama_obat")->fetchAll(); ?>
  <div class="card"><div class="card-head"><b>Stok obat & BHP</b><input class="input input-sm" placeholder="Cari obat..." data-filter="tblStok"></div><div class="tbl-wrap"><table class="tbl" id="tblStok"><thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th><th class="num">Stok</th><th class="num">Minimum</th><th>ED terdekat</th><th class="num">Harga</th></tr></thead><tbody>
  <?php foreach ($obat as $o): $ed = $o['ed'] && strtotime($o['ed']) < strtotime('+90 days'); ?>
    <tr><td class="mono"><?= aman($o['kode']) ?></td><td><b><?= aman($o['nama_obat']) ?></b> <?= $o['high_alert'] ? badge('HIGH ALERT') : '' ?> <?= $o['lasa'] ? '<span class="badge badge-amber">LASA</span>' : '' ?></td><td><?= aman($o['kategori']) ?></td>
      <td class="num <?= $o['stok'] <= $o['stok_minimum'] ? 'text-red-600 font-bold' : '' ?>"><?= (int) $o['stok'] ?> <?= aman($o['satuan']) ?></td><td class="num"><?= (int) $o['stok_minimum'] ?></td>
      <td class="<?= $ed ? 'text-amber-700 font-semibold' : '' ?>"><?= $o['ed'] ? tgl($o['ed']) : '-' ?></td><td class="num"><?= rupiah($o['harga_satuan']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php else:
    $rows = $pdo->query("SELECT r.*, p.nama_lengkap, p.nomor_rm, p.alergi, pl.nama AS poli, k.jenis, d.nama_lengkap AS dokter,
        (SELECT COUNT(*) FROM resep_item ri WHERE ri.id_resep = r.id) AS n_item,
        (SELECT COUNT(*) FROM resep_item ri JOIN obat o ON o.id = ri.id_obat WHERE ri.id_resep = r.id AND o.high_alert = 1) AS n_ha
        FROM resep r JOIN kunjungan k ON k.id = r.id_kunjungan JOIN pasien p ON p.id = k.id_pasien JOIN unit_layanan pl ON pl.id = k.id_unit JOIN pegawai d ON d.id = r.id_dokter
        WHERE $where$scope ORDER BY CASE k.jenis WHEN 'igd' THEN 0 WHEN 'rawat_inap' THEN 1 ELSE 2 END, r.tanggal")->fetchAll(); ?>
  <div class="card"><div class="card-head"><h3>Daftar Resep</h3></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>No.</th><th>Pasien</th><th>Asal / Dokter</th><th class="num">Item</th><th>Status</th><th class="num">Aksi</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">Tidak ada resep.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr><td class="mono">R/<?= $r['id'] ?><div class="muted"><?= tgl_jam($r['tanggal']) ?></div></td>
      <td><b class="pname"><?= aman($r['nama_lengkap']) ?></b><div class="muted"><?= aman($r['nomor_rm']) ?></div><?= $r['alergi'] ? '<span class="badge badge-red">Alergi: ' . aman($r['alergi']) . '</span>' : '' ?></td>
      <td><?= ['igd' => badge('IGD'), 'rawat_inap' => '<span class="badge badge-purple">Rawat Inap</span>', 'rawat_jalan' => ''][$r['jenis']] ?? '' ?> <?= aman($r['poli']) ?><div class="muted"><?= aman($r['dokter']) ?></div></td>
      <td class="num"><?= (int) $r['n_item'] ?><?= $r['n_ha'] ? '<div>' . badge('HIGH ALERT') . '</div>' : '' ?></td><td><?= badge($r['status']) ?></td>
      <td class="num"><a href="?id=<?= $r['id'] ?>" class="btn btn-<?= $apt && $r['status'] !== 'Selesai' && $r['status'] !== 'Batal' ? 'primary' : 'secondary' ?> btn-sm"><?= $apt && !in_array($r['status'], ['Selesai', 'Batal'], true) ? 'Proses' : 'Detail' ?></a></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif;
tampil_footer();
