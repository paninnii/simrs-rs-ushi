<?php
/*
 * MODUL 4: CPOE (Computerized Physician Order Entry) -- PPT Bab 6
 *   Dokter  : membuat order lab / radiologi / e-resep / tindakan untuk pasien yang ditangani
 *   Perawat : memvalidasi & mengeksekusi order TINDAKAN dari dokter (SOP CPOE), memantau order lain
 *   Lainnya : memantau status order (Apoteker, Admin Rekam Medis -- lihat saja)
 * Tabel: order_pelayanan (tindakan, laboratorium, radiologi -- satu tabel, beda di kolom jenis), resep & resep_item.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
require_once __DIR__ . '/../includes/order.php';
wajib_akses('cpoe');
$pdo = getPDO();
$dokter = role_is('dokter') && boleh('cpoe', true);
$perawat = role_is('perawat') && boleh('cpoe', true);
$errors = [];

// ---------- PERAWAT: validasi & eksekusi order tindakan ----------
if (!get('kunjungan') && is_post()) {
    if (!wajib_role_aksi(['perawat'], 'Eksekusi order tindakan dilakukan oleh Perawat.', 'cpoe')) kembali();
    $s = $pdo->prepare("SELECT tk.*, tk.uraian AS nama, p.nomor_rm, k.id_rs FROM order_pelayanan tk JOIN kunjungan k ON k.id = tk.id_kunjungan JOIN pasien p ON p.id = k.id_pasien WHERE tk.id = ? AND tk.jenis = 'Tindakan'");
    $s->execute([(int) post('id')]);
    $tk = $s->fetch();
    $aksi = post('aksi');
    if (!$tk || $tk['status'] !== 'Order Baru' || (int) $tk['id_rs'] !== rs_user()) { flash('error', 'Order tindakan sudah diproses atau bukan di cabang Anda.'); kembali(); }
    if ($aksi === 'kerjakan') {
        if (!isset($_POST['cek_identitas'])) { flash('error', 'Centang verifikasi identitas pasien (nama + tanggal lahir / No. RM) sebelum mengerjakan tindakan.'); kembali(); }
        $pdo->prepare("UPDATE order_pelayanan SET status = 'Dikerjakan', catatan = ?, id_petugas = ?, waktu_dikerjakan = ? WHERE id = ? AND status = 'Order Baru'")
            ->execute([post('catatan') ?: 'Dikerjakan sesuai instruksi DPJP', uid(), date('Y-m-d H:i:s'), $tk['id']]);
        $pesan = "tindakan {$tk['nama']} ({$tk['nomor_rm']}) selesai dikerjakan dan masuk ke tagihan";
    } elseif ($aksi === 'tolak') {
        if (mb_strlen(post('catatan')) < 5) { flash('error', 'Tulis alasan, contoh: instruksi tidak jelas / kontraindikasi, sudah dikonfirmasi ke dokter.'); kembali(); }
        $pdo->prepare("UPDATE order_pelayanan SET status = 'Batal', catatan = ?, id_petugas = ?, waktu_dikerjakan = ? WHERE id = ? AND status = 'Order Baru'")
            ->execute([mb_substr('Dikembalikan ke dokter: ' . post('catatan'), 0, 255), uid(), date('Y-m-d H:i:s'), $tk['id']]);
        $pesan = "order {$tk['nama']} dikembalikan ke dokter";
    } else { flash('error', 'Aksi tidak dikenal.'); kembali(); }
    audit('CPOE', $aksi === 'kerjakan' ? 'Eksekusi tindakan' : 'Tolak order tindakan', "{$tk['nama']} {$tk['nomor_rm']}");
    flash('sukses', ucfirst($pesan) . '.');
    kembali();
}

/** Kunjungan yang boleh diorder dokter ini: pasien DPJP aktif hari ini, IGD, atau rawat inap yang ia tangani. */
function kunjungan_dokter(): array
{
    $s = getPDO()->prepare("SELECT k.id, CONCAT(p.nomor_rm, ' · ', p.nama_lengkap, ' · ', pl.nama) AS label FROM kunjungan k JOIN pasien p ON p.id = k.id_pasien JOIN unit_layanan pl ON pl.id = k.id_unit
        LEFT JOIN rawat_inap r ON r.id_kunjungan = k.id
        WHERE k.status <> 'Batal' AND k.no_antrian IS NOT NULL AND ((k.id_dokter = ? AND k.tanggal >= ?) OR (r.status = 'Aktif' AND r.id_dokter = ?)) ORDER BY k.id DESC");
    $s->execute([id_pegawai(), date('Y-m-d', strtotime('-1 day')), uid()]);
    return $s->fetchAll(PDO::FETCH_KEY_PAIR);
}

$id_k = (int) get('kunjungan');
if ($id_k && $dokter) {
    $boleh_k = kunjungan_dokter();
    $k = ambil_kunjungan($id_k);
    if (!$k || !isset($boleh_k[$id_k])) { flash('error', 'Order hanya untuk pasien yang sedang Anda tangani (DPJP / rawat inap / IGD).'); redirect('cpoe/cpoe.php'); }
    if (is_post()) {
        [$errors, $order] = validasi_order($k);
        if (!$errors && !$order['tindakan'] && !$order['lab'] && !$order['rad'] && !$order['obat']) $errors['_db'] = 'Belum ada order yang dipilih.';
        if (!$errors) {
            $pdo->beginTransaction();
            $r = simpan_order($k, $order);
            $pdo->commit();
            flash('sukses', 'Order dikirim: ' . implode(', ', $r) . '. Status bisa dipantau di CPOE.');
            redirect('cpoe/cpoe.php?kunjungan=' . $id_k);
        }
    }
    $s = $pdo->prepare("SELECT o.jenis, o.uraian AS item, o.urgensi, CASE WHEN o.jaminan = 'Menunggu Persetujuan' THEN 'Menunggu Persetujuan Asuransi' ELSE o.status END AS status,
                               CASE WHEN o.jenis = 'Tindakan' THEN CONCAT(o.jumlah, 'x', CASE WHEN COALESCE(o.keterangan, '') = '' THEN '' ELSE CONCAT(' · ', o.keterangan) END, ' · oleh ', COALESCE(o.pelaksana, '-')) ELSE o.indikasi END AS indikasi, o.created_at
                        FROM order_pelayanan o WHERE o.id_kunjungan = ?
                        UNION ALL SELECT 'Farmasi', o.nama_obat, '-', r.status, CONCAT(ri.dosis, ' ', ri.frekuensi), r.tanggal FROM resep r JOIN resep_item ri ON ri.id_resep = r.id JOIN obat o ON o.id = ri.id_obat WHERE r.id_kunjungan = ?");
    $s->execute([$id_k, $id_k]);
    $ada = $s->fetchAll();
    tampil_header('Buat Order CPOE', $k['poli'] . ' · ' . tgl($k['tanggal']), '<a href="cpoe.php" class="btn btn-secondary">&larr; Monitoring order</a>');
    echo kartu_pasien($k, aman($k['penjamin']) . ' · DPJP ' . aman($k['dokter']));
    if ($ada) {
        echo '<div class="card mb-4"><div class="card-head"><h3>Order yang sudah ada</h3></div><div class="tbl-wrap"><table class="tbl"><tbody>';
        foreach ($ada as $a) echo '<tr><td>' . aman($a['jenis']) . '</td><td><b>' . aman($a['item']) . '</b><div class="muted">' . aman($a['indikasi']) . '</div></td><td>' . ($a['urgensi'] === 'CITO' ? badge('CITO') : aman($a['urgensi'])) . '</td><td>' . badge($a['status']) . '</td></tr>';
        echo '</tbody></table></div></div>';
    }
    ?>
    <form method="post"><?= csrf_field() ?>
      <?php if (isset($errors['_db'])): ?><div class="alert alert-error"><?= aman($errors['_db']) ?></div><?php elseif ($errors): ?><div class="alert alert-error">Periksa kembali isian order.</div><?php endif; ?>
      <?php form_order($k, $errors); ?>
      <div class="card"><div class="modal-foot"><button class="btn btn-primary">Kirim Order</button></div></div>
    </form>
    <?php tampil_footer();
    exit;
}

// ---------- MONITORING ORDER ----------
$jenis = get('jenis');
$filter = role_is('dokter') ? ' AND (o.id_dokter = ' . (int) uid() . ')' : sql_rs('k.id_rs');
$filter_r = role_is('dokter') ? ' AND (r.id_dokter = ' . (int) uid() . ')' : sql_rs('k.id_rs');
$min = date('Y-m-d', strtotime('-7 days'));
$JENIS_ORDER = ['lab' => 'Laboratorium', 'rad' => 'Radiologi', 'tindakan' => 'Tindakan'];
$q = [];
$jenis_sql = $jenis === '' ? '' : (isset($JENIS_ORDER[$jenis]) ? " AND o.jenis = '" . $JENIS_ORDER[$jenis] . "'" : null);
if ($jenis_sql !== null) $q[] = "SELECT o.jenis, o.id AS id_order, o.id_kunjungan, k.id_rs, o.uraian AS item, o.urgensi,
       CASE WHEN o.jaminan = 'Menunggu Persetujuan' THEN 'Menunggu Persetujuan Asuransi' ELSE o.status END AS status,
       CASE WHEN o.jenis = 'Tindakan' THEN CONCAT(o.jumlah, 'x · ', CASE WHEN COALESCE(o.keterangan, '') = '' THEN '' ELSE CONCAT(o.keterangan, ' · ') END, pl.nama) ELSE o.indikasi END AS indikasi,
       CASE o.jenis WHEN 'Laboratorium' THEN o.hasil WHEN 'Radiologi' THEN o.kesimpulan ELSE o.catatan END AS hasil, o.created_at, p.nama_lengkap, p.nomor_rm, p.alergi
   FROM order_pelayanan o JOIN kunjungan k ON k.id = o.id_kunjungan JOIN pasien p ON p.id = k.id_pasien JOIN unit_layanan pl ON pl.id = k.id_unit
   WHERE (o.created_at >= '$min' OR o.status = 'Order Baru')$jenis_sql$filter";
if ($jenis === '' || $jenis === 'resep') $q[] = "SELECT 'Farmasi' AS jenis, r.id AS id_order, r.id_kunjungan, k.id_rs, o.nama_obat AS item, '-' AS urgensi, r.status, CONCAT(ri.jumlah, ' ', o.satuan, ' · ', ri.dosis, ' ', ri.frekuensi) AS indikasi, ri.aturan_pakai AS hasil, r.tanggal AS created_at, p.nama_lengkap, p.nomor_rm, p.alergi
   FROM resep r JOIN resep_item ri ON ri.id_resep = r.id JOIN obat o ON o.id = ri.id_obat JOIN kunjungan k ON k.id = r.id_kunjungan JOIN pasien p ON p.id = k.id_pasien WHERE r.tanggal >= '$min'$filter_r";
$rows = $q ? $pdo->query('SELECT * FROM (' . implode(' UNION ALL ', $q) . ') x ORDER BY x.created_at DESC')->fetchAll() : [];
$final = fn($st) => in_array($st, ['Diterbitkan', 'Selesai', 'Dikerjakan'], true);
tampil_header('Order Klinis (CPOE)', role_is('dokter') ? 'Order yang Anda buat 7 hari terakhir. Order dikirim otomatis ke Lab, Radiologi, Farmasi, dan Perawat (tindakan).' : ($perawat ? 'Validasi & kerjakan order tindakan dari dokter; pantau order lab, radiologi, dan resep.' : 'Pemantauan order 7 hari terakhir (lihat saja).'),
    $dokter ? '<button class="btn btn-primary" data-open="pilihPasien">+ Buat Order CPOE</button>' : '');
echo kartu([['Total Order', count($rows), 'teal'], ['Order Lab', count(array_filter($rows, fn($r) => $r['jenis'] === 'Laboratorium')), 'purple'], ['Order Radiologi', count(array_filter($rows, fn($r) => $r['jenis'] === 'Radiologi')), 'purple'],
            ['Item Resep', count(array_filter($rows, fn($r) => $r['jenis'] === 'Farmasi')), 'purple'], ['Tindakan Belum Dikerjakan', count(array_filter($rows, fn($r) => $r['jenis'] === 'Tindakan' && $r['status'] === 'Order Baru')), 'amber'], ['Hasil Final', count(array_filter($rows, fn($r) => $final($r['status']))), 'green'], ['CITO Belum Final', count(array_filter($rows, fn($r) => $r['urgensi'] === 'CITO' && !$final($r['status']))), 'red']]);
if ($dokter): ?>
<dialog class="modal" id="pilihPasien"><form method="get"><div class="modal-head"><h3>Pilih Pasien</h3></div><div class="modal-body"><p class="muted mb-2">Pasien DPJP hari ini / kemarin, IGD, dan rawat inap yang Anda tangani.</p>
  <select name="kunjungan" class="input" required><?= opsi(kunjungan_dokter(), '', '-- Pilih pasien --') ?></select></div>
  <div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Batal</button><button class="btn btn-primary">Lanjut</button></div></form></dialog>
<?php endif; ?>
<div class="card"><div class="card-head"><div class="tabs" style="margin:0"><?php foreach (['' => 'Semua', 'lab' => 'Laboratorium', 'rad' => 'Radiologi', 'resep' => 'Farmasi', 'tindakan' => 'Tindakan'] as $kk => $l): ?><a class="tab<?= $jenis === $kk ? ' active' : '' ?>" href="?jenis=<?= $kk ?>"><?= $l ?></a><?php endforeach; ?></div>
  <input data-filter="tblOrder" class="input" style="width:200px" placeholder="Cari pasien / item"></div>
  <div class="tbl-wrap"><table class="tbl" id="tblOrder"><thead><tr><th>Waktu</th><th>Pasien</th><th>Jenis</th><th>Order</th><th>Prioritas</th><th>Status</th><th>Hasil</th><?= $perawat ? '<th class="num">Aksi</th>' : '' ?></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="empty">Belum ada order.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr><td class="muted"><?= tgl_jam($r['created_at']) ?></td><td><b class="pname"><?= aman($r['nama_lengkap']) ?></b><div class="muted"><?= aman($r['nomor_rm']) ?><?= rs_aktif() ? '' : ' · ' . aman(daftar_cabang()[$r['id_rs']]['nama_cabang']) ?></div><?= $r['alergi'] ? '<span class="badge badge-red">Alergi ' . aman($r['alergi']) . '</span>' : '' ?></td>
    <td><?= aman($r['jenis']) ?></td><td><b><?= aman($r['item']) ?></b><div class="muted"><?= aman($r['indikasi']) ?></div></td><td><?= $r['urgensi'] === 'CITO' ? badge('CITO') : aman($r['urgensi']) ?></td>
    <td><?= badge($r['status']) ?></td><td class="text-xs"><?= $final($r['status']) ? aman(mb_substr((string) $r['hasil'], 0, 90)) : '<span class="muted">Belum final</span>' ?>
      <?php if (boleh('emr') && !role_is('manajemen')): ?><div><a class="underline text-[#0b7896]" href="<?= url('emr/emr.php?id=' . $r['id_kunjungan']) ?>">EMR</a></div><?php endif; ?></td>
    <?php if ($perawat): ?><td class="num"><?php if ($r['jenis'] === 'Tindakan' && $r['status'] === 'Order Baru'): $oid = $r['id_order']; ?>
      <button class="btn btn-primary btn-sm" data-open="tk<?= $oid ?>">Validasi &amp; Kerjakan</button>
      <dialog class="modal" id="tk<?= $oid ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $oid ?>"><div class="modal-head"><h3><?= aman($r['item']) ?> · <?= aman($r['nama_lengkap']) ?></h3></div>
        <div class="modal-body text-left"><label class="flex gap-2 items-center text-sm mb-2"><input type="checkbox" name="cek_identitas" value="1"> Identitas pasien sudah dicek (nama + tanggal lahir / No. RM) dan instruksi dokter jelas</label>
        <input name="catatan" class="input" placeholder="Catatan pelaksanaan, contoh: infus RL 20 tpm terpasang di tangan kiri"></div>
        <div class="modal-foot"><button name="aksi" value="tolak" class="btn btn-secondary">Kembalikan ke Dokter</button><button name="aksi" value="kerjakan" class="btn btn-success">Selesai Dikerjakan</button></div></form></dialog>
    <?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php tampil_footer();
