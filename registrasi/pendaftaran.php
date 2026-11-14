<?php
/*
 * MODUL 2: PATIENT REGISTRATION (PPT Bab 4 / P12 + SOP Pendaftaran)
 *   1. Cari pasien lama (nama / No. RM / NIK) agar tidak terjadi data ganda.
 *   2. Pasien baru: identitas sesuai KTP -> No. RM dibuat otomatis (unik).
 *   3. Pilih tujuan (Rawat Jalan / IGD), poli, dokter yang praktik hari ini, penjamin.
 *   4. Simpan pasien + kunjungan dalam SATU transaksi -> bukti registrasi & nomor antrean.
 * Tabel: pasien (satu No. RM untuk semua cabang) dan kunjungan (id_rs = cabang tempat mendaftar).
 * Pendaftaran untuk tanggal lain disimpan di tabel kunjungan sebagai janji temu (status Terkonfirmasi, belum ada nomor antrean).
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('registrasi');
$pdo = getPDO();
$bisa_ubah = boleh('registrasi', true);
$errors = [];
$mirip = [];
$hari = date('Y-m-d');

$RS = rs_simpan();   // cabang tempat petugas bertugas
$poli_all = $pdo->query("SELECT id, nama, jenis FROM unit_layanan WHERE aktif = 1 AND jenis IN ('Poliklinik','IGD') AND id_rs = " . (int) $RS . ' ORDER BY jenis DESC, nama')->fetchAll();
$id_igd = 0;
foreach ($poli_all as $p) if ($p['jenis'] === 'IGD') $id_igd = (int) $p['id'];
$dokter_per_poli = [];
foreach ($poli_all as $p) $dokter_per_poli[$p['id']] = dokter_praktik((int) $p['id'], $hari);
// dokter praktik per poli per HARI (untuk pendaftaran/janji di tanggal lain)
$dokter_hari = [];
for ($i = 0; $i < 7; $i++) { $tg = date('Y-m-d', strtotime("+$i day")); foreach ($poli_all as $p) $dokter_hari[$p['id']][hari_ini($tg)] = dokter_praktik((int) $p['id'], $tg); }
$maks_tgl = date('Y-m-d', strtotime('+90 days'));
$penjamin = $pdo->query('SELECT id, nama, tipe FROM penjamin WHERE aktif = 1 ORDER BY id')->fetchAll();
$tipe_pj = array_column($penjamin, 'tipe', 'id');

// pasien lama yang dipilih (?pasien=id)
$pasien = null;
if (get('pasien') !== '') {
    $s = $pdo->prepare('SELECT * FROM pasien WHERE id = ?');
    $s->execute([(int) get('pasien')]);
    $pasien = $s->fetch() ?: null;
}

if (is_post()) {
    if (!$bisa_ubah) { flash('error', 'Role Anda hanya dapat melihat data registrasi.'); kembali(); }
    $aksi = post('aksi');

    if ($aksi === 'batal_kunjungan') {
        $s = $pdo->prepare("UPDATE kunjungan SET status = 'Batal' WHERE id = ? AND status = 'Menunggu' AND id_rs = ?");
        $s->execute([(int) post('id'), $RS]);
        if ($s->rowCount()) { audit('Patient Registration', 'Batal Kunjungan', 'Kunjungan #' . post('id')); flash('sukses', 'Kunjungan dibatalkan.'); }
        else flash('error', 'Hanya kunjungan berstatus Menunggu yang bisa dibatalkan.');
        kembali();
    }

    if ($aksi === 'daftar') {
        $baru = post('mode') === 'baru';
        $p = [
            'nama_lengkap' => preg_replace('/\s+/', ' ', post('nama_lengkap')), 'nik' => post('nik'), 'tanggal_lahir' => post('tanggal_lahir'),
            'jenis_kelamin' => post('jenis_kelamin'), 'golongan_darah' => post('golongan_darah', '?'), 'alamat' => post('alamat'),
            'no_hp' => post('no_hp'), 'no_bpjs' => post('no_bpjs'), 'alergi' => post('alergi'),
        ];
        $tujuan = post('tujuan');
        $tanggal = $tujuan === 'rawat_jalan' ? (post('tanggal') ?: date('Y-m-d')) : date('Y-m-d');
        $id_poli = $tujuan === 'igd' ? $id_igd : (int) post('id_poli');
        $id_dokter = (int) post('id_dokter');
        if ($tujuan === 'igd') {   // dokter jaga IGD: yang shift-nya mencakup jam sekarang, dokter umum didahulukan (spesialis = cadangan/konsulen)
            $s = $pdo->prepare("SELECT d.id FROM jadwal_pegawai j JOIN pegawai d ON d.id = j.id_pegawai
                                WHERE j.id_unit = ? AND j.hari = ? AND j.jenis = 'Praktik' AND j.aktif = 1 AND d.status = 'Aktif'
                                ORDER BY (? BETWEEN j.jam_mulai AND j.jam_selesai) DESC, (d.spesialisasi = 'Umum') DESC, d.nama_lengkap LIMIT 1");
            $s->execute([$id_igd, hari_ini(), date('H:i:s')]);
            $id_dokter = (int) $s->fetchColumn();
        }
        $id_penjamin = (int) post('id_penjamin');

        if ($baru) {
            if ($e = v_nama($p['nama_lengkap'])) $errors['nama_lengkap'] = $e;
            if ($e = v_nik($p['nik'])) $errors['nik'] = $e;
            if ($e = v_tgl_lahir($p['tanggal_lahir'])) $errors['tanggal_lahir'] = $e;
            if (!in_array($p['jenis_kelamin'], ['L', 'P'], true)) $errors['jenis_kelamin'] = 'Pilih jenis kelamin.';
            if (!in_array($p['golongan_darah'], ['A', 'B', 'AB', 'O', '?'], true)) $errors['golongan_darah'] = 'Golongan darah tidak valid.';
            if ($e = v_wajib($p['alamat'], 'Alamat')) $errors['alamat'] = $e;
            elseif (mb_strlen($p['alamat']) < 10) $errors['alamat'] = 'Alamat terlalu singkat (minimal 10 karakter: jalan, nomor, kota).';
            if ($e = v_hp($p['no_hp'])) $errors['no_hp'] = $e;
            if ($e = v_bpjs($p['no_bpjs'])) $errors['no_bpjs'] = $e;
            if (mb_strlen($p['alergi']) > 255) $errors['alergi'] = 'Maksimal 255 karakter.';
            // NIK & No. BPJS harus unik -> satu orang hanya punya satu No. RM
            if (!isset($errors['nik'])) {
                $s = $pdo->prepare('SELECT id, nomor_rm, nama_lengkap FROM pasien WHERE nik = ?');
                $s->execute([$p['nik']]);
                if ($ada = $s->fetch()) $errors['nik'] = "NIK ini sudah terdaftar atas nama {$ada['nama_lengkap']} ({$ada['nomor_rm']}). Gunakan menu Pasien Lama, jangan membuat No. RM baru.";
            }
            if ($p['no_bpjs'] !== '' && !isset($errors['no_bpjs'])) {
                $s = $pdo->prepare('SELECT nomor_rm, nama_lengkap FROM pasien WHERE no_bpjs = ?');
                $s->execute([$p['no_bpjs']]);
                if ($ada = $s->fetch()) $errors['no_bpjs'] = "No. BPJS sudah dipakai {$ada['nama_lengkap']} ({$ada['nomor_rm']}).";
            }
            // nama mirip: tetap boleh (orang berbeda), tapi petugas wajib mengecek dulu
            if (!$errors) {
                $s = $pdo->prepare('SELECT nomor_rm, nama_lengkap, nik, tanggal_lahir, alamat FROM pasien WHERE LOWER(nama_lengkap) = LOWER(?) OR (LOWER(nama_lengkap) LIKE LOWER(?) AND tanggal_lahir = ?)');
                $s->execute([$p['nama_lengkap'], '%' . $p['nama_lengkap'] . '%', $p['tanggal_lahir']]);
                $mirip = $s->fetchAll();
                if ($mirip && !isset($_POST['konfirmasi_beda'])) $errors['_mirip'] = 'Ditemukan pasien dengan nama mirip. Pastikan ini orang yang berbeda (cek NIK & tanggal lahir), lalu centang konfirmasi.';
            }
        } elseif (!$pasien) {
            $errors['_db'] = 'Pasien lama belum dipilih.';
        }

        if (!in_array($tujuan, ['rawat_jalan', 'igd'], true)) $errors['tujuan'] = 'Pilih tujuan pelayanan.';
        elseif ($tujuan === 'rawat_jalan') {
            if (v_tanggal($tanggal) !== null || $tanggal < date('Y-m-d') || $tanggal > $maks_tgl) $errors['tanggal'] = 'Tanggal kunjungan antara hari ini sampai 90 hari ke depan.';
            elseif (!isset($dokter_per_poli[$id_poli]) || $id_poli === $id_igd) $errors['id_poli'] = 'Pilih poliklinik.';
            elseif (!jadwal_praktik($id_dokter, $id_poli, $tanggal)) $errors['id_dokter'] = 'Dokter tidak praktik di poli ini pada hari ' . hari_ini($tanggal) . '. Pilih dokter lain atau tanggal lain.';
        } elseif (!$id_dokter) $errors['tujuan'] = 'Belum ada dokter jaga IGD hari ini. Atur di Jadwal Praktik Dokter.';
        [$e_pj, $no_kartu] = cek_penjamin($id_penjamin, post('no_kartu'), $baru ? $p['no_bpjs'] : ($pasien['no_bpjs'] ?? ''));
        if ($e_pj) $errors[($tipe_pj[$id_penjamin] ?? '') === 'BPJS' && $baru && post('no_kartu') === '' ? 'no_bpjs' : 'no_kartu'] = $e_pj;
        $jam = $tujuan === 'rawat_jalan' ? (string) post('jam') : '';
        $jadwal_jam = null;
        if ($tujuan === 'rawat_jalan' && !isset($errors['tanggal']) && !isset($errors['id_dokter']) && !isset($errors['id_poli'])) {
            [$jadwal_jam, $slot_tgl] = slot_tersedia($id_dokter, $id_poli, $tanggal);
            if (!$slot_tgl) $errors['jam'] = ($tanggal === date('Y-m-d') ? 'Jam praktik dokter hari ini sudah selesai.' : 'Tidak ada jam praktik tersisa pada tanggal ini.') . ' Pilih tanggal lain.';
            elseif ($jam === '') $errors['jam'] = 'Pilih jam kunjungan.';
            elseif (($slot_tgl[$jam] ?? '') !== 'Tersedia') $errors['jam'] = "Jam $jam sudah dipilih pasien lain. Pilih jam lain.";
        }
        $keluhan = post('keluhan');
        if ($tujuan === 'igd' && $keluhan === '') $errors['keluhan'] = 'Keluhan utama wajib diisi untuk pasien IGD.';

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($baru) {
                    $p['no_bpjs'] = $p['no_bpjs'] ?: null;
                    $p['alergi'] = $p['alergi'] ?: null;
                    for ($i = 0; ; $i++) {
                        $p['nomor_rm'] = kode_berikut('pasien', 'nomor_rm', 'RM');
                        try {
                            $pdo->prepare('INSERT INTO pasien (nomor_rm, nama_lengkap, nik, tanggal_lahir, jenis_kelamin, golongan_darah, alamat, no_hp, no_bpjs, alergi, id_rs_daftar, dibuat_oleh) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                                ->execute([$p['nomor_rm'], $p['nama_lengkap'], $p['nik'], $p['tanggal_lahir'], $p['jenis_kelamin'], $p['golongan_darah'], $p['alamat'], $p['no_hp'], $p['no_bpjs'], $p['alergi'], $RS, uid()]);
                            break;
                        } catch (PDOException $e) {
                            if (!is_duplikat($e) || $i > 3 || !str_contains($e->getMessage(), 'nomor_rm')) throw $e;
                        }
                    }
                    $id_pasien = (int) $pdo->lastInsertId();
                    audit('Patient Registration', 'Pasien Baru', "{$p['nomor_rm']} {$p['nama_lengkap']}");
                } else {
                    $id_pasien = (int) $pasien['id'];
                }
                if ($tujuan === 'rawat_jalan' && $tanggal > date('Y-m-d')) {   // tanggal lain: dibuat sebagai janji temu, kunjungan dibuat saat pasien datang (check-in)
                    $kode_apt = kode_berikut('kunjungan', 'kode_booking', 'APT');
                    $pdo->prepare("INSERT INTO kunjungan (id_rs, kode_booking, id_pasien, tanggal, jam_janji, id_unit, id_dokter, id_jadwal, id_penjamin, no_kartu, keluhan_awal, jenis, sumber, status, catatan, dibuat_oleh)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,'rawat_jalan','Janji Temu','Terkonfirmasi','Dibuat di loket pendaftaran',?)")
                        ->execute([$RS, $kode_apt, $id_pasien, $tanggal, $jam, $id_poli, $id_dokter, $jadwal_jam['id'], $id_penjamin, $no_kartu ?: null, $keluhan ?: null, uid()]);
                    $id_apt = (int) $pdo->lastInsertId();
                    $pdo->commit();
                    audit('Scheduling & Appointment', 'Buat', "$kode_apt " . tgl($tanggal) . " $jam");
                    flash('sukses', "Janji temu $kode_apt tersimpan: " . hari_ini($tanggal) . ', ' . tgl($tanggal) . " jam $jam.");
                    redirect('registrasi/pendaftaran.php?bukti_apt=' . $id_apt);
                }
                // jam kunjungan disimpan di kunjungan.jam_janji agar slot itu tidak bisa dipilih pasien lain
                [$id_k, $no] = buat_kunjungan(['id_pasien' => $id_pasien, 'id_unit' => $id_poli, 'id_dokter' => $id_dokter, 'id_penjamin' => $id_penjamin,
                                              'no_kartu' => $no_kartu, 'keluhan' => $keluhan, 'jam' => $jam !== '' ? $jam : null]);
                $pdo->commit();
                audit('Patient Registration', 'Daftar Kunjungan', "Kunjungan #$id_k antrean $no" . ($jam !== '' ? " jam $jam" : ''));
                flash('sukses', 'Pendaftaran berhasil disimpan ke database. Nomor antrean ' . $no . ($jam !== '' ? ", jam $jam" : '') . '.');
                redirect('registrasi/pendaftaran.php?bukti=' . $id_k);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors['_db'] = $e instanceof PDOException
                    ? (is_duplikat($e) ? 'Data kembar ditolak database (NIK / No. BPJS / No. RM sudah ada).' : 'Database menolak data: ' . $e->getMessage())
                    : $e->getMessage();
            }
        }
    }
}

// ---------- BUKTI JANJI TEMU ----------
if (get('bukti_apt') !== '') {
    $s = $pdo->prepare('SELECT a.*, a.kode_booking AS kode, a.jam_janji AS jam, COALESCE(a.keluhan_awal, a.catatan) AS catatan, p.nama_lengkap, p.nomor_rm, p.tanggal_lahir, d.nama_lengkap AS dokter, u.nama AS poli
        FROM kunjungan a JOIN pasien p ON p.id = a.id_pasien JOIN pegawai d ON d.id = a.id_dokter JOIN unit_layanan u ON u.id = a.id_unit WHERE a.id = ? AND a.kode_booking IS NOT NULL');
    $s->execute([(int) get('bukti_apt')]);
    $a = $s->fetch();
    if (!$a) redirect('registrasi/pendaftaran.php');
    tampil_header('Bukti Janji Temu', 'Berikan bukti ini kepada pasien.', '<button onclick="window.print()" class="btn btn-secondary">Cetak Bukti</button> <a href="pendaftaran.php" class="btn btn-primary">Pendaftaran Berikutnya</a>');
    ?>
    <div class="card max-w-lg mx-auto print-area"><div class="card-body text-center">
      <img src="<?= url('assets/logo.png') ?>" class="w-14 h-14 mx-auto" alt="">
      <b class="block text-[#004C6D] mt-1"><?= aman(nama_cabang((int) $a['id_rs'])) ?></b><span class="muted"><?= aman(konfig('alamat', (int) $a['id_rs'])) ?></span>
      <div class="my-4 border-t border-dashed border-slate-300"></div>
      <div class="muted">JANJI TEMU</div>
      <div class="text-3xl font-bold text-[#004C6D] my-1"><?= hari_ini($a['tanggal']) ?>, <?= tgl($a['tanggal']) ?></div>
      <div class="text-xl font-semibold">Jam <?= jam($a['jam']) ?></div>
      <div class="font-semibold mt-2"><?= aman($a['poli']) ?></div><div class="muted"><?= aman($a['dokter']) ?></div>
      <div class="my-4 border-t border-dashed border-slate-300"></div>
      <dl class="kv text-left"><dt>Kode</dt><dd class="mono"><?= aman($a['kode']) ?></dd><dt>No. RM</dt><dd class="mono"><?= aman($a['nomor_rm']) ?></dd><dt>Nama</dt><dd><?= aman($a['nama_lengkap']) ?></dd>
        <dt>Tgl lahir</dt><dd><?= tgl($a['tanggal_lahir']) ?></dd><dt>Keperluan</dt><dd><?= aman($a['catatan']) ?></dd></dl>
      <p class="muted mt-4">Datang 30 menit sebelum jam janji temu dan lapor ke loket pendaftaran dengan membawa KTP dan kartu penjamin. Nomor antrean diberikan saat pasien datang.</p>
    </div></div>
    <?php tampil_footer();
    exit;
}

// ---------- BUKTI REGISTRASI ----------
if (get('bukti') !== '') {
    $k = ambil_kunjungan((int) get('bukti'));
    if (!$k) redirect('registrasi/pendaftaran.php');
    $jam_kunjungan = $k['jam_janji'];
    tampil_header('Bukti Registrasi', 'Berikan bukti ini kepada pasien.', '<button onclick="window.print()" class="btn btn-secondary">Cetak Bukti</button> <a href="pendaftaran.php" class="btn btn-primary">Pendaftaran Berikutnya</a>');
    ?>
    <div class="card max-w-lg mx-auto print-area"><div class="card-body text-center">
      <img src="<?= url('assets/logo.png') ?>" class="w-14 h-14 mx-auto" alt="">
      <b class="block text-[#004c6d] mt-1"><?= aman(nama_cabang((int) $k['id_rs'])) ?></b><span class="muted"><?= aman(konfig('alamat', (int) $k['id_rs'])) ?></span>
      <div class="my-4 border-t border-dashed border-slate-300"></div>
      <div class="muted">NOMOR ANTREAN</div><div class="text-5xl font-extrabold text-[#004c6d] my-1"><?= aman($k['no_antrian']) ?></div>
      <div class="font-bold"><?= aman($k['poli']) ?></div><div class="muted"><?= aman($k['dokter']) ?></div>
      <?php if ($jam_kunjungan): ?><div class="mt-2 inline-block rounded-lg bg-[#EAF3F7] px-3 py-1 font-semibold text-[#004C6D]">Jam kunjungan <?= jam($jam_kunjungan) ?></div><?php endif; ?>
      <div class="my-4 border-t border-dashed border-slate-300"></div>
      <dl class="kv text-left"><dt>No. RM</dt><dd class="mono"><?= aman($k['nomor_rm']) ?></dd><dt>Nama</dt><dd><?= aman($k['nama_lengkap']) ?></dd>
        <dt>Tgl lahir</dt><dd><?= tgl($k['tanggal_lahir']) ?> (<?= umur($k['tanggal_lahir']) ?>)</dd><dt>Penjamin</dt><dd><?= aman($k['penjamin']) ?><?= $k['no_kartu'] ? ' · ' . aman($k['no_kartu']) : '' ?></dd>
        <dt>Status penjamin</dt><dd><?= $k['tipe_penjamin'] === 'Umum' ? 'Pasien umum (bayar mandiri)' : 'Kepesertaan tervalidasi (simulasi) · verifikasi akhir oleh Verifikator' ?></dd>
        <dt>Tanggal</dt><dd><?= tgl_jam($k['waktu_masuk'] ?: $k['created_at']) ?></dd><dt>Petugas</dt><dd><?= aman(user()['nama']) ?></dd></dl>
      <p class="muted mt-4">Silakan menunggu panggilan <?= $k['jenis'] === 'igd' ? 'triase di IGD' : 'pengkajian perawat di depan poli' ?>.</p>
    </div></div>
    <?php tampil_footer();
    exit;
}

// ---------- data tampilan ----------
$cari = get('cari');
$hasil_cari = [];
if ($cari !== '') {
    $s = $pdo->prepare('SELECT * FROM pasien WHERE nama_lengkap LIKE ? OR nomor_rm LIKE ? OR nik LIKE ? OR no_bpjs LIKE ? ORDER BY nama_lengkap LIMIT 20');
    $s->execute(["%$cari%", "%$cari%", "%$cari%", "%$cari%"]);
    $hasil_cari = $s->fetchAll();
}
$f_poli = get('poli');
$sql = "SELECT k.*, p.nomor_rm, p.nama_lengkap, pl.nama AS poli, d.nama_lengkap AS dokter, pj.nama AS penjamin
        FROM kunjungan k JOIN pasien p ON p.id = k.id_pasien JOIN unit_layanan pl ON pl.id = k.id_unit JOIN pegawai d ON d.id = k.id_dokter JOIN penjamin pj ON pj.id = k.id_penjamin
        WHERE k.tanggal = ? AND k.no_antrian IS NOT NULL AND k.id_rs = ?";
$par = [$hari, $RS];
if ($f_poli !== '') { $sql .= ' AND k.id_unit = ?'; $par[] = $f_poli; }
$s = $pdo->prepare($sql . ' ORDER BY k.id DESC');
$s->execute($par);
$antrian = $s->fetchAll();
$hitung = fn($st) => count(array_filter($antrian, fn($a) => $a['status'] === $st));
$mode = $pasien ? 'lama' : 'baru';
$tujuan = $_POST['tujuan'] ?? 'rawat_jalan';

tampil_header('Pendaftaran Pasien', 'Identifikasi pasien baru/lama, penjamin, poli tujuan, dan nomor antrean · ' . nama_cabang($RS) . '. No. RM berlaku di semua cabang.');
echo kartu([['Kunjungan Hari Ini', count($antrian), 'teal'], ['Menunggu', $hitung('Menunggu'), 'amber'], ['Dalam Pelayanan', $hitung('Dipanggil'), 'purple'], ['Selesai', $hitung('Selesai'), 'green'],
            ['Total Pasien Terdaftar', (int) $pdo->query('SELECT COUNT(*) FROM pasien')->fetchColumn(), 'teal']]);
?>
<div class="grid xl:grid-cols-5 gap-4">
<div class="xl:col-span-3 space-y-4">
  <div class="card"><div class="card-head"><h3>1. Cari Pasien Lama</h3><span class="muted">Wajib dicek dulu agar tidak terjadi No. RM ganda</span></div>
    <div class="card-body"><form method="get" class="flex gap-2"><input name="cari" value="<?= aman($cari) ?>" class="input" placeholder="Nama, No. RM, NIK, atau No. BPJS"><button class="btn btn-primary">Cari</button><?php if ($cari !== '' || $pasien): ?><a href="pendaftaran.php" class="btn btn-secondary">Reset</a><?php endif; ?></form>
    <?php if ($cari !== ''): ?>
      <div class="tbl-wrap mt-3"><table class="tbl"><thead><tr><th>Pasien</th><th>NIK</th><th>Tgl Lahir</th><th></th></tr></thead><tbody>
      <?php if (!$hasil_cari): ?><tr><td colspan="4" class="empty">Tidak ditemukan. Daftarkan sebagai pasien baru di bawah.</td></tr><?php endif; ?>
      <?php foreach ($hasil_cari as $h): ?><tr><td><b class="pname"><?= aman($h['nama_lengkap']) ?></b><div class="muted mono"><?= aman($h['nomor_rm']) ?></div></td><td class="mono"><?= aman($h['nik']) ?></td><td><?= tgl($h['tanggal_lahir']) ?><div class="muted"><?= jk($h['jenis_kelamin']) ?>, <?= umur($h['tanggal_lahir']) ?></div></td>
        <td class="num"><a href="?pasien=<?= $h['id'] ?>#form" class="btn btn-primary btn-sm">Daftarkan Kunjungan</a></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?></div></div>

  <?php if ($bisa_ubah): ?>
  <div class="card" id="form"><div class="card-head"><h3>2. <?= $mode === 'lama' ? 'Kunjungan Pasien Lama' : 'Pendaftaran Pasien Baru' ?></h3><?php if ($mode === 'lama'): ?><a href="pendaftaran.php" class="btn btn-secondary btn-sm">Pasien baru</a><?php endif; ?></div>
  <form method="post" class="card-body" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="daftar"><input type="hidden" name="mode" value="<?= $mode ?>">
    <?php if (isset($errors['_db'])): ?><div class="alert alert-error"><?= aman($errors['_db']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['_db']) && !isset($errors['_mirip'])): ?><div class="alert alert-error">Terdapat <?= count($errors) ?> isian yang perlu diperbaiki. Data belum disimpan.</div><?php endif; ?>
    <?php if ($mirip): ?>
      <div class="alert alert-warning"><b><?= aman($errors['_mirip'] ?? 'Pasien dengan nama mirip:') ?></b>
        <table class="tbl mt-2 bg-white rounded"><tbody><?php foreach ($mirip as $m): ?><tr><td><?= aman($m['nama_lengkap']) ?></td><td class="mono"><?= aman($m['nomor_rm']) ?></td><td class="mono"><?= aman($m['nik']) ?></td><td><?= tgl($m['tanggal_lahir']) ?></td></tr><?php endforeach; ?></tbody></table>
        <label class="inline-flex items-center gap-2 mt-2 font-semibold"><input type="checkbox" name="konfirmasi_beda" value="1"> Saya sudah cek: pasien ini ORANG BERBEDA (NIK berbeda), buat No. RM baru.</label></div>
    <?php endif; ?>

    <?php if ($mode === 'lama'): ?>
      <?= kartu_pasien($pasien, 'NIK ' . aman($pasien['nik']) . ' · HP ' . aman($pasien['no_hp']) . ($pasien['no_bpjs'] ? ' · BPJS ' . aman($pasien['no_bpjs']) : '')) ?>
    <?php else: ?>
      <div class="section-label">Identitas pasien (sesuai KTP)</div>
      <div class="form-grid">
        <div class="field"><label>NIK <span class="req">*</span></label><input name="nik" data-digits="16" value="<?= old('nik') ?>" class="<?= err_class($errors, 'nik') ?>" placeholder="16 digit angka" required><?= err($errors, 'nik') ?></div>
        <div class="field"><label>Nama lengkap <span class="req">*</span></label><input name="nama_lengkap" value="<?= old('nama_lengkap') ?>" maxlength="150" class="<?= err_class($errors, 'nama_lengkap') ?>" required><?= err($errors, 'nama_lengkap') ?></div>
        <div class="field"><label>Tanggal lahir <span class="req">*</span></label><input type="date" name="tanggal_lahir" value="<?= old('tanggal_lahir') ?>" max="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d', strtotime('-120 years')) ?>" class="<?= err_class($errors, 'tanggal_lahir') ?>" required><?= err($errors, 'tanggal_lahir') ?></div>
        <div class="field"><label>Jenis kelamin <span class="req">*</span></label><select name="jenis_kelamin" class="<?= err_class($errors, 'jenis_kelamin') ?>" required><?= opsi(['L' => 'Laki-laki', 'P' => 'Perempuan'], $_POST['jenis_kelamin'] ?? '', '-- Pilih --') ?></select><?= err($errors, 'jenis_kelamin') ?></div>
        <div class="field"><label>Golongan darah</label><select name="golongan_darah"><?= opsi(['?' => 'Tidak diketahui', 'A' => 'A', 'B' => 'B', 'AB' => 'AB', 'O' => 'O'], $_POST['golongan_darah'] ?? '?') ?></select></div>
        <div class="field"><label>No. HP <span class="req">*</span></label><input name="no_hp" data-hp value="<?= old('no_hp') ?>" class="<?= err_class($errors, 'no_hp') ?>" placeholder="08xxxxxxxxxx" required><?= err($errors, 'no_hp') ?></div>
        <div class="field" style="grid-column:1/-1"><label>Alamat lengkap <span class="req">*</span></label><textarea name="alamat" maxlength="500" class="<?= err_class($errors, 'alamat') ?>" placeholder="Jl. Nama Jalan No., RT/RW, Kelurahan, Kecamatan, Kota" required><?= old('alamat') ?></textarea><?= err($errors, 'alamat') ?></div>
        <div class="field"><label>No. kartu BPJS</label><input name="no_bpjs" data-digits="13" value="<?= old('no_bpjs') ?>" class="<?= err_class($errors, 'no_bpjs') ?>" placeholder="13 digit (jika peserta BPJS)"><?= err($errors, 'no_bpjs') ?></div>
        <div class="field"><label>Riwayat alergi</label><input name="alergi" value="<?= old('alergi') ?>" maxlength="255" class="<?= err_class($errors, 'alergi') ?>" placeholder="contoh: Penisilin, Ibuprofen (kosongkan bila tidak ada)"><?= err($errors, 'alergi') ?></div>
      </div>
    <?php endif; ?>

    <div class="section-label">Tujuan pelayanan</div>
    <div class="form-grid">
      <div class="field"><label>Tujuan <span class="req">*</span></label><select name="tujuan" id="tujuan" class="<?= err_class($errors, 'tujuan') ?>"><?= opsi(['rawat_jalan' => 'Rawat Jalan (Poliklinik)', 'igd' => 'IGD (Gawat Darurat)'], $tujuan) ?></select><?= err($errors, 'tujuan') ?></div>
      <div class="field rj"><label>Poliklinik <span class="req">*</span></label><select name="id_poli" id="id_poli" class="<?= err_class($errors, 'id_poli') ?>"><?= opsi(array_column(array_filter($poli_all, fn($x) => $x['jenis'] !== 'IGD'), 'nama', 'id'), $_POST['id_poli'] ?? '', '-- Pilih poli --') ?></select><?= err($errors, 'id_poli') ?></div>
      <div class="field rj"><label for="regTanggal">Tanggal kunjungan <span class="req">*</span></label><input type="date" id="regTanggal" name="tanggal" value="<?= aman($_POST['tanggal'] ?? date('Y-m-d')) ?>" min="<?= date('Y-m-d') ?>" max="<?= $maks_tgl ?>" class="<?= err_class($errors, 'tanggal') ?>"><?= err($errors, 'tanggal') ?><p class="hint">Hari ini = langsung dapat nomor antrean. Tanggal lain = dibuat sebagai janji temu.</p></div>
      <div class="field rj"><label>Dokter (praktik hari <span id="lblHari"><?= hari_ini() ?></span>) <span class="req">*</span></label><select name="id_dokter" id="id_dokter" class="<?= err_class($errors, 'id_dokter') ?>"></select><?= err($errors, 'id_dokter') ?></div>
      <div class="field rj" style="grid-column:1/-1"><span class="lbl">Jam kunjungan <span class="req">*</span></span>
        <input type="hidden" id="regDokterPoli">
        <div data-slot-picker data-url="<?= url('jadwal/slot.php') ?>" data-dokter="#regDokterPoli" data-tanggal="#regTanggal" data-terpilih="<?= aman($_POST['jam'] ?? '') ?>"><p class="slot-msg">Pilih tanggal, poliklinik, dan dokter untuk melihat jam yang tersedia.</p></div>
        <?= err($errors, 'jam') ?></div>
      <div class="field"><label>Penjamin <span class="req">*</span></label><select name="id_penjamin" id="id_penjamin"><?= opsi(array_column($penjamin, 'nama', 'id'), $_POST['id_penjamin'] ?? 1) ?></select></div>
      <div class="field" id="box_kartu"><label id="lbl_kartu">No. kartu penjamin</label><input name="no_kartu" value="<?= old('no_kartu') ?>" maxlength="30" class="<?= err_class($errors, 'no_kartu') ?>" placeholder="BPJS: kosongkan untuk memakai no. BPJS pasien"><?= err($errors, 'no_kartu') ?></div>
      <div class="field" style="grid-column:1/-1"><label>Keluhan awal <span class="req igd-only">*</span></label><input name="keluhan" value="<?= old('keluhan') ?>" maxlength="255" class="<?= err_class($errors, 'keluhan') ?>" placeholder="contoh: Demam 3 hari"><?= err($errors, 'keluhan') ?></div>
    </div>
    <div class="flex gap-2 mt-2"><button class="btn btn-success" style="padding:10px 18px">Simpan &amp; Buat Bukti Registrasi</button><button type="reset" class="btn btn-secondary">Reset</button></div>
  </form></div>
  <?php endif; ?>
</div>

<div class="xl:col-span-2" id="antrean"><div class="card"><div class="card-head"><h3>Antrean Hari Ini</h3>
  <form method="get"><select name="poli" class="input" style="width:auto" onchange="this.form.submit()"><?= opsi(array_column($poli_all, 'nama', 'id'), $f_poli, 'Semua poli') ?></select></form></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>No.</th><th>Pasien</th><th>Status</th><th></th></tr></thead><tbody>
  <?php if (!$antrian): ?><tr><td colspan="4" class="empty">Belum ada kunjungan hari ini.</td></tr><?php endif; ?>
  <?php foreach ($antrian as $a): ?>
    <tr><td class="mono font-bold text-[#004c6d]"><?= aman($a['no_antrian']) ?></td>
      <td><b><?= aman($a['nama_lengkap']) ?></b><div class="muted"><?= aman($a['nomor_rm']) ?> · <?= aman($a['poli']) ?></div><div class="muted"><?= aman($a['dokter']) ?> · <?= aman($a['penjamin']) ?></div></td>
      <td><?= badge($a['status'] === 'Dipanggil' ? 'Dalam Pelayanan' : $a['status']) ?></td>
      <td class="num"><a class="btn btn-secondary btn-sm" href="?bukti=<?= $a['id'] ?>">Bukti</a>
        <?php if ($bisa_ubah && $a['status'] === 'Menunggu'): ?><form method="post" class="inline" data-confirm="Batalkan kunjungan <?= aman($a['nama_lengkap']) ?>?"><?= csrf_field() ?><input type="hidden" name="aksi" value="batal_kunjungan"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-danger btn-sm">Batal</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div></div>
</div>
<script>
const DOKTER_HARI = <?= json_encode($dokter_hari) ?>, NAMA_HARI = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'], tglEl = document.getElementById('regTanggal');
function hariDipilih() { const v = tglEl && tglEl.value ? new Date(tglEl.value + 'T00:00:00') : new Date(); return NAMA_HARI[v.getDay()]; }
const DOKTER = <?= json_encode($dokter_per_poli) ?>, TIPE = <?= json_encode($tipe_pj) ?>, PILIH = <?= json_encode((string) ($_POST['id_dokter'] ?? '')) ?>;
const tujuan = document.getElementById('tujuan'), poli = document.getElementById('id_poli'), dok = document.getElementById('id_dokter'), pj = document.getElementById('id_penjamin');
function isiDokter() {
  if (!dok) return;
  const d = (DOKTER_HARI[poli.value] || {})[hariDipilih()] || {}, lama = dok.value || PILIH;
  const lh = document.getElementById('lblHari'); if (lh) lh.textContent = hariDipilih();
  dok.innerHTML = '<option value="">' + (poli.value ? (Object.keys(d).length ? '-- Pilih dokter --' : 'Tidak ada dokter praktik pada hari ' + hariDipilih()) : '-- Pilih poli dulu --') + '</option>' +
    Object.entries(d).map(([id, n]) => '<option value="' + id + '"' + (id === lama ? ' selected' : '') + '>' + n.replace(/</g, '&lt;') + '</option>').join('');
}
function aturTujuan() {
  if (!tujuan) return;
  document.querySelectorAll('.rj').forEach(e => e.style.display = tujuan.value === 'igd' ? 'none' : '');
  document.querySelectorAll('.igd-only').forEach(e => e.style.display = tujuan.value === 'igd' ? '' : 'none');
}
function aturPenjamin() {
  if (!pj) return;
  const t = TIPE[pj.value];
  document.getElementById('box_kartu').style.display = t === 'Umum' ? 'none' : '';
  document.getElementById('lbl_kartu').textContent = t === 'BPJS' ? 'No. kartu BPJS (13 digit)' : 'No. polis / kartu asuransi *';
}
function aturJam() { const h = document.getElementById('regDokterPoli'); if (!h) return; h.value = dok.value && poli.value ? dok.value + '-' + poli.value : ''; h.dispatchEvent(new Event('change')); }
poli && poli.addEventListener('change', isiDokter); poli && poli.addEventListener('change', aturJam);
tglEl && tglEl.addEventListener('change', function () { isiDokter(); aturJam(); }); dok && dok.addEventListener('change', aturJam); tujuan && tujuan.addEventListener('change', aturTujuan); pj && pj.addEventListener('change', aturPenjamin);
isiDokter(); aturTujuan(); aturPenjamin(); aturJam();
</script>
<?php tampil_footer();
