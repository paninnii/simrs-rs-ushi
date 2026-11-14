<?php
/*
 * KEPEGAWAIAN -- SATU TABEL `pegawai` UNTUK SEMUA TENAGA (payung besar)
 * Dokter, perawat, bidan, apoteker, analis lab, radiografer, dan staf administrasi ada di tabel yang sama,
 * dibedakan oleh kolom jenis_tenaga. Kolom yang tidak relevan dibiarkan kosong, contoh:
 *   - spesialisasi hanya untuk dokter
 *   - no_str & no_sip wajib untuk tenaga kesehatan, kosong untuk administrasi
 * Akun login SIMRS juga ada di tabel ini (kolom username, password, role).
 * Menu: pegawai.php (semua) / ?jenis=Dokter / ?jenis=Perawat (perawat & bidan) / ?jenis=Penunjang / ?jenis=Administrasi
 */
require_once __DIR__ . '/../includes/app.php';
wajib_akses('master');
$pdo = getPDO();

$GRUP = [
    ''             => ['judul' => 'Semua Pegawai', 'item' => 'Pegawai', 'jenis' => ['Dokter', 'Perawat', 'Bidan', 'Apoteker', 'Analis Lab', 'Radiografer', 'Administrasi']],
    'Dokter'       => ['judul' => 'Dokter', 'item' => 'Dokter', 'jenis' => ['Dokter']],
    'Perawat'      => ['judul' => 'Perawat & Bidan', 'item' => 'Perawat / Bidan', 'jenis' => ['Perawat', 'Bidan']],
    'Penunjang'    => ['judul' => 'Tenaga Penunjang Medis', 'item' => 'Tenaga Penunjang', 'jenis' => ['Apoteker', 'Analis Lab', 'Radiografer']],
    'Administrasi' => ['judul' => 'Administrasi & Manajemen', 'item' => 'Staf', 'jenis' => ['Administrasi']],
];
$PREFIX = ['Dokter' => 'DR', 'Perawat' => 'PR', 'Bidan' => 'BD', 'Apoteker' => 'AP', 'Analis Lab' => 'AL', 'Radiografer' => 'RG', 'Administrasi' => 'ST'];
$SIP = ['Dokter' => 'SIP Dokter', 'Perawat' => 'SIPP (Surat Izin Praktik Perawat)', 'Bidan' => 'SIPB (Surat Izin Praktik Bidan)', 'Apoteker' => 'SIPA (Surat Izin Praktik Apoteker)', 'Analis Lab' => 'SIP ATLM', 'Radiografer' => 'SIP Radiografer'];
$ROLE_JENIS = [
    'Dokter' => ['dokter' => 'Dokter / DPJP', 'spk' => 'Dokter Sp. Patologi Klinik', 'sprad' => 'Dokter Sp. Radiologi'],
    'Perawat' => ['perawat' => 'Perawat / Bidan'], 'Bidan' => ['perawat' => 'Perawat / Bidan'], 'Apoteker' => ['apoteker' => 'Apoteker'],
    'Analis Lab' => ['atlm' => 'ATLM (Analis Laboratorium)'], 'Radiografer' => ['radiografer' => 'Radiografer'],
    'Administrasi' => ['pendaftaran' => 'Petugas Registrasi', 'supervisorreg' => 'Supervisor Registrasi', 'kasir' => 'Kasir', 'verifikator' => 'Verifikator Asuransi', 'gudang' => 'Petugas Gudang',
                       'rekammedis' => 'Admin Rekam Medis', 'adminrad' => 'Admin Radiologi', 'manajemen' => 'Manajemen Rumah Sakit', 'admin' => 'Administrator SIMRS'],
];
$g = get('jenis');
if (!isset($GRUP[$g])) $g = '';
$K = $GRUP[$g];
$CABANG = array_map(fn($c) => $c['singkatan'] . ' ' . $c['nama_cabang'], daftar_cabang());
$UNIT = $pdo->query("SELECT u.id, CONCAT(u.nama, ' · ', rs.nama_cabang) FROM unit_layanan u JOIN konfigurasi_rs rs ON rs.id = u.id_rs WHERE u.aktif = 1 ORDER BY u.id_rs, u.jenis DESC, u.nama")->fetchAll(PDO::FETCH_KEY_PAIR);
$UNIT_RS = $pdo->query('SELECT id, id_rs FROM unit_layanan')->fetchAll(PDO::FETCH_KEY_PAIR);
$bisa_ubah = boleh('master', true);
$errors = [];

if (is_post() && $bisa_ubah) {
    $aksi = post('aksi');
    $id = (int) post('_id');

    if ($aksi === 'hapus') {
        try {
            $pdo->prepare('DELETE FROM pegawai WHERE id = ?')->execute([$id]);
            audit('Kepegawaian', 'Hapus', "Pegawai #$id dihapus");
            flash('sukses', 'Data pegawai dihapus.');
        } catch (PDOException $e) {
            flash('error', 'Pegawai ini sudah tercatat di riwayat pelayanan, jadi tidak bisa dihapus. Ubah status menjadi Nonaktif.');
        }
        kembali(['edit' => null, 'form' => null]);
    }

    if ($aksi === 'simpan') {
        $jt = post('jenis_tenaga');
        $nakes = $jt !== 'Administrasi';
        $d = [
            'id_rs' => (int) post('id_rs'), 'nama_lengkap' => post('nama_lengkap'), 'nik' => post('nik'), 'jenis_tenaga' => $jt,
            'jabatan' => post('jabatan'), 'spesialisasi' => $jt === 'Dokter' ? post('spesialisasi') : null, 'id_unit' => post('id_unit') ?: null,
            'no_str' => $nakes ? strtoupper(post('no_str')) : null, 'berlaku_str' => $nakes ? (post('berlaku_str') ?: null) : null,
            'no_sip' => $nakes ? strtoupper(post('no_sip')) : null, 'berlaku_sip' => $nakes ? (post('berlaku_sip') ?: null) : null,
            'jenis_kelamin' => post('jenis_kelamin'), 'tanggal_lahir' => post('tanggal_lahir') ?: null, 'no_hp' => post('no_hp'),
            'email' => strtolower(post('email')) ?: null, 'alamat' => post('alamat') ?: null, 'tanggal_masuk' => post('tanggal_masuk') ?: null, 'status' => post('status', 'Aktif'),
        ];
        if (!isset($CABANG[$d['id_rs']])) $errors['id_rs'] = 'Pilih cabang tempat bekerja.';
        if ($e = v_nama($d['nama_lengkap'])) $errors['nama_lengkap'] = $e;
        if ($e = v_nik($d['nik'])) $errors['nik'] = $e;
        if (!isset($PREFIX[$jt]) || !in_array($jt, $K['jenis'], true)) $errors['jenis_tenaga'] = 'Pilih jenis tenaga.';
        if ($e = v_wajib($d['jabatan'], 'Jabatan')) $errors['jabatan'] = $e;
        if ($jt === 'Dokter' && $d['spesialisasi'] === '') $errors['spesialisasi'] = 'Spesialisasi wajib diisi (tulis "Umum" untuk dokter umum).';
        if ($d['id_unit'] !== null && !isset($UNIT[$d['id_unit']])) $errors['id_unit'] = 'Unit tidak valid.';
        elseif ($d['id_unit'] !== null && (int) $UNIT_RS[$d['id_unit']] !== $d['id_rs']) $errors['id_unit'] = 'Unit kerja utama harus berada di cabang yang sama.';
        if ($nakes) {
            if (!preg_match('/^[0-9]{16}$/', (string) $d['no_str'])) $errors['no_str'] = 'No. STR wajib untuk tenaga kesehatan: 16 digit angka.';
            if ($d['no_sip'] === '') $errors['no_sip'] = 'No. SIP wajib untuk tenaga kesehatan.';
            if ($d['berlaku_sip'] && ($e = v_tanggal($d['berlaku_sip'], 'Masa berlaku SIP'))) $errors['berlaku_sip'] = $e;
            elseif (!$d['berlaku_sip']) $errors['berlaku_sip'] = 'Masa berlaku SIP wajib diisi (SIP berlaku 5 tahun).';
            if ($d['berlaku_str'] && ($e = v_tanggal($d['berlaku_str'], 'Masa berlaku STR'))) $errors['berlaku_str'] = $e;
        }
        if (!in_array($d['jenis_kelamin'], ['L', 'P'], true)) $errors['jenis_kelamin'] = 'Pilih jenis kelamin.';
        if ($e = v_hp($d['no_hp'])) $errors['no_hp'] = $e;
        if ($e = v_email($d['email'] ?? '')) $errors['email'] = $e;
        if (!in_array($d['status'], ['Aktif', 'Cuti', 'Nonaktif'], true)) $errors['status'] = 'Status tidak valid.';
        if ($d['tanggal_lahir']) {
            if ($e = v_tanggal($d['tanggal_lahir'], 'Tanggal lahir')) $errors['tanggal_lahir'] = $e;
            elseif ($d['tanggal_lahir'] > date('Y-m-d', strtotime('-18 years'))) $errors['tanggal_lahir'] = 'Pegawai minimal berusia 18 tahun.';
        }
        if ($d['tanggal_masuk'] && $d['tanggal_masuk'] > date('Y-m-d')) $errors['tanggal_masuk'] = 'Tanggal masuk tidak boleh di masa depan.';
        foreach (['nik' => 'NIK', 'no_str' => 'No. STR', 'no_sip' => 'No. SIP', 'email' => 'Email'] as $k => $label) {
            if (empty($d[$k]) || isset($errors[$k])) continue;
            $s = $pdo->prepare("SELECT nama_lengkap FROM pegawai WHERE $k = ? AND id <> ?");
            $s->execute([$d[$k], $id]);
            if ($nm = $s->fetchColumn()) $errors[$k] = "$label ini sudah dipakai pegawai lain ($nm).";
        }

        // akun login (opsional) -- kolom akun di tabel pegawai yang sama
        $buat_akun = isset($_POST['buat_akun']);
        $username = strtolower(post('username'));
        $pw = (string) ($_POST['password_awal'] ?? '');
        $role_akun = post('role_akun');
        if ($buat_akun) {
            if (!preg_match('/^[a-z0-9._]{3,50}$/', $username)) $errors['username'] = 'Username 3-50 karakter: huruf kecil, angka, titik, atau garis bawah.';
            else {
                $s = $pdo->prepare('SELECT (SELECT COUNT(*) FROM pegawai WHERE username = ? AND id <> ?) + (SELECT COUNT(*) FROM pasien WHERE username = ?)');
                $s->execute([$username, $id, $username]);
                if ($s->fetchColumn()) $errors['username'] = 'Username sudah dipakai.';
            }
            if ($e = v_password($pw, $username)) $errors['password_awal'] = $e;
            if (!isset($ROLE_JENIS[$jt][$role_akun])) $errors['role_akun'] = 'Pilih peran akun yang sesuai jenis tenaga.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($id === 0) {
                    $pre = $PREFIX[$jt];
                    $s = $pdo->prepare('SELECT kode_pegawai FROM pegawai WHERE kode_pegawai LIKE ? ORDER BY kode_pegawai DESC LIMIT 1');
                    $s->execute([$pre . '-%']);
                    $d['kode_pegawai'] = $pre . '-' . str_pad((string) ((int) substr((string) $s->fetchColumn(), 3) + 1), 3, '0', STR_PAD_LEFT);
                    $cols = array_keys($d);
                    $pdo->prepare('INSERT INTO pegawai (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($d));
                    $id = (int) $pdo->lastInsertId();
                    audit('Kepegawaian', 'Tambah', $d['kode_pegawai'] . ' ' . $d['nama_lengkap']);
                    $pesan = $d['nama_lengkap'] . ' tersimpan dengan kode ' . $d['kode_pegawai'] . '.';
                } else {
                    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
                    $pdo->prepare("UPDATE pegawai SET $set WHERE id = ?")->execute([...array_values($d), $id]);
                    if ($d['status'] === 'Nonaktif') $pdo->prepare('UPDATE pegawai SET akun_aktif = 0 WHERE id = ?')->execute([$id]);   // akun ikut nonaktif
                    audit('Kepegawaian', 'Ubah', "Pegawai #$id " . $d['nama_lengkap']);
                    $pesan = 'Data ' . $d['nama_lengkap'] . ' diperbarui.';
                }
                if ($buat_akun) {
                    $pdo->prepare('UPDATE pegawai SET username = ?, password = ?, role = ?, akun_aktif = 1, wajib_ganti_password = 1 WHERE id = ?')
                        ->execute([$username, password_hash($pw, PASSWORD_DEFAULT), $role_akun, $id]);
                    audit('Authentication', 'Buat Akun', "Akun $username ($role_akun) untuk " . $d['nama_lengkap']);
                    $pesan .= " Akun login \"$username\" dibuat (wajib ganti password saat login pertama).";
                }
                $pdo->commit();
                flash('sukses', $pesan);
                kembali(['edit' => null, 'form' => null]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors['_db'] = is_duplikat($e) ? 'Ada data unik yang kembar (NIK, STR, SIP, email, atau username).' : 'Database menolak data: ' . $e->getMessage();
            }
        }
    }
}

// ---------- data tampilan ----------
$in = implode(',', array_map(fn($j) => $pdo->quote($j), $K['jenis']));
$q = get('q');
$sql = "SELECT p.*, u.nama AS unit, r.nama AS role_nama FROM pegawai p LEFT JOIN unit_layanan u ON u.id = p.id_unit LEFT JOIN role r ON r.kode = p.role
        WHERE p.jenis_tenaga IN ($in)" . sql_rs('p.id_rs');
$par = [];
if ($q !== '') { $sql .= ' AND (p.nama_lengkap LIKE ? OR p.kode_pegawai LIKE ? OR p.nik LIKE ? OR p.no_str LIKE ? OR p.no_sip LIKE ? OR p.username LIKE ?)'; array_push($par, ...array_fill(0, 6, "%$q%")); }
if (get('status') !== '') { $sql .= ' AND p.status = ?'; $par[] = get('status'); }
if (get('sip') === 'habis') { $sql .= ' AND p.berlaku_sip IS NOT NULL AND p.berlaku_sip <= ?'; $par[] = date('Y-m-d', strtotime('+60 days')); }
$sql .= ' ORDER BY p.id_rs, p.status, p.kode_pegawai';
$s = $pdo->prepare($sql);
$s->execute($par);
$rows = $s->fetchAll();

$edit = null;
if (get('edit') !== '') {
    $s = $pdo->prepare('SELECT * FROM pegawai WHERE id = ?');
    $s->execute([(int) get('edit')]);
    $edit = $s->fetch() ?: null;
}
$tampil_form = $bisa_ubah && ($edit || get('form') === 'baru' || $errors);
$v = fn(string $k, $def = '') => aman($_POST[$k] ?? ($edit[$k] ?? $def));
$jt_form = $_POST['jenis_tenaga'] ?? ($edit['jenis_tenaga'] ?? $K['jenis'][0]);
$link = fn(array $x = []) => '?' . http_build_query(array_filter(array_merge(['jenis' => $g], $x), fn($v) => $v !== '' && $v !== null));

$jumlah = ['Aktif' => 0, 'Cuti' => 0, 'Nonaktif' => 0];
foreach ($rows as $r) $jumlah[$r['status']]++;
$sip_habis = count(array_filter($rows, fn($r) => $r['berlaku_sip'] && $r['berlaku_sip'] <= date('Y-m-d', strtotime('+60 days'))));

tampil_header($K['judul'], 'Semua tenaga ada di satu tabel pegawai, dibedakan oleh jenis tenaga. STR berlaku seumur hidup; SIP berlaku 5 tahun dan wajib diperpanjang.',
    $bisa_ubah ? '<a href="' . $link(['form' => 'baru']) . '#form" class="btn btn-primary">+ Tambah ' . aman($K['item']) . '</a>' : '');
?>
<div class="tabs">
  <?php foreach ($GRUP as $kj => $kk): ?><a class="tab<?= $kj === $g ? ' active' : '' ?>" href="?<?= $kj !== '' ? 'jenis=' . urlencode($kj) : '' ?>"><?= aman($kk['judul']) ?></a><?php endforeach; ?>
</div>
<?= kartu([['Total ' . $K['item'], count($rows), 'teal', rs_aktif() ? nama_cabang(rs_aktif()) : 'semua cabang'], ['Aktif', $jumlah['Aktif'], 'green'], ['Cuti', $jumlah['Cuti'], 'amber'],
           ['SIP habis ≤ 60 hari', $sip_habis, 'red'], ['Punya akun login', count(array_filter($rows, fn($r) => $r['username'])), 'purple']]) ?>

<?php if ($tampil_form): ?>
<div class="card mb-4" id="form">
  <div class="card-head"><h3><?= $edit ? 'Ubah Data ' . aman($edit['nama_lengkap']) : 'Tambah ' . aman($K['item']) . ' Baru' ?></h3>
    <?php if ($edit): ?><span class="mono muted"><?= aman($edit['kode_pegawai']) ?></span><?php endif; ?></div>
  <form method="post" class="card-body">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="_id" value="<?= (int) ($edit['id'] ?? post('_id')) ?>">
    <?php if (isset($errors['_db'])): ?><div class="alert alert-error"><?= aman($errors['_db']) ?></div><?php endif; ?>
    <div class="section-label">Identitas</div>
    <div class="form-grid">
      <div class="field"><label>Nama lengkap + gelar <span class="req">*</span></label><input name="nama_lengkap" value="<?= $v('nama_lengkap') ?>" maxlength="150" class="<?= err_class($errors, 'nama_lengkap') ?>" placeholder="contoh: dr. Nama, Sp.PD / Ns. Nama, S.Kep."><?= err($errors, 'nama_lengkap') ?></div>
      <div class="field"><label>NIK KTP <span class="req">*</span></label><input name="nik" data-digits="16" value="<?= $v('nik') ?>" class="<?= err_class($errors, 'nik') ?>" placeholder="16 digit angka"><?= err($errors, 'nik') ?></div>
      <div class="field"><label>Jenis kelamin <span class="req">*</span></label><select name="jenis_kelamin" class="<?= err_class($errors, 'jenis_kelamin') ?>"><?= opsi(['L' => 'Laki-laki', 'P' => 'Perempuan'], $_POST['jenis_kelamin'] ?? ($edit['jenis_kelamin'] ?? ''), '-- Pilih --') ?></select><?= err($errors, 'jenis_kelamin') ?></div>
      <div class="field"><label>Tanggal lahir</label><input type="date" name="tanggal_lahir" value="<?= $v('tanggal_lahir') ?>" max="<?= date('Y-m-d', strtotime('-18 years')) ?>" class="<?= err_class($errors, 'tanggal_lahir') ?>"><?= err($errors, 'tanggal_lahir') ?></div>
      <div class="field"><label>No. HP <span class="req">*</span></label><input name="no_hp" data-hp value="<?= $v('no_hp') ?>" class="<?= err_class($errors, 'no_hp') ?>" placeholder="08xxxxxxxxxx"><?= err($errors, 'no_hp') ?></div>
      <div class="field"><label>Email</label><input name="email" type="email" value="<?= $v('email') ?>" maxlength="100" class="<?= err_class($errors, 'email') ?>"><?= err($errors, 'email') ?></div>
      <div class="field" style="grid-column:1/-1"><label>Alamat</label><textarea name="alamat" maxlength="255"><?= $v('alamat') ?></textarea></div>
    </div>
    <div class="section-label">Kepegawaian</div>
    <div class="form-grid">
      <div class="field"><label>Cabang tempat bekerja <span class="req">*</span></label><select name="id_rs" class="<?= err_class($errors, 'id_rs') ?>"><?= opsi($CABANG, $_POST['id_rs'] ?? ($edit['id_rs'] ?? rs_simpan()), '-- Pilih --') ?></select><?= err($errors, 'id_rs') ?></div>
      <div class="field"><label>Jenis tenaga <span class="req">*</span></label><select name="jenis_tenaga" id="jtSel" class="<?= err_class($errors, 'jenis_tenaga') ?>"><?= opsi(array_combine($K['jenis'], $K['jenis']), $jt_form) ?></select><?= err($errors, 'jenis_tenaga') ?></div>
      <div class="field"><label>Jabatan <span class="req">*</span></label><input name="jabatan" value="<?= $v('jabatan') ?>" maxlength="100" class="<?= err_class($errors, 'jabatan') ?>" placeholder="contoh: Dokter Spesialis / Perawat Pelaksana / Kasir"><?= err($errors, 'jabatan') ?></div>
      <div class="field" data-jt="Dokter"><label>Spesialisasi <span class="req">*</span></label><input name="spesialisasi" value="<?= $v('spesialisasi') ?>" maxlength="100" list="dl_sp" class="<?= err_class($errors, 'spesialisasi') ?>"><datalist id="dl_sp"><?php foreach (['Umum', 'Penyakit Dalam', 'Anak', 'Bedah', 'Obstetri & Ginekologi', 'Jantung', 'Saraf', 'Patologi Klinik', 'Radiologi'] as $sp) echo '<option value="' . $sp . '">'; ?></datalist><?= err($errors, 'spesialisasi') ?></div>
      <div class="field"><label>Unit kerja utama</label><select name="id_unit" class="<?= err_class($errors, 'id_unit') ?>"><?= opsi($UNIT, $_POST['id_unit'] ?? ($edit['id_unit'] ?? ''), '-- Tidak terikat unit (contoh: farmasi, lab) --') ?></select><?= err($errors, 'id_unit') ?></div>
      <div class="field"><label>Tanggal masuk</label><input type="date" name="tanggal_masuk" value="<?= $v('tanggal_masuk') ?>" max="<?= date('Y-m-d') ?>" class="<?= err_class($errors, 'tanggal_masuk') ?>"><?= err($errors, 'tanggal_masuk') ?></div>
      <div class="field"><label>Status</label><select name="status"><?= opsi(['Aktif' => 'Aktif', 'Cuti' => 'Cuti', 'Nonaktif' => 'Nonaktif'], $_POST['status'] ?? ($edit['status'] ?? 'Aktif')) ?></select><p class="hint">Nonaktif = akun login ikut dinonaktifkan.</p></div>
    </div>
    <div data-jt="nakes">
      <div class="section-label">Izin Tenaga Kesehatan (STR &amp; SIP)</div>
      <div class="form-grid">
        <div class="field"><label>No. STR <span class="req">*</span></label><input name="no_str" data-digits="16" value="<?= $v('no_str') ?>" class="<?= err_class($errors, 'no_str') ?>" placeholder="16 digit"><?= err($errors, 'no_str') ?></div>
        <div class="field"><label>STR berlaku sampai</label><input type="date" name="berlaku_str" value="<?= $v('berlaku_str') ?>" class="<?= err_class($errors, 'berlaku_str') ?>"><p class="hint">Kosongkan bila STR berlaku seumur hidup.</p><?= err($errors, 'berlaku_str') ?></div>
        <div class="field"><label id="sipLbl"><?= aman($SIP[$jt_form] ?? 'No. SIP') ?> <span class="req">*</span></label><input name="no_sip" value="<?= $v('no_sip') ?>" maxlength="40" class="<?= err_class($errors, 'no_sip') ?>" placeholder="contoh: 503/SIP-D/0045/TBT/2024"><?= err($errors, 'no_sip') ?></div>
        <div class="field"><label>SIP berlaku sampai <span class="req">*</span></label><input type="date" name="berlaku_sip" value="<?= $v('berlaku_sip') ?>" class="<?= err_class($errors, 'berlaku_sip') ?>"><?= err($errors, 'berlaku_sip') ?></div>
      </div>
    </div>
    <div class="section-label">Akun SIMRS</div>
    <?php if (!empty($edit['username'])): ?>
      <div class="notice">Sudah punya akun <b class="mono"><?= aman($edit['username']) ?></b> (peran <?= aman($edit['role']) ?>). Reset password / ubah peran di menu <a class="underline" href="<?= url('auth/pengguna.php?q=' . urlencode($edit['username'])) ?>">Akun Pengguna</a>.</div>
    <?php else: ?>
      <label class="inline-flex items-center gap-2 text-sm font-semibold mb-3"><input type="checkbox" name="buat_akun" value="1" <?= isset($_POST['buat_akun']) ? 'checked' : '' ?> onchange="document.getElementById('akunBox').style.display=this.checked?'grid':'none'"> Buatkan akun login untuk pegawai ini</label>
      <div id="akunBox" class="form-grid" style="display:<?= isset($_POST['buat_akun']) ? 'grid' : 'none' ?>">
        <div class="field"><label>Username</label><input name="username" value="<?= old('username') ?>" maxlength="50" class="<?= err_class($errors, 'username') ?>" placeholder="contoh: dokter6 atau perawat.depok2"><?= err($errors, 'username') ?></div>
        <div class="field"><label>Password awal</label><input name="password_awal" type="text" value="<?= old('password_awal') ?>" class="<?= err_class($errors, 'password_awal') ?>" placeholder="min. 8 karakter, huruf + angka"><?= err($errors, 'password_awal') ?><p class="hint">Password "password" ditolak. Pengguna wajib mengganti saat login pertama.</p></div>
        <div class="field"><label>Peran (hak akses)</label><select name="role_akun" id="roleSel" class="<?= err_class($errors, 'role_akun') ?>"><?php foreach ($ROLE_JENIS as $jj => $rl) foreach ($rl as $rk => $rn) echo '<option value="' . $rk . '" data-jt="' . aman($jj) . '"' . (($_POST['role_akun'] ?? '') === $rk ? ' selected' : '') . '>' . aman($rn) . '</option>'; ?></select><?= err($errors, 'role_akun') ?></div>
      </div>
    <?php endif; ?>
    <div class="flex gap-2 mt-3"><button class="btn btn-primary">Simpan ke Database</button><a href="<?= $link() ?>" class="btn btn-secondary">Batal</a></div>
  </form>
</div>
<script>
(function () {
  var sel = document.getElementById('jtSel'), SIP = <?= json_encode($SIP, JSON_UNESCAPED_UNICODE) ?>;
  function atur() {
    var jt = sel.value;
    document.querySelectorAll('[data-jt="Dokter"]').forEach(function (e) { e.hidden = jt !== 'Dokter'; });
    document.querySelectorAll('[data-jt="nakes"]').forEach(function (e) { e.hidden = jt === 'Administrasi'; });
    var l = document.getElementById('sipLbl'); if (l && SIP[jt]) l.firstChild.textContent = SIP[jt] + ' ';
    var rs = document.getElementById('roleSel');
    if (rs) { var pertama = null; Array.prototype.forEach.call(rs.options, function (o) { var ok = o.dataset.jt === jt; o.hidden = !ok; o.disabled = !ok; if (ok && !pertama) pertama = o; });
      if (rs.selectedOptions[0] && rs.selectedOptions[0].disabled && pertama) rs.value = pertama.value; }
  }
  sel.addEventListener('change', atur); atur();
})();
</script>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>Daftar <?= aman($K['judul']) ?> <span class="muted">(<?= count($rows) ?> orang)</span></h3>
    <form method="get" class="flex gap-2 flex-wrap"><?php if ($g !== ''): ?><input type="hidden" name="jenis" value="<?= aman($g) ?>"><?php endif; ?>
      <select name="status" class="input" style="width:auto" onchange="this.form.submit()"><?= opsi(['Aktif' => 'Aktif', 'Cuti' => 'Cuti', 'Nonaktif' => 'Nonaktif'], get('status'), 'Semua status') ?></select>
      <select name="sip" class="input" style="width:auto" onchange="this.form.submit()"><?= opsi(['habis' => 'SIP habis ≤ 60 hari'], get('sip'), 'Semua masa berlaku') ?></select>
      <input name="q" value="<?= aman($q) ?>" class="input" style="width:230px" placeholder="Cari nama / kode / NIK / STR / SIP"><button class="btn btn-secondary">Cari</button></form></div>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Cabang</th><th>Kode</th><th>Nama</th><th>Jenis tenaga / Jabatan</th><th>Unit kerja</th><th>STR</th><th>SIP</th><th>Kontak</th><th>Status</th><th>Akun SIMRS</th><?php if ($bisa_ubah): ?><th class="num">Aksi</th><?php endif; ?></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="11" class="empty">Belum ada data.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $habis = $r['berlaku_sip'] && $r['berlaku_sip'] <= date('Y-m-d', strtotime('+60 days')); ?>
      <tr class="<?= ($edit && $edit['id'] == $r['id']) ? 'row-hl' : '' ?>">
        <td class="col-cabang"><?= aman(nama_cabang((int) $r['id_rs'])) ?></td>
        <td class="mono"><?= aman($r['kode_pegawai']) ?></td>
        <td><b class="pname"><?= aman($r['nama_lengkap']) ?></b><div class="muted"><?= jk($r['jenis_kelamin']) ?><?= $r['tanggal_lahir'] ? ' · ' . umur($r['tanggal_lahir']) : '' ?></div></td>
        <td><?= aman($r['jenis_tenaga']) ?><?= $r['spesialisasi'] ? ' · ' . aman($r['spesialisasi']) : '' ?><div class="muted"><?= aman($r['jabatan']) ?></div></td>
        <td class="text-sm"><?= aman($r['unit'] ?? '-') ?></td>
        <td class="mono text-xs"><?= $r['no_str'] ? aman($r['no_str']) . '<div class="muted">' . ($r['berlaku_str'] ? 's/d ' . tgl($r['berlaku_str']) : 'seumur hidup') . '</div>' : '<span class="muted">-</span>' ?></td>
        <td class="mono text-xs"><?= $r['no_sip'] ? aman($r['no_sip']) . '<div class="' . ($habis ? 'text-red-700 font-semibold' : 'muted') . '">s/d ' . tgl($r['berlaku_sip']) . ($habis ? ' · segera perpanjang' : '') . '</div>' : '<span class="muted">-</span>' ?></td>
        <td class="text-xs"><?= aman($r['no_hp']) ?><div class="muted"><?= aman($r['email']) ?></div></td>
        <td><?= badge($r['status']) ?></td>
        <td><?= $r['username'] ? '<span class="mono">' . aman($r['username']) . '</span><div class="muted">' . aman($r['role_nama']) . ($r['akun_aktif'] ? '' : ' · nonaktif') . '</div>' : '<span class="muted">Belum ada akun</span>' ?></td>
        <?php if ($bisa_ubah): ?><td class="num">
          <a class="btn btn-secondary btn-sm" href="<?= $link(['edit' => $r['id']]) ?>#form">Ubah</a>
          <form method="post" class="inline" data-confirm="Hapus <?= aman($r['nama_lengkap']) ?>? Pegawai yang punya riwayat pelayanan tidak bisa dihapus."><?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="_id" value="<?= $r['id'] ?>"><button class="btn btn-danger btn-sm">Hapus</button></form>
        </td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php tampil_footer();
