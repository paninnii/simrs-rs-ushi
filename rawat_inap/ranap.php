<?php
/*
 * MODUL 6: INPATIENT (RAWAT INAP) -- PPT Bab 8
 *   Peta & okupansi bed (BOR) · Admission & alokasi bed · Transfer bed · Resume pulang & discharge
 *   RBAC sesuai SOP Inpatient:
 *     Perawat Bangsal : admission + alokasi bed, transfer ruangan (atas instruksi DPJP)
 *     Dokter (DPJP)                   : discharge & finalisasi resume pulang
 * Tabel: rawat_inap, bed, transfer_bed; resume pulang -> rekam_medis (jenis_catatan Resume Pulang). Bed & pasien per cabang.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('rawat_inap');
$pdo = getPDO();
$dokter = role_is('dokter') && boleh('rawat_inap', true);
$perawat = role_is('perawat') && boleh('rawat_inap', true);
$bed_ok = $perawat;   // penempatan & transfer bed dikerjakan perawat bangsal; dokter memberi instruksi
$RS = rs_aktif();
$errors = [];

/** Cegah pasien laki-laki & perempuan dicampur di kamar bersama (kecuali VIP/ICU/Isolasi). */
function cek_gender_kamar(int $id_bed, string $jk, int $kecuali_ranap = 0): ?string
{
    $s = getPDO()->prepare("SELECT k.kelas, k.nama AS nomor, p.jenis_kelamin FROM bed b JOIN unit_layanan k ON k.id = b.id_unit
        LEFT JOIN bed b2 ON b2.id_unit = k.id LEFT JOIN rawat_inap r ON r.id_bed = b2.id AND r.status = 'Aktif' AND r.id <> ?
        LEFT JOIN kunjungan ku ON ku.id = r.id_kunjungan LEFT JOIN pasien p ON p.id = ku.id_pasien WHERE b.id = ?");
    $s->execute([$kecuali_ranap, $id_bed]);
    foreach ($s as $r) {
        if (in_array($r['kelas'], ['VIP', 'ICU', 'Isolasi'], true)) return null;
        if ($r['jenis_kelamin'] && $r['jenis_kelamin'] !== $jk) return "Kamar {$r['nomor']} sudah ditempati pasien " . jk($r['jenis_kelamin']) . '. Pasien laki-laki dan perempuan tidak boleh dalam satu kamar non-VIP.';
    }
    return null;
}

if (is_post()) {
    $aksi = post('aksi');
    if ($aksi === 'discharge') { if (!wajib_role_aksi(['dokter'], 'Discharge & resume pulang hanya oleh Dokter (DPJP).', 'rawat_inap')) kembali(); }
    elseif (!wajib_role_aksi(['perawat'], 'Penempatan dan transfer bed dilakukan Perawat Bangsal atas instruksi dokter DPJP.', 'rawat_inap')) kembali();

    if ($aksi === 'admit') {
        $k = ambil_kunjungan((int) post('id'));
        $id_bed = (int) post('id_bed');
        $alasan = post('alasan_masuk');
        $s = $pdo->prepare('SELECT COUNT(*) FROM rawat_inap WHERE id_kunjungan = ?');
        $s->execute([(int) post('id')]);
        if (!$k || $k['jenis'] !== 'rawat_inap' || $s->fetchColumn() || (int) $k['id_rs'] !== rs_user()) { flash('error', 'Pasien tidak menunggu admission di cabang Anda.'); kembali(['admit' => null]); }
        $dpjp = (int) post('id_dpjp');
        $c = $pdo->prepare("SELECT COUNT(*) FROM pegawai WHERE id = ? AND jenis_tenaga = 'Dokter' AND status = 'Aktif' AND id_rs = ?");
        $c->execute([$dpjp, $k['id_rs']]);
        if (!$c->fetchColumn()) $errors['id_dpjp'] = 'Pilih dokter penanggung jawab (DPJP) di cabang ini.';
        $c = $pdo->prepare('SELECT u.id_rs FROM bed b JOIN unit_layanan u ON u.id = b.id_unit WHERE b.id = ?');
        $c->execute([$id_bed]);
        if ($id_bed && (int) $c->fetchColumn() !== (int) $k['id_rs']) $errors['id_bed'] = 'Bed harus berada di cabang yang sama dengan kunjungan.';
        if (mb_strlen($alasan) < 5) $errors['alasan_masuk'] = 'Indikasi / alasan rawat inap wajib diisi.';
        if (!$id_bed) $errors['id_bed'] = 'Pilih bed yang tersedia.';
        elseif ($e = cek_gender_kamar($id_bed, $k['jenis_kelamin'])) $errors['id_bed'] = $e;
        if (!$errors) {
            $pdo->beginTransaction();
            // UPDATE bersyarat: berhasil hanya bila bed masih Tersedia (anti dua pasien di satu bed)
            $u = $pdo->prepare("UPDATE bed SET status = 'Terisi' WHERE id = ? AND status = 'Tersedia'");
            $u->execute([$id_bed]);
            if (!$u->rowCount()) {
                $pdo->rollBack();
                $errors['id_bed'] = 'Bed tersebut baru saja terisi pasien lain. Pilih bed lain.';
            } else {
                // DPJP dipilih saat admission (default: dokter yang memeriksa); perawat bangsal yang menempatkan bed
                $pdo->prepare('INSERT INTO rawat_inap (id_kunjungan, id_bed, id_dokter, alasan_masuk, tanggal_masuk, ditempatkan_oleh) VALUES (?,?,?,?,?,?)')->execute([$k['id'], $id_bed, $dpjp, $alasan, date('Y-m-d H:i:s'), uid()]);
                $pdo->commit();
                $s = $pdo->prepare("SELECT CONCAT(k.nama, ' bed ', b.kode_bed) FROM bed b JOIN unit_layanan k ON k.id = b.id_unit WHERE b.id = ?");
                $s->execute([$id_bed]);
                $nama_bed = $s->fetchColumn();
                audit('Inpatient', 'Admission', "{$k['nomor_rm']} -> $nama_bed");
                flash('sukses', "Admission berhasil: {$k['nama_lengkap']} ditempatkan di kamar $nama_bed. Bed ditandai terisi.");
                kembali(['admit' => null]);
            }
        }
        $_GET['admit'] = $k['id'];
    }

    if ($aksi === 'transfer' || $aksi === 'discharge') {
        $s = $pdo->prepare("SELECT r.*, ku.id_pasien, ku.id_rs, p.jenis_kelamin, p.nama_lengkap, p.nomor_rm FROM rawat_inap r JOIN kunjungan ku ON ku.id = r.id_kunjungan JOIN pasien p ON p.id = ku.id_pasien WHERE r.id = ? AND r.status = 'Aktif'");
        $s->execute([(int) post('id')]);
        $ra = $s->fetch();
        if (!$ra || (int) $ra['id_rs'] !== rs_user()) { flash('error', 'Data rawat inap aktif tidak ditemukan di cabang Anda.'); kembali(); }
        if ($aksi === 'discharge' && (int) $ra['id_dokter'] !== uid()) { flash('error', 'Discharge hanya oleh DPJP pasien ini.'); kembali(); }
    }

    if ($aksi === 'transfer') {
        $ke = (int) post('ke_bed');
        $alasan = post('alasan');
        if (mb_strlen($alasan) < 5) $errors['alasan'] = 'Alasan transfer wajib diisi.';
        if ($ke === (int) $ra['id_bed']) $errors['ke_bed'] = 'Pilih bed tujuan yang berbeda.';
        elseif ($e = cek_gender_kamar($ke, $ra['jenis_kelamin'], (int) $ra['id'])) $errors['ke_bed'] = $e;
        if (!$errors) {
            $pdo->beginTransaction();
            $u = $pdo->prepare("UPDATE bed SET status = 'Terisi' WHERE id = ? AND status = 'Tersedia'");
            $u->execute([$ke]);
            if (!$u->rowCount()) {
                $pdo->rollBack();
                flash('error', 'Bed tujuan sedang terisi / perbaikan. Transfer dibatalkan.');
                kembali();
            }
            $pdo->prepare("UPDATE bed SET status = 'Tersedia' WHERE id = ?")->execute([$ra['id_bed']]);
            $pdo->prepare('UPDATE rawat_inap SET id_bed = ? WHERE id = ?')->execute([$ke, $ra['id']]);
            $pdo->prepare('INSERT INTO transfer_bed (id_rawat_inap, dari_bed, ke_bed, alasan, oleh) VALUES (?,?,?,?,?)')->execute([$ra['id'], $ra['id_bed'], $ke, $alasan, uid()]);
            $pdo->commit();
            audit('Inpatient', 'Transfer Bed', "{$ra['nomor_rm']} bed #{$ra['id_bed']} -> #$ke: $alasan");
            flash('sukses', "Transfer {$ra['nama_lengkap']} berhasil dan tercatat beserta alasannya.");
            kembali(['transfer' => null]);
        }
        $_GET['transfer'] = $ra['id'];
    }

    if ($aksi === 'discharge') {
        $d = ['diagnosis_akhir' => post('diagnosis_akhir'), 'terapi' => post('terapi'), 'instruksi_pulang' => post('instruksi_pulang')];
        if (mb_strlen($d['diagnosis_akhir']) < 3) $errors['diagnosis_akhir'] = 'Diagnosis akhir wajib diisi.';
        if (mb_strlen($d['terapi']) < 5) $errors['terapi'] = 'Tindakan & terapi selama dirawat wajib diisi.';
        if (mb_strlen($d['instruksi_pulang']) < 5) $errors['instruksi_pulang'] = 'Instruksi tindak lanjut wajib diisi.';
        $s = $pdo->prepare("SELECT COUNT(*) FROM resep WHERE id_kunjungan = ? AND status NOT IN ('Selesai','Batal')");
        $s->execute([$ra['id_kunjungan']]);
        if ($s->fetchColumn()) $errors['terapi'] = 'Masih ada resep yang belum diserahkan Farmasi. Selesaikan atau batalkan dulu sebelum discharge.';
        if (!$errors) {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE rawat_inap SET status = 'Keluar', tanggal_keluar = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $ra['id']]);
            $pdo->prepare("UPDATE bed SET status = 'Tersedia' WHERE id = ?")->execute([$ra['id_bed']]);
            $icd = $pdo->prepare("SELECT kode_icd10 FROM rekam_medis WHERE id_kunjungan = ? AND jenis_catatan = 'SOAP Dokter' LIMIT 1");
            $icd->execute([$ra['id_kunjungan']]);
            $kode_icd = strtoupper(trim(explode(' ', $d['diagnosis_akhir'])[0]));
            $c = $pdo->prepare('SELECT COUNT(*) FROM icd10 WHERE kode = ?'); $c->execute([$kode_icd]);
            tulis_rm((int) $ra['id_kunjungan'], 'Resume Pulang', ['assessment' => $d['diagnosis_akhir'], 'kode_icd10' => $c->fetchColumn() ? $kode_icd : ($icd->fetchColumn() ?: null),
                     'tindakan' => $d['terapi'], 'plan' => $d['instruksi_pulang'], 'kunci_unik' => 'RESUME-' . $ra['id_kunjungan'],
                     'catatan' => 'Lama dirawat ' . max(1, (int) round((time() - strtotime(substr($ra['tanggal_masuk'], 0, 10))) / 86400)) . ' hari.'], 'Rawat Inap');
            buat_tagihan((int) $ra['id_kunjungan']);
            $pdo->commit();
            audit('Inpatient', 'Discharge', "{$ra['nomor_rm']} pulang");
            flash('sukses', "Discharge selesai: resume pulang {$ra['nama_lengkap']} tersimpan, bed dilepas, dan tagihan dibentuk di Billing.");
            kembali(['discharge' => null]);
        }
        $_GET['discharge'] = $ra['id'];
    }
}

// ---------- data ----------
$beds = $pdo->query("SELECT b.*, k.kode AS nomor, k.nama AS nama_kamar, k.kelas, k.lokasi AS gedung, k.id_rs, r.id AS id_ranap, p.nama_lengkap, p.jenis_kelamin
    FROM bed b JOIN unit_layanan k ON k.id = b.id_unit LEFT JOIN rawat_inap r ON r.id_bed = b.id AND r.status = 'Aktif'
    LEFT JOIN kunjungan ku ON ku.id = r.id_kunjungan LEFT JOIN pasien p ON p.id = ku.id_pasien WHERE k.aktif = 1" . sql_rs('k.id_rs') . " ORDER BY k.id_rs, k.lokasi, k.nama, b.kode_bed")->fetchAll();
$tersedia = array_filter($beds, fn($b) => $b['status'] === 'Tersedia');
$opsi_bed = [];
foreach ($tersedia as $b) $opsi_bed[$b['id']] = "{$b['nama_kamar']} · Bed {$b['kode_bed']} (Kelas {$b['kelas']})";
$opsi_dpjp = $pdo->query("SELECT id, CONCAT(nama_lengkap, ' · ', COALESCE(spesialisasi, '-')) FROM pegawai WHERE jenis_tenaga = 'Dokter' AND status = 'Aktif'
    AND spesialisasi NOT IN ('Patologi Klinik','Radiologi')" . sql_rs('id_rs') . ' ORDER BY nama_lengkap')->fetchAll(PDO::FETCH_KEY_PAIR);
$filter_dok = role_is('dokter') ? ' AND (r.id_dokter = ' . (int) uid() . ' OR ku.id_dokter = ' . (int) uid() . ')' : '';
$menunggu = $pdo->query("SELECT ku.*, p.nomor_rm, p.nama_lengkap, p.jenis_kelamin, p.tanggal_lahir, rm.assessment, rm.kode_icd10, d.nama_lengkap AS dokter, pl.nama AS poli
    FROM kunjungan ku JOIN pasien p ON p.id = ku.id_pasien JOIN pegawai d ON d.id = ku.id_dokter JOIN unit_layanan pl ON pl.id = ku.id_unit
    LEFT JOIN rekam_medis rm ON rm.id_kunjungan = ku.id AND rm.jenis_catatan = 'SOAP Dokter' LEFT JOIN rawat_inap r ON r.id_kunjungan = ku.id
    WHERE ku.jenis = 'rawat_inap' AND ku.status = 'Selesai' AND r.id IS NULL" . sql_rs('ku.id_rs') . $filter_dok . " ORDER BY ku.id")->fetchAll();
$aktif = $pdo->query("SELECT r.*, ku.id_pasien, ku.id_rs, p.nomor_rm, p.nama_lengkap, p.jenis_kelamin, p.tanggal_lahir, p.alergi, b.kode_bed, k.kode AS nomor, k.nama AS nama_kamar, k.kelas,
        u.nama_lengkap AS dpjp, rm.assessment, rm.kode_icd10, pj.nama AS penjamin,
        (SELECT COUNT(*) FROM rekam_medis c WHERE c.id_kunjungan = r.id_kunjungan AND c.jenis_catatan = 'Lapor DPJP' AND c.dibaca_dpjp = 0) AS lapor
    FROM rawat_inap r JOIN kunjungan ku ON ku.id = r.id_kunjungan JOIN pasien p ON p.id = ku.id_pasien JOIN bed b ON b.id = r.id_bed JOIN unit_layanan k ON k.id = b.id_unit
    JOIN pegawai u ON u.id = r.id_dokter JOIN penjamin pj ON pj.id = ku.id_penjamin LEFT JOIN rekam_medis rm ON rm.id_kunjungan = r.id_kunjungan AND rm.jenis_catatan = 'SOAP Dokter'
    WHERE r.status = 'Aktif'" . sql_rs('ku.id_rs') . $filter_dok . " ORDER BY k.nama, b.kode_bed")->fetchAll();
$total_bed = count(array_filter($beds, fn($b) => $b['status'] !== 'Perbaikan'));
$terisi = count(array_filter($beds, fn($b) => $b['status'] === 'Terisi'));
$n = fn($sql) => (int) $pdo->query($sql)->fetchColumn();

tampil_header('Rawat Inap & Bed Management', 'Alur: Admission & Bed → Pelayanan → Transfer → Discharge. BOR = bed terisi / bed siap pakai · ' . nama_cabang($RS ?: null) . '.');
echo kartu([['Pasien Dirawat', count($aktif), 'teal'], ['Menunggu Bed', count($menunggu), 'amber'], ['Bed Tersedia', count($tersedia), 'green'],
            ['BOR Saat Ini', $total_bed ? round($terisi / $total_bed * 100, 1) . '%' : '0%', 'purple'], ['Discharge Hari Ini', $n("SELECT COUNT(*) FROM rawat_inap r JOIN kunjungan ku ON ku.id = r.id_kunjungan WHERE r.tanggal_keluar >= CURDATE()" . sql_rs('ku.id_rs')), 'red']]);

// ---------- form admission / transfer / discharge ----------
$form = null;
if (get('admit') !== '') foreach ($menunggu as $m) if ((int) $m['id'] === (int) get('admit')) $form = ['admit', $m];
if (get('transfer') !== '') foreach ($aktif as $a) if ((int) $a['id'] === (int) get('transfer')) $form = ['transfer', $a];
if (get('discharge') !== '') foreach ($aktif as $a) if ((int) $a['id'] === (int) get('discharge')) $form = ['discharge', $a];
if ($form && ($jenis_f = $form[0]) && ($jenis_f === 'discharge' ? $dokter : $bed_ok)): [$jenis, $x] = $form; ?>
<form method="post" class="card mb-4" id="form"><?= csrf_field() ?><input type="hidden" name="aksi" value="<?= $jenis ?>"><input type="hidden" name="id" value="<?= $x['id'] ?>">
  <div class="card-head"><h3><?= ['admit' => 'Admission & Alokasi Bed', 'transfer' => 'Transfer Bed', 'discharge' => 'Resume Pulang & Discharge'][$jenis] ?> · <?= aman($x['nama_lengkap']) ?></h3><span class="mono muted"><?= aman($x['nomor_rm']) ?></span></div>
  <div class="card-body">
  <?php if ($jenis === 'admit'): ?>
    <div class="readonly-box mb-3">Dari <?= aman($x['poli']) ?> · <?= aman($x['dokter']) ?> · Diagnosis: <b><?= aman($x['kode_icd10'] . ' ' . $x['assessment']) ?></b></div>
    <div class="form-grid"><div class="field"><label>Kamar &amp; bed tersedia *</label><select name="id_bed" class="<?= err_class($errors, 'id_bed') ?>"><?= opsi($opsi_bed, $_POST['id_bed'] ?? '', '-- Pilih bed --') ?></select><?= err($errors, 'id_bed') ?></div>
      <div class="field"><label>DPJP rawat inap *</label><select name="id_dpjp" class="<?= err_class($errors, 'id_dpjp') ?>"><?= opsi($opsi_dpjp, $_POST['id_dpjp'] ?? $x['id_dokter'], '-- Pilih dokter --') ?></select><?= err($errors, 'id_dpjp') ?><p class="hint">Sesuai instruksi dokter. Pasien dari IGD biasanya diserahkan ke dokter spesialis.</p></div>
      <div class="field" style="grid-column:span 2"><label>Indikasi rawat inap *</label><input name="alasan_masuk" value="<?= old('alasan_masuk', $x['assessment']) ?>" maxlength="1000" class="<?= err_class($errors, 'alasan_masuk') ?>"><?= err($errors, 'alasan_masuk') ?></div></div>
  <?php elseif ($jenis === 'transfer'): ?>
    <div class="readonly-box mb-3">Posisi sekarang: <b><?= aman($x['nama_kamar']) ?></b> bed <b><?= aman($x['kode_bed']) ?></b></div>
    <div class="form-grid"><div class="field"><label>Pindah ke bed *</label><select name="ke_bed" class="<?= err_class($errors, 'ke_bed') ?>"><?= opsi($opsi_bed, $_POST['ke_bed'] ?? '', '-- Pilih bed tujuan --') ?></select><?= err($errors, 'ke_bed') ?></div>
      <div class="field" style="grid-column:span 2"><label>Alasan transfer *</label><input name="alasan" value="<?= old('alasan') ?>" maxlength="255" class="<?= err_class($errors, 'alasan') ?>" placeholder="contoh: Pindah ke kamar dekat nurse station"><?= err($errors, 'alasan') ?></div></div>
  <?php else: ?>
    <div class="readonly-box mb-3">Dirawat sejak <?= tgl_jam($x['tanggal_masuk']) ?> (hari ke-<?= max(1, (int) floor((time() - strtotime(substr($x['tanggal_masuk'], 0, 10))) / 86400) + 1) ?>) · <?= aman($x['nama_kamar'] . ' bed ' . $x['kode_bed']) ?></div>
    <div class="field"><label>Diagnosis akhir *</label><input name="diagnosis_akhir" maxlength="255" value="<?= old('diagnosis_akhir', trim($x['kode_icd10'] . ' ' . $x['assessment'])) ?>" class="<?= err_class($errors, 'diagnosis_akhir') ?>"><?= err($errors, 'diagnosis_akhir') ?></div>
    <div class="field"><label>Tindakan &amp; terapi selama dirawat *</label><textarea name="terapi" class="<?= err_class($errors, 'terapi') ?>"><?= old('terapi') ?></textarea><?= err($errors, 'terapi') ?></div>
    <div class="field"><label>Instruksi tindak lanjut *</label><textarea name="instruksi_pulang" class="<?= err_class($errors, 'instruksi_pulang') ?>" placeholder="Kontrol poli ... 1 minggu, obat pulang ..."><?= old('instruksi_pulang') ?></textarea><?= err($errors, 'instruksi_pulang') ?></div>
  <?php endif; ?>
  </div>
  <div class="modal-foot"><a href="ranap.php" class="btn btn-secondary">Batal</a><button class="btn <?= $jenis === 'discharge' ? 'btn-danger' : 'btn-primary' ?>"><?= ['admit' => 'Konfirmasi Admission', 'transfer' => 'Konfirmasi Transfer', 'discharge' => 'Finalisasi Discharge'][$jenis] ?></button></div>
</form>
<?php endif; ?>

<div class="card mb-4"><div class="card-head"><h3>Peta &amp; Okupansi Bed</h3><span class="muted"><?= $terisi ?> terisi · <?= count($tersedia) ?> tersedia · <?= count($beds) - $total_bed ?> perbaikan</span></div>
  <div class="card-body"><div class="bed-grid">
  <?php foreach ($beds as $b): ?><div class="bed bed-<?= $b['status'] ?>"><b><?= aman($b['nama_kamar']) ?> · Bed <?= aman($b['kode_bed']) ?></b><?= aman($b['kelas']) ?><?= $RS ? '' : ' · ' . aman(daftar_cabang()[$b['id_rs']]['nama_cabang']) ?><br>
    <?= $b['status'] === 'Terisi' ? (role_is('dokter', 'perawat') ? '<b class="inline">' . aman($b['nama_lengkap']) . '</b> (' . aman($b['jenis_kelamin']) . ')' : 'Terisi') : aman($b['status']) ?></div><?php endforeach; ?>
  </div></div></div>

<div class="space-y-4">
<div class="card"><div class="card-head"><h3>Menunggu Admission</h3><span class="muted">instruksi rawat inap dari dokter, menunggu penempatan bed</span></div><div class="tbl-wrap"><table class="tbl"><tbody>
  <?php if (!$menunggu): ?><tr><td class="empty">Tidak ada pasien menunggu bed.</td></tr><?php endif; ?>
  <?php foreach ($menunggu as $m): ?><tr><td><b class="pname"><?= aman($m['nama_lengkap']) ?></b><div class="muted"><?= aman($m['nomor_rm']) ?> · <?= jk($m['jenis_kelamin']) ?> · dari <?= aman($m['poli']) ?></div><div class="muted"><?= aman($m['kode_icd10'] . ' ' . $m['assessment']) ?></div>
    <?php if ($bed_ok): ?><a href="?admit=<?= $m['id'] ?>#form" class="btn btn-primary btn-sm mt-2">Admission &amp; Alokasi Bed</a><?php else: ?><span class="badge badge-amber mt-1">Menunggu penempatan bed oleh perawat bangsal</span><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card"><div class="card-head"><h3>Pasien Dirawat</h3></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pasien</th><th>Kamar / Bed</th><th>DPJP / Diagnosis</th><th>Lama</th><th class="num">Tindakan</th></tr></thead><tbody>
  <?php if (!$aktif): ?><tr><td colspan="5" class="empty">Tidak ada pasien dirawat.</td></tr><?php endif; ?>
  <?php foreach ($aktif as $a): $hari = max(1, (int) floor((time() - strtotime(substr($a['tanggal_masuk'], 0, 10))) / 86400) + 1); ?>
    <tr><td><b class="pname"><?= aman($a['nama_lengkap']) ?></b><div class="muted"><?= aman($a['nomor_rm']) ?> · <?= jk($a['jenis_kelamin']) ?> · <?= umur($a['tanggal_lahir']) ?> · <?= aman($a['penjamin']) ?></div>
      <?= $a['lapor'] ? '<span class="badge badge-red">' . (int) $a['lapor'] . ' laporan perawat belum dibaca</span>' : '' ?></td>
      <td><?= aman($a['nama_kamar']) ?> · Bed <?= aman($a['kode_bed']) ?><div class="muted">Kelas <?= aman($a['kelas']) ?><?= $RS ? '' : ' · ' . aman(nama_cabang((int) $a['id_rs'])) ?></div></td>
      <td><?= aman($a['dpjp']) ?><div class="muted"><?= aman($a['kode_icd10'] . ' ' . $a['assessment']) ?></div></td>
      <td class="whitespace-nowrap">Hari ke-<?= $hari ?><div class="muted">sejak <?= tgl($a['tanggal_masuk']) ?></div></td>
      <td class="num" style="min-width:210px">
        <?php if (boleh('keperawatan')): ?><a href="<?= url('keperawatan/keperawatan.php?id=' . $a['id_kunjungan']) ?>" class="btn btn-secondary btn-sm">Catatan Perawat</a><?php endif; ?>
        <?php if ($dokter): ?><a href="<?= url('cpoe/cpoe.php?kunjungan=' . $a['id_kunjungan']) ?>" class="btn btn-secondary btn-sm">Order</a><?php endif; ?>
        <?php if ($bed_ok): ?><a href="?transfer=<?= $a['id'] ?>#form" class="btn btn-secondary btn-sm">Transfer Bed</a><?php endif; ?>
        <?php if ($dokter && (int) $a['id_dokter'] === uid()): ?><a href="?discharge=<?= $a['id'] ?>#form" class="btn btn-danger btn-sm">Resume / Discharge</a><?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php tampil_footer();
