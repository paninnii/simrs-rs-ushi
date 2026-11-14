<?php
/*
 * MODUL 5 + 3 + 4: PEMERIKSAAN DOKTER RAWAT JALAN (PPT Bab 5 / P13)
 *   - Hasil pengkajian perawat tampil read-only
 *   - SOAP + ICD-10 -> rekam_medis (jenis_catatan SOAP Dokter, status Draft; finalisasi di modul EMR)
 *   - Surat kontrol / rujukan -> rekam_medis (jenis_catatan Surat Kontrol / Surat Rujukan)
 *   - Tindakan, e-resep (cek alergi), order lab & radiologi (CPOE)
 *   - Tindak lanjut: Pulang / Kontrol (surat kontrol) / Rujuk (surat rujukan) / Rawat Inap
 *   Semua disimpan dalam SATU transaksi database.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
require_once __DIR__ . '/../includes/order.php';
wajib_akses('rawat_jalan', true);
if (!role_is('dokter')) { flash('error', 'Pemeriksaan SOAP hanya oleh Dokter.'); redirect('rawat_jalan/antrian.php'); }
$pdo = getPDO();
$k = ambil_kunjungan((int) get('id'));
if (!$k || (int) $k['id_dokter'] !== id_pegawai()) { flash('error', 'Pasien ini bukan pasien DPJP Anda.'); redirect('rawat_jalan/antrian.php'); }
if ($k['status'] === 'Selesai' || $k['status'] === 'Batal') redirect('emr/emr.php?id=' . $k['id']);
$ttv = ttv_kunjungan($k['id']);
$icd = $pdo->query('SELECT kode, nama FROM icd10 WHERE aktif = 1 ORDER BY kode')->fetchAll(PDO::FETCH_KEY_PAIR);
$errors = [];

if (is_post()) {
    $rm = ['subjektif' => post('subjektif'), 'objektif' => post('objektif'), 'assessment' => post('assessment'), 'kode_icd10' => strtoupper(trim(explode(' ', post('kode_icd10'))[0])),
           'plan' => post('plan'), 'tindak_lanjut' => post('tindak_lanjut')];
    foreach (['subjektif' => 'Subjective (anamnesis)', 'objektif' => 'Objective (pemeriksaan fisik)', 'assessment' => 'Assessment (diagnosis kerja)', 'plan' => 'Plan (rencana)'] as $f => $l) {
        if (mb_strlen($rm[$f]) < 5) $errors[$f] = "$l wajib diisi (minimal 5 karakter).";
    }
    if (!isset($icd[$rm['kode_icd10']])) $errors['kode_icd10'] = 'Pilih kode ICD-10 dari daftar referensi.';
    if (!in_array($rm['tindak_lanjut'], ['Pulang', 'Kontrol', 'Rawat Inap', 'Rujuk'], true)) $errors['tindak_lanjut'] = 'Pilih tindak lanjut.';
    $tgl_kontrol = post('tanggal_kontrol');
    $rujuk_ke = post('rujuk_ke');
    if ($rm['tindak_lanjut'] === 'Kontrol' && ($tgl_kontrol <= date('Y-m-d') || v_tanggal($tgl_kontrol))) $errors['tanggal_kontrol'] = 'Tanggal kontrol harus setelah hari ini.';
    if ($rm['tindak_lanjut'] === 'Rujuk' && mb_strlen($rujuk_ke) < 3) $errors['rujuk_ke'] = 'Tujuan rujukan wajib diisi.';
    [$e_order, $order] = validasi_order($k);
    $errors += $e_order;

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            tulis_rm((int) $k['id'], 'SOAP Dokter', $rm + ['status' => 'Draft', 'kunci_unik' => 'SOAP-' . $k['id']]);
            $ringkas = simpan_order($k, $order);
            if ($rm['tindak_lanjut'] === 'Kontrol') {
                tulis_rm((int) $k['id'], 'Surat Kontrol', ['tanggal_kontrol' => $tgl_kontrol, 'tujuan' => $k['poli'], 'catatan' => post('catatan_kontrol') ?: 'Kontrol ulang sesuai rencana terapi.']);
            }
            if ($rm['tindak_lanjut'] === 'Rujuk') {
                tulis_rm((int) $k['id'], 'Surat Rujukan', ['tujuan' => $rujuk_ke, 'catatan' => "Diagnosis: {$rm['assessment']} ({$rm['kode_icd10']}). " . post('alasan_rujuk')]);
            }
            $jenis = $rm['tindak_lanjut'] === 'Rawat Inap' ? 'rawat_inap' : 'rawat_jalan';
            $pdo->prepare("UPDATE kunjungan SET status = 'Selesai', jenis = ? WHERE id = ?")->execute([$jenis, $k['id']]);
            if ($jenis === 'rawat_jalan') buat_tagihan($k['id']);
            $pdo->commit();
            audit('Outpatient', 'Pemeriksaan Dokter', "{$k['nomor_rm']} {$rm['kode_icd10']} -> {$rm['tindak_lanjut']}");
            flash('sukses', "Pemeriksaan {$k['nama_lengkap']} tersimpan (EMR status Draft)." . ($ringkas ? ' Order terkirim: ' . implode(', ', $ringkas) . '.' : '')
                . ($jenis === 'rawat_inap' ? ' Pasien diteruskan ke Rawat Inap (menunggu alokasi bed).' : ' Tagihan otomatis dibuat di Billing.'));
            redirect('emr/emr.php?id=' . $k['id']);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors['_db'] = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

$s = $pdo->prepare("SELECT k.tanggal, k.id_rs, pl.nama AS poli, rm.assessment, rm.kode_icd10, rm.plan, d.nama_lengkap AS dokter FROM kunjungan k JOIN rekam_medis rm ON rm.id_kunjungan = k.id AND rm.jenis_catatan = 'SOAP Dokter'
                    JOIN unit_layanan pl ON pl.id = k.id_unit JOIN pegawai d ON d.id = k.id_dokter WHERE k.id_pasien = ? AND k.id <> ? ORDER BY k.tanggal DESC LIMIT 5");
$s->execute([$k['id_pasien'], $k['id']]);
$riwayat = $s->fetchAll();

tampil_header('Pemeriksaan Dokter', 'Antrean ' . $k['no_antrian'] . ' · ' . $k['poli'], '<a href="antrian.php" class="btn btn-secondary">&larr; Pasien saya</a>');
echo kartu_pasien($k, aman($k['penjamin']) . ($k['no_kartu'] ? ' · ' . aman($k['no_kartu']) : '') . ' · Gol. darah ' . aman($k['golongan_darah']));
?>
<?php if ($k['status'] === 'Menunggu'): ?><div class="alert alert-warning">Pasien belum dikaji perawat (TTV belum ada). Sebaiknya tunggu pengkajian awal, atau isi pemeriksaan fisik lengkap di bagian Objective.</div><?php endif; ?>
<div class="grid lg:grid-cols-3 gap-4 mb-4">
  <div class="card lg:col-span-2"><div class="card-head"><h3>Hasil Pengkajian Perawat</h3><span class="muted">read-only</span></div>
    <div class="card-body"><div class="readonly-box"><b><?= aman($ttv['sumber'] ?? 'Belum dikaji') ?>:</b> <?= aman(teks_ttv($ttv)) ?><br><b>Keluhan utama:</b> <?= aman($ttv['keluhan_utama'] ?? $k['keluhan_awal']) ?>
      <?php if (!empty($ttv['riwayat'])): ?><br><b>Riwayat:</b> <?= aman($ttv['riwayat']) ?><?php endif; ?></div></div></div>
  <div class="card"><div class="card-head"><h3>Riwayat Kunjungan</h3></div><div class="card-body text-sm space-y-2">
    <?php if (!$riwayat): ?><p class="muted">Kunjungan pertama.</p><?php endif; ?>
    <?php foreach ($riwayat as $r): ?><div class="border-b border-slate-100 pb-2"><b><?= tgl($r['tanggal']) ?></b> · <?= aman($r['poli']) ?> <span class="col-cabang">· <?= aman(nama_cabang((int) $r['id_rs'])) ?></span><br><span class="mono text-[#004c6d]"><?= aman($r['kode_icd10']) ?></span> <?= aman($r['assessment']) ?><div class="muted"><?= aman($r['plan']) ?></div></div><?php endforeach; ?>
  </div></div>
</div>

<form method="post"><?= csrf_field() ?>
  <?php if (isset($errors['_db'])): ?><div class="alert alert-error"><?= aman($errors['_db']) ?></div><?php endif; ?>
  <?php if ($errors && !isset($errors['_db'])): ?><div class="alert alert-error">Ada <?= count($errors) ?> isian yang perlu diperbaiki. Data belum disimpan.</div><?php endif; ?>
  <div class="card mb-3"><div class="card-head"><h3>Catatan SOAP</h3><span class="muted">tersimpan ke Rekam Medis Elektronik</span></div><div class="card-body">
    <div class="form-grid">
      <div class="field"><label>S · Subjective (anamnesis) *</label><textarea name="subjektif" maxlength="2000" class="<?= err_class($errors, 'subjektif') ?>"><?= old('subjektif', $ttv['keluhan_utama'] ?? $k['keluhan_awal']) ?></textarea><?= err($errors, 'subjektif') ?></div>
      <div class="field"><label>O · Objective (pemeriksaan fisik) *</label><textarea name="objektif" maxlength="2000" class="<?= err_class($errors, 'objektif') ?>"><?= old('objektif', $ttv ? teks_ttv($ttv) . '. ' : '') ?></textarea><?= err($errors, 'objektif') ?></div>
      <div class="field"><label>A · Assessment (diagnosis kerja) *</label><textarea name="assessment" maxlength="1000" class="<?= err_class($errors, 'assessment') ?>"><?= old('assessment') ?></textarea><?= err($errors, 'assessment') ?></div>
      <div class="field"><label>P · Plan (terapi, edukasi, rencana) *</label><textarea name="plan" maxlength="2000" class="<?= err_class($errors, 'plan') ?>"><?= old('plan') ?></textarea><?= err($errors, 'plan') ?></div>
    </div>
    <div class="form-grid">
      <div class="field"><label>Kode ICD-10 *</label><input name="kode_icd10" list="dl_icd" value="<?= old('kode_icd10') ?>" class="<?= err_class($errors, 'kode_icd10') ?>" placeholder="Ketik kode atau nama penyakit">
        <datalist id="dl_icd"><?php foreach ($icd as $kd => $nm): ?><option value="<?= aman($kd) ?>"><?= aman($nm) ?></option><?php endforeach; ?></datalist><?= err($errors, 'kode_icd10') ?></div>
      <div class="field"><label>Tindak lanjut *</label><select name="tindak_lanjut" id="tl" class="<?= err_class($errors, 'tindak_lanjut') ?>"><?= opsi(['Pulang' => 'Pulang', 'Kontrol' => 'Kontrol ulang (surat kontrol)', 'Rujuk' => 'Rujuk ke faskes lain', 'Rawat Inap' => 'Rawat inap (admission)'], $_POST['tindak_lanjut'] ?? 'Pulang') ?></select></div>
      <div class="field tl tl-Kontrol"><label>Tanggal kontrol *</label><input type="date" name="tanggal_kontrol" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" value="<?= old('tanggal_kontrol') ?>" class="<?= err_class($errors, 'tanggal_kontrol') ?>"><?= err($errors, 'tanggal_kontrol') ?></div>
      <div class="field tl tl-Kontrol"><label>Catatan kontrol</label><input name="catatan_kontrol" maxlength="255" value="<?= old('catatan_kontrol') ?>" placeholder="Bawa hasil lab"></div>
      <div class="field tl tl-Rujuk"><label>Rujuk ke *</label><input name="rujuk_ke" maxlength="150" value="<?= old('rujuk_ke') ?>" class="<?= err_class($errors, 'rujuk_ke') ?>" placeholder="RSUP / Sp. Jantung"><?= err($errors, 'rujuk_ke') ?></div>
      <div class="field tl tl-Rujuk"><label>Alasan rujukan</label><input name="alasan_rujuk" maxlength="255" value="<?= old('alasan_rujuk') ?>"></div>
    </div>
  </div></div>
  <?php form_order($k, $errors); ?>
  <div class="card"><div class="modal-foot"><a href="antrian.php" class="btn btn-secondary">Batal</a><button class="btn btn-success" style="padding:10px 18px">Simpan Pemeriksaan &amp; Kirim Order</button></div></div>
</form>
<script>
(function () { var tl = document.getElementById('tl');
  function f() { document.querySelectorAll('.tl').forEach(function (e) { e.style.display = e.classList.contains('tl-' + tl.value) ? '' : 'none'; }); }
  tl.addEventListener('change', f); f(); })();
</script>
<?php tampil_footer();
