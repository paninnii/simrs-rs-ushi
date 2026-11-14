<?php
/*
 * MODUL 13: NURSING (ASUHAN KEPERAWATAN)
 *   Monitoring TTV + nursing note · Pemberian obat (dari resep dokter) · Lapor kondisi ke DPJP
 *   Perawat mengisi; dokter melihat (laporan otomatis ditandai sudah dibaca).
 * Tabel: rekam_medis (jenis_catatan Catatan Keperawatan / Pemberian Obat / Lapor DPJP), dengan sumber ruang rawat & DPJP.
 */
const JENIS_RM = ['Monitoring' => 'Catatan Keperawatan', 'Pemberian Obat' => 'Pemberian Obat', 'Lapor DPJP' => 'Lapor DPJP'];
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('keperawatan');
$pdo = getPDO();
$perawat = role_is('perawat') && boleh('keperawatan', true);
$errors = [];

if (is_post()) {
    if (!wajib_role_aksi(['perawat'], 'Catatan keperawatan hanya diisi oleh perawat.', 'keperawatan')) kembali();
    $k = ambil_kunjungan((int) post('id'));
    $jenis = post('jenis');
    if (!$k || !isset(JENIS_RM[$jenis]) || (int) $k['id_rs'] !== rs_user()) { flash('error', 'Data tidak valid atau pasien bukan di cabang Anda.'); kembali(); }
    $d = ['td' => post('tekanan_darah'), 'nadi' => post('nadi'), 'rr' => post('respirasi'), 'suhu' => str_replace(',', '.', post('suhu')), 'spo2' => post('spo2')];
    $catatan = post('catatan');
    $wajib_ttv = $jenis === 'Monitoring';
    if ($e = v_td($d['td'], $wajib_ttv)) $errors['tekanan_darah'] = $e;
    if ($e = v_angka($d['nadi'], 'Nadi', 20, 250, $wajib_ttv)) $errors['nadi'] = $e;
    if ($e = v_angka($d['rr'], 'Respirasi', 5, 80, false)) $errors['respirasi'] = $e;
    if ($e = v_angka($d['suhu'], 'Suhu', 30, 45, $wajib_ttv)) $errors['suhu'] = $e;
    if ($e = v_angka($d['spo2'], 'SpO2', 50, 100, $wajib_ttv)) $errors['spo2'] = $e;
    if (mb_strlen($catatan) < 5) $errors['catatan'] = 'Catatan wajib diisi (minimal 5 karakter).';
    $id_item = null;
    $urg = null;
    if ($jenis === 'Pemberian Obat') {
        $s = $pdo->prepare("SELECT ri.id FROM resep_item ri JOIN resep r ON r.id = ri.id_resep WHERE ri.id = ? AND r.id_kunjungan = ? AND r.status IN ('Siap Diserahkan','Selesai')");
        $s->execute([(int) post('id_resep_item'), $k['id']]);
        $id_item = $s->fetchColumn() ?: null;
        if (!$id_item) $errors['id_resep_item'] = 'Pilih obat dari resep dokter yang sudah disiapkan farmasi.';
    }
    if ($jenis === 'Lapor DPJP') $urg = post('urgensi') === 'Segera' ? 'Segera' : 'Rutin';
    if (!$errors) {
        $ranap = $k['jenis'] === 'rawat_inap' ? 'Rawat Inap' : null;
        tulis_rm((int) $k['id'], JENIS_RM[$jenis], ['tekanan_darah' => $d['td'] ?: null, 'nadi' => $d['nadi'] ?: null, 'respirasi' => $d['rr'] ?: null, 'suhu' => $d['suhu'] ?: null,
                 'spo2' => $d['spo2'] ?: null, 'catatan' => $catatan, 'id_resep_item' => $id_item, 'urgensi' => $urg], $ranap);
        audit('Nursing', $jenis, "{$k['nomor_rm']}: " . mb_substr($catatan, 0, 80));
        flash('sukses', $jenis === 'Lapor DPJP' ? "Laporan kondisi terkirim ke DPJP ($urg)." : "$jenis {$k['nama_lengkap']} tersimpan.");
        redirect('keperawatan/keperawatan.php?id=' . $k['id']);
    }
    $_GET['id'] = $k['id'];
}

// ---------- DETAIL PASIEN ----------
if (get('id') !== '') {
    $k = ambil_kunjungan((int) get('id'));
    if (!$k) redirect('keperawatan/keperawatan.php');
    wajib_pasien_sendiri((int) $k['id_pasien']);
    if (role_is('dokter')) $pdo->prepare("UPDATE rekam_medis SET dibaca_dpjp = 1 WHERE id_kunjungan = ? AND jenis_catatan = 'Lapor DPJP' AND id_dokter = ?")->execute([$k['id'], uid()]);
    $s = $pdo->prepare("SELECT c.*, CASE c.jenis_catatan WHEN 'Catatan Keperawatan' THEN 'Monitoring' ELSE c.jenis_catatan END AS jenis, c.waktu_catat AS created_at,
                               u.nama_lengkap, o.nama_obat, ri.dosis, ri.frekuensi FROM rekam_medis c JOIN pegawai u ON u.id = c.ditulis_oleh
                        LEFT JOIN resep_item ri ON ri.id = c.id_resep_item LEFT JOIN obat o ON o.id = ri.id_obat
                        WHERE c.id_kunjungan = ? AND c.jenis_catatan IN ('Catatan Keperawatan','Pemberian Obat','Lapor DPJP') ORDER BY c.waktu_catat DESC, c.id DESC");
    $s->execute([$k['id']]);
    $log = $s->fetchAll();
    $s = $pdo->prepare("SELECT ri.id, CONCAT(o.nama_obat, ' · ', ri.dosis, ' ', ri.frekuensi, ' (', ri.aturan_pakai, ')') FROM resep_item ri JOIN resep r ON r.id = ri.id_resep JOIN obat o ON o.id = ri.id_obat
                        WHERE r.id_kunjungan = ? AND r.status IN ('Siap Diserahkan','Selesai')");
    $s->execute([$k['id']]);
    $obat = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    $s = $pdo->prepare("SELECT ra.*, b.kode_bed, km.nama AS nomor FROM rawat_inap ra JOIN bed b ON b.id = ra.id_bed JOIN unit_layanan km ON km.id = b.id_unit WHERE ra.id_kunjungan = ?");
    $s->execute([$k['id']]);
    $ra = $s->fetch();
    $tab = $_POST['jenis'] ?? get('tab', 'Monitoring');
    tampil_header('Asuhan Keperawatan', $ra ? "{$ra['nomor']} bed {$ra['kode_bed']} · hari rawat ke-" . (max(1, (int) floor((time() - strtotime(substr($ra['tanggal_masuk'], 0, 10))) / 86400) + 1)) : aman($k['poli']), '<a href="keperawatan.php" class="btn btn-secondary">&larr; Daftar pasien</a>');
    echo kartu_pasien($k, 'DPJP ' . aman($k['dokter']) . ' · ' . aman($k['penjamin']));
    ?>
    <div class="grid lg:grid-cols-5 gap-4">
    <?php if ($perawat): ?>
    <div class="lg:col-span-2"><div class="tabs"><?php foreach (['Monitoring' => 'Monitoring TTV', 'Pemberian Obat' => 'Pemberian Obat', 'Lapor DPJP' => 'Lapor Kondisi ke DPJP'] as $t => $l): ?><a class="tab<?= $tab === $t ? ' active' : '' ?>" href="?id=<?= $k['id'] ?>&tab=<?= urlencode($t) ?>"><?= $l ?></a><?php endforeach; ?></div>
      <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $k['id'] ?>"><input type="hidden" name="jenis" value="<?= aman($tab) ?>"><div class="card-body">
        <?php if ($tab === 'Pemberian Obat'): ?>
          <div class="field"><label>Obat dari instruksi dokter *</label><select name="id_resep_item" class="<?= err_class($errors, 'id_resep_item') ?>"><?= opsi($obat, $_POST['id_resep_item'] ?? '', $obat ? '-- Pilih obat --' : 'Belum ada obat yang disiapkan farmasi') ?></select><?= err($errors, 'id_resep_item') ?></div>
        <?php endif; ?>
        <div class="form-grid">
          <div class="field"><label>TD <?= $tab === 'Monitoring' ? '*' : '' ?></label><input name="tekanan_darah" value="<?= old('tekanan_darah') ?>" placeholder="120/80" class="<?= err_class($errors, 'tekanan_darah') ?>"><?= err($errors, 'tekanan_darah') ?></div>
          <div class="field"><label>Nadi <?= $tab === 'Monitoring' ? '*' : '' ?></label><input type="number" name="nadi" value="<?= old('nadi') ?>" class="<?= err_class($errors, 'nadi') ?>"><?= err($errors, 'nadi') ?></div>
          <div class="field"><label>RR</label><input type="number" name="respirasi" value="<?= old('respirasi') ?>" class="<?= err_class($errors, 'respirasi') ?>"><?= err($errors, 'respirasi') ?></div>
          <div class="field"><label>Suhu <?= $tab === 'Monitoring' ? '*' : '' ?></label><input type="number" step="0.1" name="suhu" value="<?= old('suhu') ?>" class="<?= err_class($errors, 'suhu') ?>"><?= err($errors, 'suhu') ?></div>
          <div class="field"><label>SpO₂ <?= $tab === 'Monitoring' ? '*' : '' ?></label><input type="number" name="spo2" value="<?= old('spo2') ?>" class="<?= err_class($errors, 'spo2') ?>"><?= err($errors, 'spo2') ?></div>
          <?php if ($tab === 'Lapor DPJP'): ?><div class="field"><label>Urgensi *</label><select name="urgensi"><?= opsi(['Rutin' => 'Rutin', 'Segera' => 'Segera'], $_POST['urgensi'] ?? 'Segera') ?></select></div><?php endif; ?>
        </div>
        <div class="field"><label><?= ['Monitoring' => 'Nursing note & rencana asuhan', 'Pemberian Obat' => 'Catatan pemberian (jam, rute, reaksi)', 'Lapor DPJP' => 'Perubahan kondisi yang dilaporkan'][$tab] ?? 'Catatan' ?> *</label><textarea name="catatan" class="<?= err_class($errors, 'catatan') ?>"><?= old('catatan') ?></textarea><?= err($errors, 'catatan') ?></div>
      </div><div class="modal-foot"><button class="btn <?= $tab === 'Lapor DPJP' ? 'btn-danger' : 'btn-primary' ?>"><?= $tab === 'Lapor DPJP' ? 'Simpan & Laporkan ke DPJP' : 'Simpan' ?></button></div></form>
      <p class="muted mt-2">Perawat tidak dapat membuat order atau mengubah diagnosis dokter.</p></div>
    <?php endif; ?>
    <div class="card <?= $perawat ? 'lg:col-span-3' : 'lg:col-span-5' ?>"><div class="card-head"><h3>Riwayat Asuhan (<?= count($log) ?>)</h3></div><div class="card-body">
      <?php if (!$log): ?><p class="muted">Belum ada catatan keperawatan.</p><?php endif; ?>
      <?php foreach ($log as $c): ?><div class="border-l-4 pl-3 mb-4 <?= $c['jenis'] === 'Lapor DPJP' ? 'border-red-600' : ($c['jenis'] === 'Pemberian Obat' ? 'border-purple-600' : 'border-[#0b7896]') ?>">
        <div class="text-sm"><b><?= aman($c['jenis']) ?></b> <?= $c['urgensi'] ? badge($c['urgensi']) : '' ?> <span class="muted">· <?= tgl_jam($c['created_at']) ?> · <?= aman($c['nama_lengkap']) ?></span>
          <?= $c['jenis'] === 'Lapor DPJP' ? ($c['dibaca_dpjp'] ? ' <span class="badge badge-green">Dibaca DPJP</span>' : ' <span class="badge badge-amber">Belum dibaca DPJP</span>') : '' ?></div>
        <?php if ($c['tekanan_darah'] || $c['nadi']): ?><div class="text-xs text-slate-600 mt-0.5">TD <?= aman($c['tekanan_darah'] ?: '-') ?> · N <?= aman($c['nadi'] ?: '-') ?> · RR <?= aman($c['respirasi'] ?: '-') ?> · S <?= aman($c['suhu'] ?: '-') ?> · SpO₂ <?= aman($c['spo2'] ?: '-') ?>%</div><?php endif; ?>
        <?php if ($c['nama_obat']): ?><div class="text-xs text-purple-800 mt-0.5">Obat: <?= aman($c['nama_obat'] . ' ' . $c['dosis'] . ' ' . $c['frekuensi']) ?></div><?php endif; ?>
        <div class="text-sm mt-1"><?= nl2br(aman($c['catatan'])) ?></div></div><?php endforeach; ?>
    </div></div></div>
    <?php tampil_footer();
    exit;
}

// ---------- DAFTAR PASIEN DIPANTAU ----------
$CK = "c.id_kunjungan = ku.id AND c.jenis_catatan IN ('Catatan Keperawatan','Pemberian Obat','Lapor DPJP')";
$rows = $pdo->query("SELECT ku.id, ku.id_rs, p.nomor_rm, p.nama_lengkap, p.jenis_kelamin, p.tanggal_lahir, p.alergi, ra.tanggal_masuk, b.kode_bed, km.nama AS nomor, u.nama_lengkap AS dpjp,
        (SELECT CONCAT(COALESCE(c.tekanan_darah,'-'), ' · N ', COALESCE(c.nadi,'-'), ' · S ', COALESCE(c.suhu,'-'), ' · SpO2 ', COALESCE(c.spo2,'-')) FROM rekam_medis c WHERE c.id_kunjungan = ku.id AND c.jenis_catatan = 'Catatan Keperawatan' ORDER BY c.waktu_catat DESC, c.id DESC LIMIT 1) AS ttv,
        (SELECT c.waktu_catat FROM rekam_medis c WHERE $CK ORDER BY c.waktu_catat DESC, c.id DESC LIMIT 1) AS terakhir,
        (SELECT c.catatan FROM rekam_medis c WHERE $CK ORDER BY c.waktu_catat DESC, c.id DESC LIMIT 1) AS note,
        (SELECT COUNT(*) FROM rekam_medis c WHERE c.id_kunjungan = ku.id AND c.jenis_catatan = 'Lapor DPJP' AND c.dibaca_dpjp = 0) AS lapor
    FROM rawat_inap ra JOIN kunjungan ku ON ku.id = ra.id_kunjungan JOIN pasien p ON p.id = ku.id_pasien JOIN bed b ON b.id = ra.id_bed JOIN unit_layanan km ON km.id = b.id_unit
    JOIN pegawai u ON u.id = ra.id_dokter WHERE ra.status = 'Aktif'" . (role_is('dokter') ? ' AND ra.id_dokter = ' . (int) uid() : sql_rs('ku.id_rs')) . " ORDER BY km.nama, b.kode_bed")->fetchAll();
$batas = date('Y-m-d H:i:s', time() - 8 * 3600);
tampil_header('Asuhan Keperawatan', role_is('dokter') ? 'Pasien rawat inap Anda dan laporan perawat.' : 'Pasien rawat inap yang dipantau. Monitoring minimal satu kali per sif (8 jam).');
echo kartu([['Pasien Dipantau', count($rows), 'teal'], ['Belum Dimonitor 8 Jam', count(array_filter($rows, fn($r) => !$r['terakhir'] || $r['terakhir'] < $batas)), 'amber'],
            ['Laporan ke DPJP Belum Dibaca', array_sum(array_column($rows, 'lapor')), 'red'], ['Punya Alergi', count(array_filter($rows, fn($r) => $r['alergi'])), 'purple']]);
?>
<div class="card"><div class="card-head"><h3>Pasien Rawat Inap</h3></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pasien</th><th>Kamar</th><th>TTV Terakhir</th><th>Nursing Note Terakhir</th><th class="num"></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="5" class="empty">Tidak ada pasien rawat inap.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr><td><b class="pname"><?= aman($r['nama_lengkap']) ?></b><div class="muted"><?= aman($r['nomor_rm']) ?> · <?= jk($r['jenis_kelamin']) ?> · <?= umur($r['tanggal_lahir']) ?> · DPJP <?= aman($r['dpjp']) ?></div>
    <?= $r['alergi'] ? '<span class="badge badge-red">Alergi ' . aman($r['alergi']) . '</span> ' : '' ?><?= $r['lapor'] ? '<span class="badge badge-red">Lapor DPJP belum dibaca</span>' : '' ?></td>
    <td><?= aman($r['nomor']) ?> · <?= aman($r['kode_bed']) ?></td>
    <td class="text-xs"><?= aman($r['ttv'] ?: 'Belum ada') ?><div class="muted"><?= tgl_jam($r['terakhir']) ?></div><?= (!$r['terakhir'] || $r['terakhir'] < $batas) ? '<span class="badge badge-amber">Perlu monitoring</span>' : '' ?></td>
    <td class="text-xs"><?= aman(mb_substr((string) $r['note'], 0, 110)) ?></td>
    <td class="num"><a href="?id=<?= $r['id'] ?>" class="btn btn-primary btn-sm"><?= $perawat ? 'Pengkajian / Monitoring' : 'Lihat' ?></a></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php tampil_footer();
