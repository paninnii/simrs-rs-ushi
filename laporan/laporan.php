<?php
/*
 * MODUL 15: REPORTING & ANALYTICS (Laporan Manajemen)
 *   Indikator: kunjungan, pasien baru, BOR, ALOS, BTO, TOI, pendapatan, klaim, 10 besar diagnosis (ICD-10).
 *   Filter periode + cabang (pilihan cabang di bagian atas) + unit. Ekspor CSV. Data agregat, tanpa membuka isi rekam medis.
 */
require_once __DIR__ . '/../includes/app.php';
wajib_akses('laporan');
$pdo = getPDO();
$lengkap = !role_is('supervisorreg');   // Supervisor Registrasi: hanya laporan registrasi/kunjungan (SOP Patient Registration)

$sampai = get('sampai') ?: date('Y-m-d');
$dari = get('dari') ?: date('Y-m-d', strtotime('-29 days'));
if (v_tanggal($dari) !== null || v_tanggal($sampai) !== null || $dari > $sampai) { flash('error', 'Rentang tanggal tidak valid.'); $dari = date('Y-m-d', strtotime('-29 days')); $sampai = date('Y-m-d'); }
if ((strtotime($sampai) - strtotime($dari)) / 86400 > 366) $dari = date('Y-m-d', strtotime($sampai . ' -365 days'));
$poli = (int) get('poli');
$hari_periode = (int) round((strtotime($sampai) - strtotime($dari)) / 86400) + 1;
$fp = sql_rs('k.id_rs') . ($poli ? ' AND k.id_unit = ?' : '');   // filter cabang (atas layar) + unit
$par = fn(array $p) => $poli ? [...$p, $poli] : $p;
$q = function (string $sql, array $p) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s; };

// ---- kunjungan ----
$kunj = $q("SELECT k.tanggal, k.jenis, k.status, k.id_rs, pl.nama AS poli, pj.tipe AS penjamin FROM kunjungan k JOIN unit_layanan pl ON pl.id = k.id_unit JOIN penjamin pj ON pj.id = k.id_penjamin
            WHERE k.tanggal BETWEEN ? AND ? AND k.status <> 'Batal' AND k.no_antrian IS NOT NULL $fp", $par([$dari, $sampai]))->fetchAll();
$per_hari = [];
for ($t = strtotime($dari); $t <= strtotime($sampai); $t += 86400) $per_hari[date('Y-m-d', $t)] = 0;
$per_poli = $per_jenis = $per_penjamin = $per_cabang = [];
foreach ($kunj as $k) {
    $per_cabang[nama_cabang((int) $k['id_rs'])] = ($per_cabang[nama_cabang((int) $k['id_rs'])] ?? 0) + 1;
    $per_hari[$k['tanggal']] = ($per_hari[$k['tanggal']] ?? 0) + 1;
    $per_poli[$k['poli']] = ($per_poli[$k['poli']] ?? 0) + 1;
    $per_jenis[$k['jenis']] = ($per_jenis[$k['jenis']] ?? 0) + 1;
    $per_penjamin[$k['penjamin']] = ($per_penjamin[$k['penjamin']] ?? 0) + 1;
}
arsort($per_poli);
$pasien_baru = (int) $q('SELECT COUNT(*) FROM pasien WHERE created_at >= ? AND created_at < ?' . sql_rs('id_rs_daftar'), [$dari, date('Y-m-d', strtotime($sampai . ' +1 day'))])->fetchColumn();

// ---- indikator rawat inap (Depkes): BOR, ALOS, BTO, TOI ----
$jml_bed = (int) $pdo->query("SELECT COUNT(*) FROM bed b JOIN unit_layanan km ON km.id = b.id_unit WHERE km.aktif = 1 AND b.status <> 'Perbaikan'" . sql_rs('km.id_rs'))->fetchColumn();
$ranap = $q("SELECT ra.tanggal_masuk, ra.tanggal_keluar FROM rawat_inap ra JOIN kunjungan k ON k.id = ra.id_kunjungan WHERE ra.tanggal_masuk <= ? AND (ra.tanggal_keluar IS NULL OR ra.tanggal_keluar >= ?) $fp",
            $par([$sampai . ' 23:59:59', $dari]))->fetchAll();
$hari_rawat = 0; $keluar = 0; $lama_keluar = 0;
foreach ($ranap as $r) {
    $m = max(strtotime(substr($r['tanggal_masuk'], 0, 10)), strtotime($dari));
    $kel = $r['tanggal_keluar'] ? strtotime(substr($r['tanggal_keluar'], 0, 10)) : strtotime(date('Y-m-d'));
    $s = min($kel, strtotime($sampai));
    $hari_rawat += max(0, (int) round(($s - $m) / 86400)) + ($r['tanggal_keluar'] ? 0 : 1);
    if ($r['tanggal_keluar'] && substr($r['tanggal_keluar'], 0, 10) >= $dari && substr($r['tanggal_keluar'], 0, 10) <= $sampai) {
        $keluar++;
        $lama_keluar += max(1, (int) round(($kel - strtotime(substr($r['tanggal_masuk'], 0, 10))) / 86400));
    }
}
$kapasitas = max(1, $jml_bed * $hari_periode);
$bor = round($hari_rawat / $kapasitas * 100, 1);
$alos = $keluar ? round($lama_keluar / $keluar, 1) : 0;
$bto = $jml_bed ? round($keluar / $jml_bed, 2) : 0;
$toi = $keluar ? round(($kapasitas - $hari_rawat) / $keluar, 1) : 0;

// ---- keuangan ----
$tg = $q("SELECT t.status, t.total, t.porsi_pasien, t.porsi_penjamin FROM tagihan t JOIN kunjungan k ON k.id = t.id_kunjungan WHERE k.tanggal BETWEEN ? AND ? $fp", $par([$dari, $sampai]))->fetchAll();
$pendapatan = array_sum(array_map(fn($x) => in_array($x['status'], ['Dibayar', 'Final'], true) ? (float) $x['total'] : 0, $tg));
$piutang = array_sum(array_map(fn($x) => in_array($x['status'], ['Dibayar', 'Final'], true) ? (float) $x['porsi_penjamin'] : 0, $tg));
$belum = count(array_filter($tg, fn($x) => !in_array($x['status'], ['Dibayar', 'Final'], true)));
$komp = $q("SELECT td.komponen, SUM(td.subtotal) AS n FROM tagihan_detail td JOIN tagihan t ON t.id = td.id_tagihan JOIN kunjungan k ON k.id = t.id_kunjungan
            WHERE t.status IN ('Dibayar','Final') AND k.tanggal BETWEEN ? AND ? $fp GROUP BY td.komponen ORDER BY n DESC", $par([$dari, $sampai]))->fetchAll(PDO::FETCH_KEY_PAIR);
$klaim = $q("SELECT kl.status, COUNT(*) n, SUM(kl.jumlah_tagihan) s FROM pembayaran kl JOIN tagihan t ON t.id = kl.id_tagihan JOIN kunjungan k ON k.id = t.id_kunjungan WHERE kl.jenis = 'Klaim Penjamin' AND k.tanggal BETWEEN ? AND ? $fp GROUP BY kl.status", $par([$dari, $sampai]))->fetchAll(PDO::FETCH_UNIQUE);

// ---- 10 besar diagnosis ----
$icd = $q("SELECT rm.kode_icd10, i.nama, COUNT(*) n FROM rekam_medis rm JOIN kunjungan k ON k.id = rm.id_kunjungan LEFT JOIN icd10 i ON i.kode = rm.kode_icd10
           WHERE rm.jenis_catatan = 'SOAP Dokter' AND k.tanggal BETWEEN ? AND ? $fp GROUP BY rm.kode_icd10, i.nama ORDER BY n DESC, rm.kode_icd10 LIMIT 10", $par([$dari, $sampai]))->fetchAll();

// ---- ekspor CSV ----
if (get('csv') !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-' . strtolower(preg_replace('/\W+/', '-', nama_cabang(rs_aktif() ?: null))) . '-' . $dari . '-sd-' . $sampai . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");   // BOM agar Excel membaca UTF-8
    fputcsv($f, ['Laporan ' . (rs_aktif() ? nama_cabang(rs_aktif()) : konfig('singkatan') . ' semua cabang'), "$dari s/d $sampai"], ';');
    fputcsv($f, ['Indikator', 'Nilai'], ';');
    $ind = ['Total kunjungan' => count($kunj), 'Pasien baru' => $pasien_baru] + ($lengkap ? ['BOR (%)' => $bor, 'ALOS (hari)' => $alos, 'BTO (kali)' => $bto, 'TOI (hari)' => $toi, 'Pendapatan (Rp)' => $pendapatan, 'Tagihan belum lunas' => $belum] : []);
    foreach ($ind as $k => $v) fputcsv($f, [$k, $v], ';');
    fputcsv($f, [], ';'); fputcsv($f, ['Tanggal', 'Jumlah kunjungan'], ';');
    foreach ($per_hari as $k => $v) fputcsv($f, [$k, $v], ';');
    fputcsv($f, [], ';'); fputcsv($f, ['Cabang', 'Jumlah kunjungan'], ';');
    foreach ($per_cabang as $k => $v) fputcsv($f, [$k, $v], ';');
    fputcsv($f, [], ';'); fputcsv($f, ['Poliklinik', 'Jumlah kunjungan'], ';');
    foreach ($per_poli as $k => $v) fputcsv($f, [$k, $v], ';');
    if (!$lengkap) { foreach (['Umum', 'BPJS', 'Asuransi'] as $k) fputcsv($f, ["Pasien $k", $per_penjamin[$k] ?? 0], ';'); audit('Reporting', 'Ekspor CSV registrasi', "$dari s/d $sampai"); exit; }
    fputcsv($f, [], ';'); fputcsv($f, ['Kode ICD-10', 'Diagnosis', 'Jumlah'], ';');
    foreach ($icd as $r) fputcsv($f, [$r['kode_icd10'], $r['nama'], $r['n']], ';');
    fputcsv($f, [], ';'); fputcsv($f, ['Komponen pendapatan', 'Rp'], ';');
    foreach ($komp as $k => $v) fputcsv($f, [$k, $v], ';');
    audit('Reporting', 'Ekspor CSV', "$dari s/d $sampai");
    exit;
}

$polis = $pdo->query("SELECT u.id, CONCAT(u.nama, ' · ', rs.nama_cabang) FROM unit_layanan u JOIN konfigurasi_rs rs ON rs.id = u.id_rs WHERE u.jenis <> 'Rawat Inap'" . sql_rs('u.id_rs') . ' ORDER BY u.id_rs, u.nama')->fetchAll(PDO::FETCH_KEY_PAIR);
$qs = http_build_query(['dari' => $dari, 'sampai' => $sampai, 'poli' => $poli ?: '']);
tampil_header($lengkap ? 'Laporan & Indikator' : 'Laporan Registrasi', 'Periode ' . tgl($dari) . ' – ' . tgl($sampai) . ' · ' . nama_cabang(rs_aktif() ?: null) . ($poli ? ' · ' . ($polis[$poli] ?? '') : ' · semua unit') . ' · data agregat',
    '<a href="?' . $qs . '&csv=1" class="btn btn-secondary">Ekspor CSV</a> <button onclick="window.print()" class="btn btn-primary">Cetak</button>');
?>
<form class="card mb-4 no-print"><div class="card-body flex flex-wrap gap-3 items-end">
  <div class="field mb-0"><label>Dari</label><input type="date" name="dari" value="<?= aman($dari) ?>" max="<?= date('Y-m-d') ?>"></div>
  <div class="field mb-0"><label>Sampai</label><input type="date" name="sampai" value="<?= aman($sampai) ?>" max="<?= date('Y-m-d') ?>"></div>
  <div class="field mb-0"><label>Unit</label><select name="poli"><?= opsi($polis, $poli ?: null, 'Semua unit') ?></select></div>
  <div class="flex gap-1"><?php foreach ([7 => '7 hari', 30 => '30 hari', 90 => '90 hari'] as $n => $l): ?><a class="btn btn-secondary btn-sm" href="?dari=<?= date('Y-m-d', strtotime('-' . ($n - 1) . ' days')) ?>&sampai=<?= date('Y-m-d') ?>&poli=<?= $poli ?: '' ?>"><?= $l ?></a><?php endforeach; ?></div>
  <button class="btn btn-primary">Terapkan</button></div></form>

<?= $lengkap ? kartu([['Total Kunjungan', number_format(count($kunj), 0, ',', '.'), 'teal'], ['Pasien Baru', $pasien_baru, 'green'], ['BOR (Keterisian Tempat Tidur)', $bor . '%', $bor > 85 ? 'red' : ($bor < 60 ? 'amber' : 'green')], ['ALOS (Rata-rata Lama Rawat)', $alos . ' hari', 'purple'], ['Pendapatan', rupiah($pendapatan), 'teal']])
    : kartu([['Total Kunjungan', count($kunj), 'teal'], ['Pasien Baru', $pasien_baru, 'green'], ['Rawat Jalan', $per_jenis['rawat_jalan'] ?? 0, 'purple'], ['IGD', $per_jenis['igd'] ?? 0, 'red'], ['Pasien BPJS', $per_penjamin['BPJS'] ?? 0, 'amber']]) ?>

<div class="grid lg:grid-cols-3 gap-4 mb-4">
  <div class="card lg:col-span-2"><div class="card-head"><b>Kunjungan per hari</b><span class="muted"><?= count($kunj) ?> kunjungan · rata-rata <?= round(count($kunj) / $hari_periode, 1) ?>/hari</span></div><div class="card-body"><div class="chart-box"><canvas id="cHari" role="img" aria-label="Grafik garis jumlah kunjungan per hari"></canvas></div></div></div>
  <?php if ($lengkap): ?><div class="card"><div class="card-head"><b>Indikator rawat inap</b><span class="muted"><?= $jml_bed ?> bed</span></div><div class="card-body">
    <dl class="kv"><dt>BOR <span class="muted">keterisian tempat tidur</span></dt><dd><?= $bor ?>% <span class="muted font-normal">(ideal 60–85%)</span></dd><dt>ALOS <span class="muted">rata-rata lama rawat</span></dt><dd><?= $alos ?> hari <span class="muted font-normal">(ideal 6–9)</span></dd>
      <dt>BTO <span class="muted">frekuensi pemakaian bed</span></dt><dd><?= $bto ?> kali</dd><dt>TOI <span class="muted">rata-rata bed kosong</span></dt><dd><?= $toi ?> hari <span class="muted font-normal">(ideal 1–3)</span></dd><dt>Hari rawat</dt><dd><?= $hari_rawat ?></dd><dt>Pasien keluar</dt><dd><?= $keluar ?></dd></dl>
    <p class="muted text-xs mt-3">BOR = hari rawat ÷ (bed × <?= $hari_periode ?> hari) × 100%. ALOS = lama rawat pasien keluar ÷ jumlah pasien keluar.</p></div></div><?php endif; ?>
</div>

<div class="grid lg:grid-cols-2 gap-4 mb-4">
  <div class="card"><div class="card-head"><b>Kunjungan per poliklinik</b></div><div class="card-body"><div class="chart-box"><canvas id="cPoli" role="img" aria-label="Grafik batang kunjungan per poliklinik"></canvas></div></div></div>
  <?php if ($lengkap): ?><div class="card"><div class="card-head"><b>Pendapatan per komponen</b><span class="muted">tagihan dibayar/final</span></div><div class="card-body"><div class="chart-box"><canvas id="cKomp" role="img" aria-label="Grafik batang pendapatan per komponen"></canvas></div></div></div><?php endif; ?>
</div>

<div class="grid lg:grid-cols-3 gap-4">
  <?php if ($lengkap): ?><div class="card lg:col-span-2"><div class="card-head"><b>10 besar diagnosis (ICD-10)</b></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>#</th><th>Kode</th><th>Diagnosis</th><th class="num">Kasus</th><th style="width:30%"></th></tr></thead><tbody>
  <?php $mx = $icd ? max(array_column($icd, 'n')) : 1; if (!$icd): ?><tr><td colspan="5" class="empty">Belum ada diagnosis pada periode ini.</td></tr><?php endif; ?>
  <?php foreach ($icd as $i => $r): ?><tr><td><?= $i + 1 ?></td><td class="mono"><?= aman($r['kode_icd10']) ?></td><td><?= aman($r['nama'] ?: '-') ?></td><td class="num"><b><?= (int) $r['n'] ?></b></td>
    <td><div style="height:8px;border-radius:4px;background:#0b7896;width:<?= round($r['n'] / $mx * 100) ?>%"></div></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
  <div class="space-y-4">
    <?php if (count($per_cabang) > 1 || !rs_aktif()): ?><div class="card"><div class="card-head"><b>Kunjungan per cabang</b></div><div class="card-body text-sm"><dl class="kv">
      <?php foreach ($per_cabang as $k => $v): ?><dt><?= aman($k) ?></dt><dd><?= $v ?> <span class="muted font-normal">(<?= count($kunj) ? round($v / count($kunj) * 100) : 0 ?>%)</span></dd><?php endforeach; ?></dl></div></div><?php endif; ?>
    <div class="card"><div class="card-head"><b>Komposisi</b></div><div class="card-body text-sm"><dl class="kv">
      <?php foreach (['rawat_jalan' => 'Rawat Jalan', 'igd' => 'IGD', 'rawat_inap' => 'Rawat Inap'] as $k => $l): ?><dt><?= $l ?></dt><dd><?= $per_jenis[$k] ?? 0 ?></dd><?php endforeach; ?>
      <?php foreach (['Umum', 'BPJS', 'Asuransi'] as $k): ?><dt>Pasien <?= $k ?></dt><dd><?= $per_penjamin[$k] ?? 0 ?> <span class="muted font-normal">(<?= count($kunj) ? round(($per_penjamin[$k] ?? 0) / count($kunj) * 100) : 0 ?>%)</span></dd><?php endforeach; ?></dl></div></div>
    <?php if ($lengkap): ?><div class="card"><div class="card-head"><b>Keuangan & klaim</b></div><div class="card-body text-sm"><dl class="kv">
      <dt>Pendapatan</dt><dd><?= rupiah($pendapatan) ?></dd><dt>Tanggungan penjamin</dt><dd><?= rupiah($piutang) ?></dd><dt>Tagihan belum lunas</dt><dd><?= $belum ?></dd>
      <?php foreach (['Diajukan', 'Disetujui', 'Perlu Perbaikan', 'Ditolak'] as $s): ?><dt>Klaim <?= $s ?></dt><dd><?= (int) ($klaim[$s]['n'] ?? 0) ?> <span class="muted font-normal"><?= rupiah($klaim[$s]['s'] ?? 0) ?></span></dd><?php endforeach; ?></dl></div></div><?php endif; ?>
  </div>
</div>

<details class="card mt-4"><summary class="card-head cursor-pointer"><b>Tabel data grafik</b><span class="muted">untuk dibaca tanpa grafik</span></summary><div class="card-body grid md:grid-cols-3 gap-4 text-sm">
  <table class="tbl"><thead><tr><th>Tanggal</th><th class="num">Kunjungan</th></tr></thead><tbody><?php foreach ($per_hari as $k => $v): ?><tr><td><?= tgl($k) ?></td><td class="num"><?= $v ?></td></tr><?php endforeach; ?></tbody></table>
  <table class="tbl"><thead><tr><th>Poliklinik</th><th class="num">Kunjungan</th></tr></thead><tbody><?php foreach ($per_poli as $k => $v): ?><tr><td><?= aman($k) ?></td><td class="num"><?= $v ?></td></tr><?php endforeach; ?></tbody></table>
  <?php if ($lengkap): ?><table class="tbl"><thead><tr><th>Komponen</th><th class="num">Rp</th></tr></thead><tbody><?php foreach ($komp as $k => $v): ?><tr><td><?= aman($k) ?></td><td class="num"><?= rupiah($v) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div></details>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (!window.Chart) return;
  const TEAL = '#0b7896', INK = '#475569', GRID = '#e2e8f0';
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
  Chart.defaults.color = INK;
  const rp = v => 'Rp ' + Number(v).toLocaleString('id-ID');
  const base = (fmt, horiz) => ({ responsive: true, maintainAspectRatio: false, indexAxis: horiz ? 'y' : 'x', interaction: { mode: 'index', intersect: false },
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' ' + (fmt ? fmt(c.parsed[horiz ? 'x' : 'y']) : c.parsed[horiz ? 'x' : 'y']) } } },
    scales: { x: { grid: { display: !!horiz, color: GRID }, ticks: horiz && fmt ? { callback: v => fmt(v) } : { maxRotation: 0, autoSkip: true } },
              y: { grid: { display: !horiz, color: GRID }, beginAtZero: true, ticks: !horiz ? (fmt ? { callback: v => fmt(v) } : { precision: 0 }) : {} } } });
  const hari = <?= json_encode(array_map(fn($d) => date('j/n', strtotime($d)), array_keys($per_hari))) ?>;
  new Chart(document.getElementById('cHari'), { type: 'line', data: { labels: hari, datasets: [{ data: <?= json_encode(array_values($per_hari)) ?>, borderColor: TEAL, backgroundColor: 'rgba(11,120,150,.08)', fill: true, borderWidth: 2, tension: .3, pointRadius: 0, pointHoverRadius: 5 }] }, options: base() });
  new Chart(document.getElementById('cPoli'), { type: 'bar', data: { labels: <?= json_encode(array_keys($per_poli)) ?>, datasets: [{ data: <?= json_encode(array_values($per_poli)) ?>, backgroundColor: TEAL, borderRadius: 4, maxBarThickness: 22 }] }, options: base(null, true) });
  if (document.getElementById('cKomp')) new Chart(document.getElementById('cKomp'), { type: 'bar', data: { labels: <?= json_encode(array_keys($komp)) ?>, datasets: [{ data: <?= json_encode(array_map('floatval', array_values($komp))) ?>, backgroundColor: TEAL, borderRadius: 4, maxBarThickness: 22 }] }, options: base(rp, true) });
})();
</script>
<?php tampil_footer();
