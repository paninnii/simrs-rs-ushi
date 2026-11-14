<?php
/*
 * AUTENTIKASI & RBAC (PPT Bab 3 + Modul 1)
 * ----------------------------------------------------------------
 * - Akun petugas ada di tabel pegawai (kolom username, password, role); akun pasien di tabel pasien.
 * - Role ditentukan dari akun (pegawai.role), bukan dipilih saat login.
 * - Hak akses per modul disimpan di tabel hak_akses (role x modul x bisa_ubah)
 *   dan bisa diubah Administrator lewat menu "Hak Akses (RBAC)".
 * - Setiap halaman memanggil wajib_akses('kode_modul') di baris atas.
 */

/** Halaman awal tiap role setelah login. */
const BERANDA_ROLE = [
    'admin'       => 'dashboard.php',
    'pendaftaran' => 'registrasi/pendaftaran.php',
    'supervisorreg' => 'registrasi/pasien.php',
    'dokter'      => 'rawat_jalan/antrian.php',
    'perawat'     => 'rawat_jalan/antrian.php',
    'atlm'        => 'lab/lab.php',
    'spk'         => 'lab/lab.php',
    'adminrad'    => 'radiologi/radiologi.php',
    'radiografer' => 'radiologi/radiologi.php',
    'sprad'       => 'radiologi/radiologi.php',
    'apoteker'    => 'farmasi/farmasi.php',
    'kasir'       => 'billing/billing.php',
    'verifikator' => 'billing/billing.php',
    'gudang'      => 'inventori/inventori.php',
    'rekammedis'  => 'emr/emr.php',
    'manajemen'   => 'laporan/laporan.php',
    'pasien'      => 'portal/portal.php',
];

function user(): ?array
{
    return $_SESSION['user'] ?? null;
}
/** pegawai.id user yang login (NULL untuk akun pasien). Dipakai untuk kolom dibuat_oleh, ditulis_oleh, dst. */
function uid(): ?int
{
    $u = $_SESSION['user'] ?? null;
    return ($u && ($u['jenis'] ?? '') === 'pegawai') ? (int) $u['id'] : null;
}
function role(): string
{
    return (string) ($_SESSION['user']['role'] ?? '');
}
function role_is(string ...$roles): bool
{
    return in_array(role(), $roles, true);
}

/* ================= CABANG (multi-RS) =================
 * Petugas loket & klinis (pendaftaran, perawat, dokter, kasir, apoteker) hanya melihat data cabangnya sendiri.
 * Unit pusat / penunjang / manajemen melayani semua cabang dan bisa memilih cabang di bagian atas layar.
 */
const ROLE_SEMUA_CABANG = ['admin', 'manajemen', 'rekammedis', 'verifikator', 'gudang', 'atlm', 'spk', 'adminrad', 'radiografer', 'sprad'];
function rs_user(): int
{
    return (int) ($_SESSION['user']['id_rs'] ?? 0);
}
function lintas_cabang(): bool
{
    return role_is(...ROLE_SEMUA_CABANG);
}
/** Cabang yang sedang dilihat. 0 = semua cabang (khusus peran lintas cabang). */
function rs_aktif(): int
{
    if (!user()) return 0;
    if (!lintas_cabang()) return rs_user();
    return (int) ($_SESSION['rs_pilih'] ?? 0);
}
/** Potongan SQL filter cabang, contoh: sql_rs('k.id_rs') -> " AND k.id_rs = 2" (kosong bila semua cabang). */
function sql_rs(string $kolom): string
{
    $rs = rs_aktif();
    return $rs ? " AND $kolom = " . (int) $rs : '';
}
/** Cabang untuk data BARU (pendaftaran, dsb). Peran lintas cabang yang memilih "semua" memakai cabang asalnya. */
function rs_simpan(): int
{
    return rs_aktif() ?: rs_user();
}

/* ================= DOKTER: HANYA PASIEN SENDIRI =================
 * Pasien milik dokter = pernah punya kunjungan dengan dokter itu (DPJP poli/IGD) atau dirawat inap dengan DPJP dokter itu.
 * Riwayat lengkap pasien tersebut tetap terlihat agar keputusan klinis aman.
 */
function sql_pasien_dokter(string $kolom_id_pasien): string
{
    if (!role_is('dokter')) return '';
    $d = (int) uid();
    return " AND $kolom_id_pasien IN (SELECT kd.id_pasien FROM kunjungan kd WHERE kd.id_dokter = $d
             UNION SELECT kr.id_pasien FROM rawat_inap rd JOIN kunjungan kr ON kr.id = rd.id_kunjungan WHERE rd.id_dokter = $d)";
}
function boleh_lihat_pasien(int $id_pasien): bool
{
    if (!role_is('dokter')) return true;
    $s = getPDO()->prepare('SELECT 1 FROM pasien p WHERE p.id = ?' . sql_pasien_dokter('p.id'));
    $s->execute([$id_pasien]);
    return (bool) $s->fetchColumn();
}
/** Hentikan halaman bila dokter membuka pasien yang bukan pasiennya. */
function wajib_pasien_sendiri(int $id_pasien): void
{
    if (boleh_lihat_pasien($id_pasien)) return;
    audit('RBAC', 'Akses Ditolak', 'Dokter membuka data pasien lain (id pasien ' . $id_pasien . ')');
    flash('error', 'Pasien ini bukan pasien Anda. Dokter hanya dapat membuka data pasien yang ditanganinya sendiri.');
    redirect(BERANDA_ROLE['dokter']);
}

/** Ambil daftar modul yang boleh dibuka role ini: ['lab' => 1 (bisa ubah) / 0 (lihat saja)]. */
function muat_akses(string $role): array
{
    $s = getPDO()->prepare('SELECT modul, bisa_ubah FROM hak_akses WHERE role = ?');
    $s->execute([$role]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Wajib sudah login. Hak akses dimuat ulang tiap halaman agar perubahan RBAC langsung berlaku. */
function wajib_login(): void
{
    if (!user()) {
        redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
    if (($_SESSION['user']['jenis'] ?? '') === 'pasien') {
        $s = getPDO()->prepare("SELECT akun_aktif AS aktif, 'pasien' AS role, 0 AS wajib_ganti_password FROM pasien WHERE id = ?");
        $s->execute([$_SESSION['user']['id_pasien']]);
    } else {
        $s = getPDO()->prepare('SELECT akun_aktif AS aktif, role, wajib_ganti_password FROM pegawai WHERE id = ? AND status <> \'Nonaktif\'');
        $s->execute([uid()]);
    }
    $u = $s->fetch();
    if (!$u || !(int) $u['aktif']) {
        session_destroy();
        session_start();
        flash('error', 'Akun Anda dinonaktifkan. Hubungi Administrator.');
        redirect('login.php');
    }
    $_SESSION['user']['role']  = $u['role'];
    $_SESSION['user']['akses'] = muat_akses($u['role']);
    // peran lintas cabang memilih cabang yang ditampilkan lewat ?_rs= (0 = semua cabang)
    if (isset($_GET['_rs']) && lintas_cabang()) {
        $pilih = (int) $_GET['_rs'];
        $_SESSION['rs_pilih'] = isset(daftar_cabang()[$pilih]) ? $pilih : 0;
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ((int) $u['wajib_ganti_password'] && !in_array($script, ['password.php', 'logout.php'], true)) {
        flash('info', 'Password Anda adalah password sementara. Silakan buat password baru terlebih dahulu.');
        redirect('password.php');
    }
}

/** Boleh membuka modul? ($ubah = true -> boleh menambah/mengubah data) */
function boleh(string $modul, bool $ubah = false): bool
{
    $akses = $_SESSION['user']['akses'] ?? [];
    if (!array_key_exists($modul, $akses)) return false;
    return $ubah ? $akses[$modul] === 1 : true;
}

/** Penjaga halaman: tolak bila role tidak punya akses ke modul. */
function wajib_akses(string $modul, bool $ubah = false): void
{
    wajib_login();
    if (!boleh($modul, $ubah)) {
        audit('RBAC', 'Akses Ditolak', 'Mencoba membuka modul ' . $modul . ' (' . ($_SERVER['REQUEST_URI'] ?? '') . ')');
        http_response_code(403);
        tampil_header('Akses Ditolak');
        echo '<div class="card p-8 text-center max-w-xl mx-auto mt-6"><div class="mx-auto mb-3 w-14 h-14 rounded-full bg-red-50 text-red-700 flex items-center justify-center"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></div>'
           . '<h2 class="text-xl font-bold mb-2">Akses ditolak</h2>'
           . '<p class="text-slate-600">Role <b>' . aman($_SESSION['user']['role_nama'] ?? role()) . '</b> tidak memiliki hak '
           . ($ubah ? 'mengubah data di' : 'membuka') . ' modul ini.</p>'
           . '<a class="btn btn-primary mt-5" href="' . url(BERANDA_ROLE[role()] ?? 'dashboard.php') . '">Kembali ke halaman saya</a></div>';
        tampil_footer();
        exit;
    }
}

/**
 * Penjaga AKSI (bukan halaman): contoh: hanya dokter yang boleh disposisi IGD.
 * Bila ditolak: pesan merah lalu kembali ke halaman sebelumnya.
 */
function wajib_role_aksi(array $roles, string $pesan, string $modul): bool
{
    if (!in_array(role(), $roles, true) || !boleh($modul, true)) {
        audit('RBAC', 'Aksi Ditolak', $pesan);
        flash('error', $pesan);
        return false;
    }
    return true;
}

/** pegawai.id milik user yang login (sama dengan uid()). */
function id_pegawai(): int
{
    return (int) uid();
}
/** pasien.id untuk akun pasien. */
function id_pasien_login(): int
{
    return (int) ($_SESSION['user']['id_pasien'] ?? 0);
}
