<?php
/*
 * MODUL 1: MATRIKS HAK AKSES (RBAC)
 * Baris = role, kolom = 16 modul SIMRS. Isi sel: - (tidak ada) / Lihat / Ubah.
 * Perubahan langsung berlaku: menu sidebar & penjaga halaman membaca tabel hak_akses.
 */
require_once __DIR__ . '/../includes/app.php';
wajib_akses('auth');
$pdo = getPDO();
$roles = $pdo->query('SELECT kode, nama FROM role ORDER BY nama')->fetchAll(PDO::FETCH_KEY_PAIR);
$moduls = $pdo->query('SELECT kode, nomor, nama, grup FROM modul ORDER BY CASE WHEN nomor = 0 THEN 99 ELSE nomor END')->fetchAll();
$akses = [];
foreach ($pdo->query('SELECT role, modul, bisa_ubah FROM hak_akses') as $h) $akses[$h['role']][$h['modul']] = (int) $h['bisa_ubah'];

if (is_post() && boleh('auth', true)) {
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM hak_akses');
    $ins = $pdo->prepare('INSERT INTO hak_akses (role, modul, bisa_ubah) VALUES (?,?,?)');
    $jumlah = 0;
    foreach ($roles as $r => $_) {
        foreach ($moduls as $m) {
            $v = $_POST['h'][$r][$m['kode']] ?? '';
            // pengaman: admin selalu bisa mengubah modul Authentication agar tidak terkunci sendiri
            if ($r === 'admin' && $m['kode'] === 'auth') $v = 'ubah';
            if ($v === 'lihat' || $v === 'ubah') { $ins->execute([$r, $m['kode'], $v === 'ubah' ? 1 : 0]); $jumlah++; }
        }
    }
    $pdo->commit();
    audit('Authentication', 'Ubah RBAC', "Matriks hak akses disimpan ($jumlah izin)");
    flash('sukses', 'Hak akses disimpan dan langsung berlaku untuk semua pengguna.');
    kembali();
}
tampil_header('Hak Akses', 'Atur menu yang dapat dibuka setiap peran. Setiap peran hanya membuka menu sesuai tugasnya.');
?>
<div class="notice mb-4"><b>Lihat</b> = hanya dapat membuka dan membaca. <b>Ubah</b> = dapat menambah dan mengubah data. Di dalam setiap menu, langkah tertentu tetap dibatasi sesuai tugas, misalnya hanya dokter spesialis patologi klinik yang dapat menerbitkan hasil laboratorium.</div>
<form method="post" class="card"><?= csrf_field() ?>
  <div class="card-head"><h3>Hak Akses per Peran</h3><?php if (boleh('auth', true)): ?><button class="btn btn-primary">Simpan Hak Akses</button><?php endif; ?></div>
  <div class="tbl-wrap"><table class="tbl" style="font-size:12px">
    <thead><tr><th style="position:sticky;left:0;background:#f5f8fa;z-index:1">Peran</th>
      <?php foreach ($moduls as $m): ?><th title="Modul <?= $m['nomor'] ?: 'tambahan' ?>: <?= aman($m['nama']) ?>" style="text-align:center;white-space:normal;min-width:84px"><?= aman(nama_modul($m['kode'])) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($roles as $r => $nama): ?>
      <tr><td style="position:sticky;left:0;background:#fff"><b><?= aman($nama) ?></b></td>
      <?php foreach ($moduls as $m): $val = isset($akses[$r][$m['kode']]) ? ($akses[$r][$m['kode']] ? 'ubah' : 'lihat') : ''; ?>
        <td style="text-align:center;background:<?= $val === 'ubah' ? '#dcfce7' : ($val === 'lihat' ? '#e0f2fe' : 'transparent') ?>">
          <select name="h[<?= aman($r) ?>][<?= aman($m['kode']) ?>]" style="font-size:11px;padding:3px;border:1px solid #cbd5e1;border-radius:6px;background:transparent" <?= boleh('auth', true) ? '' : 'disabled' ?>>
            <?= opsi(['' => '–', 'lihat' => 'Lihat', 'ubah' => 'Ubah'], $val) ?></select></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</form>
<?php tampil_footer();
