<?php
/*
 * MODUL 1: AKUN PENGGUNA
 * Tidak ada tabel akun terpisah: akun petugas = kolom username/password/role di tabel pegawai,
 * akun pasien = kolom username/password di tabel pasien. Halaman ini menampilkan keduanya.
 * Aksi: buat akun, ubah peran, aktif/nonaktif, reset password, buka kunci, cabut akun.
 */
require_once __DIR__ . '/../includes/app.php';
wajib_akses('auth');
$pdo = getPDO();
$bisa_ubah = boleh('auth', true);
$roles = $pdo->query('SELECT kode, nama FROM role ORDER BY nama')->fetchAll(PDO::FETCH_KEY_PAIR);
$errors = [];

/** "pegawai-5" / "pasien-12" -> [tabel, id] */
function akun_ref(string $ref): array
{
    [$t, $id] = array_pad(explode('-', $ref, 2), 2, 0);
    return [$t === 'pasien' ? 'pasien' : 'pegawai', (int) $id];
}

if (is_post() && $bisa_ubah) {
    $aksi = post('aksi');
    [$tabel, $id] = akun_ref(post('akun'));
    $diri_sendiri = $tabel === 'pegawai' && $id === uid();
    $s = $pdo->prepare("SELECT username FROM $tabel WHERE id = ?");
    $s->execute([$id]);
    $un = (string) $s->fetchColumn();

    if ($aksi === 'simpan') {
        $aktif = isset($_POST['aktif']) ? 1 : 0;
        if ($tabel === 'pasien') {
            $pdo->prepare('UPDATE pasien SET akun_aktif = ? WHERE id = ?')->execute([$aktif, $id]);
            audit('Authentication', 'Ubah Akun', "Akun pasien $un aktif=$aktif");
            flash('sukses', 'Akun pasien diperbarui.');
        } else {
            $role_baru = post('role');
            if (!isset($roles[$role_baru]) || $role_baru === 'pasien') flash('error', 'Peran tidak valid.');
            elseif ($diri_sendiri && ($role_baru !== 'admin' || !$aktif)) flash('error', 'Anda tidak bisa menonaktifkan atau menurunkan peran akun Anda sendiri.');
            else {
                $pdo->prepare('UPDATE pegawai SET role = ?, akun_aktif = ? WHERE id = ?')->execute([$role_baru, $aktif, $id]);
                audit('Authentication', 'Ubah Akun', "Akun $un peran=$role_baru aktif=$aktif");
                flash('sukses', 'Akun diperbarui. Hak akses mengikuti peran yang baru.');
            }
        }
        kembali();
    }
    if ($aksi === 'reset') {
        $sementara = 'Ushi#' . random_int(1000, 9999) . strtoupper(bin2hex(random_bytes(1)));
        $pdo->prepare($tabel === 'pasien' ? 'UPDATE pasien SET password = ?, gagal_login = 0, kunci_sampai = NULL WHERE id = ?'
                                          : 'UPDATE pegawai SET password = ?, wajib_ganti_password = 1, gagal_login = 0, kunci_sampai = NULL WHERE id = ?')
            ->execute([password_hash($sementara, PASSWORD_DEFAULT), $id]);
        audit('Authentication', 'Reset Password', "Password akun $un direset");
        flash('info', "Password sementara untuk $un: $sementara  (berikan langsung ke pemilik akun" . ($tabel === 'pegawai' ? '; wajib diganti saat login' : '') . ').');
        kembali();
    }
    if ($aksi === 'buka_kunci') {
        $pdo->prepare("UPDATE $tabel SET gagal_login = 0, kunci_sampai = NULL WHERE id = ?")->execute([$id]);
        audit('Authentication', 'Buka Kunci', "Akun $un dibuka");
        flash('sukses', 'Kunci akun dibuka.');
        kembali();
    }
    if ($aksi === 'hapus') {
        if ($diri_sendiri) { flash('error', 'Tidak bisa mencabut akun sendiri.'); kembali(); }
        // data pegawai / pasien TETAP ada; hanya kolom akunnya yang dikosongkan
        $pdo->prepare($tabel === 'pasien' ? 'UPDATE pasien SET username = NULL, password = NULL WHERE id = ?'
                                          : 'UPDATE pegawai SET username = NULL, password = NULL, role = NULL, wajib_ganti_password = 0 WHERE id = ?')->execute([$id]);
        audit('Authentication', 'Cabut Akun', "Akun $un dicabut");
        flash('sukses', "Akun $un dicabut. Data " . ($tabel === 'pasien' ? 'pasien' : 'pegawai') . ' tetap tersimpan.');
        kembali();
    }
    if ($aksi === 'tambah') {
        $jenis = post('jenis_akun');
        $username = strtolower(post('username'));
        $pw = (string) ($_POST['password_awal'] ?? '');
        $role_baru = post('role');
        if (!preg_match('/^[a-z0-9._]{3,50}$/', $username)) $errors['username'] = 'Username 3-50 karakter: huruf kecil, angka, titik, garis bawah.';
        else { $s = $pdo->prepare('SELECT (SELECT COUNT(*) FROM pegawai WHERE username = ?) + (SELECT COUNT(*) FROM pasien WHERE username = ?)'); $s->execute([$username, $username]); if ($s->fetchColumn()) $errors['username'] = 'Username sudah dipakai.'; }
        if ($e = v_password($pw, $username)) $errors['password_awal'] = $e;
        if ($jenis === 'pasien') {
            $s = $pdo->prepare('SELECT id, nama_lengkap, username FROM pasien WHERE nomor_rm = ?');
            $s->execute([strtoupper(post('nomor_rm'))]);
            $p = $s->fetch();
            if (!$p) $errors['nomor_rm'] = 'No. RM tidak ditemukan. Pastikan pasien sudah terdaftar di Pendaftaran Pasien.';
            elseif ($p['username']) $errors['nomor_rm'] = "Pasien ini sudah punya akun ({$p['username']}).";
            if (!$errors) {
                $pdo->prepare('UPDATE pasien SET username = ?, password = ?, akun_aktif = 1 WHERE id = ?')->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $p['id']]);
                $nama = $p['nama_lengkap']; $role_baru = 'pasien';
            }
        } else {
            $s = $pdo->prepare("SELECT id, nama_lengkap FROM pegawai WHERE id = ? AND username IS NULL AND status <> 'Nonaktif'");
            $s->execute([(int) post('id_pegawai')]);
            $p = $s->fetch();
            if (!$p) $errors['id_pegawai'] = 'Pilih pegawai yang belum punya akun.';
            if (!isset($roles[$role_baru]) || $role_baru === 'pasien') $errors['role'] = 'Pilih peran.';
            if (!$errors) {
                $pdo->prepare('UPDATE pegawai SET username = ?, password = ?, role = ?, akun_aktif = 1, wajib_ganti_password = 1 WHERE id = ?')
                    ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $role_baru, $p['id']]);
                $nama = $p['nama_lengkap'];
            }
        }
        if (!$errors) {
            audit('Authentication', 'Buat Akun', "Akun $username ($role_baru) untuk $nama");
            flash('sukses', "Akun baru \"$username\" berhasil dibuat untuk $nama. Hak akses otomatis mengikuti peran {$roles[$role_baru]}.");
            kembali(['form' => null]);
        }
    }
}

$q = get('q');
$f_role = get('role');
$sql = "SELECT * FROM (
          SELECT CONCAT('pegawai-', g.id) AS ref, 'pegawai' AS tabel, g.id, g.id_rs, g.username, g.nama_lengkap, g.role, r.nama AS role_nama, g.akun_aktif, g.wajib_ganti_password,
                 g.kunci_sampai, g.login_terakhir, CONCAT(g.kode_pegawai, ' · ', g.jenis_tenaga) AS terhubung
          FROM pegawai g JOIN role r ON r.kode = g.role WHERE g.username IS NOT NULL
          UNION ALL
          SELECT CONCAT('pasien-', p.id), 'pasien', p.id, p.id_rs_daftar, p.username, p.nama_lengkap, 'pasien', 'Pasien', p.akun_aktif, 0,
                 p.kunci_sampai, p.login_terakhir, CONCAT('Pasien ', p.nomor_rm)
          FROM pasien p WHERE p.username IS NOT NULL
        ) a WHERE 1=1" . sql_rs('a.id_rs');
$par = [];
if ($q !== '') { $sql .= ' AND (a.username LIKE ? OR a.nama_lengkap LIKE ?)'; array_push($par, "%$q%", "%$q%"); }
if ($f_role !== '') { $sql .= ' AND a.role = ?'; $par[] = $f_role; }
$s = $pdo->prepare($sql . ' ORDER BY a.role_nama, a.username');
$s->execute($par);
$akun = $s->fetchAll();
$pegawai_tanpa_akun = $pdo->query("SELECT g.id, CONCAT(g.kode_pegawai, ' · ', g.nama_lengkap, ' (', g.jenis_tenaga, ', ', rs.nama_cabang, ')') FROM pegawai g JOIN konfigurasi_rs rs ON rs.id = g.id_rs
                                   WHERE g.username IS NULL AND g.status <> 'Nonaktif'" . sql_rs('g.id_rs') . " ORDER BY g.kode_pegawai")->fetchAll(PDO::FETCH_KEY_PAIR);
$aktif = count(array_filter($akun, fn($a) => $a['akun_aktif']));

tampil_header('Akun Pengguna & Akses', 'Akun petugas tersimpan di data pegawai, akun pasien di data pasien. Buat akun, ubah peran, aktif/nonaktif, reset password, buka kunci.',
    $bisa_ubah ? '<a href="?form=baru#form" class="btn btn-primary">+ Tambah Akun</a>' : '');
echo kartu([['Akun Aktif', $aktif, 'green'], ['Akun Nonaktif', count($akun) - $aktif, 'amber'],
            ['Terkunci', count(array_filter($akun, fn($a) => $a['kunci_sampai'] && strtotime($a['kunci_sampai']) > time())), 'red'],
            ['Wajib Ganti Password', count(array_filter($akun, fn($a) => $a['wajib_ganti_password'])), 'purple']]);

if ($bisa_ubah && (get('form') === 'baru' || $errors)): $jenis = $_POST['jenis_akun'] ?? 'pegawai'; ?>
<div class="card mb-4" id="form"><div class="card-head"><h3>Tambah Akun</h3></div>
  <form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="aksi" value="tambah">
    <div class="flex gap-4 mb-3 text-sm font-semibold">
      <label class="inline-flex gap-2"><input type="radio" name="jenis_akun" value="pegawai" <?= $jenis === 'pegawai' ? 'checked' : '' ?> onchange="this.form.querySelectorAll('[data-j]').forEach(e=>e.style.display=e.dataset.j==='pegawai'?'':'none')"> Akun pegawai</label>
      <label class="inline-flex gap-2"><input type="radio" name="jenis_akun" value="pasien" <?= $jenis === 'pasien' ? 'checked' : '' ?> onchange="this.form.querySelectorAll('[data-j]').forEach(e=>e.style.display=e.dataset.j==='pasien'?'':'none')"> Akun pasien (tautkan No. RM)</label>
    </div>
    <div class="form-grid">
      <div class="field" data-j="pegawai" style="<?= $jenis === 'pegawai' ? '' : 'display:none' ?>"><label>Pegawai <span class="req">*</span></label><select name="id_pegawai" class="<?= err_class($errors, 'id_pegawai') ?>"><?= opsi($pegawai_tanpa_akun, $_POST['id_pegawai'] ?? '', '-- Pegawai belum punya akun --') ?></select><?= err($errors, 'id_pegawai') ?><p class="hint">Pegawai baru ditambahkan dulu di menu Kepegawaian.</p></div>
      <div class="field" data-j="pegawai" style="<?= $jenis === 'pegawai' ? '' : 'display:none' ?>"><label>Peran <span class="req">*</span></label><select name="role" class="<?= err_class($errors, 'role') ?>"><?= opsi(array_diff_key($roles, ['pasien' => 1]), $_POST['role'] ?? '', '-- Pilih peran --') ?></select><?= err($errors, 'role') ?></div>
      <div class="field" data-j="pasien" style="<?= $jenis === 'pasien' ? '' : 'display:none' ?>"><label>No. Rekam Medis pasien <span class="req">*</span></label><input name="nomor_rm" value="<?= old('nomor_rm') ?>" placeholder="RM-2026-000001" class="<?= err_class($errors, 'nomor_rm') ?>"><?= err($errors, 'nomor_rm') ?></div>
      <div class="field"><label>Username <span class="req">*</span></label><input name="username" value="<?= old('username') ?>" maxlength="50" class="<?= err_class($errors, 'username') ?>"><?= err($errors, 'username') ?></div>
      <div class="field"><label>Password awal <span class="req">*</span></label><input name="password_awal" value="<?= old('password_awal') ?>" class="<?= err_class($errors, 'password_awal') ?>" placeholder="contoh: Rina#2026"><?= err($errors, 'password_awal') ?><p class="hint">Minimal 8 karakter huruf + angka. "password" ditolak.</p></div>
    </div>
    <div class="flex gap-2"><button class="btn btn-primary">Buat Akun</button><a href="pengguna.php" class="btn btn-secondary">Batal</a></div>
  </form></div>
<?php endif; ?>

<div class="card"><div class="card-head"><h3>Direktori Akun <span class="muted">(<?= count($akun) ?>)</span></h3>
  <form method="get" class="flex gap-2 flex-wrap"><select name="role" class="input" style="width:auto" onchange="this.form.submit()"><?= opsi($roles, $f_role, 'Semua peran') ?></select>
    <input name="q" value="<?= aman($q) ?>" class="input" style="width:200px" placeholder="Cari username / nama"><button class="btn btn-secondary">Cari</button></form></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Username</th><th>Nama / Data asal</th><th>Cabang</th><th>Peran &amp; Status</th><th>Login Terakhir</th><th class="num">Administrasi</th></tr></thead><tbody>
  <?php foreach ($akun as $a): $kunci = $a['kunci_sampai'] && strtotime($a['kunci_sampai']) > time(); $saya = $a['tabel'] === 'pegawai' && (int) $a['id'] === uid(); ?>
    <tr><td class="mono"><b><?= aman($a['username']) ?></b><?= $saya ? '<div class="muted">akun Anda</div>' : '' ?></td>
      <td><?= aman($a['nama_lengkap']) ?><div class="muted"><?= aman($a['terhubung']) ?> · tabel <?= $a['tabel'] ?></div></td>
      <td class="col-cabang"><?= aman(nama_cabang($a['id_rs'] ? (int) $a['id_rs'] : null)) ?></td>
      <td>
        <?php if ($bisa_ubah): ?>
        <form method="post" class="flex items-center gap-2 flex-wrap"><?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="akun" value="<?= aman($a['ref']) ?>">
          <?php if ($a['tabel'] === 'pegawai'): ?><select name="role" class="input" style="width:190px;padding:6px 8px"><?= opsi(array_diff_key($roles, ['pasien' => 1]), $a['role']) ?></select><?php else: ?><span class="text-sm">Pasien</span><?php endif; ?>
          <label class="inline-flex items-center gap-1 text-xs font-semibold"><input type="checkbox" name="aktif" value="1" <?= $a['akun_aktif'] ? 'checked' : '' ?>> Aktif</label>
          <button class="btn btn-secondary btn-sm">Simpan</button></form>
        <?php else: ?><?= aman($a['role_nama']) ?><?php endif; ?>
        <div class="mt-1"><?= $a['akun_aktif'] ? badge('Aktif') : badge('Nonaktif') ?> <?= $kunci ? badge('Terkunci') : '' ?> <?= $a['wajib_ganti_password'] ? '<span class="badge badge-purple">Password sementara</span>' : '' ?></div></td>
      <td class="muted"><?= tgl_jam($a['login_terakhir']) ?></td>
      <td class="num"><?php if ($bisa_ubah): ?>
        <form method="post" class="inline" data-confirm="Reset password <?= aman($a['username']) ?>? Password sementara acak akan dibuat."><?= csrf_field() ?><input type="hidden" name="aksi" value="reset"><input type="hidden" name="akun" value="<?= aman($a['ref']) ?>"><button class="btn btn-warning btn-sm">Reset Password</button></form>
        <?php if ($kunci): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="buka_kunci"><input type="hidden" name="akun" value="<?= aman($a['ref']) ?>"><button class="btn btn-secondary btn-sm">Buka Kunci</button></form><?php endif; ?>
        <?php if (!$saya): ?><form method="post" class="inline" data-confirm="Cabut akun <?= aman($a['username']) ?>? Data pegawai/pasien tetap tersimpan."><?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="akun" value="<?= aman($a['ref']) ?>"><button class="btn btn-danger btn-sm">Cabut Akun</button></form><?php endif; ?>
      <?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php tampil_footer();
