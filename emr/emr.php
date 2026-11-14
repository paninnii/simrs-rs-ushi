<?php
/*
 * MODUL 3: ELECTRONIC MEDICAL RECORD (EMR)
 *   Dokter        : edit SOAP selama Draft, Finalisasi, Addendum (setelah Final)
 *   Rekam Medis   : verifikasi kelengkapan, kirim ke SATUSEHAT (FHIR R4) -- tidak mengubah isi klinis
 *   Perawat       : melihat
 *   Manajemen     : hanya angka ringkasan (tanpa isi rekam medis)
 * SEMUA catatan klinis ada di SATU tabel rekam_medis. Setiap baris menyimpan sumbernya:
 *   id_rs (cabang) · jenis_layanan (Rawat Jalan/IGD/Rawat Inap) · id_unit (poli/IGD/ruang) · id_dokter (DPJP) ·
 *   waktu_masuk (jam pasien masuk) · jenis_catatan · ditulis_oleh · waktu_catat.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('emr');
$pdo = getPDO();
$errors = [];
$icd = $pdo->query('SELECT kode, nama FROM icd10 WHERE aktif = 1 ORDER BY kode')->fetchAll(PDO::FETCH_KEY_PAIR);

function ambil_rm(int $id_kunjungan): ?array
{
    $s = getPDO()->prepare("SELECT rm.*, rm.waktu_catat AS created_at, i.nama AS nama_icd, u.nama_lengkap AS dokter_rm, v.nama_lengkap AS verifikator FROM rekam_medis rm JOIN icd10 i ON i.kode = rm.kode_icd10
                            JOIN pegawai u ON u.id = rm.ditulis_oleh LEFT JOIN pegawai v ON v.id = rm.diverifikasi_oleh WHERE rm.id_kunjungan = ? AND rm.jenis_catatan = 'SOAP Dokter'");
    $s->execute([$id_kunjungan]);
    return $s->fetch() ?: null;
}

if (is_post()) {
    $aksi = post('aksi');
    $rm = ambil_rm((int) post('id'));
    if (!$rm) { flash('error', 'Rekam medis tidak ditemukan.'); kembali(); }
    $pemilik = role_is('dokter') && (int) $rm['ditulis_oleh'] === uid();

    if ($aksi === 'ubah' || $aksi === 'finalisasi') {
        if (!$pemilik || !boleh('emr', true)) { flash('error', 'Hanya dokter penulis yang dapat mengubah / memfinalisasi EMR ini.'); kembali(); }
        if ($rm['status'] === 'Final') { flash('error', 'Dokumen FINAL tidak bisa diubah. Gunakan Addendum.'); kembali(); }
        $d = ['subjektif' => post('subjektif'), 'objektif' => post('objektif'), 'assessment' => post('assessment'), 'kode_icd10' => strtoupper(trim(explode(' ', post('kode_icd10'))[0])), 'plan' => post('plan')];
        foreach (['subjektif', 'objektif', 'assessment', 'plan'] as $f) if (mb_strlen($d[$f]) < 5) $errors[$f] = 'Wajib diisi (minimal 5 karakter).';
        if (!isset($icd[$d['kode_icd10']])) $errors['kode_icd10'] = 'Kode ICD-10 tidak ada di referensi.';
        if (!$errors) {
            $pdo->prepare('UPDATE rekam_medis SET subjektif=?, objektif=?, assessment=?, kode_icd10=?, plan=? WHERE id=?')->execute([...array_values($d), $rm['id']]);
            if ($aksi === 'finalisasi') {
                $pdo->prepare("UPDATE rekam_medis SET status = 'Final', finalisasi_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $rm['id']]);
                audit('Electronic Medical Record', 'Finalisasi', "EMR kunjungan #{$rm['id_kunjungan']}");
                flash('sukses', 'EMR difinalisasi. Status FINAL: isi tidak bisa diubah lagi, perubahan berikutnya lewat addendum.');
            } else {
                audit('Electronic Medical Record', 'Ubah Draft', "EMR kunjungan #{$rm['id_kunjungan']}");
                flash('sukses', 'Draft EMR disimpan.');
            }
            kembali(['edit' => null]);
        }
        $_GET['edit'] = 1;
    }
    if ($aksi === 'addendum') {
        if (!$pemilik || $rm['status'] !== 'Final') { flash('error', 'Addendum hanya oleh dokter penulis pada dokumen FINAL.'); kembali(); }
        $isi = post('isi');
        if (mb_strlen($isi) < 5) { flash('error', 'Isi addendum minimal 5 karakter.'); kembali(); }
        tulis_rm((int) $rm['id_kunjungan'], 'Addendum', ['id_induk' => $rm['id'], 'catatan' => $isi], $rm['jenis_layanan'], (int) $rm['id_unit'], (int) $rm['id_dokter'], $rm['waktu_masuk']);
        audit('Electronic Medical Record', 'Addendum', "EMR kunjungan #{$rm['id_kunjungan']}");
        flash('sukses', 'Addendum disimpan tanpa mengubah dokumen asli.');
        kembali();
    }
    if ($aksi === 'verifikasi') {
        if (!wajib_role_aksi(['rekammedis'], 'Verifikasi kelengkapan hanya oleh Admin Rekam Medis.', 'emr')) kembali();
        if ($rm['status'] !== 'Final') { flash('error', 'Hanya EMR berstatus FINAL yang bisa diverifikasi.'); kembali(); }
        $hasil = post('hasil') === 'Lengkap' ? 'Lengkap' : 'Tidak Lengkap';
        $cat = post('catatan');
        if ($hasil === 'Tidak Lengkap' && mb_strlen($cat) < 5) { flash('error', 'Tulis catatan kekurangan untuk dokter.'); kembali(); }
        $pdo->prepare('UPDATE rekam_medis SET status_verifikasi = ?, catatan_verifikasi = ?, diverifikasi_oleh = ? WHERE id = ?')->execute([$hasil, $cat ?: null, uid(), $rm['id']]);
        audit('Electronic Medical Record', 'Verifikasi', "EMR kunjungan #{$rm['id_kunjungan']}: $hasil");
        flash('sukses', "EMR terverifikasi: $hasil.");
        kembali();
    }
    if ($aksi === 'satusehat') {
        if (!wajib_role_aksi(['rekammedis'], 'Kirim SATUSEHAT hanya untuk Admin Rekam Medis setelah verifikasi.', 'emr')) kembali();
        if ($rm['status_verifikasi'] !== 'Lengkap') { flash('error', 'EMR harus terverifikasi LENGKAP sebelum dikirim ke SATUSEHAT.'); kembali(); }
        $bundle = fhir_bundle((int) $rm['id_kunjungan']);
        $ref = 'SAT-' . date('Y') . '-' . str_pad((string) $rm['id_kunjungan'], 6, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE rekam_medis SET satusehat_ref = ? WHERE id = ?')->execute([$ref, $rm['id']]);
        audit('Electronic Medical Record', 'Kirim SATUSEHAT', "$ref (" . count($bundle['entry']) . ' resource FHIR)');
        flash('sukses', "Pengiriman SATUSEHAT berhasil (simulasi): $ref · " . count($bundle['entry']) . ' resource FHIR R4 (Patient, Encounter, Condition, Observation).');
        kembali();
    }
}

// ---------- DETAIL EMR ----------
if (get('id') !== '' && !role_is('manajemen')) {
    $k = ambil_kunjungan((int) get('id'));
    if (!$k) redirect('emr/emr.php');
    wajib_pasien_sendiri((int) $k['id_pasien']);   // dokter: hanya pasiennya sendiri (riwayat lengkap pasien itu boleh dilihat)
    if (rs_aktif() && lintas_cabang() && (int) $k['id_rs'] !== rs_aktif()) $_SESSION['rs_pilih'] = 0;   // buka dari tautan cabang lain
    $rm = ambil_rm($k['id']);
    $ttv = ttv_kunjungan($k['id']);
    $q = function (string $sql) use ($pdo, $k) { $s = $pdo->prepare($sql); $s->execute([$k['id']]); return $s->fetchAll(); };
    $lab = $q("SELECT o.*, j.nama, j.nilai_normal, v.nama_lengkap AS validator FROM order_pelayanan o JOIN layanan j ON j.id = o.id_layanan LEFT JOIN pegawai v ON v.id = o.id_validator WHERE o.id_kunjungan = ? AND o.jenis = 'Laboratorium'");
    $rad = $q("SELECT o.*, o.uraian AS nama, v.nama_lengkap AS radiolog FROM order_pelayanan o LEFT JOIN pegawai v ON v.id = o.id_validator WHERE o.id_kunjungan = ? AND o.jenis = 'Radiologi'");
    $resep = $q('SELECT r.status, o.nama_obat, ri.jumlah, o.satuan, ri.dosis, ri.frekuensi, ri.aturan_pakai, ri.alasan_alergi FROM resep r JOIN resep_item ri ON ri.id_resep = r.id JOIN obat o ON o.id = ri.id_obat WHERE r.id_kunjungan = ?');
    $tindakan = $q("SELECT o.uraian AS nama, o.jumlah, o.status, o.catatan, o.keterangan, o.pelaksana AS oleh, u.nama_lengkap AS pelaksana FROM order_pelayanan o LEFT JOIN pegawai u ON u.id = o.id_petugas WHERE o.id_kunjungan = ? AND o.jenis = 'Tindakan'");
    // seluruh catatan kunjungan ini dari satu tabel rekam_medis, lengkap dengan sumbernya
    $semua = $q("SELECT r.*, u.nama_lengkap AS penulis, u.jenis_tenaga, un.nama AS unit, d.nama_lengkap AS dpjp FROM rekam_medis r JOIN pegawai u ON u.id = r.ditulis_oleh
                 JOIN unit_layanan un ON un.id = r.id_unit JOIN pegawai d ON d.id = r.id_dokter WHERE r.id_kunjungan = ? ORDER BY r.waktu_catat, r.id");
    $kep = array_values(array_filter($semua, fn($c) => in_array($c['jenis_catatan'], ['Catatan Keperawatan', 'Pemberian Obat', 'Lapor DPJP'], true)));
    $dok = array_values(array_filter($semua, fn($c) => in_array($c['jenis_catatan'], ['Surat Kontrol', 'Surat Rujukan', 'Resume Pulang'], true)));
    $add = $rm ? array_values(array_filter($semua, fn($c) => $c['jenis_catatan'] === 'Addendum' && (int) $c['id_induk'] === (int) $rm['id'])) : [];
    $riwayat = (function () use ($pdo, $k) {
        $s = $pdo->prepare("SELECT k.id, k.tanggal, k.id_rs, k.jenis, u.nama AS unit, d.nama_lengkap AS dokter, r.kode_icd10, r.assessment FROM kunjungan k JOIN unit_layanan u ON u.id = k.id_unit
                            JOIN pegawai d ON d.id = k.id_dokter LEFT JOIN rekam_medis r ON r.id_kunjungan = k.id AND r.jenis_catatan = 'SOAP Dokter'
                            WHERE k.id_pasien = ? AND k.id <> ? AND k.no_antrian IS NOT NULL ORDER BY k.tanggal DESC LIMIT 10");
        $s->execute([$k['id_pasien'], $k['id']]);
        return $s->fetchAll();
    })();
    $pemilik = $rm && role_is('dokter') && (int) $rm['ditulis_oleh'] === uid();
    $edit = $pemilik && $rm['status'] === 'Draft' && (get('edit') === '1' || $errors);
    $aksi_head = '<button onclick="window.print()" class="btn btn-secondary">Cetak Ringkasan</button>';
    if ($rm && role_is('dokter', 'rekammedis')) $aksi_head .= ' <a href="fhir.php?id=' . $k['id'] . '" target="_blank" class="btn btn-secondary">Data SATUSEHAT</a>';
    if ($pemilik && $rm['status'] === 'Draft' && !$edit) $aksi_head .= ' <a href="?id=' . $k['id'] . '&edit=1" class="btn btn-primary">Isi / Finalisasi</a>';
    tampil_header('Rekam Medis Elektronik', tgl($k['tanggal']) . ' · ' . nama_cabang((int) $k['id_rs']) . ' · ' . $k['poli'] . ' · DPJP ' . $k['dokter'], $aksi_head);
    echo kartu_pasien($k, 'NIK ' . aman($k['nik']) . ' · ' . aman($k['penjamin']) . ' · Kunjungan ' . aman($k['no_antrian']));
    $sumber = '<div class="card mb-3"><div class="card-head"><h3>Sumber Rekam Medis</h3><span class="muted">tercatat di setiap baris tabel rekam_medis</span></div><div class="card-body">'
        . '<dl class="kv kv-4"><dt>Cabang</dt><dd>' . aman(nama_cabang((int) $k['id_rs'])) . '</dd><dt>Layanan</dt><dd>' . aman(['rawat_jalan' => 'Rawat Jalan', 'igd' => 'IGD', 'rawat_inap' => 'Rawat Inap'][$k['jenis']]) . '</dd>'
        . '<dt>Poli / Unit</dt><dd>' . aman($k['poli']) . '</dd><dt>Dokter (DPJP)</dt><dd>' . aman($k['dokter']) . '</dd>'
        . '<dt>Jam masuk</dt><dd>' . tgl_jam($k['waktu_masuk'] ?: $k['created_at']) . '</dd><dt>Daftar lewat</dt><dd>' . aman($k['sumber']) . ($k['kode_booking'] ? ' · ' . aman($k['kode_booking']) : '') . '</dd></dl></div></div>';
    echo $sumber;
    if (!$rm) { echo '<div class="notice">Belum ada catatan SOAP dokter untuk kunjungan ini.</div>'; }
    if (!$rm && !$semua) { tampil_footer(); exit; }
    if (!$rm) $rm = ['status' => 'Draft', 'status_verifikasi' => 'Belum', 'satusehat_ref' => null, 'id' => 0];
    ?>
    <?php if (!empty($rm['subjektif'])): ?><div class="flex flex-wrap gap-2 mb-3 no-print"><?= badge($rm['status'] === 'Final' ? 'FINAL' : 'DRAFT') ?> <?= badge('Verifikasi: ' . ($rm['status_verifikasi'] ?: 'Belum')) ?> <?= $rm['satusehat_ref'] ? '<span class="badge badge-green">SATUSEHAT ' . aman($rm['satusehat_ref']) . '</span>' : '<span class="badge badge-gray">Belum dikirim SATUSEHAT</span>' ?></div><?php endif; ?>
    <div class="grid lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 space-y-4">
      <?php if ($edit): ?>
      <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $k['id'] ?>"><div class="card-head"><h3>Ubah SOAP (Draft)</h3></div><div class="card-body">
        <?php foreach (['subjektif' => 'S · Subjective', 'objektif' => 'O · Objective', 'assessment' => 'A · Assessment', 'plan' => 'P · Plan'] as $f => $l): ?>
          <div class="field"><label><?= $l ?> *</label><textarea name="<?= $f ?>" class="<?= err_class($errors, $f) ?>"><?= aman($_POST[$f] ?? $rm[$f]) ?></textarea><?= err($errors, $f) ?></div><?php endforeach; ?>
        <div class="field"><label>ICD-10 *</label><input name="kode_icd10" list="dl_icd" value="<?= aman($_POST['kode_icd10'] ?? $rm['kode_icd10']) ?>" class="<?= err_class($errors, 'kode_icd10') ?>"><datalist id="dl_icd"><?php foreach ($icd as $kd => $nm): ?><option value="<?= aman($kd) ?>"><?= aman($nm) ?></option><?php endforeach; ?></datalist><?= err($errors, 'kode_icd10') ?></div>
      </div><div class="modal-foot"><a href="?id=<?= $k['id'] ?>" class="btn btn-secondary">Batal</a><button name="aksi" value="ubah" class="btn btn-secondary">Simpan Draft</button>
        <button name="aksi" value="finalisasi" class="btn btn-success" onclick="return confirm('Finalisasi EMR? Setelah FINAL isi tidak bisa diubah, hanya bisa addendum.')">Finalisasi EMR</button></div></form>
      <?php elseif (!empty($rm['subjektif'])): ?>
      <div class="card print-area"><div class="card-head"><h3>Catatan SOAP</h3><span class="muted"><?= aman($rm['dokter_rm']) ?> · <?= tgl_jam($rm['created_at']) ?><?= $rm['finalisasi_at'] ? ' · final ' . tgl_jam($rm['finalisasi_at']) : '' ?></span></div><div class="card-body">
        <dl class="kv"><dt>Subjective</dt><dd><?= nl2br(aman($rm['subjektif'])) ?></dd><dt>Objective</dt><dd><?= nl2br(aman($rm['objektif'])) ?></dd>
          <dt>Assessment</dt><dd><?= nl2br(aman($rm['assessment'])) ?><br><span class="mono text-[#004c6d]">ICD-10 <?= aman($rm['kode_icd10']) ?></span> · <?= aman($rm['nama_icd']) ?></dd>
          <dt>Plan</dt><dd><?= nl2br(aman($rm['plan'])) ?></dd><dt>Tindak lanjut</dt><dd><?= aman($rm['tindak_lanjut']) ?></dd></dl>
        <?php if ($add): ?><div class="section-label">Addendum</div><?php foreach ($add as $a): ?><div class="readonly-box mb-2"><b><?= tgl_jam($a['waktu_catat']) ?> · <?= aman($a['penulis']) ?>:</b> <?= nl2br(aman($a['catatan'])) ?></div><?php endforeach; ?><?php endif; ?>
      </div></div>
      <?php endif; ?>
      <div class="card"><div class="card-head"><h3>Semua Catatan Kunjungan Ini</h3><span class="muted"><?= count($semua) ?> baris di tabel rekam_medis</span></div>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Waktu catat</th><th>Jenis catatan</th><th>Unit sumber</th><th>Ditulis oleh</th><th>Isi singkat</th></tr></thead><tbody>
        <?php foreach ($semua as $c): $isi = $c['assessment'] ?: ($c['catatan'] ?: ($c['keluhan_utama'] ?: ($c['triase_kategori'] ? 'Triase ' . $c['triase_kategori'] : ''))); ?>
          <tr><td class="whitespace-nowrap"><?= tgl_jam($c['waktu_catat']) ?></td><td><?= badge($c['jenis_catatan']) ?><?= $c['status'] === 'Draft' ? ' <span class="muted text-xs">draft</span>' : '' ?></td>
            <td><?= aman($c['jenis_layanan']) ?><div class="muted text-xs"><?= aman($c['unit']) ?> · DPJP <?= aman($c['dpjp']) ?></div></td>
            <td><?= aman($c['penulis']) ?><div class="muted text-xs"><?= aman($c['jenis_tenaga']) ?></div></td>
            <td class="text-sm"><?= aman(mb_strimwidth((string) $isi, 0, 140, '…')) ?><?= $c['tekanan_darah'] ? '<div class="muted text-xs">TD ' . aman($c['tekanan_darah']) . ' · N ' . (int) $c['nadi'] . ' · S ' . aman($c['suhu']) . '</div>' : '' ?></td></tr>
        <?php endforeach; ?></tbody></table></div></div>
      <div class="card"><div class="card-head"><h3>Hasil Penunjang</h3></div><div class="card-body text-sm space-y-2">
        <?php if (!$lab && !$rad): ?><p class="muted">Tidak ada order penunjang.</p><?php endif; ?>
        <?php foreach ($lab as $l): ?><div class="readonly-box"><b>Lab · <?= aman($l['nama']) ?></b> <?= badge($l['status']) ?> <?= $l['urgensi'] === 'CITO' ? badge('CITO') : '' ?><br>
          <?= $l['status'] === 'Diterbitkan' ? 'Hasil: ' . aman($l['hasil']) . '<br><span class="muted">Rujukan: ' . aman($l['nilai_normal']) . ' · Validasi ' . aman($l['validator']) . '</span>' : '<span class="muted">Hasil belum diterbitkan · indikasi: ' . aman($l['indikasi']) . '</span>' ?></div><?php endforeach; ?>
        <?php foreach ($rad as $r): ?><div class="readonly-box"><b>Radiologi · <?= aman($r['nama']) ?></b> <?= badge($r['status']) ?><br>
          <?= $r['status'] === 'Diterbitkan' ? '<div class="mt-2">' . kartu_hasil_rad($r) . '</div>' : '<span class="muted">Ekspertise belum terbit · indikasi: ' . aman($r['indikasi']) . ($r['jaminan'] === 'Menunggu Persetujuan' ? ' · <b style="color:#92400e">menunggu persetujuan asuransi</b>' : '') . '</span>' ?></div><?php endforeach; ?>
      </div></div>
    </div>
    <div class="space-y-4">
      <div class="card"><div class="card-head"><h3>Tanda Vital</h3></div><div class="card-body text-sm"><?= aman(teks_ttv($ttv)) ?><div class="muted"><?= aman($ttv['sumber'] ?? '') ?></div></div></div>
      <div class="card"><div class="card-head"><h3>Terapi</h3></div><div class="card-body text-sm">
        <?php foreach ($resep as $r): ?><div class="mb-1"><span class="muted">Obat:</span> <?= aman($r['nama_obat']) ?> · <?= (int) $r['jumlah'] ?> <?= aman($r['satuan']) ?> · <?= aman($r['dosis'] . ' ' . $r['frekuensi']) ?> <?= badge($r['status']) ?><?= $r['alasan_alergi'] ? '<div class="text-red-700 text-xs">Override alergi: ' . aman($r['alasan_alergi']) . '</div>' : '' ?></div><?php endforeach; ?>
        <?php foreach ($tindakan as $t): ?><div class="mb-1"><span class="muted">Tindakan:</span> <?= aman($t['nama']) ?> × <?= (int) $t['jumlah'] ?> <?= badge($t['status']) ?><?= $t['keterangan'] ? '<div class="text-xs">' . aman($t['keterangan']) . '</div>' : '' ?><?= $t['pelaksana'] ? '<div class="muted text-xs">' . aman($t['pelaksana']) . ': ' . aman($t['catatan']) . '</div>' : '' ?></div><?php endforeach; ?>
        <?php if (!$resep && !$tindakan): ?><span class="muted">Tidak ada resep / tindakan.</span><?php endif; ?></div></div>
      <?php if ($kep): ?><div class="card"><div class="card-head"><h3>Perkembangan Pasien (Perawat)</h3><span class="muted"><?= count($kep) ?> catatan</span></div><div class="card-body text-sm" style="max-height:320px;overflow:auto">
        <?php foreach ($kep as $c): ?><div class="border-b border-slate-100 pb-2 mb-2"><b><?= aman($c['jenis_catatan']) ?></b> <?= $c['urgensi'] ? badge($c['urgensi']) : '' ?> <span class="muted"><?= tgl_jam($c['waktu_catat']) ?> · <?= aman($c['penulis']) ?> · <?= aman($c['unit']) ?></span>
          <?= $c['tekanan_darah'] ? '<div class="text-xs">TD ' . aman($c['tekanan_darah']) . ' · N ' . (int) $c['nadi'] . ' · RR ' . (int) $c['respirasi'] . ' · S ' . aman($c['suhu']) . ' · SpO2 ' . (int) $c['spo2'] . '%</div>' : '' ?><div><?= aman($c['catatan']) ?></div></div><?php endforeach; ?></div></div><?php endif; ?>
      <?php if ($dok): ?><div class="card"><div class="card-head"><h3>Dokumen Klinis</h3></div><div class="card-body text-sm"><?php foreach ($dok as $d): ?><div class="readonly-box mb-2"><b><?= aman($d['jenis_catatan']) ?></b><?= $d['tanggal_kontrol'] ? ' · ' . tgl($d['tanggal_kontrol']) : '' ?><?= $d['tujuan'] ? ' · ' . aman($d['tujuan']) : '' ?><br>
          <?php if ($d['jenis_catatan'] === 'Resume Pulang'): ?>Diagnosis akhir: <?= aman($d['assessment']) ?><br>Terapi: <?= nl2br(aman($d['tindakan'])) ?><br>Instruksi: <?= nl2br(aman($d['plan'])) ?><br><?php endif; ?>
          <?= nl2br(aman($d['catatan'])) ?><div class="muted text-xs mt-1"><?= aman($d['penulis']) ?> · <?= tgl_jam($d['waktu_catat']) ?></div></div><?php endforeach; ?></div></div><?php endif; ?>
      <?php if ($riwayat): ?><div class="card"><div class="card-head"><h3>Riwayat Kunjungan Lain</h3><span class="muted">semua cabang</span></div><div class="card-body text-sm">
        <?php foreach ($riwayat as $r): ?><a href="?id=<?= $r['id'] ?>" class="block border-b border-slate-100 pb-2 mb-2 hover:text-[#0b7896]"><b><?= tgl($r['tanggal']) ?></b> · <?= aman($r['unit']) ?> <span class="col-cabang">· <?= aman(nama_cabang((int) $r['id_rs'])) ?></span>
          <div><span class="mono text-[#004c6d]"><?= aman($r['kode_icd10']) ?></span> <?= aman($r['assessment'] ?? '-') ?></div><div class="muted text-xs"><?= aman($r['dokter']) ?></div></a><?php endforeach; ?></div></div><?php endif; ?>
      <?php if ($pemilik && $rm['status'] === 'Final'): ?>
        <form method="post" class="card no-print"><?= csrf_field() ?><input type="hidden" name="aksi" value="addendum"><input type="hidden" name="id" value="<?= $k['id'] ?>"><div class="card-head"><h3>Tambah Addendum</h3></div>
          <div class="card-body"><p class="muted mb-2">Dokumen FINAL tidak bisa diubah. Catatan tambahan disimpan sebagai addendum.</p><textarea name="isi" class="input" rows="3" placeholder="contoh: Edukasi diet tambahan..."></textarea></div><div class="modal-foot"><button class="btn btn-primary">Simpan Addendum</button></div></form>
      <?php endif; ?>
      <?php if (role_is('rekammedis') && $rm['status'] === 'Final' && !empty($rm['subjektif'])): ?>
        <form method="post" class="card no-print"><?= csrf_field() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="id" value="<?= $k['id'] ?>"><div class="card-head"><h3>Verifikasi Kelengkapan</h3></div>
          <div class="card-body"><select name="hasil" class="input mb-2"><?= opsi(['Lengkap' => 'Lengkap (SOAP, ICD-10, TTV, tanda tangan dokter)', 'Tidak Lengkap' => 'Tidak lengkap'], $rm['status_verifikasi']) ?></select><input name="catatan" class="input" placeholder="Catatan (wajib bila tidak lengkap)" value="<?= aman($rm['catatan_verifikasi']) ?>"></div>
          <div class="modal-foot"><button class="btn btn-primary">Simpan Verifikasi</button></div></form>
        <form method="post" class="card no-print"><?= csrf_field() ?><input type="hidden" name="aksi" value="satusehat"><input type="hidden" name="id" value="<?= $k['id'] ?>"><div class="card-body"><b>Interoperabilitas SATUSEHAT</b><p class="muted">Data dikonversi ke HL7 FHIR R4 (Patient, Encounter, Condition ICD-10, Observation LOINC).</p></div>
          <div class="modal-foot"><button class="btn btn-success" <?= $rm['status_verifikasi'] !== 'Lengkap' ? 'disabled' : '' ?>>Kirim ke SATUSEHAT</button></div></form>
      <?php endif; ?>
    </div></div>
    <?php tampil_footer();
    exit;
}

// ---------- DAFTAR EMR ----------
$n = fn($sql, $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn(); })();
$filter = sql_pasien_dokter('rm.id_pasien') . (role_is('dokter') ? '' : sql_rs('rm.id_rs'));   // dokter: pasiennya sendiri; unit lain: per cabang
$SOAP = "rm.jenis_catatan = 'SOAP Dokter'";
tampil_header('Rekam Medis Elektronik (EMR)', role_is('manajemen') ? 'Ringkasan angka saja. Isi rekam medis tidak ditampilkan untuk manajemen.' : 'Semua catatan klinis tersimpan di satu tabel rekam_medis, lengkap dengan cabang, poli/unit, dokter, dan jam masuk pasien.');
echo kartu([['Catatan Rekam Medis', $n("SELECT COUNT(*) FROM rekam_medis rm WHERE 1=1$filter"), 'teal', 'semua jenis catatan'],
            ['SOAP Draft', $n("SELECT COUNT(*) FROM rekam_medis rm WHERE $SOAP AND rm.status='Draft'$filter"), 'amber'],
            ['SOAP Final', $n("SELECT COUNT(*) FROM rekam_medis rm WHERE $SOAP AND rm.status='Final'$filter"), 'green'],
            ['Menunggu Verifikasi RM', $n("SELECT COUNT(*) FROM rekam_medis rm WHERE $SOAP AND rm.status='Final' AND rm.status_verifikasi='Belum'$filter"), 'purple'],
            ['Terkirim SATUSEHAT', $n("SELECT COUNT(*) FROM rekam_medis rm WHERE rm.satusehat_ref IS NOT NULL$filter"), 'teal']]);
if (role_is('manajemen')) {
    $top = $pdo->query("SELECT rm.kode_icd10, i.nama, COUNT(*) n FROM rekam_medis rm JOIN icd10 i ON i.kode = rm.kode_icd10 WHERE $SOAP$filter GROUP BY rm.kode_icd10, i.nama ORDER BY n DESC LIMIT 10")->fetchAll();
    echo '<div class="card"><div class="card-head"><h3>10 Diagnosis Terbanyak</h3></div><div class="tbl-wrap"><table class="tbl"><tbody>';
    foreach ($top as $t) echo '<tr><td class="mono">' . aman($t['kode_icd10']) . '</td><td>' . aman($t['nama']) . '</td><td class="num">' . (int) $t['n'] . '</td></tr>';
    echo '</tbody></table></div></div>';
    tampil_footer();
    exit;
}
$status = get('status');
$JENIS = ['Pengkajian Awal', 'Triase IGD', 'Penanganan IGD', 'SOAP Dokter', 'Catatan Keperawatan', 'Pemberian Obat', 'Lapor DPJP', 'Addendum', 'Resume Pulang', 'Surat Kontrol', 'Surat Rujukan'];
$semua = $status === 'semua';
$sql = "SELECT rm.id AS id_rm, rm.id_kunjungan AS id, rm.id_rs, rm.jenis_layanan, rm.waktu_masuk, rm.waktu_catat, rm.jenis_catatan, rm.status, rm.kode_icd10, rm.assessment, rm.catatan, rm.keluhan_utama,
               rm.status_verifikasi, rm.satusehat_ref, p.nomor_rm, p.nama_lengkap, un.nama AS poli, d.nama_lengkap AS dokter, w.nama_lengkap AS penulis
        FROM rekam_medis rm JOIN pasien p ON p.id = rm.id_pasien JOIN unit_layanan un ON un.id = rm.id_unit JOIN pegawai d ON d.id = rm.id_dokter JOIN pegawai w ON w.id = rm.ditulis_oleh
        WHERE " . ($semua ? '1=1' : $SOAP) . $filter;
$par = [];
if ($status === 'Draft' || $status === 'Final') { $sql .= ' AND rm.status = ?'; $par[] = $status; }
if ($status === 'Belum') { $sql .= " AND rm.status = 'Final' AND rm.status_verifikasi = 'Belum'"; }
if ($semua && in_array(get('jenis'), $JENIS, true)) { $sql .= ' AND rm.jenis_catatan = ?'; $par[] = get('jenis'); }
if (get('layanan') !== '' && in_array(get('layanan'), ['Rawat Jalan', 'IGD', 'Rawat Inap'], true)) { $sql .= ' AND rm.jenis_layanan = ?'; $par[] = get('layanan'); }
if (get('q') !== '') { $sql .= ' AND (p.nama_lengkap LIKE ? OR p.nomor_rm LIKE ?)'; array_push($par, '%' . get('q') . '%', '%' . get('q') . '%'); }
$s = $pdo->prepare($sql . ' ORDER BY rm.waktu_catat DESC, rm.id DESC LIMIT ' . ($semua ? 300 : 200));
$s->execute($par);
$rows = $s->fetchAll();
?>
<div class="card"><div class="card-head"><div class="tabs" style="margin:0"><?php foreach (['' => 'SOAP dokter', 'Draft' => 'Draft', 'Final' => 'Final', 'Belum' => 'Perlu verifikasi', 'semua' => 'Semua catatan (tabel rekam_medis)'] as $kk => $l): ?><a class="tab<?= $status === $kk ? ' active' : '' ?>" href="?status=<?= $kk ?>"><?= $l ?></a><?php endforeach; ?></div>
  <form method="get" class="flex gap-2 flex-wrap"><input type="hidden" name="status" value="<?= aman($status) ?>">
    <?php if ($semua): ?><select name="jenis" class="input" style="width:auto" onchange="this.form.submit()"><?= opsi(array_combine($JENIS, $JENIS), get('jenis'), 'Semua jenis catatan') ?></select><?php endif; ?>
    <select name="layanan" class="input" style="width:auto" onchange="this.form.submit()"><?= opsi(['Rawat Jalan' => 'Rawat Jalan', 'IGD' => 'IGD', 'Rawat Inap' => 'Rawat Inap'], get('layanan'), 'Semua layanan') ?></select>
    <input name="q" value="<?= aman(get('q')) ?>" class="input" style="width:200px" placeholder="Nama / No. RM"><button class="btn btn-secondary">Cari</button></form></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Jam masuk</th><th>Cabang · Layanan</th><th>Poli / Unit · Dokter</th><th>Pasien</th><th><?= $semua ? 'Jenis catatan · isi' : 'Diagnosis' ?></th><th>Status</th><th></th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Tidak ada rekam medis.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr>
    <td class="whitespace-nowrap"><?= tgl_jam($r['waktu_masuk']) ?><?php if ($semua): ?><div class="muted text-xs">dicatat <?= tgl_jam($r['waktu_catat']) ?></div><?php endif; ?></td>
    <td><span class="col-cabang"><?= aman(nama_cabang((int) $r['id_rs'])) ?></span><div><?= aman($r['jenis_layanan']) ?></div></td>
    <td><?= aman($r['poli']) ?><div class="muted"><?= aman($r['dokter']) ?></div></td>
    <td><b class="pname"><?= aman($r['nama_lengkap']) ?></b><div class="muted"><?= aman($r['nomor_rm']) ?></div></td>
    <?php if ($semua): ?><td><?= badge($r['jenis_catatan']) ?><div class="text-sm"><?= aman(mb_strimwidth((string) ($r['assessment'] ?: ($r['catatan'] ?: $r['keluhan_utama'])), 0, 110, '…')) ?></div><div class="muted text-xs">oleh <?= aman($r['penulis']) ?></div></td>
    <?php else: ?><td><span class="mono text-[#004c6d]"><?= aman($r['kode_icd10']) ?></span> <?= aman($r['assessment']) ?></td><?php endif; ?>
    <td><?= $r['jenis_catatan'] === 'SOAP Dokter' ? badge($r['status'] === 'Final' ? 'FINAL' : 'DRAFT') . '<div class="muted">Verifikasi: ' . aman($r['status_verifikasi'] ?: '-') . ($r['satusehat_ref'] ? ' · SATUSEHAT ✓' : '') . '</div>' : '<span class="muted">-</span>' ?></td>
    <td class="num"><a href="?id=<?= $r['id'] ?>" class="btn btn-<?= role_is('dokter') && $r['status'] === 'Draft' && $r['jenis_catatan'] === 'SOAP Dokter' ? 'primary' : 'secondary' ?> btn-sm"><?= role_is('dokter') && $r['status'] === 'Draft' && $r['jenis_catatan'] === 'SOAP Dokter' ? 'Isi / Finalisasi' : (role_is('rekammedis') && $r['status_verifikasi'] === 'Belum' && $r['status'] === 'Final' ? 'Verifikasi' : 'Lihat') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php tampil_footer();
