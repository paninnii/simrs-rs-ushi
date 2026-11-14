<?php
/* FUNGSI BANTU yang dipakai di semua halaman */

/** Cegah XSS: SELALU bungkus data dari pengguna/database saat ditampilkan (PPT Bab 11). */
function aman($teks): string
{
    return htmlspecialchars((string) ($teks ?? ''), ENT_QUOTES, 'UTF-8');
}

/** URL relatif terhadap folder aplikasi, contoh: url('lab/lab.php'). */
function url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $base = getenv('APP_BASE');
        if ($base === false) {
            $doc  = str_replace('\\', '/', rtrim((string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
            $root = str_replace('\\', '/', (string) realpath(APP_ROOT));
            $base = ($doc !== '' && str_starts_with($root, $doc)) ? substr($root, strlen($doc)) : '';
        }
        $base = rtrim($base, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('~^(https?:)?/~', $path) ? $path : url($path)));
    exit;
}

/** Kembali ke halaman yang sama (POST-Redirect-GET agar F5 tidak mengirim ulang form). */
function kembali(array $param = []): void
{
    $uri = strtok($_SERVER['REQUEST_URI'], '?');
    $q = $_GET;
    foreach ($param as $k => $v) {
        if ($v === null) unset($q[$k]); else $q[$k] = $v;
    }
    header('Location: ' . $uri . ($q ? '?' . http_build_query($q) : ''));
    exit;
}

function post(string $k, $default = '')
{
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}
function get(string $k, $default = '')
{
    $v = $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

/* ---------- Pesan sukses / gagal sekali tampil ---------- */
function flash(string $tipe, string $pesan): void
{
    $_SESSION['flash'][] = [$tipe, $pesan];
}
function ambil_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF: setiap form POST wajib membawa token ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}
function csrf_cek(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(400);
        die('Sesi formulir kedaluwarsa. Silakan muat ulang halaman lalu coba lagi.');
    }
}
function is_post(): bool
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_cek();
        return true;
    }
    return false;
}

/* ---------- Format tampilan ---------- */
function rupiah($n): string
{
    return 'Rp ' . number_format((float) $n, 0, ',', '.');
}
function tgl($d): string
{
    if (!$d) return '-';
    $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime((string) $d);
    return date('j', $t) . ' ' . $bulan[(int) date('n', $t)] . ' ' . date('Y', $t);
}
function tgl_jam($d): string
{
    return $d ? tgl($d) . ' ' . date('H:i', strtotime((string) $d)) : '-';
}
function jam($t): string
{
    return $t ? substr((string) $t, 0, 5) : '-';
}
function umur($tgl_lahir): string
{
    if (!$tgl_lahir) return '-';
    $d = (new DateTime($tgl_lahir))->diff(new DateTime());
    return $d->y > 0 ? $d->y . ' th' : $d->m . ' bln';
}
function jk($kode): string
{
    return $kode === 'L' ? 'Laki-laki' : ($kode === 'P' ? 'Perempuan' : '-');
}
function hari_ini(string $tanggal = 'now'): string
{
    $h = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    return $h[(int) date('w', strtotime($tanggal))];
}
function inisial(string $nama): string
{
    $n = trim(preg_replace('/^(dr|ns|apt)\.\s*/i', '', $nama));
    $p = preg_split('/[\s,]+/', $n);
    return strtoupper(substr($p[0] ?? '', 0, 1) . substr($p[1] ?? '', 0, 1));
}

/** Label status berwarna. */
function badge(?string $status): string
{
    // Makna warna tunggal & konsisten di semua modul (Panduan UI SIMRS B.3):
    // hijau = selesai/tersedia/valid · kuning = menunggu/dalam proses/perlu perhatian · merah = error/habis/mendesak/batal · biru-abu = info/terjadwal/draft
    $s = (string) $status;
    $warna = 'gray';
    if (preg_match('/^(Siap|Menunggu|Belum)/i', $s)) $warna = 'amber';
    elseif (preg_match('/CITO|Merah|Ditolak|Batal|Dibatalkan|Habis|Tidak Lengkap|Segera|Terisi|Nonaktif|HIGH ALERT|Kritis|Hitam/i', $s)) $warna = 'red';
    elseif (preg_match('/Selesai|Diterbitkan|Dikerjakan|Final|Lunas|Lengkap|Disetujui|Hadir|Tersedia|Aktif|Aman|Dikeluarkan|Diterima|Hijau|Pulang|Dibayar|Diserahkan|Buka/i', $s)) $warna = 'green';
    elseif (preg_match('/Menunggu|Order|Diproses|Proses|Spesimen|Dispensing|Ekspertise|Validasi|Diajukan|Perbaikan|Dipanggil|Siap|Kuning|Dihitung|Gambar|Pemeriksaan|Menipis|Libur|Belum/i', $s)) $warna = 'amber';
    elseif (preg_match('/Terjadwal|Terkonfirmasi|Terverifikasi|Draft|Rutin|IGD|Rawat|Info|Baik/i', $s)) $warna = 'blue';
    return '<span class="badge badge-' . $warna . '">' . aman($s) . '</span>';
}

/**
 * KONFIGURASI RS & CABANG (tabel konfigurasi_rs).
 * konfig('telepon') -> nilai untuk cabang yang sedang aktif (cabang user / cabang yang dipilih).
 * konfig('telepon', 2) -> nilai untuk cabang id 2.
 */
function daftar_cabang(): array
{
    static $c = null;
    if ($c === null) {
        $c = [];
        foreach (getPDO()->query('SELECT * FROM konfigurasi_rs ORDER BY id') as $r) $c[(int) $r['id']] = $r;
    }
    return $c;
}
function konfig(string $kunci, ?int $id_rs = null, string $default = ''): string
{
    $c = daftar_cabang();
    $id = $id_rs ?: (function_exists('rs_aktif') && rs_aktif() ? rs_aktif() : (function_exists('rs_user') && rs_user() ? rs_user() : array_key_first($c)));
    return isset($c[$id][$kunci]) && $c[$id][$kunci] !== null ? (string) $c[$id][$kunci] : $default;
}
/** Nama cabang singkat, contoh: "RS USHI Depok". */
function nama_cabang(?int $id_rs): string
{
    $c = daftar_cabang()[(int) $id_rs] ?? null;
    return $c ? $c['singkatan'] . ' ' . $c['nama_cabang'] : 'Semua cabang';
}
/** Nama kunci lama tetap didukung (dipakai beberapa halaman publik). */
function pengaturan(string $kunci, string $default = ''): string
{
    $map = ['alamat_rs' => 'alamat', 'telepon_rs' => 'telepon', 'email_rs' => 'email'];
    return konfig($map[$kunci] ?? $kunci, null, $default);
}

/** Catat jejak audit (siapa, cabang, modul, aksi, detail). */
function audit(string $modul, string $aksi, string $detail = ''): void
{
    $u = $_SESSION['user'] ?? null;
    getPDO()->prepare('INSERT INTO audit_log (id_rs, id_pegawai, username, modul, aksi, detail, ip) VALUES (?,?,?,?,?,?,?)')
        ->execute([$u['id_rs'] ?? null, ($u['jenis'] ?? '') === 'pegawai' ? $u['id'] : null, $u['username'] ?? 'sistem', $modul, $aksi,
                   mb_substr($detail, 0, 1000), $_SERVER['REMOTE_ADDR'] ?? null]);
}

/**
 * Nomor berikutnya berformat PREFIX-TAHUN-000001 (No. RM, invoice, kwitansi, dst).
 * Kolom harus UNIQUE di database, jadi kalau ada dua petugas menyimpan bersamaan
 * dan nomornya bentrok, database menolak dan kita coba nomor berikutnya.
 */
function kode_berikut(string $tabel, string $kolom, string $prefix): string
{
    $awal = $prefix . '-' . date('Y') . '-';
    $s = getPDO()->prepare("SELECT $kolom FROM $tabel WHERE $kolom LIKE ? ORDER BY $kolom DESC LIMIT 1");
    $s->execute([$awal . '%']);
    $terakhir = $s->fetchColumn();
    $n = $terakhir ? (int) substr($terakhir, strlen($awal)) + 1 : 1;
    return $awal . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

/** Apakah error database ini pelanggaran UNIQUE (data kembar)? */
function is_duplikat(Throwable $e): bool
{
    $m = $e->getMessage();
    return str_contains($m, 'Duplicate entry') || str_contains($m, 'UNIQUE constraint') || str_contains($m, '1062');
}
function is_fk(Throwable $e): bool
{
    $m = $e->getMessage();
    return str_contains($m, 'foreign key') || str_contains($m, 'FOREIGN KEY') || str_contains($m, '1451');
}

/** Opsi <select> dari array [nilai => label]. */
function opsi(array $daftar, $terpilih = null, string $kosong = ''): string
{
    $h = $kosong !== '' ? '<option value="">' . aman($kosong) . '</option>' : '';
    foreach ($daftar as $v => $l) {
        $sel = ((string) $v === (string) $terpilih) ? ' selected' : '';
        $h .= '<option value="' . aman($v) . '"' . $sel . '>' . aman($l) . '</option>';
    }
    return $h;
}

/** Peringatan alergi obat (PPT Bab 6 CPOE). Cocokkan nama obat dengan kata kunci alergi pasien. */
function cek_alergi(?string $alergi, string $nama_obat): ?string
{
    $alergi = strtolower(trim((string) $alergi));
    if ($alergi === '' || in_array($alergi, ['-', 'tidak ada', 'tidak diketahui'], true)) return null;
    // golongan obat: alergi penisilin juga berlaku untuk amoxicillin, dst.
    $golongan = [
        'penisilin'  => ['penisilin', 'penicillin', 'amoxicillin', 'ampicillin'],
        'sulfonamid' => ['sulfonamid', 'sulfa', 'cotrimoxazole', 'kotrimoksazol'],
        'nsaid'      => ['ibuprofen', 'asam mefenamat', 'diklofenak', 'ketorolac'],
    ];
    $obat = strtolower($nama_obat);
    foreach (preg_split('/[,;\/]+/', $alergi) as $kata) {
        $kata = trim($kata);
        if (strlen($kata) < 3) continue;
        $daftar = [$kata];
        foreach ($golongan as $g => $anggota) {
            if (str_contains($kata, $g) || in_array($kata, $anggota, true)) $daftar = array_merge($daftar, $anggota);
        }
        foreach ($daftar as $k) {
            if (str_contains($obat, $k)) return $kata;
        }
    }
    return null;
}

/** Nama modul untuk TAMPILAN (bahasa Indonesia, tanpa istilah teknis). Kode modul di database tetap. */
function nama_modul(string $kode_atau_nama): string
{
    static $map = [
        'auth' => 'Akun & Hak Akses', 'registrasi' => 'Pendaftaran Pasien', 'emr' => 'Rekam Medis', 'cpoe' => 'Order Klinis',
        'rawat_jalan' => 'Rawat Jalan', 'rawat_inap' => 'Rawat Inap', 'igd' => 'IGD', 'lab' => 'Laboratorium', 'radiologi' => 'Radiologi',
        'farmasi' => 'Farmasi', 'billing' => 'Billing & Asuransi', 'jadwal' => 'Jadwal & Janji Temu', 'keperawatan' => 'Keperawatan',
        'inventori' => 'Gudang & Persediaan', 'laporan' => 'Laporan', 'master' => 'Data Master', 'portal' => 'Akun Pasien',
        'Authentication' => 'Akun & Login', 'Patient Registration' => 'Pendaftaran Pasien', 'Electronic Medical Record' => 'Rekam Medis',
        'CPOE' => 'Order Klinis', 'Outpatient' => 'Rawat Jalan', 'Inpatient' => 'Rawat Inap', 'Emergency Department' => 'IGD',
        'Laboratory' => 'Laboratorium', 'Radiology' => 'Radiologi', 'Pharmacy' => 'Farmasi', 'Billing' => 'Billing & Asuransi',
        'Scheduling & Appointment' => 'Jadwal & Janji Temu', 'Nursing' => 'Keperawatan', 'Inventory' => 'Gudang & Persediaan',
        'Reporting' => 'Laporan', 'Master Data' => 'Data Master', 'Portal' => 'Akun Pasien', 'Sistem' => 'Sistem', 'RBAC' => 'Hak Akses',
    ];
    return $map[$kode_atau_nama] ?? $kode_atau_nama;
}
