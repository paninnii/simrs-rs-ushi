<?php
/*
 * LOGIN (PPT Bab 3 / P03 / P11 + SOP Modul 1)
 * - password_verify() terhadap hash di database
 * - 3x gagal -> akun dikunci 5 menit (Bab 11, mitigasi brute force)
 * - session_regenerate_id() setelah berhasil (cegah session fixation)
 */
require_once __DIR__ . '/includes/app.php';

if (user()) redirect(BERANDA_ROLE[role()] ?? 'dashboard.php');

$error = '';
$username = '';
if (is_post()) {
    $username = strtolower(post('username'));
    $password = (string) ($_POST['password'] ?? '');
    $pdo = getPDO();
    // akun petugas ada di tabel pegawai, akun pasien di tabel pasien
    $s = $pdo->prepare("SELECT u.*, u.akun_aktif AS aktif, r.nama AS role_nama, 'pegawai' AS sumber FROM pegawai u JOIN role r ON r.kode = u.role WHERE u.username = ?");
    $s->execute([$username]);
    $u = $s->fetch();
    if (!$u) {
        $s = $pdo->prepare("SELECT u.*, u.akun_aktif AS aktif, 'pasien' AS role, 'Pasien' AS role_nama, 'pasien' AS sumber FROM pasien u WHERE u.username = ?");
        $s->execute([$username]);
        $u = $s->fetch();
    }
    $tabel = ($u['sumber'] ?? '') === 'pasien' ? 'pasien' : 'pegawai';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } elseif (!$u) {
        $error = 'Username atau password salah.';
    } elseif ($u['kunci_sampai'] && strtotime($u['kunci_sampai']) > time()) {
        $sisa = (int) ceil((strtotime($u['kunci_sampai']) - time()) / 60);
        $error = "Akun dikunci karena 3 kali salah password. Coba lagi dalam $sisa menit.";
    } elseif (!(int) $u['aktif'] || ($tabel === 'pegawai' && $u['status'] === 'Nonaktif')) {
        $error = 'Akun nonaktif. Hubungi Administrator SIMRS.';
    } elseif (!password_verify($password, $u['password'])) {
        $gagal = (int) $u['gagal_login'] + 1;
        if ($gagal >= 3) {
            $pdo->prepare("UPDATE $tabel SET gagal_login = 0, kunci_sampai = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', time() + 300), $u['id']]);
            $error = '3 kali percobaan gagal. Akun dikunci selama 5 menit.';
            $_SESSION['user'] = ['id' => $u['id'], 'username' => $u['username'], 'jenis' => $tabel, 'id_rs' => $u['id_rs'] ?? null];
            audit('Authentication', 'Akun Dikunci', '3 kali password salah');
            unset($_SESSION['user']);
        } else {
            $pdo->prepare("UPDATE $tabel SET gagal_login = ? WHERE id = ?")->execute([$gagal, $u['id']]);
            $error = 'Username atau password salah. Sisa percobaan: ' . (3 - $gagal) . '.';
        }
    } else {
        session_regenerate_id(true);
        unset($_SESSION['rs_pilih']);
        $pdo->prepare("UPDATE $tabel SET gagal_login = 0, kunci_sampai = NULL, login_terakhir = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $u['id']]);
        $_SESSION['user'] = $tabel === 'pegawai' ? [
            'jenis' => 'pegawai', 'id' => (int) $u['id'], 'id_pegawai' => (int) $u['id'], 'username' => $u['username'], 'nama' => $u['nama_lengkap'],
            'role' => $u['role'], 'role_nama' => $u['role_nama'], 'id_rs' => (int) $u['id_rs'], 'akses' => muat_akses($u['role']),
        ] : [
            'jenis' => 'pasien', 'id' => 0, 'id_pasien' => (int) $u['id'], 'username' => $u['username'], 'nama' => $u['nama_lengkap'],
            'role' => 'pasien', 'role_nama' => 'Pasien', 'id_rs' => (int) $u['id_rs_daftar'], 'akses' => muat_akses('pasien'),
        ];
        audit('Authentication', 'Login', 'Login berhasil');
        $next = (string) ($_GET['next'] ?? '');
        if ($next !== '' && str_starts_with($next, url('')) && !str_contains($next, 'login.php')) {
            header('Location: ' . $next);
            exit;
        }
        redirect(BERANDA_ROLE[$u['role']] ?? 'dashboard.php');
    }
}

$akun_demo = [
    'Administrasi (Pusat Tebet)' => ['admin' => 'Administrator SIMRS', 'pendaftaran' => 'Petugas Registrasi', 'supervisorreg' => 'Supervisor Registrasi', 'rekammedis' => 'Admin Rekam Medis', 'manajemen' => 'Manajemen RS'],
    'Dokter' => ['dokter1' => 'dr. Aditya, Sp.PD', 'dokter2' => 'dr. Clarissa, Sp.A', 'dokter3' => 'dr. Bagus, Sp.B', 'dokter4' => 'dr. Maria, Sp.OG', 'dokter5' => 'dr. Rendi (Umum/IGD)'],
    'Perawat' => ['perawat' => 'Rawat Jalan', 'perawat2' => 'Rawat Inap', 'perawat3' => 'IGD', 'perawat4' => 'Rawat Inap', 'perawat5' => 'Rawat Jalan'],
    'Penunjang' => ['atlm' => 'ATLM Lab', 'spk' => 'Dokter Sp.PK', 'adminrad' => 'Admin Radiologi', 'radiologi' => 'Radiografer', 'sprad' => 'Dokter Sp.Rad', 'farmasi' => 'Apoteker'],
    'Keuangan & Logistik' => ['kasir' => 'Kasir', 'verifikator' => 'Verifikator Asuransi', 'gudang' => 'Petugas Gudang', 'pasien' => 'Akun Pasien'],
    'Cabang Depok' => ['dokter.depok' => 'dr. Fadli (Umum/IGD)', 'dokterpd.depok' => 'dr. Wulandari, Sp.PD', 'perawat.depok' => 'Perawat', 'pendaftaran.depok' => 'Registrasi', 'farmasi.depok' => 'Apoteker', 'kasir.depok' => 'Kasir'],
    'Cabang Bekasi' => ['dokter.bekasi' => 'dr. Yoga (Umum/IGD)', 'dokterog.bekasi' => 'dr. Anindya, Sp.OG', 'bidan.bekasi' => 'Bidan', 'pendaftaran.bekasi' => 'Registrasi', 'farmasi.bekasi' => 'Apoteker', 'kasir.bekasi' => 'Kasir'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · SIMRS <?= aman(konfig('singkatan')) ?></title>
<link rel="icon" href="assets/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="min-h-screen bg-[#F1F5F9] flex items-center justify-center p-4">
<div class="w-full max-w-5xl">
  <div class="grid lg:grid-cols-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <!-- Panel identitas rumah sakit -->
    <div class="bg-[#004C6D] text-white p-8 lg:p-10 flex flex-col">
      <a href="index.php" class="inline-flex items-center gap-3">
        <img src="assets/logo.png" class="w-14 h-14 rounded-full bg-white p-1" alt="Logo">
        <div><b class="block text-xl"><?= aman(konfig('singkatan')) ?></b><span class="text-white/85 text-sm"><?= aman(pengaturan('nama_rs')) ?></span></div>
      </a>
      <h1 class="text-[26px] font-bold leading-tight mt-10">Sistem Informasi Manajemen Rumah Sakit</h1>
      <p class="text-white/85 mt-3 text-[15px]">Satu akun untuk seluruh layanan. Menu yang tampil menyesuaikan tugas Anda.</p>
      <ul class="mt-6 space-y-3 text-[15px]">
        <li class="flex gap-2"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9CCB7F" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="flex-none mt-0.5"><path d="M20 6 9 17l-5-5"/></svg><span>Petugas rumah sakit masuk dengan akun dari Administrator.</span></li>
        <li class="flex gap-2"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9CCB7F" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="flex-none mt-0.5"><path d="M20 6 9 17l-5-5"/></svg><span>Pasien masuk untuk membuat janji temu, melihat hasil pemeriksaan, dan tagihan.</span></li>
        <li class="flex gap-2"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9CCB7F" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="flex-none mt-0.5"><path d="M20 6 9 17l-5-5"/></svg><span>Data pasien dilindungi sesuai UU No. 27 Tahun 2022.</span></li>
      </ul>
      <div class="mt-auto pt-10"><div class="pt-6 text-sm text-white/85 border-t border-white/20">
        IGD 24 jam: <b class="text-white"><?= aman(pengaturan('telepon_igd')) ?></b><br>
        <a href="index.php" class="inline-block mt-2 underline text-white">&larr; Kembali ke halaman utama</a>
      </div></div>
    </div>
    <!-- Formulir masuk -->
    <div class="p-8 lg:p-10">
      <h2 class="text-[22px] font-bold text-slate-900">Masuk</h2>
      <p class="text-sm text-slate-600 mt-1 mb-6">Masukkan username dan password Anda.</p>
      <?php foreach (ambil_flash() as [$t, $m]): ?><div class="alert alert-<?= aman($t) ?>"><?= aman($m) ?></div><?php endforeach; ?>
      <?php if ($error): ?><div class="alert alert-error"><?= aman($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="field"><label for="username">Username <span class="req">*</span></label>
          <input id="username" name="username" value="<?= aman($username) ?>" placeholder="Masukkan username" required autofocus></div>
        <div class="field"><label for="password">Password <span class="req">*</span></label>
          <input id="password" name="password" type="password" placeholder="Masukkan password" required></div>
        <button class="btn btn-primary w-full mt-2" style="padding:12px">Masuk</button>
      </form>
      <div class="mt-6 rounded-xl bg-[#EAF3F7] border border-[#D9EBF2] p-4 text-sm text-slate-700">
        <b class="block text-slate-900">Pasien belum punya akun?</b>
        Cukup siapkan NIK, tanggal lahir, dan nomor HP yang terdaftar di rumah sakit.
        <a href="daftar.php" class="btn btn-soft w-full mt-3">Daftar Akun Pasien</a>
      </div>
      <p class="mt-5 text-center text-[13px] text-slate-600">Lupa password? Hubungi <a href="mailto:<?= aman(konfig('email')) ?>" class="text-[#004C6D] font-medium hover:underline">Admin IT</a> (ext. 1234).</p>
    </div>
  </div>

  <div class="mt-4 bg-white border border-slate-200 rounded-2xl p-5 text-slate-700 text-[13px]">
    <b class="block mb-3 text-slate-900 text-sm">Akun Demo untuk Pengujian · password semua akun: <span class="mono bg-slate-100 px-1.5 rounded">password</span></b>
    <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-x-6 gap-y-3">
      <?php foreach ($akun_demo as $grup => $list): ?>
        <div><span class="text-[#004C6D] font-semibold"><?= aman($grup) ?></span>
          <?php foreach ($list as $un => $ket): ?><div><span class="mono"><?= aman($un) ?></span> <span class="text-slate-500">· <?= aman($ket) ?></span></div><?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</body>
</html>
