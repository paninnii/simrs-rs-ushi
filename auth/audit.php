<?php
/* MODUL 1: AUDIT LOG (jejak siapa melakukan apa, kapan) */
require_once __DIR__ . '/../includes/app.php';
wajib_akses('auth');
$pdo = getPDO();
$dari = get('dari', date('Y-m-d', strtotime('-7 days')));
$sampai = get('sampai', date('Y-m-d'));
$modul = get('modul');
$user = get('user');
$sql = 'SELECT * FROM audit_log WHERE created_at >= ? AND created_at < ?' . sql_rs('id_rs');
$par = [$dari, date('Y-m-d', strtotime($sampai . ' +1 day'))];
if ($modul !== '') { $sql .= ' AND modul = ?'; $par[] = $modul; }
if ($user !== '') { $sql .= ' AND username LIKE ?'; $par[] = "%$user%"; }
$s = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 500');
$s->execute($par);
$log = $s->fetchAll();
$daftar_modul = $pdo->query('SELECT DISTINCT modul FROM audit_log ORDER BY modul')->fetchAll(PDO::FETCH_COLUMN);
tampil_header('Audit Log', 'Login, logout, perubahan data, dan akses yang ditolak tercatat otomatis.');
?>
<div class="card"><div class="card-head"><h3>Jejak Aktivitas <span class="muted">(<?= count($log) ?> baris, maks. 500)</span></h3>
  <form method="get" class="flex gap-2 flex-wrap items-center">
    <input type="date" name="dari" value="<?= aman($dari) ?>" class="input" style="width:auto"> s/d <input type="date" name="sampai" value="<?= aman($sampai) ?>" class="input" style="width:auto">
    <select name="modul" class="input" style="width:auto"><?= opsi(array_combine($daftar_modul, array_map('nama_modul', $daftar_modul)), $modul, 'Semua modul') ?></select>
    <input name="user" value="<?= aman($user) ?>" class="input" style="width:140px" placeholder="Username"><button class="btn btn-secondary">Tampilkan</button></form></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Waktu</th><th>User</th><th>Cabang</th><th>Modul</th><th>Aksi</th><th>Detail</th><th>IP</th></tr></thead><tbody>
  <?php if (!$log): ?><tr><td colspan="7" class="empty">Tidak ada aktivitas pada filter ini.</td></tr><?php endif; ?>
  <?php foreach ($log as $a): ?><tr><td class="muted" style="white-space:nowrap"><?= tgl_jam($a['created_at']) ?></td><td class="mono"><?= aman($a['username']) ?></td><td class="col-cabang"><?= $a['id_rs'] ? aman(nama_cabang((int) $a['id_rs'])) : '-' ?></td><td><?= aman(nama_modul($a['modul'])) ?></td><td><?= badge($a['aksi']) ?></td><td><?= aman($a['detail']) ?></td><td class="muted mono"><?= aman($a['ip']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php tampil_footer();
