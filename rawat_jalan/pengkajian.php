<?php
/* MODUL 5: PENGKAJIAN AWAL PERAWAT (TTV, antropometri, keluhan, alergi) -> kirim ke dokter */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('rawat_jalan', true);
if (!role_is('perawat')) { flash('error', 'Pengkajian awal hanya diisi oleh Perawat.'); redirect('rawat_jalan/antrian.php'); }
$pdo = getPDO();
$k = ambil_kunjungan((int) get('id'));
if (!$k || !in_array($k['status'], ['Menunggu', 'Dipanggil'], true) || $k['jenis'] !== 'rawat_jalan' || (int) $k['id_rs'] !== rs_user()) { flash('error', 'Kunjungan tidak ditemukan, sudah selesai, atau bukan di cabang Anda.'); redirect('rawat_jalan/antrian.php'); }
$s = $pdo->prepare("SELECT * FROM rekam_medis WHERE id_kunjungan = ? AND jenis_catatan = 'Pengkajian Awal'");
$s->execute([$k['id']]);
$lama = $s->fetch() ?: [];
$errors = [];

if (is_post()) {
    $d = ['tekanan_darah' => post('tekanan_darah'), 'nadi' => post('nadi'), 'respirasi' => post('respirasi'), 'suhu' => str_replace(',', '.', post('suhu')),
          'spo2' => post('spo2'), 'berat_badan' => str_replace(',', '.', post('berat_badan')), 'tinggi_badan' => str_replace(',', '.', post('tinggi_badan')),
          'skala_nyeri' => post('skala_nyeri', '0'), 'keluhan_utama' => post('keluhan_utama'), 'riwayat' => post('riwayat')];
    if ($e = v_td($d['tekanan_darah'])) $errors['tekanan_darah'] = $e;
    if ($e = v_angka($d['nadi'], 'Nadi', 20, 250)) $errors['nadi'] = $e;
    if ($e = v_angka($d['respirasi'], 'Respirasi', 5, 80)) $errors['respirasi'] = $e;
    if ($e = v_angka($d['suhu'], 'Suhu', 30, 45)) $errors['suhu'] = $e;
    if ($e = v_angka($d['spo2'], 'SpO2', 50, 100)) $errors['spo2'] = $e;
    if ($e = v_angka($d['berat_badan'], 'Berat badan', 0.5, 400)) $errors['berat_badan'] = $e;
    if ($e = v_angka($d['tinggi_badan'], 'Tinggi badan', 30, 250)) $errors['tinggi_badan'] = $e;
    if ($e = v_angka($d['skala_nyeri'], 'Skala nyeri', 0, 10)) $errors['skala_nyeri'] = $e;
    if (mb_strlen($d['keluhan_utama']) < 5) $errors['keluhan_utama'] = 'Keluhan utama wajib diisi (minimal 5 karakter).';
    $alergi = post('alergi');
    if (!$errors) {
        $pdo->beginTransaction();
        $d['riwayat'] = $d['riwayat'] ?: null;
        // disimpan ke tabel rekam_medis (jenis_catatan = Pengkajian Awal); sumber cabang, poli, dokter, jam masuk diisi otomatis
        if ($lama) {
            $set = implode(', ', array_map(fn($x) => "$x = ?", array_keys($d)));
            $pdo->prepare("UPDATE rekam_medis SET $set, ditulis_oleh = ?, waktu_catat = NOW() WHERE id = ?")->execute([...array_values($d), uid(), $lama['id']]);
        } else {
            tulis_rm((int) $k['id'], 'Pengkajian Awal', $d + ['kunci_unik' => 'PENGKAJIAN-' . $k['id']]);
        }
        if ($alergi !== (string) $k['alergi']) $pdo->prepare('UPDATE pasien SET alergi = ? WHERE id = ?')->execute([$alergi ?: null, $k['id_pasien']]);
        $pdo->prepare("UPDATE kunjungan SET status = 'Dipanggil' WHERE id = ? AND status = 'Menunggu'")->execute([$k['id']]);
        $pdo->commit();
        audit('Outpatient', 'Pengkajian Awal', "{$k['nomor_rm']} TD {$d['tekanan_darah']} S {$d['suhu']}");
        flash('sukses', "Pengkajian {$k['nama_lengkap']} tersimpan dan dikirim ke antrean {$k['dokter']}.");
        redirect('rawat_jalan/antrian.php');
    }
}
$v = fn($x, $def = '') => aman($_POST[$x] ?? ($lama[$x] ?? $def));
tampil_header('Pengkajian Awal Perawat', 'Antrean ' . $k['no_antrian'] . ' · ' . $k['poli'] . ' · DPJP ' . $k['dokter'], '<a href="antrian.php" class="btn btn-secondary">&larr; Antrean</a>');
echo kartu_pasien($k, aman($k['penjamin']) . ' · Keluhan awal: ' . aman($k['keluhan_awal'] ?: '-'));
?>
<form method="post" class="card"><?= csrf_field() ?>
  <div class="card-body">
    <?php if ($errors): ?><div class="alert alert-error">Periksa kembali <?= count($errors) ?> isian yang ditandai merah.</div><?php endif; ?>
    <div class="section-label" style="margin-top:0">Tanda vital (TTV)</div>
    <div class="form-grid">
      <div class="field"><label>Tekanan darah (mmHg) *</label><input name="tekanan_darah" value="<?= $v('tekanan_darah') ?>" placeholder="120/80" maxlength="7" class="<?= err_class($errors, 'tekanan_darah') ?>"><?= err($errors, 'tekanan_darah') ?></div>
      <div class="field"><label>Nadi (x/menit) *</label><input type="number" name="nadi" min="20" max="250" value="<?= $v('nadi') ?>" class="<?= err_class($errors, 'nadi') ?>"><?= err($errors, 'nadi') ?></div>
      <div class="field"><label>Respirasi (x/menit) *</label><input type="number" name="respirasi" min="5" max="80" value="<?= $v('respirasi') ?>" class="<?= err_class($errors, 'respirasi') ?>"><?= err($errors, 'respirasi') ?></div>
      <div class="field"><label>Suhu (°C) *</label><input type="number" step="0.1" name="suhu" min="30" max="45" value="<?= $v('suhu') ?>" class="<?= err_class($errors, 'suhu') ?>"><?= err($errors, 'suhu') ?></div>
      <div class="field"><label>SpO₂ (%) *</label><input type="number" name="spo2" min="50" max="100" value="<?= $v('spo2') ?>" class="<?= err_class($errors, 'spo2') ?>"><?= err($errors, 'spo2') ?></div>
      <div class="field"><label>Berat badan (kg) *</label><input type="number" step="0.1" name="berat_badan" min="0.5" max="400" value="<?= $v('berat_badan') ?>" class="<?= err_class($errors, 'berat_badan') ?>"><?= err($errors, 'berat_badan') ?></div>
      <div class="field"><label>Tinggi badan (cm) *</label><input type="number" step="0.1" name="tinggi_badan" min="30" max="250" value="<?= $v('tinggi_badan') ?>" class="<?= err_class($errors, 'tinggi_badan') ?>"><?= err($errors, 'tinggi_badan') ?></div>
      <div class="field"><label>Skala nyeri (0-10) *</label><input type="number" name="skala_nyeri" min="0" max="10" value="<?= $v('skala_nyeri', '0') ?>" class="<?= err_class($errors, 'skala_nyeri') ?>"><?= err($errors, 'skala_nyeri') ?></div>
    </div>
    <div class="section-label">Anamnesis keperawatan</div>
    <div class="field"><label>Keluhan utama *</label><textarea name="keluhan_utama" maxlength="1000" class="<?= err_class($errors, 'keluhan_utama') ?>"><?= $v('keluhan_utama', $k['keluhan_awal']) ?></textarea><?= err($errors, 'keluhan_utama') ?></div>
    <div class="form-grid">
      <div class="field"><label>Riwayat penyakit &amp; obat rutin</label><textarea name="riwayat" maxlength="1000"><?= $v('riwayat') ?></textarea></div>
      <div class="field"><label>Riwayat alergi (diperbarui ke data pasien)</label><input name="alergi" maxlength="255" value="<?= aman($_POST['alergi'] ?? $k['alergi']) ?>" placeholder="Kosongkan bila tidak ada"></div>
    </div>
    <div class="notice text-xs">Perawat tidak mengisi diagnosis, resep, atau order. Setelah disimpan, pasien masuk antrean dokter dengan status "Siap Diperiksa".</div>
  </div>
  <div class="modal-foot"><a href="antrian.php" class="btn btn-secondary">Batal</a><button class="btn btn-primary">Simpan &amp; Kirim ke Dokter</button></div>
</form>
<?php tampil_footer();
