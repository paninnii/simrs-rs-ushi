<?php
/*
 * MODUL 12: SCHEDULING & APPOINTMENT
 *  - Buat appointment: pilih jadwal dokter + tanggal -> slot jam otomatis dari jadwal praktik,
 *    slot yang sudah dipakai pasien lain tidak bisa dipilih.
 *  - Konfirmasi -> Hadir (check-in memberi nomor antrean pada baris kunjungan yang sama) / Reschedule / Batal.
 * Tabel: kunjungan. Janji temu = baris kunjungan dengan kode_booking, sumber Janji Temu / Online,
 * status Menunggu Konfirmasi / Terkonfirmasi / Batal dan belum punya nomor antrean.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('jadwal');
$pdo = getPDO();
$bisa_ubah = boleh('jadwal', true) && role_is('pendaftaran', 'supervisorreg');
$errors = [];

$RS = rs_simpan();
$jadwal_all = $pdo->query("SELECT DISTINCT j.id_pegawai AS id_dokter, j.id_unit AS id_poli, d.nama_lengkap AS dokter, pl.nama AS poli FROM jadwal_pegawai j JOIN pegawai d ON d.id=j.id_pegawai JOIN unit_layanan pl ON pl.id=j.id_unit
                           WHERE j.aktif=1 AND j.jenis='Praktik' AND d.status='Aktif' AND pl.jenis = 'Poliklinik' AND pl.id_rs = " . (int) $RS . " ORDER BY d.nama_lengkap, pl.nama")->fetchAll();
$pilihan_dokter = [];
foreach ($jadwal_all as $j) $pilihan_dokter[$j['id_dokter'] . '-' . $j['id_poli']] = $j['dokter'] . ' · ' . $j['poli'];

if (is_post()) {
    if (!$bisa_ubah) { flash('error', 'Janji temu hanya dapat dibuat/diubah Petugas Registrasi.'); kembali(); }
    $aksi = post('aksi');
    $id = (int) post('id');
    $s = $pdo->prepare("SELECT a.*, a.kode_booking AS kode, a.jam_janji AS jam, a.id_unit AS id_poli, COALESCE(a.keluhan_awal, a.catatan) AS keperluan,
                               CASE WHEN a.no_antrian IS NOT NULL THEN 'Hadir' ELSE a.status END AS status FROM kunjungan a WHERE a.id = ? AND a.kode_booking IS NOT NULL AND a.id_rs = ?");
    $s->execute([$id, $RS]);
    $apt = $s->fetch();

    if ($aksi === 'buat') {
        [$id_dokter, $id_poli] = array_map('intval', explode('-', post('dokter_poli') . '-0'));
        $tanggal = post('tanggal');
        $jam = post('jam');
        $s = $pdo->prepare('SELECT * FROM pasien WHERE nomor_rm = ? OR nik = ?');
        $s->execute([strtoupper(post('pasien')), post('pasien')]);
        $p = $s->fetch();
        if (!$p) $errors['pasien'] = 'Pasien tidak ditemukan. Daftarkan dulu sebagai pasien di menu Pendaftaran.';
        if ($e = v_tanggal($tanggal, 'Tanggal')) $errors['tanggal'] = $e;
        elseif ($tanggal < date('Y-m-d')) $errors['tanggal'] = 'Tanggal janji temu tidak boleh di masa lalu.';
        elseif ($tanggal > date('Y-m-d', strtotime('+90 days'))) $errors['tanggal'] = 'Janji temu maksimal 90 hari ke depan.';
        if (!$errors) {
            [$j, $slot] = slot_tersedia($id_dokter, $id_poli, $tanggal);
            if (!$j) $errors['tanggal'] = 'Dokter tidak praktik pada hari ' . hari_ini($tanggal) . '.';
            elseif (($slot[$jam] ?? '') !== 'Tersedia') $errors['jam'] = 'Slot jam tidak tersedia. Pilih slot lain.';
            else {
                $s = $pdo->prepare("SELECT COUNT(*) FROM kunjungan a WHERE a.id_pasien=? AND a.tanggal=? AND a.id_dokter=? AND a.status <> 'Batal'");
                $s->execute([$p['id'], $tanggal, $id_dokter]);
                if ($s->fetchColumn()) $errors['pasien'] = 'Pasien sudah punya janji temu dengan dokter ini pada tanggal tersebut.';
            }
        }
        if (!$errors) {
            $kode = kode_berikut('kunjungan', 'kode_booking', 'APT');
            $pdo->prepare("INSERT INTO kunjungan (id_rs, kode_booking, id_pasien, tanggal, jam_janji, id_unit, id_dokter, id_jadwal, id_penjamin, no_kartu, keluhan_awal, jenis, sumber, status, dibuat_oleh)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,'rawat_jalan','Janji Temu',?,?)")
                ->execute([$RS, $kode, $p['id'], $tanggal, $jam, $id_poli, $id_dokter, $j['id'], $p['no_bpjs'] ? 2 : 1, $p['no_bpjs'] ?: null, post('catatan') ?: null,
                           post('status') === 'Terkonfirmasi' ? 'Terkonfirmasi' : 'Menunggu Konfirmasi', uid()]);
            audit('Scheduling & Appointment', 'Buat', "$kode {$p['nama_lengkap']} $tanggal $jam");
            flash('sukses', "Janji temu $kode tersimpan: {$p['nama_lengkap']}, " . tgl($tanggal) . " jam $jam.");
            redirect('jadwal/appointment.php?tanggal=' . $tanggal);
        }
    } elseif (!$apt) {
        flash('error', 'Janji temu tidak ditemukan.');
        kembali();
    } elseif ($aksi === 'konfirmasi' && $apt['status'] === 'Menunggu Konfirmasi') {
        $pdo->prepare("UPDATE kunjungan SET status = 'Terkonfirmasi' WHERE id = ?")->execute([$id]);
        audit('Scheduling & Appointment', 'Konfirmasi', $apt['kode']);
        flash('sukses', 'Janji temu ' . $apt['kode'] . ' terkonfirmasi.');
        kembali();
    } elseif ($aksi === 'batal' && in_array($apt['status'], ['Menunggu Konfirmasi', 'Terkonfirmasi'], true)) {
        $pdo->prepare("UPDATE kunjungan SET status = 'Batal', catatan = ? WHERE id = ?")->execute([mb_substr(post('alasan') ?: 'Dibatalkan', 0, 255), $id]);
        audit('Scheduling & Appointment', 'Batal', $apt['kode']);
        flash('sukses', 'Janji temu ' . $apt['kode'] . ' dibatalkan, slot kembali tersedia.');
        kembali();
    } elseif ($aksi === 'reschedule' && in_array($apt['status'], ['Menunggu Konfirmasi', 'Terkonfirmasi'], true)) {
        $tanggal = post('tanggal');
        $jam = post('jam');
        [$j, $slot] = $tanggal >= date('Y-m-d') ? slot_tersedia((int) $apt['id_dokter'], (int) $apt['id_poli'], $tanggal, $id) : [null, []];
        if (!$j) flash('error', 'Tanggal tidak valid atau dokter tidak praktik pada hari ' . hari_ini($tanggal ?: 'now') . '.');
        elseif (($slot[$jam] ?? '') !== 'Tersedia') flash('error', "Slot $jam tidak tersedia pada " . tgl($tanggal) . '. Slot tersedia: ' . implode(', ', array_keys(array_filter($slot, fn($x) => $x === 'Tersedia'))));
        else {
            $pdo->prepare('UPDATE kunjungan SET tanggal = ?, jam_janji = ?, id_jadwal = ? WHERE id = ?')->execute([$tanggal, $jam, $j['id'], $id]);
            audit('Scheduling & Appointment', 'Reschedule', "{$apt['kode']} -> $tanggal $jam");
            flash('sukses', "Janji temu {$apt['kode']} dipindah ke " . tgl($tanggal) . " jam $jam.");
        }
        kembali();
    } elseif ($aksi === 'hadir' && in_array($apt['status'], ['Menunggu Konfirmasi', 'Terkonfirmasi'], true)) {
        if ($apt['tanggal'] !== date('Y-m-d')) { flash('error', 'Konfirmasi hadir hanya bisa pada tanggal janji temu (' . tgl($apt['tanggal']) . ').'); kembali(); }
        $s = $pdo->prepare('SELECT * FROM pasien WHERE id = ?');
        $s->execute([$apt['id_pasien']]);
        $p = $s->fetch();
        [$e, $no_kartu] = cek_penjamin((int) post('id_penjamin'), post('no_kartu'), $p['no_bpjs']);
        if ($e) { flash('error', 'Check-in gagal: ' . $e); kembali(); }
        try {
            $pdo->beginTransaction();
            [$id_k, $no] = buat_kunjungan(['id_pasien' => $p['id'], 'id_unit' => $apt['id_poli'], 'id_dokter' => $apt['id_dokter'], 'id_penjamin' => (int) post('id_penjamin'),
                                          'no_kartu' => $no_kartu, 'keluhan' => null, 'id_kunjungan_janji' => $id]);
            $pdo->commit();
            audit('Scheduling & Appointment', 'Hadir', "{$apt['kode']} -> kunjungan #$id_k ($no)");
            flash('sukses', "Pasien hadir. Kunjungan dibuat dengan nomor antrean $no dan diteruskan ke antrean poli.");
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('error', 'Check-in gagal: ' . $ex->getMessage());
        }
        kembali();
    } else {
        flash('error', 'Aksi tidak sesuai dengan status janji temu.');
        kembali();
    }
}

// ---------- data tampilan ----------
$tanggal = get('tanggal', date('Y-m-d'));
$sql = "SELECT a.*, a.kode_booking AS kode, a.jam_janji AS jam, COALESCE(a.keluhan_awal, a.catatan) AS catatan, CASE WHEN a.no_antrian IS NOT NULL THEN 'Hadir' ELSE a.status END AS status,
               p.nama_lengkap, p.nomor_rm, p.no_bpjs, d.nama_lengkap AS dokter, pl.nama AS poli
        FROM kunjungan a JOIN pasien p ON p.id = a.id_pasien JOIN pegawai d ON d.id = a.id_dokter JOIN unit_layanan pl ON pl.id = a.id_unit
        WHERE a.tanggal = ? AND a.kode_booking IS NOT NULL" . (role_is('dokter') ? '' : ' AND a.id_rs = ' . (int) $RS);
$par = [$tanggal];
if (role_is('dokter')) { $sql .= ' AND a.id_dokter = ?'; $par[] = id_pegawai(); }
$s = $pdo->prepare($sql . ' ORDER BY a.jam_janji');
$s->execute($par);
$apts = $s->fetchAll();
$s = $pdo->prepare("SELECT a.tanggal, COUNT(*) n FROM kunjungan a WHERE a.kode_booking IS NOT NULL AND a.tanggal >= CURDATE() AND a.status <> 'Batal'" . (role_is('dokter') ? ' AND a.id_dokter = ' . id_pegawai() : ' AND a.id_rs = ' . (int) $RS) . ' GROUP BY a.tanggal ORDER BY a.tanggal LIMIT 10');
$s->execute();
$mendatang = $s->fetchAll();
$penjamin = $pdo->query('SELECT id, nama FROM penjamin WHERE aktif=1 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$c = fn($st) => count(array_filter($apts, fn($a) => $a['status'] === $st));

// form buat (2 langkah: pilih dokter+tanggal -> pilih slot)
$form = $bisa_ubah && (get('form') === 'baru' || $errors);
$f_dp = $_POST['dokter_poli'] ?? get('dokter_poli');
$f_tgl = $_POST['tanggal'] ?? get('tgl', date('Y-m-d'));
$slot = [];
$jadwal_pilih = null;
if ($form && $f_dp !== '') {
    [$a, $b] = array_map('intval', explode('-', $f_dp . '-0'));
    [$jadwal_pilih, $slot] = slot_tersedia($a, $b, $f_tgl);
}

tampil_header('Janji Temu', role_is('dokter') ? 'Janji temu pasien Anda.' : 'Janji temu pasien sesuai jadwal praktik dokter · ' . nama_cabang($RS) . '.', $bisa_ubah ? '<a href="?form=baru#form" class="btn btn-primary">+ Buat Janji Temu</a>' : '');
echo kartu([['Janji temu ' . tgl($tanggal), count($apts), 'teal'], ['Menunggu Konfirmasi', $c('Menunggu Konfirmasi'), 'amber'], ['Terkonfirmasi', $c('Terkonfirmasi'), 'purple'], ['Hadir', $c('Hadir'), 'green'], ['Dibatalkan', $c('Batal'), 'red']]);
?>
<?php if ($form): ?>
<div class="card mb-4" id="form"><div class="card-head"><h3>Buat Janji Temu</h3></div><div class="card-body">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="buat">
    <div class="form-grid">
      <div class="field"><label for="apDokter">1. Dokter &amp; poli <span class="req">*</span></label><select id="apDokter" name="dokter_poli" required><?= opsi($pilihan_dokter, $f_dp, '-- Pilih dokter --') ?></select></div>
      <div class="field"><label for="apTanggal">2. Tanggal <span class="req">*</span></label><input id="apTanggal" type="date" name="tanggal" value="<?= aman($f_tgl) ?>" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+90 days')) ?>" required></div>
    </div>
    <div class="field"><span class="lbl">3. Pilih jam <span class="req">*</span></span>
      <div data-slot-picker data-url="<?= url('jadwal/slot.php') ?>" data-dokter="#apDokter" data-tanggal="#apTanggal" data-terpilih="<?= aman($_POST['jam'] ?? '') ?>"><p class="slot-msg">Pilih dokter dan tanggal untuk melihat jam yang tersedia.</p></div><?= err($errors, 'jam') ?></div>
    <div class="form-grid">
      <div class="field"><label>Pasien (No. RM atau NIK) <span class="req">*</span></label><input name="pasien" value="<?= old('pasien', get('pasien')) ?>" class="<?= err_class($errors, 'pasien') ?>" placeholder="contoh: RM-2026-000011" required><?= err($errors, 'pasien') ?></div>
      <div class="field"><label>Status awal</label><select name="status"><?= opsi(['Menunggu Konfirmasi' => 'Menunggu Konfirmasi', 'Terkonfirmasi' => 'Terkonfirmasi'], $_POST['status'] ?? '') ?></select></div>
      <div class="field"><label>Keperluan</label><input name="catatan" maxlength="255" value="<?= old('catatan') ?>" placeholder="contoh: Kontrol, konsultasi hasil lab"></div>
    </div>
    <?= err($errors, 'tanggal') ?>
    <div class="flex gap-2"><button class="btn btn-primary">Simpan Janji Temu</button><a href="appointment.php" class="btn btn-secondary">Batal</a></div>
  </form>
</div></div>
<?php endif; ?>

<div class="grid xl:grid-cols-4 gap-4">
<div class="card xl:col-span-3"><div class="card-head"><h3>Janji Temu <?= tgl($tanggal) ?> (<?= hari_ini($tanggal) ?>)</h3>
  <form method="get" class="flex gap-2"><input type="date" name="tanggal" value="<?= aman($tanggal) ?>" class="input" style="width:auto" onchange="this.form.submit()"><a href="?tanggal=<?= date('Y-m-d') ?>" class="btn btn-secondary">Hari ini</a></form></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Jam</th><th>Kode</th><th>Pasien</th><th>Dokter / Poli</th><th>Status</th><?php if ($bisa_ubah): ?><th class="num">Tindakan</th><?php endif; ?></tr></thead><tbody>
  <?php if (!$apts): ?><tr><td colspan="6" class="empty">Tidak ada janji temu pada tanggal ini.</td></tr><?php endif; ?>
  <?php foreach ($apts as $a): $aktif = in_array($a['status'], ['Menunggu Konfirmasi', 'Terkonfirmasi'], true); ?>
    <tr><td class="font-bold text-[#004c6d]"><?= jam($a['jam']) ?></td><td class="mono"><?= aman($a['kode']) ?></td>
      <td><b><?= aman($a['nama_lengkap']) ?></b><div class="muted"><?= aman($a['nomor_rm']) ?><?= $a['catatan'] ? ' · ' . aman($a['catatan']) : '' ?></div></td>
      <td><?= aman($a['dokter']) ?><div class="muted"><?= aman($a['poli']) ?></div></td>
      <td><?= badge($a['status']) ?><?= $a['no_antrian'] ? '<div class="muted">Antrean ' . aman($a['no_antrian']) . '</div>' : '' ?></td>
      <?php if ($bisa_ubah): ?><td class="num" style="min-width:250px">
        <?php if ($a['status'] === 'Menunggu Konfirmasi'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="konfirmasi"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-secondary btn-sm">Konfirmasi</button></form><?php endif; ?>
        <?php if ($aktif && $a['tanggal'] === date('Y-m-d')): ?><button class="btn btn-success btn-sm" data-open="hadir<?= $a['id'] ?>">Konfirmasi Hadir</button>
          <dialog class="modal" id="hadir<?= $a['id'] ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="hadir"><input type="hidden" name="id" value="<?= $a['id'] ?>">
            <div class="modal-head"><h3>Check-in <?= aman($a['nama_lengkap']) ?></h3></div><div class="modal-body text-left">
              <div class="field"><label>Penjamin</label><select name="id_penjamin"><?= opsi($penjamin, $a['no_bpjs'] ? 2 : 1) ?></select></div>
              <div class="field"><label>No. kartu (BPJS otomatis dari data pasien bila kosong)</label><input name="no_kartu" maxlength="30"></div></div>
            <div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Batal</button><button class="btn btn-success">Check-in &amp; Buat Antrean</button></div></form></dialog><?php endif; ?>
        <?php if ($aktif): ?><button class="btn btn-secondary btn-sm" data-open="rs<?= $a['id'] ?>">Reschedule / Batal</button>
          <dialog class="modal" id="rs<?= $a['id'] ?>"><div class="modal-head"><h3>Reschedule / Batal <?= aman($a['kode']) ?></h3></div>
            <form method="post" class="modal-body text-left"><?= csrf_field() ?><input type="hidden" name="aksi" value="reschedule"><input type="hidden" name="id" value="<?= $a['id'] ?>">
              <div class="form-grid"><div class="field"><label>Tanggal baru</label><input type="date" name="tanggal" min="<?= date('Y-m-d') ?>" value="<?= aman($a['tanggal']) ?>" required></div>
              <div class="field"><label>Jam baru</label><input type="time" name="jam" value="<?= jam($a['jam']) ?>" required></div></div>
              <button class="btn btn-primary">Simpan Reschedule</button></form>
            <form method="post" class="modal-foot"><?= csrf_field() ?><input type="hidden" name="aksi" value="batal"><input type="hidden" name="id" value="<?= $a['id'] ?>">
              <input name="alasan" class="input" placeholder="Alasan pembatalan" maxlength="255"><button type="button" class="btn btn-secondary" data-close>Tutup</button><button class="btn btn-danger">Batalkan Janji Temu</button></form></dialog><?php endif; ?>
      </td><?php endif; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<div class="card"><div class="card-head"><h3>Tanggal Mendatang</h3></div><div class="card-body text-sm">
  <?php if (!$mendatang): ?><p class="muted">Belum ada janji temu.</p><?php endif; ?>
  <?php foreach ($mendatang as $m): ?><a href="?tanggal=<?= $m['tanggal'] ?>" class="flex justify-between py-1.5 border-b border-slate-100 hover:text-[#0b7896]"><span><?= hari_ini($m['tanggal']) ?>, <?= tgl($m['tanggal']) ?></span><b><?= (int) $m['n'] ?></b></a><?php endforeach; ?>
</div></div>
</div>
<?php tampil_footer();
