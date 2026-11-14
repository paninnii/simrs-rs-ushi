<?php
/*
 * PORTAL PASIEN
 *   Pasien hanya melihat datanya sendiri: profil, janji temu (bisa buat & batalkan), riwayat kunjungan,
 *   hasil lab/radiologi yang SUDAH DITERBITKAN, resep, dan tagihan -- dari semua cabang (satu No. RM).
 * Akun pasien tersimpan di tabel pasien (kolom username & password). Janji temu disimpan di tabel kunjungan (sumber Online).
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('portal');
$pdo = getPDO();
$idp = id_pasien_login();
$s = $pdo->prepare('SELECT * FROM pasien WHERE id = ?');
$s->execute([$idp]);
$p = $s->fetch();
if (!$p) {
    tampil_header('Akun Pasien');
    echo '<div class="alert alert-error">Akun ini belum ditautkan ke data pasien. Hubungi bagian pendaftaran.</div>';
    tampil_footer();
    exit;
}
$jadwal = $pdo->query("SELECT j.*, j.id_pegawai AS id_dokter, j.id_unit AS id_poli, d.nama_lengkap AS dokter, d.spesialisasi, CONCAT(pl.nama, ' · ', rs.nama_cabang) AS poli, pl.id_rs
                       FROM jadwal_pegawai j JOIN pegawai d ON d.id = j.id_pegawai JOIN unit_layanan pl ON pl.id = j.id_unit JOIN konfigurasi_rs rs ON rs.id = pl.id_rs
                       WHERE j.aktif = 1 AND j.jenis = 'Praktik' AND d.status = 'Aktif' AND pl.jenis = 'Poliklinik' ORDER BY pl.id_rs, pl.nama, d.nama_lengkap")->fetchAll();
$pilihan = []; $rs_unit = [];
foreach ($jadwal as $j) { $pilihan[$j['id_dokter'] . '-' . $j['id_poli']] = $j['poli'] . ' · ' . $j['dokter']; $rs_unit[$j['id_poli']] = (int) $j['id_rs']; }

if (is_post()) {
    $aksi = post('aksi');
    if ($aksi === 'profil') {
        $errors = array_filter(['no_hp' => v_hp(post('no_hp')), 'alamat' => mb_strlen(post('alamat')) < 10 ? 'Alamat minimal 10 karakter.' : null]);
        if ($errors) { flash('error', implode(' ', $errors)); kembali(); }
        $pdo->prepare('UPDATE pasien SET no_hp = ?, alamat = ? WHERE id = ?')->execute([post('no_hp'), post('alamat'), $idp]);
        audit('Portal', 'Ubah kontak', $p['nomor_rm']);
        flash('sukses', 'Nomor HP dan alamat diperbarui.');
    } elseif ($aksi === 'janji') {
        [$dok, $pol] = array_map('intval', explode('-', post('dokter') . '-0'));
        $tanggal = post('tanggal'); $jam = post('jam');
        try {
            if (!isset($pilihan["$dok-$pol"])) throw new Exception('Pilih poliklinik & dokter.');
            if (v_tanggal($tanggal) !== null || $tanggal < date('Y-m-d') || $tanggal > date('Y-m-d', strtotime('+30 days'))) throw new Exception('Tanggal janji temu hari ini sampai 30 hari ke depan.');
            [$j, $slot] = slot_tersedia($dok, $pol, $tanggal);
            if (!$j) throw new Exception('Dokter tidak praktik pada hari ' . hari_ini($tanggal) . '.');
            if (($slot[$jam] ?? '') !== 'Tersedia') throw new Exception('Jam tersebut sudah terisi atau tidak tersedia. Pilih jam lain.');
            $c = $pdo->prepare("SELECT COUNT(*) FROM kunjungan a WHERE a.id_pasien = ? AND a.tanggal = ? AND a.id_unit = ? AND a.status IN ('Menunggu Konfirmasi','Terkonfirmasi')");
            $c->execute([$idp, $tanggal, $pol]);
            if ($c->fetchColumn()) throw new Exception('Anda sudah punya janji temu di poli ini pada tanggal tersebut.');
            $kode = kode_berikut('kunjungan', 'kode_booking', 'APT');
            $pdo->prepare("INSERT INTO kunjungan (id_rs, kode_booking, id_pasien, tanggal, jam_janji, id_unit, id_dokter, id_jadwal, id_penjamin, no_kartu, keluhan_awal, jenis, sumber, status, catatan, dibuat_oleh)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,'rawat_jalan','Online','Menunggu Konfirmasi','Dibuat dari akun pasien',NULL)")
                ->execute([$rs_unit[$pol], $kode, $idp, $tanggal, $jam, $pol, $dok, $j['id'], $p['no_bpjs'] ? 2 : 1, $p['no_bpjs'] ?: null, post('keluhan') ? mb_substr(post('keluhan'), 0, 255) : null]);
            audit('Portal', 'Buat janji temu', "$kode $tanggal $jam");
            flash('sukses', "Janji temu $kode tercatat: " . tgl($tanggal) . " jam $jam. Menunggu konfirmasi petugas, datang 30 menit lebih awal dengan membawa KTP" . ($p['no_bpjs'] ? '/kartu BPJS' : '') . '.');
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
    } elseif ($aksi === 'batal') {
        $u = $pdo->prepare("UPDATE kunjungan SET status = 'Batal', catatan = 'Dibatalkan pasien lewat akun pasien' WHERE id = ? AND id_pasien = ? AND kode_booking IS NOT NULL AND no_antrian IS NULL AND status IN ('Menunggu Konfirmasi','Terkonfirmasi') AND tanggal >= ?");
        $u->execute([(int) post('id'), $idp, date('Y-m-d')]);
        flash($u->rowCount() ? 'sukses' : 'error', $u->rowCount() ? 'Janji temu dibatalkan.' : 'Janji temu tidak bisa dibatalkan.');
    }
    kembali();
}

// ---- data pasien sendiri ----
$q = function (string $sql) use ($pdo, $idp) { $s = $pdo->prepare($sql); $s->execute([$idp]); return $s->fetchAll(); };
$janji = $q("SELECT a.*, a.kode_booking AS kode, a.jam_janji AS jam, COALESCE(a.keluhan_awal, a.catatan) AS catatan, CASE WHEN a.no_antrian IS NOT NULL THEN 'Hadir' ELSE a.status END AS status,
                    d.nama_lengkap AS dokter, CONCAT(pl.nama, ' · ', rs.nama_cabang) AS poli
             FROM kunjungan a JOIN pegawai d ON d.id = a.id_dokter JOIN unit_layanan pl ON pl.id = a.id_unit JOIN konfigurasi_rs rs ON rs.id = a.id_rs
             WHERE a.id_pasien = ? AND a.kode_booking IS NOT NULL ORDER BY a.tanggal DESC, a.jam_janji DESC LIMIT 20");
$kunj = $q("SELECT k.*, CONCAT(pl.nama, ' · ', rs.nama_cabang) AS poli, d.nama_lengkap AS dokter, pj.nama AS penjamin, rm.kode_icd10, i.nama AS diagnosis, rm.status AS status_rm, rm.plan, rm.tindak_lanjut
            FROM kunjungan k JOIN unit_layanan pl ON pl.id = k.id_unit JOIN konfigurasi_rs rs ON rs.id = k.id_rs JOIN pegawai d ON d.id = k.id_dokter JOIN penjamin pj ON pj.id = k.id_penjamin
            LEFT JOIN rekam_medis rm ON rm.id_kunjungan = k.id AND rm.jenis_catatan = 'SOAP Dokter' LEFT JOIN icd10 i ON i.kode = rm.kode_icd10
            WHERE k.id_pasien = ? AND k.status <> 'Batal' AND k.no_antrian IS NOT NULL ORDER BY k.tanggal DESC, k.id DESC LIMIT 30");
$lab = $q("SELECT o.*, o.catatan AS catatan_validasi, j.nama, j.nilai_normal, k.tanggal FROM order_pelayanan o JOIN layanan j ON j.id = o.id_layanan JOIN kunjungan k ON k.id = o.id_kunjungan WHERE k.id_pasien = ? AND o.jenis = 'Laboratorium' AND o.status = 'Diterbitkan' ORDER BY o.waktu_terbit DESC");
$rad = $q("SELECT o.*, o.uraian AS nama, k.tanggal, v.nama_lengkap AS radiolog FROM order_pelayanan o LEFT JOIN pegawai v ON v.id = o.id_validator JOIN kunjungan k ON k.id = o.id_kunjungan WHERE k.id_pasien = ? AND o.jenis = 'Radiologi' AND o.status = 'Diterbitkan' ORDER BY o.waktu_terbit DESC");
$obat = $q("SELECT r.tanggal, r.status, o.nama_obat, ri.jumlah, ri.dosis, ri.frekuensi, ri.aturan_pakai, o.satuan FROM resep r JOIN resep_item ri ON ri.id_resep = r.id JOIN obat o ON o.id = ri.id_obat JOIN kunjungan k ON k.id = r.id_kunjungan WHERE k.id_pasien = ? AND r.status <> 'Batal' ORDER BY r.tanggal DESC LIMIT 30");
$tagihan = $q("SELECT t.*, k.tanggal, CONCAT(pl.nama, ' · ', rs.nama_cabang) AS poli, pj.nama AS penjamin, pb.no_transaksi AS no_kwitansi, pb.metode FROM tagihan t JOIN kunjungan k ON k.id = t.id_kunjungan
               JOIN unit_layanan pl ON pl.id = k.id_unit JOIN konfigurasi_rs rs ON rs.id = k.id_rs JOIN penjamin pj ON pj.id = k.id_penjamin
               LEFT JOIN pembayaran pb ON pb.id_tagihan = t.id AND pb.jenis = 'Pembayaran Pasien' WHERE k.id_pasien = ? ORDER BY t.created_at DESC");
$aktif = array_filter($janji, fn($a) => in_array($a['status'], ['Menunggu Konfirmasi', 'Terkonfirmasi'], true) && $a['tanggal'] >= date('Y-m-d'));

$tab = get('tab', 'ringkasan');
$cek_tgl = get('tanggal') ?: date('Y-m-d', strtotime('+1 day'));
$cek_dok = get('dokter');
tampil_header('Halo, ' . explode(' ', $p['nama_lengkap'])[0], 'Akun Pasien ' . konfig('nama_rs') . ' · ' . $p['nomor_rm'] . ' · berlaku di semua cabang');
echo kartu_pasien($p, 'NIK ' . aman(substr($p['nik'], 0, 6) . '******' . substr($p['nik'], -4)) . ($p['no_bpjs'] ? ' · BPJS ' . aman($p['no_bpjs']) : '') . ' · Gol. darah ' . aman($p['golongan_darah']));
echo kartu([['Janji Temu Aktif', count($aktif), 'teal'], ['Kunjungan', count($kunj), 'green'], ['Hasil Lab & Radiologi', count($lab) + count($rad), 'purple'], ['Tagihan Belum Lunas', count(array_filter($tagihan, fn($t) => !in_array($t['status'], ['Dibayar', 'Final'], true))), 'amber']]);
$TAB = ['ringkasan' => 'Ringkasan', 'janji' => 'Janji temu', 'riwayat' => 'Riwayat berobat', 'hasil' => 'Hasil pemeriksaan', 'tagihan' => 'Tagihan', 'profil' => 'Profil'];
?>
<div class="tabs"><?php foreach ($TAB as $k => $v): ?><a class="tab<?= $tab === $k ? ' active' : '' ?>" href="?tab=<?= $k ?>"><?= $v ?></a><?php endforeach; ?></div>

<?php if ($tab === 'ringkasan'): ?>
  <div class="grid lg:grid-cols-2 gap-4">
    <div class="card"><div class="card-head"><b>Janji temu mendatang</b><a href="?tab=janji" class="btn btn-primary btn-sm">Buat Janji</a></div><div class="card-body">
      <?php if (!$aktif): ?><p class="muted">Belum ada janji temu. Buat janji agar tidak antre lama.</p><?php endif; ?>
      <?php foreach ($aktif as $a): ?><div class="flex items-center gap-3 py-2 border-b border-slate-100"><div class="text-center w-14"><b class="text-xl text-[#004c6d]"><?= date('j', strtotime($a['tanggal'])) ?></b><div class="muted"><?= hari_ini($a['tanggal']) ?></div></div>
        <div><b><?= aman($a['poli']) ?></b> · <?= jam($a['jam']) ?><div class="muted"><?= aman($a['dokter']) ?> · <?= aman($a['kode']) ?></div></div><div class="ml-auto"><?= badge($a['status']) ?></div></div><?php endforeach; ?></div></div>
    <div class="card"><div class="card-head"><b>Kunjungan terakhir</b></div><div class="card-body">
      <?php if (!$kunj): ?><p class="muted">Belum ada riwayat kunjungan.</p><?php else: $k = $kunj[0]; ?>
        <dl class="kv"><dt>Tanggal</dt><dd><?= tgl($k['tanggal']) ?></dd><dt>Poli</dt><dd><?= aman($k['poli']) ?></dd><dt>Dokter</dt><dd><?= aman($k['dokter']) ?></dd>
          <dt>Diagnosis</dt><dd><?= $k['status_rm'] === 'Final' ? aman($k['kode_icd10'] . ' ' . $k['diagnosis']) : '<span class="muted">Belum final</span>' ?></dd><dt>Tindak lanjut</dt><dd><?= aman($k['tindak_lanjut'] ?: '-') ?></dd></dl><?php endif; ?>
      <div class="notice mt-3 text-xs">Darurat? Hubungi IGD 24 jam: <?= implode(' · ', array_map(fn($c) => aman($c['nama_cabang']) . ' <b>' . aman($c['telepon_igd']) . '</b>', daftar_cabang())) ?>, atau langsung datang ke IGD terdekat.</div></div></div>
  </div>

<?php elseif ($tab === 'janji'): ?>
  <div class="grid lg:grid-cols-5 gap-4">
    <div class="card lg:col-span-2"><div class="card-head"><h3>Buat Janji Temu</h3></div><div class="card-body">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="janji">
        <div class="field"><label for="pjDokter">1. Poliklinik &amp; dokter <span class="req">*</span></label><select id="pjDokter" name="dokter" required><?= opsi($pilihan, $cek_dok, '- pilih dokter -') ?></select></div>
        <div class="field"><label for="pjTanggal">2. Tanggal kunjungan <span class="req">*</span></label><input id="pjTanggal" type="date" name="tanggal" value="<?= aman($cek_tgl) ?>" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+30 days')) ?>" required></div>
        <div class="field"><span class="lbl">3. Pilih jam <span class="req">*</span></span>
          <div data-slot-picker data-url="<?= url('jadwal/slot.php') ?>" data-dokter="#pjDokter" data-tanggal="#pjTanggal"><p class="slot-msg">Pilih dokter dan tanggal untuk melihat jam yang tersedia.</p></div></div>
        <div class="field"><label for="pjKeluhan">Keluhan singkat</label><input id="pjKeluhan" name="keluhan" maxlength="200" placeholder="contoh: Kontrol tekanan darah"></div>
        <button class="btn btn-primary w-full">Buat Janji Temu</button>
      </form></div></div>
    <div class="card lg:col-span-3"><div class="card-head"><b>Janji temu saya</b></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Kode</th><th>Jadwal</th><th>Poli / Dokter</th><th>Status</th><th></th></tr></thead><tbody>
      <?php if (!$janji): ?><tr><td colspan="5" class="empty">Belum ada janji temu.</td></tr><?php endif; ?>
      <?php foreach ($janji as $a): ?><tr><td class="mono"><?= aman($a['kode']) ?></td><td><?= hari_ini($a['tanggal']) ?>, <?= tgl($a['tanggal']) ?><div class="muted"><?= jam($a['jam']) ?></div></td><td><?= aman($a['poli']) ?><div class="muted"><?= aman($a['dokter']) ?></div></td><td><?= badge($a['status']) ?></td>
        <td class="num"><?php if (in_array($a['status'], ['Menunggu Konfirmasi', 'Terkonfirmasi'], true) && $a['tanggal'] >= date('Y-m-d')): ?><form method="post" data-confirm="Batalkan janji temu ini?"><?= csrf_field() ?><input type="hidden" name="aksi" value="batal"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-outline-danger btn-sm">Batalkan</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
      <div class="card-body border-t"><div class="section-label mt-0">Jadwal praktik dokter</div><div class="grid md:grid-cols-2 gap-x-6 text-sm">
        <?php $grup = []; foreach ($jadwal as $j) $grup[$j['dokter'] . '|' . $j['poli']][] = mb_substr($j['hari'], 0, 3) . ' ' . jam($j['jam_mulai']) . '–' . jam($j['jam_selesai']);
        foreach ($grup as $k => $hr): [$dk, $pl] = explode('|', $k); ?><div class="py-1.5 border-b border-slate-100"><b><?= aman($dk) ?></b> <span class="muted">· <?= aman($pl) ?></span><div class="text-xs text-slate-600"><?= aman(implode(' · ', $hr)) ?></div></div><?php endforeach; ?></div></div></div>
  </div>

<?php elseif ($tab === 'riwayat'): ?>
  <div class="card mb-4"><div class="card-head"><h3>Riwayat Kunjungan</h3></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Tanggal</th><th>Layanan</th><th>Dokter</th><th>Diagnosis</th><th>Rencana / Tindak lanjut</th></tr></thead><tbody>
    <?php if (!$kunj): ?><tr><td colspan="5" class="empty">Belum ada riwayat.</td></tr><?php endif; ?>
    <?php foreach ($kunj as $k): ?><tr><td><?= tgl($k['tanggal']) ?></td><td><?= ['rawat_jalan' => 'Rawat Jalan', 'igd' => 'IGD', 'rawat_inap' => 'Rawat Inap'][$k['jenis']] ?? '' ?><div class="muted"><?= aman($k['poli']) ?> · <?= aman($k['penjamin']) ?></div></td><td><?= aman($k['dokter']) ?></td>
      <td><?= $k['status_rm'] === 'Final' ? '<span class="mono">' . aman($k['kode_icd10']) . '</span> ' . aman($k['diagnosis']) : '<span class="muted">' . ($k['status_rm'] ? 'Sedang diproses dokter' : '-') . '</span>' ?></td>
      <td class="text-xs"><?= $k['status_rm'] === 'Final' ? aman($k['plan']) . ' · <b>' . aman($k['tindak_lanjut']) . '</b>' : '-' ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="card"><div class="card-head"><b>Obat yang pernah diresepkan</b></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Tanggal</th><th>Obat</th><th>Aturan pakai</th><th>Status</th></tr></thead><tbody>
    <?php if (!$obat): ?><tr><td colspan="4" class="empty">Belum ada resep.</td></tr><?php endif; ?>
    <?php foreach ($obat as $o): ?><tr><td><?= tgl($o['tanggal']) ?></td><td><b><?= aman($o['nama_obat']) ?></b> · <?= (int) $o['jumlah'] ?> <?= aman($o['satuan']) ?></td><td><?= aman($o['dosis']) ?> · <?= aman($o['frekuensi']) ?><div class="muted"><?= aman($o['aturan_pakai']) ?></div></td><td><?= badge($o['status'] === 'Selesai' ? 'Diserahkan' : 'Diproses Farmasi') ?></td></tr><?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'hasil'): ?>
  <div class="notice mb-4">Hanya hasil yang sudah divalidasi dokter spesialis yang ditampilkan. Konsultasikan hasil dengan dokter Anda.</div>
  <div class="grid xl:grid-cols-2 gap-4">
    <div class="card"><div class="card-head"><b>Laboratorium</b></div><div class="card-body space-y-3">
      <?php if (!$lab): ?><p class="muted">Belum ada hasil laboratorium.</p><?php endif; ?>
      <?php foreach ($lab as $l): ?><div class="border rounded-xl p-3"><div class="flex justify-between"><b><?= aman($l['nama']) ?></b><span class="muted"><?= tgl($l['waktu_terbit']) ?></span></div>
        <div class="text-sm mt-1"><?= nl2br(aman($l['hasil'])) ?></div><div class="muted text-xs mt-1">Nilai rujukan: <?= aman($l['nilai_normal']) ?></div><div class="text-xs mt-1"><b>Catatan dokter:</b> <?= aman($l['catatan_validasi']) ?></div></div><?php endforeach; ?></div></div>
    <div class="card"><div class="card-head"><b>Radiologi</b></div><div class="card-body space-y-3">
      <?php if (!$rad): ?><p class="muted">Belum ada hasil radiologi.</p><?php endif; ?>
      <?php foreach ($rad as $r): ?><?= kartu_hasil_rad($r) ?><?php endforeach; ?></div></div>
  </div>

<?php elseif ($tab === 'tagihan'): ?>
  <div class="card"><div class="card-head"><h3>Tagihan Saya</h3></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>No. Tagihan</th><th>Tanggal / Layanan</th><th>Penjamin</th><th class="num">Total</th><th class="num">Bagian Anda</th><th>Status</th></tr></thead><tbody>
    <?php if (!$tagihan): ?><tr><td colspan="6" class="empty">Belum ada tagihan.</td></tr><?php endif; ?>
    <?php foreach ($tagihan as $t): ?><tr><td class="mono"><?= aman($t['no_tagihan']) ?><?= $t['no_kwitansi'] ? '<div class="muted">' . aman($t['no_kwitansi']) . '</div>' : '' ?></td><td><?= tgl($t['tanggal']) ?><div class="muted"><?= aman($t['poli']) ?></div></td><td><?= aman($t['penjamin']) ?></td>
      <td class="num"><?= $t['total'] > 0 ? rupiah($t['total']) : '<span class="muted">sedang dihitung</span>' ?></td><td class="num"><b><?= $t['total'] > 0 ? rupiah($t['porsi_pasien']) : '-' ?></b></td><td><?= badge(in_array($t['status'], ['Dibayar', 'Final'], true) ? 'Lunas' : $t['status']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="card-body muted text-xs">Pembayaran dilakukan di kasir rumah sakit (tunai, debit, QRIS, transfer).</div></div>

<?php else: ?>
  <div class="grid lg:grid-cols-2 gap-4">
    <div class="card"><div class="card-head"><b>Data identitas</b><span class="muted">perubahan melalui pendaftaran</span></div><div class="card-body"><dl class="kv">
      <dt>Nama</dt><dd><?= aman($p['nama_lengkap']) ?></dd><dt>No. RM</dt><dd class="mono"><?= aman($p['nomor_rm']) ?></dd><dt>NIK</dt><dd class="mono"><?= aman(substr($p['nik'], 0, 6) . '******' . substr($p['nik'], -4)) ?></dd>
      <dt>Tanggal lahir</dt><dd><?= tgl($p['tanggal_lahir']) ?> (<?= umur($p['tanggal_lahir']) ?>)</dd><dt>Jenis kelamin</dt><dd><?= jk($p['jenis_kelamin']) ?></dd><dt>No. BPJS</dt><dd><?= aman($p['no_bpjs'] ?: '-') ?></dd><dt>Alergi</dt><dd><?= aman($p['alergi'] ?: 'Tidak ada') ?></dd></dl></div></div>
    <div class="card"><div class="card-head"><b>Kontak</b></div><form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="aksi" value="profil">
      <div class="field"><label>No. HP <span class="req">*</span></label><input name="no_hp" value="<?= aman($p['no_hp']) ?>" data-hp required maxlength="15" placeholder="08xxxxxxxxxx"><div class="muted text-xs mt-1">Format 08… / 628… / +628…, 10–15 digit</div></div>
      <div class="field"><label>Alamat <span class="req">*</span></label><textarea name="alamat" required minlength="10"><?= aman($p['alamat']) ?></textarea></div>
      <button class="btn btn-primary">Simpan Kontak</button> <a href="<?= url('password.php') ?>" class="btn btn-secondary">Ganti Password</a></form></div>
  </div>
<?php endif;
tampil_footer();
