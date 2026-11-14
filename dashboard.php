<?php
/*
 * DASHBOARD ADMINISTRATOR
 * Sesuai prinsip least privilege: admin TIDAK melihat data klinis pasien,
 * hanya akun, data referensi, dan aktivitas sistem.
 * Role lain langsung diarahkan ke halaman kerjanya masing-masing.
 */
require_once __DIR__ . '/includes/app.php';
wajib_login();
if (!role_is('admin')) redirect(BERANDA_ROLE[role()] ?? 'login.php');
$pdo = getPDO();
$n = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

$per_role = $pdo->query("SELECT r.nama, COUNT(u.id) AS jml, SUM(CASE WHEN u.akun_aktif=1 THEN 1 ELSE 0 END) AS aktif
                         FROM role r LEFT JOIN pegawai u ON u.role = r.kode GROUP BY r.kode, r.nama ORDER BY jml DESC, r.nama")->fetchAll();
foreach ($per_role as &$r) if ($r['nama'] === 'Pasien') { $r['jml'] = $n("SELECT COUNT(*) FROM pasien WHERE username IS NOT NULL"); $r['aktif'] = $n("SELECT COUNT(*) FROM pasien WHERE username IS NOT NULL AND akun_aktif=1"); }
unset($r);
$sdm = $pdo->query("SELECT jenis_tenaga, COUNT(*) AS jml FROM pegawai WHERE status='Aktif'" . sql_rs('id_rs') . " GROUP BY jenis_tenaga")->fetchAll(PDO::FETCH_KEY_PAIR);
$per_cabang = $pdo->query("SELECT rs.id, rs.nama_cabang, rs.jenis,
        (SELECT COUNT(*) FROM pegawai g WHERE g.id_rs = rs.id AND g.status='Aktif') AS pegawai,
        (SELECT COUNT(*) FROM unit_layanan u WHERE u.id_rs = rs.id AND u.aktif=1 AND u.jenis <> 'Rawat Inap') AS unit,
        (SELECT COUNT(*) FROM bed b JOIN unit_layanan u ON u.id = b.id_unit WHERE u.id_rs = rs.id) AS bed
        FROM konfigurasi_rs rs ORDER BY rs.id")->fetchAll();
$audit = $pdo->query("SELECT * FROM audit_log WHERE 1=1" . sql_rs('id_rs') . " ORDER BY id DESC LIMIT 8")->fetchAll();

tampil_header('Dashboard Administrator', 'Ringkasan akun, hak akses, cabang, dan data referensi SIMRS.');
echo kartu([
    ['Akun Petugas Aktif', $n("SELECT COUNT(*) FROM pegawai WHERE username IS NOT NULL AND akun_aktif=1"), 'teal'],
    ['Akun Pasien', $n("SELECT COUNT(*) FROM pasien WHERE username IS NOT NULL"), 'green'],
    ['Cabang RS', count($per_cabang), 'purple', '1 pusat + ' . (count($per_cabang) - 1) . ' cabang'],
    ['Aktivitas Audit Hari Ini', $n("SELECT COUNT(*) FROM audit_log WHERE created_at >= CURDATE()"), 'amber'],
    ['Akun Terkunci', $n("SELECT (SELECT COUNT(*) FROM pegawai WHERE kunci_sampai > NOW()) + (SELECT COUNT(*) FROM pasien WHERE kunci_sampai > NOW())"), 'red'],
]);
?>
<div class="notice mb-4">Workspace Administrator tidak menampilkan data klinis pasien (SOAP, diagnosis, resep). Data klinis hanya dapat dibuka oleh tenaga kesehatan sesuai hak akses.</div>
<div class="card mb-4">
  <div class="card-head"><h3>Cabang Rumah Sakit</h3><a href="<?= url('master/pengaturan.php') ?>" class="btn btn-secondary btn-sm">Konfigurasi RS &amp; Cabang</a></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Cabang</th><th>Status</th><th class="num">Pegawai aktif</th><th class="num">Poli &amp; IGD</th><th class="num">Bed rawat inap</th></tr></thead><tbody>
  <?php foreach ($per_cabang as $c): ?><tr><td class="pname"><?= aman(nama_cabang((int) $c['id'])) ?></td><td><?= badge($c['jenis']) ?></td><td class="num"><?= (int) $c['pegawai'] ?></td><td class="num"><?= (int) $c['unit'] ?></td><td class="num"><?= (int) $c['bed'] ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<div class="grid lg:grid-cols-3 gap-4">
  <div class="card lg:col-span-2">
    <div class="card-head"><h3>Akun per Peran</h3><a href="<?= url('auth/pengguna.php') ?>" class="btn btn-secondary btn-sm">Kelola Akun</a></div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Peran</th><th class="num">Jumlah Akun</th><th class="num">Aktif</th></tr></thead><tbody>
    <?php foreach ($per_role as $r): ?><tr><td><?= aman($r['nama']) ?></td><td class="num"><?= (int) $r['jml'] ?></td><td class="num"><?= (int) $r['aktif'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Kepegawaian Aktif<?= rs_aktif() ? ' · ' . aman(nama_cabang(rs_aktif())) : '' ?></h3></div>
    <div class="card-body space-y-2 text-sm">
      <?php foreach (['Dokter' => 'Dokter', 'Perawat' => 'Perawat', 'Bidan' => 'Perawat', 'Apoteker' => 'Penunjang', 'Analis Lab' => 'Penunjang', 'Radiografer' => 'Penunjang', 'Administrasi' => 'Administrasi'] as $j => $link): ?>
        <a href="<?= url('master/pegawai.php?jenis=' . $link) ?>" class="flex justify-between py-1.5 border-b border-slate-100 hover:text-[#0b7896]"><span><?= $j ?></span><b><?= (int) ($sdm[$j] ?? 0) ?></b></a>
      <?php endforeach; ?>
      <div class="section-label">Data Layanan</div>
      <?php foreach ([['Poliklinik & IGD', "SELECT COUNT(*) FROM unit_layanan WHERE aktif=1 AND jenis<>'Rawat Inap'", 'master/unit.php?jenis=Poliklinik'], ['Bed rawat inap', "SELECT COUNT(*) FROM bed", 'master/unit.php?jenis=Rawat Inap'], ['Tarif & pemeriksaan', "SELECT COUNT(*) FROM layanan WHERE aktif=1", 'master/layanan.php?kategori=Tindakan'], ['Obat & alkes', "SELECT COUNT(*) FROM obat WHERE aktif=1", 'master/obat.php'], ['Kode ICD-10', "SELECT COUNT(*) FROM icd10 WHERE aktif=1", 'master/icd10.php']] as $d): ?>
        <a href="<?= url($d[2]) ?>" class="flex justify-between py-1.5 border-b border-slate-100 hover:text-[#0b7896]"><span><?= $d[0] ?></span><b><?= $n($d[1]) ?></b></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<div class="card mt-4">
  <div class="card-head"><h3>Aktivitas Terbaru (Audit Log)</h3><a href="<?= url('auth/audit.php') ?>" class="btn btn-secondary btn-sm">Lihat semua</a></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Waktu</th><th>User</th><th>Modul</th><th>Aksi</th><th>Detail</th></tr></thead><tbody>
  <?php foreach ($audit as $a): ?><tr><td class="muted"><?= tgl_jam($a['created_at']) ?></td><td class="mono"><?= aman($a['username']) ?><div class="col-cabang"><?= aman(nama_cabang($a['id_rs'] ? (int) $a['id_rs'] : null)) ?></div></td><td><?= aman($a['modul']) ?></td><td><?= badge($a['aksi']) ?></td><td><?= aman($a['detail']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php tampil_footer();
