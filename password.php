<?php
/* GANTI PASSWORD (semua role) */
require_once __DIR__ . '/includes/app.php';
wajib_login();
$pdo = getPDO();
$errors = [];

if (is_post()) {
    $lama = (string) ($_POST['lama'] ?? '');
    $baru = (string) ($_POST['baru'] ?? '');
    $ulang = (string) ($_POST['ulang'] ?? '');
    $tabel = (user()['jenis'] ?? '') === 'pasien' ? 'pasien' : 'pegawai';
    $id_akun = $tabel === 'pasien' ? id_pasien_login() : uid();
    $s = $pdo->prepare("SELECT password FROM $tabel WHERE id = ?");
    $s->execute([$id_akun]);
    if (!password_verify($lama, (string) $s->fetchColumn())) $errors['lama'] = 'Password lama salah.';
    if ($e = v_password($baru, user()['username'])) $errors['baru'] = $e;
    if ($baru !== $ulang) $errors['ulang'] = 'Konfirmasi password tidak sama.';
    if ($baru === $lama && !$errors) $errors['baru'] = 'Password baru tidak boleh sama dengan password lama.';
    if (!$errors) {
        $pdo->prepare($tabel === 'pasien' ? 'UPDATE pasien SET password = ? WHERE id = ?' : 'UPDATE pegawai SET password = ?, wajib_ganti_password = 0 WHERE id = ?')
            ->execute([password_hash($baru, PASSWORD_DEFAULT), $id_akun]);
        audit('Authentication', 'Ganti Password', 'Password diganti oleh pemilik akun');
        flash('sukses', 'Password berhasil diganti.');
        redirect(BERANDA_ROLE[role()] ?? 'dashboard.php');
    }
}
tampil_header('Ganti Password', 'Password minimal 8 karakter, mengandung huruf dan angka.');
?>
<div class="card max-w-md">
  <form method="post" class="card-body">
    <?= csrf_field() ?>
    <div class="field"><label>Password lama <span class="req">*</span></label><input type="password" name="lama" class="<?= err_class($errors, 'lama') ?>" required><?= err($errors, 'lama') ?></div>
    <div class="field"><label>Password baru <span class="req">*</span></label><input type="password" name="baru" class="<?= err_class($errors, 'baru') ?>" required><?= err($errors, 'baru') ?></div>
    <div class="field"><label>Ulangi password baru <span class="req">*</span></label><input type="password" name="ulang" class="<?= err_class($errors, 'ulang') ?>" required><?= err($errors, 'ulang') ?></div>
    <button class="btn btn-primary">Simpan Password</button>
  </form>
</div>
<?php tampil_footer();
