<?php
/*
 * MODUL 8: LABORATORY
 *   ATLM   : Order Baru -> Spesimen Diterima -> Diproses (input hasil) -> Menunggu Validasi   (bisa tolak spesimen)
 *   Sp.PK  : Menunggu Validasi -> Diterbitkan (atau dikembalikan ke ATLM)
 *   Dokter/Perawat: memantau STATUS semua order; isi hasil baru terlihat setelah DITERBITKAN (SOP Laboratory).
 * Tabel: order_pelayanan (jenis = Laboratorium). Laboratorium pusat melayani semua cabang (kolom Cabang).
 */
require_once __DIR__ . '/../includes/app.php';
wajib_akses('lab');
$pdo = getPDO();
$TAHAP = ['Order Baru', 'Spesimen Diterima', 'Diproses', 'Menunggu Validasi', 'Diterbitkan'];
$atlm = role_is('atlm') && boleh('lab', true);
$spk = role_is('spk') && boleh('lab', true);

if (is_post()) {
    $s = $pdo->prepare("SELECT o.*, j.nama, p.nomor_rm FROM order_pelayanan o JOIN layanan j ON j.id = o.id_layanan JOIN kunjungan k ON k.id = o.id_kunjungan JOIN pasien p ON p.id = k.id_pasien WHERE o.id = ? AND o.jenis = 'Laboratorium'");
    $s->execute([(int) post('id')]);
    $o = $s->fetch();
    $aksi = post('aksi');
    if (!$o) { flash('error', 'Order tidak ditemukan.'); kembali(); }
    if ($o['jaminan'] === 'Menunggu Persetujuan') { flash('error', 'Order ini masih menunggu persetujuan asuransi. Hubungi Verifikator.'); kembali(); }
    $ubah = function (string $dari, string $ke, array $set = []) use ($pdo, $o) {
        $cols = array_merge(['status' => $ke], $set);
        $u = $pdo->prepare('UPDATE order_pelayanan SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols))) . ' WHERE id = ? AND status = ?');
        $u->execute([...array_values($cols), $o['id'], $dari]);
        return $u->rowCount() > 0;
    };
    $ok = false;
    if (in_array($aksi, ['terima', 'tolak', 'hasil', 'verifikasi'], true)) {
        if (!wajib_role_aksi(['atlm'], 'Tahap ini hanya untuk ATLM. Dokter Sp.PK hanya melakukan validasi.', 'lab')) kembali();
        if ($aksi === 'terima') $ok = $ubah('Order Baru', 'Spesimen Diterima', ['id_petugas' => uid(), 'waktu_dikerjakan' => date('Y-m-d H:i:s')]);
        if ($aksi === 'tolak') {
            if (mb_strlen(post('alasan')) < 5) { flash('error', 'Tulis alasan spesimen ditolak.'); kembali(); }
            $ok = $ubah('Order Baru', 'Ditolak', ['catatan' => mb_substr('Spesimen ditolak: ' . post('alasan'), 0, 255), 'id_petugas' => uid()]);
        }
        if ($aksi === 'hasil') {
            if (mb_strlen(post('hasil')) < 3) { flash('error', 'Hasil pemeriksaan wajib diisi.'); kembali(); }
            $ok = $ubah('Spesimen Diterima', 'Diproses', ['hasil' => post('hasil'), 'id_petugas' => uid()]) || $ubah('Diproses', 'Diproses', ['hasil' => post('hasil')]);
        }
        if ($aksi === 'verifikasi') $ok = $ubah('Diproses', 'Menunggu Validasi');
    } elseif (in_array($aksi, ['validasi', 'kembalikan'], true)) {
        if (!wajib_role_aksi(['spk'], 'Validasi medis hanya oleh Dokter Sp. Patologi Klinik.', 'lab')) kembali();
        if ($aksi === 'validasi') $ok = $ubah('Menunggu Validasi', 'Diterbitkan', ['catatan' => mb_substr(post('catatan') ?: 'Tervalidasi', 0, 255), 'id_validator' => uid(), 'waktu_terbit' => date('Y-m-d H:i:s')]);
        if ($aksi === 'kembalikan') $ok = $ubah('Menunggu Validasi', 'Diproses', ['catatan' => mb_substr('Dikembalikan: ' . post('catatan'), 0, 255)]);
    }
    if ($ok) {
        audit('Laboratory', ucfirst($aksi), "Order lab #{$o['id']} {$o['nama']} {$o['nomor_rm']}");
        flash('sukses', "Order {$o['nama']} ({$o['nomor_rm']}): " . ['terima' => 'spesimen diterima', 'tolak' => 'spesimen ditolak', 'hasil' => 'hasil tersimpan (status Diproses)', 'verifikasi' => 'verifikasi teknis selesai, menunggu validasi Sp.PK', 'validasi' => 'hasil DITERBITKAN dan tampil di EMR dokter', 'kembalikan' => 'dikembalikan ke ATLM'][$aksi] . '.');
    } else flash('error', 'Status order sudah berubah atau tahap tidak sesuai. Muat ulang halaman.');
    kembali();
}

// ---------- LAPORAN HASIL ----------
if (get('laporan') !== '') {
    $s = $pdo->prepare("SELECT o.*, o.catatan AS catatan_validasi, j.nama, j.nilai_normal, j.sub_kategori AS kategori, p.id AS id_pasien, p.nama_lengkap, p.nomor_rm, p.tanggal_lahir, p.jenis_kelamin, d.nama_lengkap AS dokter_pengirim,
        a.nama_lengkap AS atlm, v.nama_lengkap AS validator, k.id_rs FROM order_pelayanan o JOIN layanan j ON j.id = o.id_layanan JOIN kunjungan k ON k.id = o.id_kunjungan JOIN pasien p ON p.id = k.id_pasien
        JOIN pegawai d ON d.id = o.id_dokter LEFT JOIN pegawai a ON a.id = o.id_petugas LEFT JOIN pegawai v ON v.id = o.id_validator WHERE o.id = ? AND o.jenis = 'Laboratorium' AND o.status = 'Diterbitkan'");
    $s->execute([(int) get('laporan')]);
    $l = $s->fetch();
    if (!$l) { flash('error', 'Laporan belum final (belum divalidasi Sp.PK).'); redirect('lab/lab.php'); }
    wajib_pasien_sendiri((int) $l['id_pasien']);
    tampil_header('Laporan Hasil Laboratorium', '', '<button onclick="window.print()" class="btn btn-primary">Cetak</button> <a href="lab.php" class="btn btn-secondary">Kembali</a>');
    ?>
    <div class="card max-w-3xl print-area"><div class="card-body">
      <div class="flex items-center gap-3 border-b pb-3 mb-3"><img src="<?= url('assets/logo.png') ?>" class="w-12 h-12" alt=""><div><b class="text-[#004c6d]"><?= aman(konfig('nama_rs', (int) $l['id_rs'])) ?> · <?= aman(konfig('nama_cabang', (int) $l['id_rs'])) ?></b><div class="muted">Instalasi Laboratorium · <?= aman(konfig('alamat', (int) $l['id_rs'])) ?></div></div></div>
      <dl class="kv mb-4"><dt>Pasien</dt><dd><?= aman($l['nama_lengkap']) ?> (<?= aman($l['nomor_rm']) ?>)</dd><dt>Umur / JK</dt><dd><?= umur($l['tanggal_lahir']) ?> / <?= jk($l['jenis_kelamin']) ?></dd>
        <dt>Dokter pengirim</dt><dd><?= aman($l['dokter_pengirim']) ?></dd><dt>Indikasi</dt><dd><?= aman($l['indikasi']) ?></dd><dt>Prioritas</dt><dd><?= aman($l['urgensi']) ?></dd></dl>
      <table class="tbl"><thead><tr><th>Pemeriksaan</th><th>Hasil</th><th>Nilai Rujukan</th></tr></thead><tbody><tr><td><b><?= aman($l['nama']) ?></b><div class="muted"><?= aman($l['kategori']) ?></div></td><td><?= nl2br(aman($l['hasil'])) ?></td><td class="text-xs"><?= aman($l['nilai_normal']) ?></td></tr></tbody></table>
      <p class="mt-3 text-sm"><b>Catatan validasi:</b> <?= aman($l['catatan_validasi']) ?></p>
      <div class="grid grid-cols-2 gap-4 mt-8 text-sm text-center"><div>Pemeriksa (ATLM)<br><br><br><b><?= aman($l['atlm']) ?></b></div><div>Validator<br><br><br><b><?= aman($l['validator']) ?></b><br><?= tgl_jam($l['waktu_terbit']) ?></div></div>
    </div></div>
    <?php tampil_footer();
    exit;
}

// ---------- DAFTAR ORDER ----------
$tab = get('tab', 'aktif');
$where = "o.jenis = 'Laboratorium' AND " . ($tab === 'selesai' ? "o.status IN ('Diterbitkan','Ditolak') AND o.created_at >= ?" : "o.status NOT IN ('Diterbitkan','Ditolak') AND o.created_at >= ?") . sql_rs('k.id_rs');
$par = [$tab === 'selesai' ? date('Y-m-d', strtotime('-30 days')) : '2000-01-01'];
$petugas_lab = $atlm || $spk;
if (!$petugas_lab) {   // dokter/perawat: pantau status order 14 hari terakhir (+ yang belum selesai)
    $where = "o.jenis = 'Laboratorium' AND (o.created_at >= ? OR o.status NOT IN ('Diterbitkan','Ditolak'))" . (role_is('dokter') ? '' : sql_rs('k.id_rs'));
    $par = [date('Y-m-d', strtotime('-14 days'))];
    if (role_is('dokter')) { $where .= ' AND o.id_dokter = ?'; $par[] = uid(); }
}
$s = $pdo->prepare("SELECT o.*, o.catatan AS catatan_validasi, j.nama, j.nilai_normal, p.nama_lengkap, p.nomor_rm, p.tanggal_lahir, p.jenis_kelamin, d.nama_lengkap AS dokter, pl.nama AS poli, k.id_rs
    FROM order_pelayanan o JOIN layanan j ON j.id = o.id_layanan JOIN kunjungan k ON k.id = o.id_kunjungan JOIN pasien p ON p.id = k.id_pasien JOIN pegawai d ON d.id = o.id_dokter JOIN unit_layanan pl ON pl.id = k.id_unit
    WHERE $where ORDER BY CASE o.urgensi WHEN 'CITO' THEN 0 ELSE 1 END, o.created_at");
$s->execute($par);
$rows = $s->fetchAll();
$semua = $pdo->query("SELECT o.status, o.urgensi FROM order_pelayanan o JOIN kunjungan k ON k.id = o.id_kunjungan WHERE o.jenis = 'Laboratorium' AND o.created_at >= '" . date('Y-m-d', strtotime('-30 days')) . "'" . sql_rs('k.id_rs'))->fetchAll();
$c = fn($st) => count(array_filter($semua, fn($r) => $r['status'] === $st));
tampil_header('Laboratorium', $atlm ? 'ATLM: terima spesimen, input hasil, verifikasi teknis.' : ($spk ? 'Dokter Sp.PK: validasi medis dan penerbitan hasil.' : 'Pantau status order lab. Hasil tampil setelah divalidasi & diterbitkan Sp.PK.'));
echo kartu([['Order Baru', $c('Order Baru'), 'amber'], ['Spesimen / Diproses', $c('Spesimen Diterima') + $c('Diproses'), 'teal'], ['Menunggu Validasi', $c('Menunggu Validasi'), 'purple'], ['Diterbitkan (30 hari)', $c('Diterbitkan'), 'green'],
            ['CITO Aktif', count(array_filter($semua, fn($r) => $r['urgensi'] === 'CITO' && !in_array($r['status'], ['Diterbitkan', 'Ditolak'], true))), 'red']]);
?>
<?php if ($petugas_lab): ?><div class="tabs"><a class="tab<?= $tab === 'aktif' ? ' active' : '' ?>" href="?tab=aktif">Order aktif</a><a class="tab<?= $tab === 'selesai' ? ' active' : '' ?>" href="?tab=selesai">Diterbitkan / ditolak</a></div><?php endif; ?>
<div class="card"><div class="card-head"><h3>Daftar Order Laboratorium</h3></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pasien</th><th>Pemeriksaan</th><th>Pengirim</th><th>Alur</th><th class="num">Tindakan</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="5" class="empty">Tidak ada order.</td></tr><?php endif; ?>
<?php foreach ($rows as $o): ?>
  <tr><td><b class="pname"><?= aman($o['nama_lengkap']) ?></b><div class="muted"><?= aman($o['nomor_rm']) ?> · <?= jk($o['jenis_kelamin']) ?> · <?= umur($o['tanggal_lahir']) ?></div></td>
    <td><b><?= aman($o['nama']) ?></b> <?= $o['urgensi'] === 'CITO' ? badge('CITO') : '' ?><?= in_array($o['jaminan'], ['Disetujui', 'Bayar Sendiri'], true) ? ' <span class="badge badge-gray">' . ($o['jaminan'] === 'Disetujui' ? 'Disetujui asuransi' : 'Biaya pribadi') . '</span>' : '' ?><div class="muted"><?= aman($o['indikasi']) ?></div><?= $o['status_puasa'] !== '-' ? '<div class="text-xs mt-1"' . ($o['status_puasa'] === 'Tidak puasa' ? ' style="color:#92400e"' : '') . '>Status puasa: <b>' . aman($o['status_puasa']) . '</b></div>' : '' ?><?= $o['hasil'] && ($petugas_lab || $o['status'] === 'Diterbitkan') ? '<div class="text-xs mt-1">Hasil: ' . aman($o['hasil']) . '</div>' : '' ?></td>
    <td><?= aman($o['dokter']) ?><div class="muted"><?= aman($o['poli']) ?> · <?= aman(nama_cabang((int) $o['id_rs'])) ?> · <?= tgl_jam($o['created_at']) ?></div></td>
    <td><?= $o['status'] === 'Ditolak' ? badge('Ditolak') . '<div class="muted">' . aman($o['catatan_validasi']) . '</div>' : stepper($TAHAP, $o['status']) ?></td>
    <td class="num" style="min-width:170px">
      <?php if ($o['jaminan'] === 'Menunggu Persetujuan' && $o['status'] === 'Order Baru'): ?><span class="badge badge-amber">Menunggu persetujuan asuransi</span>
      <?php elseif ($atlm && $o['status'] === 'Order Baru'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="terima"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn btn-primary btn-sm">Terima Spesimen</button></form>
        <button class="btn btn-secondary btn-sm" data-open="tolak<?= $o['id'] ?>">Tolak</button>
        <dialog class="modal" id="tolak<?= $o['id'] ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="tolak"><input type="hidden" name="id" value="<?= $o['id'] ?>"><div class="modal-head"><h3>Tolak spesimen</h3></div>
          <div class="modal-body"><input name="alasan" class="input" placeholder="contoh: Spesimen lisis / volume kurang" required></div><div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Batal</button><button class="btn btn-danger">Tolak Spesimen</button></div></form></dialog>
      <?php elseif ($atlm && in_array($o['status'], ['Spesimen Diterima', 'Diproses'], true)): ?>
        <button class="btn btn-primary btn-sm" data-open="hasil<?= $o['id'] ?>"><?= $o['status'] === 'Diproses' ? 'Ubah Hasil' : 'Input Hasil' ?></button>
        <?php if ($o['status'] === 'Diproses'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn btn-success btn-sm">Verifikasi Teknis</button></form><?php endif; ?>
        <dialog class="modal" id="hasil<?= $o['id'] ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="hasil"><input type="hidden" name="id" value="<?= $o['id'] ?>">
          <div class="modal-head"><h3>Hasil <?= aman($o['nama']) ?> · <?= aman($o['nama_lengkap']) ?></h3></div><div class="modal-body text-left">
          <div class="notice mb-2 text-xs">Nilai rujukan: <?= aman($o['nilai_normal']) ?></div>
          <textarea name="hasil" class="input" rows="5" required placeholder="contoh: Hb 13,8 g/dL; Leukosit 8.200/uL; Trombosit 250.000/uL"><?= aman($o['hasil']) ?></textarea></div>
          <div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Batal</button><button class="btn btn-primary">Simpan Hasil</button></div></form></dialog>
      <?php elseif ($spk && $o['status'] === 'Menunggu Validasi'): ?>
        <button class="btn btn-success btn-sm" data-open="val<?= $o['id'] ?>">Validasi Sp.PK</button>
        <dialog class="modal" id="val<?= $o['id'] ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>"><div class="modal-head"><h3>Validasi <?= aman($o['nama']) ?></h3></div>
          <div class="modal-body text-left"><div class="readonly-box mb-2"><b>Hasil ATLM:</b> <?= aman($o['hasil']) ?><br><span class="muted">Rujukan: <?= aman($o['nilai_normal']) ?></span></div>
          <input name="catatan" class="input" placeholder="Catatan validasi, contoh: Hasil dalam batas normal"></div>
          <div class="modal-foot"><button name="aksi" value="kembalikan" class="btn btn-secondary">Kembalikan ke ATLM</button><button name="aksi" value="validasi" class="btn btn-success">Setujui &amp; Terbitkan</button></div></form></dialog>
      <?php elseif ($o['status'] === 'Diterbitkan'): ?><a href="?laporan=<?= $o['id'] ?>" class="btn btn-secondary btn-sm">Laporan</a>
      <?php else: ?><span class="muted">Laporan belum final</span><?php endif; ?>
    </td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php tampil_footer();
