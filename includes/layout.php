<?php
/*
 * LAYOUT DASHBOARD (PPT Bab 4 / P04): topbar + sidebar + konten.
 * Menu sidebar hanya menampilkan modul yang diizinkan untuk role (RBAC).
 * Format item menu: [kode_modul, label, url]
 */
const MENU = [
    'Utama'       => [['*', 'Dashboard', 'dashboard.php']],
    'Administrasi Pasien' => [
        ['registrasi', 'Pendaftaran Pasien', 'registrasi/pendaftaran.php', true],   // true = hanya tampil bila boleh UBAH
        ['registrasi', 'Data Pasien', 'registrasi/pasien.php'],
        ['jadwal', 'Janji Temu', 'jadwal/appointment.php'],
        ['jadwal', 'Jadwal Praktik Dokter', 'jadwal/praktik.php'],
    ],
    'Pelayanan'   => [
        ['rawat_jalan', 'Rawat Jalan (Poliklinik)', 'rawat_jalan/antrian.php'],
        ['igd', 'Gawat Darurat (IGD)', 'igd/igd.php'],
        ['rawat_inap', 'Rawat Inap & Bed', 'rawat_inap/ranap.php'],
    ],
    'Klinis'      => [
        ['emr', 'Rekam Medis (EMR)', 'emr/emr.php'],
        ['cpoe', 'Order Klinis (CPOE)', 'cpoe/cpoe.php'],
        ['keperawatan', 'Asuhan Keperawatan', 'keperawatan/keperawatan.php'],
    ],
    'Penunjang'   => [
        ['lab', 'Laboratorium', 'lab/lab.php'],
        ['radiologi', 'Radiologi', 'radiologi/radiologi.php'],
        ['farmasi', 'Farmasi', 'farmasi/farmasi.php'],
    ],
    'Keuangan'    => [['billing', 'Billing & Asuransi', 'billing/billing.php']],
    'Sistem'      => [
        ['master', 'Konfigurasi RS & Cabang', 'master/pengaturan.php'],
        ['auth', 'Akun Pengguna', 'auth/pengguna.php'],
        ['auth', 'Hak Akses', 'auth/hak_akses.php'],
        ['auth', 'Audit Log', 'auth/audit.php'],
    ],
    'Kepegawaian' => [
        ['master', 'Semua Pegawai', 'master/pegawai.php'],
        ['master', 'Dokter', 'master/pegawai.php?jenis=Dokter'],
        ['master', 'Perawat & Bidan', 'master/pegawai.php?jenis=Perawat'],
        ['master', 'Tenaga Penunjang Medis', 'master/pegawai.php?jenis=Penunjang'],
        ['master', 'Administrasi & Manajemen', 'master/pegawai.php?jenis=Administrasi'],
        ['master', 'Jadwal Praktik & Shift', 'master/jadwal_dokter.php'],
    ],
    'Data Layanan' => [
        ['master', 'Poliklinik & IGD', 'master/unit.php?jenis=Poliklinik'],
        ['master', 'Ruang Rawat & Bed', 'master/unit.php?jenis=Rawat Inap'],
        ['master', 'Tarif Tindakan & Administrasi', 'master/layanan.php?kategori=Tindakan'],
        ['master', 'Pemeriksaan Lab', 'master/layanan.php?kategori=Laboratorium'],
        ['master', 'Paket Lab', 'master/layanan.php?kategori=Paket Lab'],
        ['master', 'Pemeriksaan Radiologi', 'master/layanan.php?kategori=Radiologi'],
        ['master', 'Penjamin / Asuransi', 'master/penjamin.php'],
        ['master', 'Diagnosis ICD-10', 'master/icd10.php'],
        ['master', 'Obat & Alkes', 'master/obat.php'],
    ],
    'Manajemen'   => [['laporan', 'Laporan & Indikator', 'laporan/laporan.php']],
    'Logistik'    => [['inventori', 'Gudang & Persediaan', 'inventori/inventori.php']],
    'Pasien'      => [['portal', 'Beranda Pasien', 'portal/portal.php']],
];

/**
 * Tabel database tempat data halaman ini disimpan (ditampilkan kecil di bawah judul halaman).
 * Halaman bisa menimpa dengan $GLOBALS['TABEL_HALAMAN'] = '...'.
 */
const TABEL_HALAMAN = [
    'dashboard.php'               => 'kunjungan, rawat_inap, tagihan (ringkasan)',
    'registrasi/pendaftaran.php'  => 'pasien, kunjungan',
    'registrasi/pasien.php'       => 'pasien',
    'jadwal/appointment.php'      => 'kunjungan (sumber = Janji Temu / Online)',
    'jadwal/praktik.php'          => 'jadwal_pegawai (jenis = Praktik)',
    'rawat_jalan/antrian.php'     => 'kunjungan',
    'rawat_jalan/pengkajian.php'  => 'rekam_medis (jenis_catatan = Pengkajian Awal)',
    'rawat_jalan/periksa.php'     => 'rekam_medis (jenis_catatan = SOAP Dokter, Surat Kontrol, Surat Rujukan)',
    'igd/igd.php'                 => 'kunjungan, rekam_medis (jenis_catatan = Triase IGD, Penanganan IGD)',
    'rawat_inap/ranap.php'        => 'rawat_inap, bed, transfer_bed, rekam_medis (jenis_catatan = Resume Pulang)',
    'emr/emr.php'                 => 'rekam_medis (semua catatan klinis + sumber: cabang, unit, dokter, jam masuk)',
    'cpoe/cpoe.php'               => 'order_pelayanan, resep, resep_item',
    'keperawatan/keperawatan.php' => 'rekam_medis (jenis_catatan = Catatan Keperawatan, Pemberian Obat, Lapor DPJP)',
    'lab/lab.php'                 => 'order_pelayanan (jenis = Laboratorium)',
    'radiologi/radiologi.php'     => 'order_pelayanan (jenis = Radiologi)',
    'farmasi/farmasi.php'         => 'resep, resep_item, obat, stok_batch, mutasi_stok',
    'billing/billing.php'         => 'tagihan, tagihan_detail, pembayaran, order_pelayanan (persetujuan asuransi)',
    'inventori/inventori.php'     => 'obat, stok_batch, mutasi_stok, pengadaan',
    'laporan/laporan.php'         => 'kunjungan, rekam_medis, tagihan, pembayaran (hanya dibaca)',
    'portal/portal.php'           => 'pasien, kunjungan, rekam_medis, order_pelayanan',
    'auth/pengguna.php'           => 'pegawai (kolom akun: username, password, role) dan pasien (akun pasien)',
    'auth/hak_akses.php'          => 'role, modul, hak_akses',
    'auth/audit.php'              => 'audit_log',
    'master/pengaturan.php'       => 'konfigurasi_rs',
    'master/pegawai.php'          => 'pegawai',
    'master/jadwal_dokter.php'    => 'jadwal_pegawai',
    'master/unit.php'             => 'unit_layanan, bed',
    'master/layanan.php'          => 'layanan',
    'master/penjamin.php'         => 'penjamin',
    'master/icd10.php'            => 'icd10',
    'master/obat.php'             => 'obat, stok_batch',
    'password.php'                => 'pegawai / pasien (kolom password)',
];
function tabel_halaman(): string
{
    if (!empty($GLOBALS['TABEL_HALAMAN'])) return $GLOBALS['TABEL_HALAMAN'];
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    foreach (TABEL_HALAMAN as $path => $t) if (str_ends_with($script, '/' . $path)) return $t;
    return '';
}

/**
 * Notifikasi tugas per peran: [label => [jumlah, url tujuan, kode modul]].
 * Item hanya bisa diklik bila peran punya akses ke modul tujuan. Sudah difilter per cabang.
 */
function hitung_notifikasi(): array
{
    $pdo = getPDO();
    $q = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return (int) $s->fetchColumn();
    })();
    $v = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return (string) $s->fetchColumn();
    })();
    $rsk = sql_rs('k.id_rs');
    $ordr = fn(string $jenis, string $status) => "SELECT COUNT(*) FROM order_pelayanan o JOIN kunjungan k ON k.id = o.id_kunjungan WHERE o.jenis = '$jenis' AND $status$rsk";
    try {
        switch (role()) {
            case 'dokter':
                return ['Laporan perawat belum dibaca' => [$q("SELECT COUNT(*) FROM rekam_medis c JOIN rawat_inap r ON r.id_kunjungan=c.id_kunjungan WHERE c.jenis_catatan='Lapor DPJP' AND c.dibaca_dpjp=0 AND r.status='Aktif' AND r.id_dokter=?", [uid()]), 'rawat_inap/ranap.php', 'rawat_inap'],
                        'Pasien siap diperiksa' => [$q("SELECT COUNT(*) FROM kunjungan WHERE tanggal=CURDATE() AND status='Dipanggil' AND id_dokter=?", [uid()]), 'rawat_jalan/antrian.php', 'rawat_jalan']];
            case 'perawat':
                return ['Pasien menunggu pengkajian' => [$q("SELECT COUNT(*) FROM kunjungan k WHERE k.tanggal=CURDATE() AND k.status='Menunggu' AND k.jenis='rawat_jalan'$rsk"), 'rawat_jalan/antrian.php', 'rawat_jalan'],
                        'Pasien IGD belum triase' => [$q("SELECT COUNT(*) FROM kunjungan k WHERE k.status='Menunggu' AND k.jenis='igd'$rsk AND NOT EXISTS (SELECT 1 FROM rekam_medis t WHERE t.id_kunjungan=k.id AND t.jenis_catatan='Triase IGD')"), 'igd/igd.php', 'igd'],
                        'Order tindakan belum dikerjakan' => [$q($ordr('Tindakan', "o.status='Order Baru'")), 'cpoe/cpoe.php?jenis=tindakan', 'cpoe']];
            case 'supervisorreg':
                return ['Pasien terdaftar hari ini' => [$q("SELECT COUNT(*) FROM kunjungan k WHERE k.tanggal=CURDATE() AND k.no_antrian IS NOT NULL AND k.status<>'Batal'$rsk"), 'registrasi/pendaftaran.php#antrean', 'registrasi']];
            case 'pendaftaran':
                $tgl = $v("SELECT MIN(tanggal) FROM kunjungan k WHERE k.status='Menunggu Konfirmasi' AND k.tanggal>=CURDATE()$rsk");
                return ['Janji temu menunggu konfirmasi' => [$q("SELECT COUNT(*) FROM kunjungan k WHERE k.status='Menunggu Konfirmasi' AND k.tanggal>=CURDATE()$rsk"), 'jadwal/appointment.php' . ($tgl ? '?tanggal=' . $tgl : ''), 'jadwal']];
            case 'atlm':
                return ['Order lab belum selesai diproses' => [$q($ordr('Laboratorium', "o.status IN ('Order Baru','Spesimen Diterima','Diproses') AND o.jaminan <> 'Menunggu Persetujuan'")), 'lab/lab.php', 'lab']];
            case 'spk':
                return ['Hasil lab menunggu validasi' => [$q($ordr('Laboratorium', "o.status='Menunggu Validasi'")), 'lab/lab.php', 'lab']];
            case 'adminrad':
                return ['Order radiologi baru' => [$q($ordr('Radiologi', "o.status='Order Baru' AND o.jaminan <> 'Menunggu Persetujuan'")), 'radiologi/radiologi.php', 'radiologi']];
            case 'radiografer':
                return ['Pemeriksaan radiologi berjalan' => [$q($ordr('Radiologi', "o.status IN ('Terverifikasi','Terjadwal','Pemeriksaan Selesai','Gambar Terunggah')")), 'radiologi/radiologi.php', 'radiologi']];
            case 'sprad':
                return ['Menunggu ekspertise' => [$q($ordr('Radiologi', "o.status='Menunggu Ekspertise'")), 'radiologi/radiologi.php', 'radiologi']];
            case 'apoteker':
                return ['Resep belum selesai' => [$q("SELECT COUNT(*) FROM resep r JOIN kunjungan k ON k.id=r.id_kunjungan WHERE r.status NOT IN ('Selesai','Batal')$rsk"), 'farmasi/farmasi.php', 'farmasi']];
            case 'kasir':
                return ['Tagihan siap diproses kasir' => [$q("SELECT COUNT(*) FROM tagihan t JOIN kunjungan k ON k.id=t.id_kunjungan WHERE t.status IN ('Terverifikasi','Dihitung')$rsk"), 'billing/billing.php?tab=proses', 'billing']];
            case 'verifikator':
                return ['Tagihan menunggu verifikasi' => [$q("SELECT COUNT(*) FROM tagihan t JOIN kunjungan k ON k.id=t.id_kunjungan WHERE t.status='Menunggu Verifikasi'$rsk"), 'billing/billing.php?tab=verifikasi', 'billing'],
                        'Order menunggu persetujuan asuransi' => [$q("SELECT COUNT(*) FROM order_pelayanan o JOIN kunjungan k ON k.id=o.id_kunjungan WHERE o.jaminan='Menunggu Persetujuan'$rsk"), 'billing/billing.php?tab=persetujuan', 'billing']];
            case 'gudang':
                return ['Permintaan unit baru' => [$q("SELECT COUNT(*) FROM pengadaan WHERE jenis='Permintaan Unit' AND status='Diajukan'" . sql_rs('id_rs')), 'inventori/inventori.php?tab=permintaan', 'inventori'],
                        'Barang di bawah stok minimum' => [$q("SELECT COUNT(*) FROM obat WHERE aktif=1 AND stok < stok_minimum"), 'inventori/inventori.php?tab=stok', 'inventori']];
            case 'rekammedis':
                return ['Rekam medis final belum diverifikasi' => [$q("SELECT COUNT(*) FROM rekam_medis r WHERE r.jenis_catatan='SOAP Dokter' AND r.status='Final' AND r.status_verifikasi='Belum'" . sql_rs('r.id_rs')), 'emr/emr.php?status=Belum', 'emr']];
            case 'manajemen':
                return ['Purchase request menunggu persetujuan' => [$q("SELECT COUNT(*) FROM pengadaan WHERE jenis='Purchase Request' AND status='Diajukan'"), 'inventori/inventori.php?tab=pr', 'inventori']];
            case 'admin':
                return ['Akun terkunci sementara' => [$q("SELECT (SELECT COUNT(*) FROM pegawai WHERE kunci_sampai > NOW()) + (SELECT COUNT(*) FROM pasien WHERE kunci_sampai > NOW())"), 'auth/pengguna.php', 'auth'],
                        'SIP pegawai habis dalam 60 hari' => [$q("SELECT COUNT(*) FROM pegawai WHERE status='Aktif' AND berlaku_sip IS NOT NULL AND berlaku_sip <= ?", [date('Y-m-d', strtotime('+60 days'))]), 'master/pegawai.php?sip=habis', 'master']];
        }
    } catch (Throwable $e) {
    }
    return [];
}

function menu_aktif(string $item_url): bool
{
    $path = parse_url($item_url, PHP_URL_PATH);
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (!str_ends_with($script, '/' . $path)) return false;
    parse_str((string) parse_url($item_url, PHP_URL_QUERY), $qs);
    foreach ($qs as $k => $v) {
        if (($_GET[$k] ?? '') !== $v) return false;
    }
    return true;
}

function tampil_header(string $judul, string $sub = '', string $aksi = ''): void
{
    $u = user();
    $nama_rs = konfig('nama_rs', null, 'Rumah Sakit Untuk Semua Hari Ini');
    $singkat = konfig('singkatan', null, 'RS USHI');
    $tabel = tabel_halaman();
    $notif = $u ? hitung_notifikasi() : [];
    $total_notif = array_sum(array_column($notif, 0));
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= aman($judul) ?> · SIMRS <?= aman($singkat) ?></title>
<link rel="icon" href="<?= url('assets/logo.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{fontFamily:{sans:['Inter','Arial','sans-serif'],mono:['"JetBrains Mono"','"Roboto Mono"','"Courier New"','monospace']},colors:{ushi:{50:'#EAF3F7',100:'#D9EBF2',500:'#0B7896',600:'#075F7D',700:'#004C6D',800:'#003A54',900:'#002B3F'},leaf:'#5B9B3C'}}}}</script>
<link rel="stylesheet" href="<?= url('assets/app.css') ?>">
</head>
<body class="app-body">
<?php if ($u): ?>
<aside id="sidebar" class="sidebar">
    <a href="<?= url(BERANDA_ROLE[role()] ?? 'dashboard.php') ?>" class="side-brand">
        <img src="<?= url('assets/logo.png') ?>" alt="Logo <?= aman($singkat) ?>">
        <div><b><?= aman($singkat) ?></b><small><?= aman($nama_rs) ?></small></div>
    </a>
    <nav class="side-nav">
    <?php foreach (MENU as $grup => $items):
        $tampil = array_filter($items, fn($i) => ($i[0] === '*' && role_is('admin')) || ($i[0] !== '*' && boleh($i[0], !empty($i[3]))));
        if (!$tampil) continue; ?>
        <?php if ($grup === 'Utama'): foreach ($tampil as $i): ?>
            <a href="<?= url($i[2]) ?>" class="side-home<?= menu_aktif($i[2]) ? ' active' : '' ?>"><?= aman($i[1]) ?></a>
        <?php endforeach; else: ?>
        <details class="side-sec" open>
            <summary class="side-group"><span><?= aman($grup) ?></span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
            <div class="side-items">
            <?php foreach ($tampil as $i): ?>
                <a href="<?= url($i[2]) ?>" class="side-link<?= menu_aktif($i[2]) ? ' active' : '' ?>"><?= aman($i[1]) ?></a>
            <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    <?php endforeach; ?>
    </nav>
    <div class="side-foot">SIMRS v2.0 · Kelompok 10</div>
</aside>
<div id="sideBack" class="side-back" onclick="document.body.classList.remove('side-open')"></div>
<div class="shell">
<header class="topbar no-print">
    <div class="flex items-center gap-3 min-w-0">
        <button class="hamb" onclick="document.body.classList.toggle('side-open')" aria-label="Menu">&#9776;</button>
        <img src="<?= url('assets/logo.png') ?>" class="top-logo" alt="">
        <div class="top-title">SIMRS <?= aman($singkat) ?><span class="hidden md:inline"> · <?= aman($judul) ?></span></div>
    </div>
    <?php if (($u['jenis'] ?? '') === 'pegawai'): ?>
    <?php if (lintas_cabang()): ?>
        <form method="get" class="cabang-pilih" title="Cabang yang ditampilkan">
            <?php foreach ($_GET as $gk => $gv) if ($gk !== '_rs' && is_string($gv)) echo '<input type="hidden" name="' . aman($gk) . '" value="' . aman($gv) . '">'; ?>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>
            <select name="_rs" onchange="this.form.submit()" aria-label="Pilih cabang"><?= opsi([0 => 'Semua cabang'] + array_map(fn($c) => $c['singkatan'] . ' ' . $c['nama_cabang'] . ($c['jenis'] === 'Pusat' ? ' (Pusat)' : ''), daftar_cabang()), rs_aktif()) ?></select>
        </form>
    <?php else: ?>
        <span class="cabang-pilih" title="Cabang tempat Anda bertugas"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg><?= aman(nama_cabang(rs_user())) ?></span>
    <?php endif; endif; ?>
    <div class="flex items-center gap-2 sm:gap-3">
        <details class="notif">
            <summary title="Notifikasi" aria-label="Notifikasi"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg><?php if ($total_notif): ?><span class="bubble"><?= $total_notif ?></span><?php endif; ?></summary>
            <div class="notif-panel">
                <b class="block mb-2 text-slate-800">Tugas menunggu</b>
                <?php if (!$notif || !$total_notif): ?><p class="text-slate-500 text-sm">Tidak ada tugas yang menunggu.</p>
                <?php else: foreach ($notif as $label => [$n, $tujuan, $modul]): if (!$n) continue; ?>
                    <?php if (boleh($modul)): ?><a href="<?= url($tujuan) ?>" class="notif-item"><span><?= aman($label) ?></span><span class="notif-n"><?= $n ?><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg></span></a>
                    <?php else: ?><div class="notif-item notif-item-static"><span><?= aman($label) ?></span><span class="notif-n"><?= $n ?></span></div><?php endif; ?>
                <?php endforeach; endif; ?>
                <?php if ($total_notif): ?><p class="text-xs text-slate-500 mt-2">Klik untuk membuka halaman terkait.</p><?php endif; ?>
            </div>
        </details>
        <div class="avatar"><?= aman(inisial($u['nama'])) ?></div>
        <div class="identity hidden sm:block"><b><?= aman($u['nama']) ?></b><small><?= aman($u['role_nama']) ?></small></div>
        <a href="<?= url('password.php') ?>" class="btn btn-akun hidden lg:inline-flex"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Ganti Password</a>
        <a href="<?= url('logout.php') ?>" class="btn btn-keluar" data-confirm-link="Keluar dari SIMRS?"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>Keluar</a>
    </div>
</header>
<main class="content">
<?php endif; ?>
    <div class="page-head no-print">
        <div>
            <h1><?= aman($judul) ?></h1>
            <?php if ($sub): ?><p><?= aman($sub) ?></p><?php endif; ?>
            <?php if (!empty($tabel)): ?><p class="tabel-src">Tersimpan di tabel: <?= aman($tabel) ?></p><?php endif; ?>
        </div>
        <div class="flex items-center gap-2 flex-wrap justify-end">
            <?= $aksi ?>
            <span class="page-date">Tanggal sistem <?= tgl(date('Y-m-d')) ?> · <?= hari_ini() ?></span>
        </div>
    </div>
    <?php $flash = ambil_flash(); if ($flash): ?><div class="toast-wrap no-print" role="status" aria-live="polite">
    <?php foreach ($flash as [$tipe, $pesan]): ?>
        <div class="alert toast alert-<?= aman($tipe) ?>"><span><?= aman($pesan) ?></span><button type="button" class="toast-x" aria-label="Tutup" onclick="this.parentNode.remove()">&times;</button></div>
    <?php endforeach; ?></div><?php endif;
}

function tampil_footer(): void
{
    ?>
</main>
</div>
<script src="<?= url('assets/app.js') ?>"></script>
</body>
</html>
<?php
}

/** Kartu angka ringkasan: [[label, nilai, warna], ...] warna: teal|green|amber|red|purple */
function kartu(array $daftar): string
{
    $h = '<div class="metrics">';
    foreach ($daftar as $k) {
        $h .= '<div class="metric metric-' . ($k[2] ?? 'teal') . '"><span>' . aman($k[0]) . '</span><strong>' . aman((string) $k[1]) . '</strong>'
            . (isset($k[3]) ? '<small>' . aman($k[3]) . '</small>' : '') . '</div>';
    }
    return $h . '</div>';
}

/** Kotak identitas pasien ringkas (dipakai di banyak modul). */
function kartu_pasien(array $p, string $tambahan = ''): string
{
    $alergi = trim((string) ($p['alergi'] ?? ''));
    return '<div class="patient-banner"><div class="avatar avatar-lg">' . aman(inisial($p['nama_lengkap'])) . '</div><div class="min-w-0">'
        . '<div class="text-lg font-bold text-slate-900">' . aman($p['nama_lengkap']) . '</div>'
        . '<div class="text-sm text-slate-600">' . aman($p['nomor_rm']) . ' · ' . jk($p['jenis_kelamin']) . ' · ' . umur($p['tanggal_lahir']) . ' · Lahir ' . tgl($p['tanggal_lahir']) . '</div>'
        . ($tambahan ? '<div class="text-sm text-slate-600 mt-0.5">' . $tambahan . '</div>' : '')
        . '</div><div class="ml-auto text-right">'
        . ($alergi !== '' ? '<span class="badge badge-red">Alergi: ' . aman($alergi) . '</span>' : '<span class="badge badge-green">Tidak ada alergi tercatat</span>')
        . '</div></div>';
}

/** Alur bertahap (stepper) untuk workflow lab, radiologi, farmasi, billing, IGD. */
function stepper(array $tahap, string $sekarang): string
{
    $idx = array_search($sekarang, $tahap, true);
    $h = '<div class="stepper">';
    foreach ($tahap as $i => $t) {
        $c = $idx === false ? '' : ($i < $idx ? ' done' : ($i === $idx ? ' now' : ''));
        $h .= '<span class="step' . $c . '">' . aman($t) . '</span>';
    }
    return $h . '</div>';
}
