<?php
/*
 * MODUL 5: OUTPATIENT (RAWAT JALAN) -- antrean poli
 *   Perawat : melihat semua antrean poli, melakukan Pengkajian Awal (TTV).
 *   Dokter  : hanya melihat pasien DPJP-nya sendiri, lalu Periksa (SOAP).
 */
require_once __DIR__ . '/../includes/app.php';
wajib_akses('rawat_jalan');
$pdo = getPDO();
$tanggal = get('tanggal', date('Y-m-d'));
$f_poli = get('poli');
$sql = "SELECT k.*, p.nomor_rm, p.nama_lengkap, p.tanggal_lahir, p.jenis_kelamin, p.alergi, pl.nama AS poli, d.nama_lengkap AS dokter, pj.nama AS penjamin,
               pa.tekanan_darah, pa.suhu, rm.status AS status_rm, rm.kode_icd10
        FROM kunjungan k JOIN pasien p ON p.id = k.id_pasien JOIN unit_layanan pl ON pl.id = k.id_unit JOIN pegawai d ON d.id = k.id_dokter
        JOIN penjamin pj ON pj.id = k.id_penjamin
        LEFT JOIN rekam_medis pa ON pa.id_kunjungan = k.id AND pa.jenis_catatan = 'Pengkajian Awal'
        LEFT JOIN rekam_medis rm ON rm.id_kunjungan = k.id AND rm.jenis_catatan = 'SOAP Dokter'
        WHERE k.tanggal = ? AND pl.jenis = 'Poliklinik' AND k.no_antrian IS NOT NULL AND k.status <> 'Batal'";
$par = [$tanggal];
if (role_is('dokter')) { $sql .= ' AND k.id_dokter = ?'; $par[] = id_pegawai(); } else $sql .= sql_rs('k.id_rs');
if ($f_poli !== '') { $sql .= ' AND k.id_unit = ?'; $par[] = $f_poli; }
$sql .= " ORDER BY CASE k.status WHEN 'Dipanggil' THEN 1 WHEN 'Menunggu' THEN 2 ELSE 3 END, k.no_antrian";
$s = $pdo->prepare($sql);
$s->execute($par);
$rows = $s->fetchAll();
$poli = $pdo->query("SELECT id, nama FROM unit_layanan WHERE aktif=1 AND jenis = 'Poliklinik'" . sql_rs('id_rs') . " ORDER BY id_rs, nama")->fetchAll(PDO::FETCH_KEY_PAIR);
$c = fn($st) => count(array_filter($rows, fn($r) => $r['status'] === $st));
$tahap = ['Menunggu' => 'Menunggu pengkajian perawat', 'Dipanggil' => 'Pengkajian selesai · menunggu dokter', 'Selesai' => 'Pemeriksaan selesai'];

tampil_header(role_is('dokter') ? 'Pasien Saya Hari Ini' : 'Antrean Rawat Jalan',
    role_is('dokter') ? 'Hanya pasien dengan DPJP ' . user()['nama'] . '.' : 'Pengkajian awal perawat sebelum pasien diperiksa dokter · ' . nama_cabang(rs_aktif() ?: null) . '.');
echo kartu([['Total Pasien', count($rows), 'teal'], ['Menunggu Pengkajian', $c('Menunggu'), 'amber'], ['Siap Diperiksa Dokter', $c('Dipanggil'), 'purple'], ['Selesai', $c('Selesai'), 'green']]);
?>
<div class="card"><div class="card-head"><h3>Antrean <?= tgl($tanggal) ?></h3>
  <form method="get" class="flex gap-2 flex-wrap">
    <?php if (!role_is('dokter')): ?><select name="poli" class="input" style="width:auto" onchange="this.form.submit()"><?= opsi($poli, $f_poli, 'Semua poli') ?></select><?php endif; ?>
    <input type="date" name="tanggal" value="<?= aman($tanggal) ?>" class="input" style="width:auto" onchange="this.form.submit()">
    <input data-filter="tblAntrian" class="input" style="width:180px" placeholder="Cari nama / RM"></form></div>
  <div class="tbl-wrap"><table class="tbl" id="tblAntrian"><thead><tr><th>No.</th><th>Pasien</th><th>Poli / DPJP</th><th>Penjamin</th><th>Status / Tahap</th><th class="num">Tindakan</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">Tidak ada pasien pada antrean ini.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr><td class="mono font-bold text-[#004c6d] text-base"><?= aman($r['no_antrian']) ?></td>
      <td><b class="pname"><?= aman($r['nama_lengkap']) ?></b><div class="muted"><?= aman($r['nomor_rm']) ?> · <?= jk($r['jenis_kelamin']) ?> · <?= umur($r['tanggal_lahir']) ?></div>
        <?= $r['alergi'] ? '<span class="badge badge-red mt-1">Alergi ' . aman($r['alergi']) . '</span>' : '' ?></td>
      <td><?= aman($r['poli']) ?><div class="muted"><?= aman($r['dokter']) ?></div></td>
      <td><?= aman($r['penjamin']) ?></td>
      <td><?= badge($r['status'] === 'Dipanggil' ? 'Siap Diperiksa' : $r['status']) ?><div class="muted"><?= aman($tahap[$r['status']] ?? '') ?><?= $r['tekanan_darah'] ? ' · TD ' . aman($r['tekanan_darah']) . ', S ' . aman($r['suhu']) : '' ?></div>
        <?= $r['jenis'] === 'rawat_inap' ? '<span class="badge badge-purple">Lanjut rawat inap</span>' : '' ?><?= $r['status_rm'] ? ' <span class="muted">EMR ' . aman($r['status_rm']) . ' · ' . aman($r['kode_icd10']) . '</span>' : '' ?></td>
      <td class="num">
        <?php if (role_is('perawat') && boleh('rawat_jalan', true) && in_array($r['status'], ['Menunggu', 'Dipanggil'], true)): ?>
          <a class="btn <?= $r['status'] === 'Menunggu' ? 'btn-primary' : 'btn-secondary' ?> btn-sm" href="pengkajian.php?id=<?= $r['id'] ?>"><?= $r['status'] === 'Menunggu' ? 'Pengkajian Awal' : 'Ubah Pengkajian' ?></a>
        <?php endif; ?>
        <?php if (role_is('dokter') && $r['status'] !== 'Selesai'): ?><a class="btn btn-primary btn-sm" href="periksa.php?id=<?= $r['id'] ?>">Periksa</a><?php endif; ?>
        <?php if ($r['status'] === 'Selesai' && boleh('emr')): ?><a class="btn btn-secondary btn-sm" href="<?= url('emr/emr.php?id=' . $r['id']) ?>">Lihat EMR</a><?php endif; ?>
        <?php if ($r['status'] === 'Selesai' && role_is('dokter')): ?><a class="btn btn-secondary btn-sm" href="<?= url('cpoe/cpoe.php?kunjungan=' . $r['id']) ?>">Order Tambahan</a><?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php tampil_footer();
