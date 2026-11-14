<?php
/*
 * VALIDASI INPUT (PPT Bab 3 / P06: "NEVER trust user input")
 * Setiap fungsi mengembalikan PESAN ERROR (string) atau null bila valid.
 * Aturan yang sama juga dijaga database lewat CHECK & UNIQUE, jadi data
 * tetap aman walaupun validasi di browser dilewati.
 */

function v_wajib($nilai, string $label): ?string
{
    return trim((string) $nilai) === '' ? "$label wajib diisi." : null;
}

/** Nama orang: 3-150 huruf, boleh spasi titik koma petik dan strip (untuk gelar). */
function v_nama($nama, string $label = 'Nama lengkap'): ?string
{
    $nama = trim((string) $nama);
    if ($nama === '') return "$label wajib diisi.";
    if (mb_strlen($nama) < 3) return "$label minimal 3 huruf.";
    if (mb_strlen($nama) > 150) return "$label maksimal 150 karakter.";
    if (!preg_match("/^[\p{L} .,'\-]+$/u", $nama)) return "$label hanya boleh berisi huruf, spasi, titik, koma, dan tanda petik.";
    return null;
}

/** NIK KTP: tepat 16 digit angka, tidak boleh 16 angka sama. */
function v_nik($nik): ?string
{
    $nik = (string) $nik;
    if ($nik === '') return 'NIK wajib diisi.';
    if (!ctype_digit($nik)) return 'NIK hanya boleh berisi angka.';
    if (strlen($nik) !== 16) return 'NIK harus tepat 16 digit (sekarang ' . strlen($nik) . ' digit).';
    if (preg_match('/^(\d)\1{15}$/', $nik)) return 'NIK tidak valid (16 angka sama).';
    if ((int) substr($nik, 0, 2) < 11 || (int) substr($nik, 0, 2) > 94) return 'NIK tidak valid: 2 digit awal harus kode provinsi (11-94).';
    return null;
}

/** Nomor HP Indonesia: diawali 08 / 62 / +62, total 10-13 digit. */
function v_hp($hp, bool $wajib = true): ?string
{
    $hp = (string) $hp;
    if ($hp === '') return $wajib ? 'Nomor HP wajib diisi.' : null;
    if (!preg_match('/^(08|\+628|628)[0-9]{8,11}$/', $hp)) {
        return 'Nomor HP harus diawali 08 atau +62 dan berisi 10-13 digit angka (contoh 081234567890).';
    }
    return null;
}

function v_bpjs($no, bool $wajib = false): ?string
{
    $no = (string) $no;
    if ($no === '') return $wajib ? 'No. kartu BPJS wajib diisi untuk penjamin BPJS.' : null;
    return preg_match('/^[0-9]{13}$/', $no) ? null : 'No. kartu BPJS harus tepat 13 digit angka.';
}

function v_tanggal($tgl, string $label = 'Tanggal'): ?string
{
    $tgl = (string) $tgl;
    if ($tgl === '') return "$label wajib diisi.";
    $d = DateTime::createFromFormat('Y-m-d', $tgl);
    return ($d && $d->format('Y-m-d') === $tgl) ? null : "$label tidak valid.";
}

/** Tanggal lahir: tidak boleh di masa depan dan tidak lebih dari 120 tahun lalu. */
function v_tgl_lahir($tgl): ?string
{
    if ($e = v_tanggal($tgl, 'Tanggal lahir')) return $e;
    if ($tgl > date('Y-m-d')) return 'Tanggal lahir tidak boleh di masa depan.';
    if ($tgl < date('Y-m-d', strtotime('-120 years'))) return 'Tanggal lahir tidak masuk akal (lebih dari 120 tahun).';
    return null;
}

function v_email($email, bool $wajib = false): ?string
{
    $email = (string) $email;
    if ($email === '') return $wajib ? 'Email wajib diisi.' : null;
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? null : 'Format email tidak valid.';
}

/** Angka bulat/desimal dalam rentang. */
function v_angka($nilai, string $label, float $min, float $max, bool $wajib = true): ?string
{
    $nilai = str_replace(',', '.', (string) $nilai);
    if ($nilai === '') return $wajib ? "$label wajib diisi." : null;
    if (!is_numeric($nilai)) return "$label harus berupa angka.";
    if ((float) $nilai < $min || (float) $nilai > $max) return "$label harus di antara " . rtrim(rtrim(number_format($min, 1, ',', '.'), '0'), ',') . ' dan ' . rtrim(rtrim(number_format($max, 1, ',', '.'), '0'), ',') . '.';
    return null;
}

/** Tekanan darah sistolik/diastolik, contoh: 120/80. */
function v_td($td, bool $wajib = true): ?string
{
    $td = (string) $td;
    if ($td === '') return $wajib ? 'Tekanan darah wajib diisi.' : null;
    if (!preg_match('/^(\d{2,3})\/(\d{2,3})$/', $td, $m)) return 'Tekanan darah ditulis sistolik/diastolik, contoh 120/80.';
    if ((int) $m[1] <= (int) $m[2]) return 'Sistolik harus lebih besar dari diastolik.';
    if ((int) $m[1] > 300 || (int) $m[2] < 20) return 'Nilai tekanan darah di luar batas wajar.';
    return null;
}

/** Password kuat untuk akun baru / ganti password. */
function v_password($pw, string $username = ''): ?string
{
    $pw = (string) $pw;
    if (strlen($pw) < 8) return 'Password minimal 8 karakter.';
    if (strtolower($pw) === 'password' || ($username !== '' && stripos($pw, $username) !== false)) return 'Password terlalu mudah ditebak (jangan "password" atau memuat username).';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) return 'Password harus mengandung huruf dan angka.';
    return null;
}

/** Tampilkan pesan error di bawah field. */
function err(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<p class="field-error">' . aman($errors[$field]) . '</p>' : '';
}
function err_class(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}
/** Isi ulang form dengan nilai lama setelah gagal validasi. */
function old(string $k, $default = ''): string
{
    return aman($_POST[$k] ?? $default);
}
