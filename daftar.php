<?php
/*
 * DAFTAR AKUN PASIEN (mandiri) -- akun disimpan di tabel pasien (kolom username & password)
 * Pasien yang sudah pernah berobat (datanya sudah ada di rekam medis) membuat akun sendiri untuk janji temu,
 * melihat hasil pemeriksaan, dan tagihan. Identitas diverifikasi dengan 3 data sekaligus:
 * NIK + tanggal lahir + nomor HP yang terdaftar (harus cocok semua). Satu pasien hanya boleh punya satu akun.
 * Proteksi: 5x verifikasi gagal -> formulir dikunci 10 menit; pesan error tidak membocorkan data mana yang salah.
 * Pasien baru (belum pernah berobat) tetap mendaftar pertama kali di loket dengan KTP, sesuai SOP Patient Registration.
 */
require_once __DIR__ . '/includes/app.php';
if (user()) redirect(BERANDA_ROLE[role()] ?? 'dashboard.php');

$errors = [];
$f = ['nik' => '', 'tanggal_lahir' => '', 'no_hp' => '', 'username' => ''];
/** 0812..., 62812..., +62812... dianggap nomor yang sama */
function hp_normal(string $hp): string { $hp = preg_replace('/\D/', '', $hp); return preg_replace('/^62/', '0', $hp); }
$kunci = (int) ($_SESSION['daftar_kunci'] ?? 0);
if ($kunci && $kunci < time()) { unset($_SESSION['daftar_kunci'], $_SESSION['daftar_gagal']); $kunci = 0; }

if (is_post()) {
    $pdo = getPDO();
    $f = ['nik' => post('nik'), 'tanggal_lahir' => post('tanggal_lahir'), 'no_hp' => post('no_hp'), 'username' => strtolower(post('username'))];
    $pw = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');
    if ($kunci) {
        $errors['_'] = 'Terlalu banyak percobaan verifikasi yang gagal. Coba lagi dalam ' . (int) ceil(($kunci - time()) / 60) . ' menit, atau hubungi loket pendaftaran.';
    } else {
        if ($e = v_nik($f['nik'])) $errors['nik'] = $e;
        if ($e = v_tgl_lahir($f['tanggal_lahir'])) $errors['tanggal_lahir'] = $e;
        if ($e = v_hp($f['no_hp'])) $errors['no_hp'] = $e;
        if (!preg_match('/^[a-z0-9._]{3,50}$/', $f['username'])) $errors['username'] = 'Username 3–50 karakter: huruf kecil, angka, titik, atau garis bawah (tanpa spasi).';
        if ($e = v_password($pw, $f['username'])) $errors['password'] = $e;
        elseif ($pw !== $pw2) $errors['password2'] = 'Konfirmasi password tidak sama.';
        if (empty($_POST['setuju'])) $errors['setuju'] = 'Centang persetujuan penggunaan data.';

        if (!$errors) {
            $s = $pdo->prepare('SELECT p.*, p.username AS id_akun FROM pasien p WHERE p.nik = ? AND p.tanggal_lahir = ?');
            $s->execute([$f['nik'], $f['tanggal_lahir']]);
            $p = $s->fetch();
            if ($p && hp_normal($p['no_hp']) !== hp_normal($f['no_hp'])) $p = null;   // nomor HP harus sama dengan yang terdaftar
            if (!$p) {
                $_SESSION['daftar_gagal'] = ($_SESSION['daftar_gagal'] ?? 0) + 1;
                audit('Authentication', 'Daftar Akun Pasien Gagal', 'Verifikasi identitas tidak cocok (NIK ' . substr($f['nik'], 0, 6) . '******)');
                if ($_SESSION['daftar_gagal'] >= 5) { $_SESSION['daftar_kunci'] = time() + 600; $errors['_'] = 'Verifikasi gagal 5 kali. Formulir dikunci 10 menit.'; }
                else $errors['_'] = 'Data tidak cocok dengan data pasien kami. Periksa kembali NIK, tanggal lahir, dan nomor HP yang Anda berikan saat mendaftar di rumah sakit (sisa percobaan: ' . (5 - $_SESSION['daftar_gagal']) . ').';
            } elseif ($p['id_akun']) {
                $errors['_'] = 'Pasien ini sudah memiliki akun. Silakan masuk. Lupa username/password? Hubungi loket pendaftaran dengan membawa KTP.';
            } else {
                $c = $pdo->prepare('SELECT (SELECT COUNT(*) FROM pegawai WHERE username = ?) + (SELECT COUNT(*) FROM pasien WHERE username = ?)');
                $c->execute([$f['username'], $f['username']]);
                if ($c->fetchColumn()) $errors['username'] = 'Username sudah dipakai, pilih yang lain.';
                else {
                    try {
                        $pdo->prepare('UPDATE pasien SET username = ?, password = ?, akun_aktif = 1 WHERE id = ? AND username IS NULL')
                            ->execute([$f['username'], password_hash($pw, PASSWORD_DEFAULT), $p['id']]);
                        unset($_SESSION['daftar_gagal']);
                        audit('Authentication', 'Daftar Akun Pasien', "{$p['nomor_rm']} membuat akun portal '{$f['username']}'");
                        flash('sukses', "Akun pasien untuk {$p['nama_lengkap']} berhasil dibuat. Silakan masuk dengan username \"{$f['username']}\".");
                        redirect('login.php');
                    } catch (PDOException $e) {
                        $errors['_'] = is_duplikat($e) ? 'Username atau akun pasien sudah terdaftar.' : 'Gagal menyimpan akun.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daftar Akun Pasien · <?= aman(konfig('singkatan')) ?></title>
<link rel="icon" href="assets/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="min-h-screen bg-[#F1F5F9] flex items-center justify-center p-4">
<div class="w-full max-w-5xl grid lg:grid-cols-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
  <div class="bg-[#004C6D] text-white p-8 lg:p-10 flex flex-col">
    <a href="index.php" class="inline-flex items-center gap-3">
      <img src="assets/logo.png" class="w-14 h-14 rounded-full bg-white p-1" alt="Logo">
      <div><b class="block text-xl"><?= aman(konfig('singkatan')) ?></b><span class="text-white/85 text-sm"><?= aman(pengaturan('nama_rs')) ?></span></div>
    </a>
    <h1 class="text-[26px] font-bold leading-tight mt-10">Akun Pasien</h1>
    <p class="text-white/85 mt-3 text-[15px]">Buat janji temu dokter dari rumah, lihat hasil laboratorium dan radiologi yang sudah divalidasi dokter, riwayat berobat, dan tagihan Anda.</p>
    <div class="mt-6 rounded-xl bg-white/10 border border-white/20 p-4 text-[14px] space-y-2">
      <b class="block">Syarat membuat akun</b>
      <div>1. Sudah pernah berobat di <?= aman(konfig('singkatan')) ?> (data Anda sudah tercatat di rumah sakit).</div>
      <div>2. Menyiapkan <b>NIK</b> (16 digit, sesuai KTP/KK), <b>tanggal lahir</b>, dan <b>nomor HP</b> yang Anda berikan saat mendaftar di loket.</div>
      <div>3. Belum pernah berobat? Datang ke loket pendaftaran dengan membawa KTP untuk pendaftaran pertama.</div>
    </div>
    <div class="mt-auto pt-10"><div class="pt-6 text-sm text-white/85 border-t border-white/20">
      IGD 24 jam: <b class="text-white"><?= aman(pengaturan('telepon_igd')) ?></b><br>
      <a href="index.php" class="inline-block mt-2 underline text-white">&larr; Kembali ke halaman utama</a>
    </div></div>
  </div>

  <div class="p-8 lg:p-10">
    <h2 class="text-[22px] font-bold text-slate-900">Daftar Akun Pasien</h2>
    <p class="text-sm text-slate-600 mt-1 mb-6">Identitas Anda dicocokkan dengan data rekam medis rumah sakit.</p>
    <?php if (isset($errors['_'])): ?><div class="alert alert-error"><?= aman($errors['_']) ?></div><?php endif; ?>
    <form method="post" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <div class="section-label mt-0">1. Verifikasi identitas</div>
            <div class="form-grid">
        <div class="field"><label for="nik">NIK <span class="req">*</span></label>
          <input id="nik" name="nik" value="<?= aman($f['nik']) ?>" data-digits="16" inputmode="numeric" placeholder="16 digit" class="<?= err_class($errors, 'nik') ?>"><?= err($errors, 'nik') ?></div>
        <div class="field"><label for="tanggal_lahir">Tanggal lahir <span class="req">*</span></label>
          <input id="tanggal_lahir" type="date" name="tanggal_lahir" value="<?= aman($f['tanggal_lahir']) ?>" max="<?= date('Y-m-d') ?>" class="<?= err_class($errors, 'tanggal_lahir') ?>"><?= err($errors, 'tanggal_lahir') ?></div>
      </div>
      <div class="field"><label for="no_hp">Nomor HP terdaftar <span class="req">*</span></label>
        <input id="no_hp" name="no_hp" value="<?= aman($f['no_hp']) ?>" data-hp inputmode="tel" placeholder="contoh: 081234567890" maxlength="15" class="<?= err_class($errors, 'no_hp') ?>"><?= err($errors, 'no_hp') ?>
        <p class="hint">Nomor yang Anda berikan saat mendaftar di loket rumah sakit.</p></div>
      <div class="section-label">2. Buat akun</div>
      <div class="field"><label for="username">Username <span class="req">*</span></label>
        <input id="username" name="username" value="<?= aman($f['username']) ?>" placeholder="contoh: rina.k88" maxlength="50" class="<?= err_class($errors, 'username') ?>"><?= err($errors, 'username') ?></div>
      <div class="form-grid">
        <div class="field"><label for="password">Password <span class="req">*</span></label>
          <input id="password" type="password" name="password" placeholder="min. 8, huruf + angka" class="<?= err_class($errors, 'password') ?>"><?= err($errors, 'password') ?></div>
        <div class="field"><label for="password2">Ulangi password <span class="req">*</span></label>
          <input id="password2" type="password" name="password2" class="<?= err_class($errors, 'password2') ?>"><?= err($errors, 'password2') ?></div>
      </div>
      <label class="flex gap-2 items-start text-xs text-slate-600 mt-1"><input type="checkbox" name="setuju" value="1" class="mt-0.5" <?= !empty($_POST['setuju']) ? 'checked' : '' ?>>
        <span>Saya adalah pasien yang bersangkutan / wali sah, dan menyetujui data rekam medis saya ditampilkan di akun pasien sesuai UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi.</span></label>
      <?= err($errors, 'setuju') ?>
      <button class="btn btn-primary w-full mt-4" style="padding:12px">Buat Akun Pasien</button>
    </form>
    <div class="mt-6 pt-4 border-t border-slate-200 text-center text-sm text-slate-600">Sudah punya akun? <a href="login.php" class="text-[#0b7896] font-semibold hover:underline">Masuk</a></div>
  </div>
</div>
<script src="assets/app.js"></script>
</body>
</html>
