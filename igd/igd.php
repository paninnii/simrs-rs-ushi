<?php
/*
 * MODUL 7: EMERGENCY DEPARTMENT (IGD)
 *   A. Penerimaan pasien (Petugas Registrasi: identitas, penjamin, administrasi -- TANPA data klinis)
 *      Triase (HANYA Perawat IGD): kategori Merah/Kuning/Hijau/Hitam + TTV + GCS
 *   B. Pemeriksaan Awal, C. Tindakan, D. Observasi (Dokter, dicatat per tahap)
 *   E. Disposisi (HANYA Dokter): Pulang / Rujuk -> tagihan, Rawat Inap -> modul Inpatient
 * Semua catatan klinis IGD disimpan di tabel rekam_medis (jenis_catatan Triase IGD / Penanganan IGD / SOAP Dokter / Surat Rujukan).
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('igd');
$pdo = getPDO();
$TAHAP = ['Triase', 'Pemeriksaan Awal', 'Tindakan', 'Observasi', 'Disposisi'];
$errors = [];
$icd = $pdo->query('SELECT kode, nama FROM icd10 WHERE aktif = 1 ORDER BY kode')->fetchAll(PDO::FETCH_KEY_PAIR);

if (is_post()) {
    $aksi = post('aksi');
    $k = ambil_kunjungan((int) post('id'));
    if (!$k || $k['jenis'] !== 'igd' || $k['status'] === 'Selesai' || (int) $k['id_rs'] !== rs_user()) { flash('error', 'Pasien IGD tidak ditemukan, sudah didisposisi, atau bukan di cabang Anda.'); kembali(); }
    if (role_is('dokter') && (int) $k['id_dokter'] !== uid()) { flash('error', 'Pasien ini bukan pasien Anda.'); kembali(); }

    if ($aksi === 'triase') {
        if (!wajib_role_aksi(['perawat'], 'Triase & tanda vital IGD hanya oleh Perawat IGD. Petugas registrasi hanya melengkapi identitas & penjamin.', 'igd')) kembali();
        $d = ['kategori' => post('kategori'), 'keluhan' => post('keluhan'), 'tekanan_darah' => post('tekanan_darah'), 'nadi' => post('nadi'), 'respirasi' => post('respirasi'),
              'suhu' => str_replace(',', '.', post('suhu')), 'spo2' => post('spo2'), 'gcs' => post('gcs'), 'skala_nyeri' => post('skala_nyeri')];
        if (!in_array($d['kategori'], ['Merah', 'Kuning', 'Hijau', 'Hitam'], true)) $errors['kategori'] = 'Pilih kategori triase.';
        if (mb_strlen($d['keluhan']) < 5) $errors['keluhan'] = 'Keluhan utama wajib diisi.';
        if ($e = v_td($d['tekanan_darah'])) $errors['tekanan_darah'] = $e;
        if ($e = v_angka($d['nadi'], 'Nadi', 0, 250)) $errors['nadi'] = $e;
        if ($e = v_angka($d['respirasi'], 'Respirasi', 0, 80)) $errors['respirasi'] = $e;
        if ($e = v_angka($d['suhu'], 'Suhu', 25, 45)) $errors['suhu'] = $e;
        if ($e = v_angka($d['spo2'], 'SpO2', 0, 100)) $errors['spo2'] = $e;
        if ($e = v_angka($d['gcs'], 'GCS', 3, 15)) $errors['gcs'] = $e;
        if ($e = v_angka($d['skala_nyeri'], 'Skala nyeri', 0, 10)) $errors['skala_nyeri'] = $e;
        if (!$errors) {
            $pdo->beginTransaction();
            $rm = ['triase_kategori' => $d['kategori'], 'keluhan_utama' => $d['keluhan'], 'tekanan_darah' => $d['tekanan_darah'], 'nadi' => $d['nadi'], 'respirasi' => $d['respirasi'],
                   'suhu' => $d['suhu'], 'spo2' => $d['spo2'], 'gcs' => $d['gcs'], 'skala_nyeri' => $d['skala_nyeri']];
            $s = $pdo->prepare("SELECT id FROM rekam_medis WHERE id_kunjungan = ? AND jenis_catatan = 'Triase IGD'");
            $s->execute([$k['id']]);
            if ($id_rm = $s->fetchColumn()) {   // triase ulang: perbarui baris yang sama
                $pdo->prepare('UPDATE rekam_medis SET ' . implode(', ', array_map(fn($x) => "$x = ?", array_keys($rm))) . ', ditulis_oleh = ?, waktu_catat = NOW() WHERE id = ?')->execute([...array_values($rm), uid(), $id_rm]);
            } else {
                tulis_rm((int) $k['id'], 'Triase IGD', $rm + ['kunci_unik' => 'TRIASE-' . $k['id']]);
            }
            $pdo->prepare("UPDATE kunjungan SET status = 'Dipanggil' WHERE id = ?")->execute([$k['id']]);
            $pdo->commit();
            audit('Emergency Department', 'Triase', "{$k['nomor_rm']} kategori {$d['kategori']}");
            flash('sukses', "Triase {$k['nama_lengkap']} tersimpan (kategori {$d['kategori']}). Pasien masuk antrean dokter IGD.");
            redirect('igd/igd.php');
        }
        $_GET['triase'] = $k['id'];
    }

    if ($aksi === 'tahap') {
        if (!wajib_role_aksi(['dokter'], 'Pemeriksaan dan disposisi IGD hanya untuk dokter.', 'igd')) kembali();
        $tahap = post('tahap');
        $asesmen = post('asesmen');
        $tindakan = post('tindakan');
        if (!in_array($tahap, ['Pemeriksaan Awal', 'Tindakan', 'Observasi', 'Disposisi'], true)) $errors['tahap'] = 'Pilih tahap.';
        if (mb_strlen($asesmen) < 5) $errors['asesmen'] = 'Asesmen wajib diisi (min. 5 karakter).';
        $s = $pdo->prepare("SELECT COUNT(*) FROM rekam_medis WHERE id_kunjungan = ? AND jenis_catatan = 'Triase IGD'");
        $s->execute([$k['id']]);
        if (!$s->fetchColumn()) $errors['tahap'] = 'Pasien belum ditriase.';
        $disp = post('disposisi');
        $kode = strtoupper(trim(explode(' ', post('kode_icd10'))[0]));
        if ($tahap === 'Disposisi') {
            if (!in_array($disp, ['Pulang', 'Rawat Inap', 'Rujuk'], true)) $errors['disposisi'] = 'Pilih disposisi.';
            if (!isset($icd[$kode])) $errors['kode_icd10'] = 'Diagnosis ICD-10 wajib dipilih saat disposisi.';
            if (mb_strlen($tindakan) < 5) $errors['tindakan'] = 'Ringkasan tindakan / terapi wajib diisi.';
        }
        if (!$errors) {
            $pdo->beginTransaction();
            tulis_rm((int) $k['id'], 'Penanganan IGD', ['tahap_igd' => $tahap, 'catatan' => $asesmen, 'tindakan' => $tindakan ?: null, 'disposisi' => $tahap === 'Disposisi' ? $disp : null]);
            $pesan = "Tahap $tahap dicatat.";
            if ($tahap === 'Disposisi') {
                $t = ttv_kunjungan($k['id']);
                $s = $pdo->prepare("SELECT catatan FROM rekam_medis WHERE id_kunjungan = ? AND jenis_catatan = 'Penanganan IGD' AND tahap_igd <> 'Disposisi' ORDER BY id");
                $s->execute([$k['id']]);
                $riw = implode(' | ', $s->fetchAll(PDO::FETCH_COLUMN));
                tulis_rm((int) $k['id'], 'SOAP Dokter', ['subjektif' => $t['keluhan_utama'] ?? $k['keluhan_awal'], 'objektif' => teks_ttv($t) . ($riw ? '. ' . $riw : ''), 'assessment' => $asesmen,
                         'kode_icd10' => $kode, 'plan' => $tindakan, 'tindak_lanjut' => $disp, 'status' => 'Draft', 'kunci_unik' => 'SOAP-' . $k['id']], 'IGD');
                $jenis = $disp === 'Rawat Inap' ? 'rawat_inap' : 'igd';
                $pdo->prepare("UPDATE kunjungan SET status = 'Selesai', jenis = ? WHERE id = ?")->execute([$jenis, $k['id']]);
                if ($disp === 'Rujuk') tulis_rm((int) $k['id'], 'Surat Rujukan', ['tujuan' => post('rujuk_ke') ?: 'RS rujukan', 'catatan' => $asesmen . '. ' . $tindakan], 'IGD');
                if ($disp !== 'Rawat Inap') buat_tagihan($k['id']);
                $pesan = $disp === 'Rawat Inap' ? "Disposisi rawat inap: {$k['nama_lengkap']} diteruskan ke modul Inpatient (menunggu alokasi bed)." : "Disposisi $disp: rekam medis dibuat dan tagihan diteruskan ke Billing.";
            }
            $pdo->commit();
            audit('Emergency Department', $tahap, "{$k['nomor_rm']}: $asesmen" . ($tahap === 'Disposisi' ? " -> $disp" : ''));
            flash('sukses', $pesan);
            redirect($tahap === 'Disposisi' ? 'igd/igd.php' : 'igd/igd.php?periksa=' . $k['id']);
        }
        $_GET['periksa'] = $k['id'];
    }
}

// ---------- FORM TRIASE ----------
if (get('triase') !== '') {
    $k = ambil_kunjungan((int) get('triase'));
    if (!$k || $k['jenis'] !== 'igd' || (int) $k['id_rs'] !== rs_user()) redirect('igd/igd.php');
    $s = $pdo->prepare("SELECT *, triase_kategori AS kategori, keluhan_utama AS keluhan FROM rekam_medis WHERE id_kunjungan = ? AND jenis_catatan = 'Triase IGD'");
    $s->execute([$k['id']]);
    $t = $s->fetch() ?: [];
    $v = fn($x, $d = '') => aman($_POST[$x] ?? ($t[$x] ?? $d));
    tampil_header('Penerimaan & Triase IGD', 'Semua isian wajib agar triase tidak tercatat setengah-setengah.', '<a href="igd.php" class="btn btn-secondary">&larr; Daftar IGD</a>');
    echo kartu_pasien($k, 'Antrean ' . aman($k['no_antrian']) . ' · ' . aman($k['penjamin']));
    ?>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="aksi" value="triase"><input type="hidden" name="id" value="<?= $k['id'] ?>"><div class="card-body">
      <div class="field"><label>Kategori triase *</label><div class="flex flex-wrap gap-2">
        <?php foreach (['Merah' => 'Resusitasi / gawat darurat', 'Kuning' => 'Darurat tidak gawat', 'Hijau' => 'Tidak darurat', 'Hitam' => 'Meninggal / DOA'] as $kat => $ket): ?>
          <label class="badge badge-<?= ['Merah' => 'red', 'Kuning' => 'amber', 'Hijau' => 'green', 'Hitam' => 'gray'][$kat] ?>" style="padding:8px 12px;font-size:13px;cursor:pointer"><input type="radio" name="kategori" value="<?= $kat ?>" <?= ($_POST['kategori'] ?? ($t['kategori'] ?? '')) === $kat ? 'checked' : '' ?>> <?= $kat ?> · <?= $ket ?></label>
        <?php endforeach; ?></div><?= err($errors, 'kategori') ?></div>
      <div class="field"><label>Keluhan utama *</label><textarea name="keluhan" class="<?= err_class($errors, 'keluhan') ?>"><?= $v('keluhan', $k['keluhan_awal']) ?></textarea><?= err($errors, 'keluhan') ?></div>
      <div class="form-grid">
        <div class="field"><label>TD (mmHg) *</label><input name="tekanan_darah" value="<?= $v('tekanan_darah') ?>" placeholder="120/80" class="<?= err_class($errors, 'tekanan_darah') ?>"><?= err($errors, 'tekanan_darah') ?></div>
        <div class="field"><label>Nadi *</label><input type="number" name="nadi" value="<?= $v('nadi') ?>" class="<?= err_class($errors, 'nadi') ?>"><?= err($errors, 'nadi') ?></div>
        <div class="field"><label>RR *</label><input type="number" name="respirasi" value="<?= $v('respirasi') ?>" class="<?= err_class($errors, 'respirasi') ?>"><?= err($errors, 'respirasi') ?></div>
        <div class="field"><label>Suhu °C *</label><input type="number" step="0.1" name="suhu" value="<?= $v('suhu') ?>" class="<?= err_class($errors, 'suhu') ?>"><?= err($errors, 'suhu') ?></div>
        <div class="field"><label>SpO₂ % *</label><input type="number" name="spo2" value="<?= $v('spo2') ?>" class="<?= err_class($errors, 'spo2') ?>"><?= err($errors, 'spo2') ?></div>
        <div class="field"><label>GCS (3-15) *</label><input type="number" name="gcs" min="3" max="15" value="<?= $v('gcs', '15') ?>" class="<?= err_class($errors, 'gcs') ?>"><?= err($errors, 'gcs') ?></div>
        <div class="field"><label>Skala nyeri (0-10) *</label><input type="number" name="skala_nyeri" min="0" max="10" value="<?= $v('skala_nyeri') ?>" class="<?= err_class($errors, 'skala_nyeri') ?>"><?= err($errors, 'skala_nyeri') ?></div>
      </div></div>
      <div class="modal-foot"><a href="igd.php" class="btn btn-secondary">Batal</a><button class="btn btn-danger">Terima &amp; Simpan Triase</button></div></form>
    <?php tampil_footer();
    exit;
}

// ---------- PEMERIKSAAN DOKTER IGD ----------
if (get('periksa') !== '') {
    $k = ambil_kunjungan((int) get('periksa'));
    if (!$k || !in_array($k['jenis'], ['igd', 'rawat_inap'], true) || $k['jenis_unit'] !== 'IGD') redirect('igd/igd.php');
    wajib_pasien_sendiri((int) $k['id_pasien']);
    $t = ttv_kunjungan($k['id']);
    $s = $pdo->prepare("SELECT it.*, it.tahap_igd AS tahap, it.catatan AS asesmen, it.waktu_catat AS created_at, u.nama_lengkap FROM rekam_medis it JOIN pegawai u ON u.id = it.ditulis_oleh
                        WHERE it.id_kunjungan = ? AND it.jenis_catatan = 'Penanganan IGD' ORDER BY it.waktu_catat, it.id");
    $s->execute([$k['id']]);
    $log = $s->fetchAll();
    $sekarang = $log ? end($log)['tahap'] : ($t ? 'Triase' : '');
    $dokter = role_is('dokter') && boleh('igd', true) && $k['status'] !== 'Selesai' && (int) $k['id_dokter'] === uid();
    tampil_header('Pemeriksaan IGD', 'Antrean ' . $k['no_antrian'] . ' · ' . $k['dokter'], '<a href="igd.php" class="btn btn-secondary">&larr; Daftar IGD</a>' . (role_is('dokter') && $k['status'] !== 'Selesai' ? ' <a class="btn btn-secondary" href="' . url('cpoe/cpoe.php?kunjungan=' . $k['id']) . '">Order Lab / Radiologi / Resep</a>' : ''));
    echo kartu_pasien($k, aman($k['penjamin']));
    echo stepper($TAHAP, $sekarang);
    ?>
    <div class="grid lg:grid-cols-2 gap-4 mt-3">
      <div class="card"><div class="card-head"><h3>Triase</h3><?= isset($t['kategori']) ? badge($t['kategori']) : '' ?></div><div class="card-body"><div class="readonly-box"><?= aman(teks_ttv($t)) ?><br><b>Keluhan:</b> <?= aman($t['keluhan_utama'] ?? '-') ?></div>
        <div class="section-label">Catatan per tahap</div>
        <?php if (!$log): ?><p class="muted">Belum ada catatan dokter.</p><?php endif; ?>
        <?php foreach ($log as $l): ?><div class="border-l-4 border-[#0b7896] pl-3 mb-3 text-sm"><b><?= aman($l['tahap']) ?></b> <span class="muted">· <?= tgl_jam($l['created_at']) ?> · <?= aman($l['nama_lengkap']) ?></span><br><?= aman($l['asesmen']) ?><?= $l['tindakan'] ? '<br><span class="muted">Tindakan: ' . aman($l['tindakan']) . '</span>' : '' ?><?= $l['disposisi'] ? '<br>' . badge('Disposisi ' . $l['disposisi']) : '' ?></div><?php endforeach; ?>
      </div></div>
      <?php if ($dokter): ?>
      <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="aksi" value="tahap"><input type="hidden" name="id" value="<?= $k['id'] ?>">
        <div class="card-head"><h3>Catat Tahap Berikutnya</h3></div><div class="card-body">
        <?php if ($errors): ?><div class="alert alert-error">Periksa isian yang ditandai.</div><?php endif; ?>
        <div class="field"><label>Tahap *</label><select name="tahap" id="tahap" class="<?= err_class($errors, 'tahap') ?>"><?= opsi(array_combine(['Pemeriksaan Awal', 'Tindakan', 'Observasi', 'Disposisi'], ['Pemeriksaan Awal', 'Tindakan darurat', 'Observasi / evaluasi ulang', 'Disposisi (akhir pelayanan IGD)']), $_POST['tahap'] ?? (['Triase' => 'Pemeriksaan Awal', 'Pemeriksaan Awal' => 'Tindakan', 'Tindakan' => 'Observasi'][$sekarang] ?? 'Disposisi')) ?></select><?= err($errors, 'tahap') ?></div>
        <div class="field"><label>Asesmen / temuan *</label><textarea name="asesmen" class="<?= err_class($errors, 'asesmen') ?>"><?= old('asesmen') ?></textarea><?= err($errors, 'asesmen') ?></div>
        <div class="field"><label>Tindakan / terapi</label><textarea name="tindakan" class="<?= err_class($errors, 'tindakan') ?>"><?= old('tindakan') ?></textarea><?= err($errors, 'tindakan') ?></div>
        <div id="boxDisp" class="form-grid">
          <div class="field"><label>Disposisi *</label><select name="disposisi" class="<?= err_class($errors, 'disposisi') ?>"><?= opsi(['Pulang' => 'Pulang', 'Rawat Inap' => 'Admission rawat inap', 'Rujuk' => 'Rujuk ke RS lain'], $_POST['disposisi'] ?? '', '-- Pilih --') ?></select><?= err($errors, 'disposisi') ?></div>
          <div class="field"><label>Diagnosis ICD-10 *</label><input name="kode_icd10" list="dl_icd" value="<?= old('kode_icd10') ?>" class="<?= err_class($errors, 'kode_icd10') ?>"><datalist id="dl_icd"><?php foreach ($icd as $kd => $nm): ?><option value="<?= aman($kd) ?>"><?= aman($nm) ?></option><?php endforeach; ?></datalist><?= err($errors, 'kode_icd10') ?></div>
          <div class="field"><label>Rujuk ke (bila rujuk)</label><input name="rujuk_ke" maxlength="150" value="<?= old('rujuk_ke') ?>"></div>
        </div></div>
        <div class="modal-foot"><button class="btn btn-primary">Simpan Tahap</button></div></form>
      <script>(function(){var t=document.getElementById('tahap'),b=document.getElementById('boxDisp');function f(){b.style.display=t.value==='Disposisi'?'grid':'none'}t.addEventListener('change',f);f();})();</script>
      <?php elseif ($k['status'] === 'Selesai'): ?><div class="card card-body"><div class="notice">Pelayanan IGD sudah didisposisi. <a class="underline" href="<?= url('emr/emr.php?id=' . $k['id']) ?>">Lihat rekam medis</a>.</div></div>
      <?php else: ?><div class="card card-body"><div class="notice">Pemeriksaan, tindakan, dan disposisi IGD hanya dapat diisi oleh dokter. Petugas penerimaan hanya melakukan triase.</div></div><?php endif; ?>
    </div>
    <?php tampil_footer();
    exit;
}

// ---------- DAFTAR PASIEN IGD ----------
$s = $pdo->prepare("SELECT k.*, p.nomor_rm, p.nama_lengkap, p.tanggal_lahir, p.jenis_kelamin, p.alergi, d.nama_lengkap AS dokter, pj.nama AS penjamin,
        t.triase_kategori AS kategori,
        (SELECT tahap_igd FROM rekam_medis it WHERE it.id_kunjungan = k.id AND it.jenis_catatan = 'Penanganan IGD' ORDER BY it.waktu_catat DESC, it.id DESC LIMIT 1) AS tahap,
        (SELECT disposisi FROM rekam_medis it WHERE it.id_kunjungan = k.id AND it.jenis_catatan = 'Penanganan IGD' AND it.tahap_igd = 'Disposisi' LIMIT 1) AS disposisi
    FROM kunjungan k JOIN pasien p ON p.id = k.id_pasien JOIN unit_layanan pl ON pl.id = k.id_unit JOIN pegawai d ON d.id = k.id_dokter JOIN penjamin pj ON pj.id = k.id_penjamin
    LEFT JOIN rekam_medis t ON t.id_kunjungan = k.id AND t.jenis_catatan = 'Triase IGD'
    WHERE pl.jenis = 'IGD' AND k.status <> 'Batal' AND (k.status IN ('Menunggu','Dipanggil') OR k.tanggal >= ?)" . sql_rs('k.id_rs') . (role_is('dokter') ? ' AND k.id_dokter = ' . (int) uid() : '') . "
    ORDER BY CASE WHEN k.status = 'Selesai' THEN 2 ELSE 1 END, CASE t.triase_kategori WHEN 'Merah' THEN 1 WHEN 'Kuning' THEN 2 WHEN 'Hijau' THEN 3 ELSE 4 END, k.id");
$s->execute([date('Y-m-d', strtotime('-1 day'))]);
$rows = $s->fetchAll();
$aktif = array_filter($rows, fn($r) => $r['status'] !== 'Selesai');
tampil_header('Gawat Darurat (IGD)', 'Alur: Triase → Pemeriksaan Awal → Tindakan → Observasi → Disposisi. Kategori Merah diprioritaskan di atas · ' . nama_cabang(rs_aktif() ?: null) . '.',
    boleh('registrasi', true) ? '<a href="' . url('registrasi/pendaftaran.php') . '" class="btn btn-danger">+ Penerimaan Pasien IGD</a>' : '');
echo kartu([['Pasien IGD Aktif', count($aktif), 'teal'], ['Belum Triase', count(array_filter($aktif, fn($r) => !$r['kategori'])), 'amber'],
            ['Triase Merah', count(array_filter($aktif, fn($r) => $r['kategori'] === 'Merah')), 'red'], ['Disposisi (24 jam)', count($rows) - count($aktif), 'green']]);
?>
<div class="card"><div class="card-head"><h3>Daftar Pasien IGD</h3></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>No.</th><th>Pasien</th><th>Triase</th><th>Tahap</th><th>Dokter Jaga</th><th class="num">Tindakan</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="empty">Tidak ada pasien IGD.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <tr><td><span class="mono font-bold text-red-700"><?= aman($r['no_antrian']) ?></span><?= $r['tanggal'] !== date('Y-m-d') ? '<div class="muted text-xs">' . tgl($r['tanggal']) . '</div>' : '' ?></td>
    <td><b class="pname"><?= aman($r['nama_lengkap']) ?></b><div class="muted"><?= aman($r['nomor_rm']) ?> · <?= jk($r['jenis_kelamin']) ?> · <?= umur($r['tanggal_lahir']) ?></div><div class="muted"><?= aman($r['keluhan_awal']) ?></div></td>
    <td><?= $r['kategori'] ? badge($r['kategori']) : '<span class="badge badge-amber">Belum triase</span>' ?></td>
    <td><?= $r['status'] === 'Selesai' ? badge('Disposisi ' . ($r['disposisi'] ?: '')) : aman($r['tahap'] ?: ($r['kategori'] ? 'Menunggu dokter' : 'Menunggu triase')) ?></td>
    <td><?= aman($r['dokter']) ?><div class="muted"><?= aman($r['penjamin']) ?></div></td>
    <td class="num">
      <?php if ($r['status'] !== 'Selesai' && role_is('perawat') && boleh('igd', true)): ?><a href="?triase=<?= $r['id'] ?>" class="btn <?= $r['kategori'] ? 'btn-secondary' : 'btn-danger' ?> btn-sm"><?= $r['kategori'] ? 'Ubah Triase' : 'Penerimaan & Triase' ?></a><?php endif; ?>
      <?php if ($r['status'] !== 'Selesai' && role_is('pendaftaran', 'supervisorreg') && boleh('registrasi', true)): ?><a href="<?= url('registrasi/pasien.php?id=' . $r['id_pasien'] . '&ubah=1') ?>" class="btn btn-secondary btn-sm" title="Identitas, kontak, penjamin (tanpa data klinis)">Lengkapi Identitas</a><?php endif; ?>
      <?php if ($r['kategori'] || $r['status'] === 'Selesai'): ?><a href="?periksa=<?= $r['id'] ?>" class="btn <?= role_is('dokter') && $r['status'] !== 'Selesai' ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= role_is('dokter') && $r['status'] !== 'Selesai' ? 'Pemeriksaan / Disposisi' : 'Lihat' ?></a><?php endif; ?>
    </td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php tampil_footer();
