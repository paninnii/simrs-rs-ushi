<?php
/*
 * KONEKSI DATABASE (PPT Bab 3: koneksi.php dengan PDO)
 * ----------------------------------------------------------------
 * Untuk XAMPP biasanya TIDAK perlu diubah:
 *   host = localhost, database = db_simrs_ushi, user = root, password kosong.
 *
 * Kalau di-upload ke hosting, ganti 4 nilai di bawah sesuai data
 * MySQL dari hosting (atau isi lewat environment variable DB_HOST, dst).
 */
const DB_HOST = 'localhost';
const DB_PORT = '3306';
const DB_NAME = 'db_simrs_ushi';
const DB_USER = 'root';
const DB_PASS = '';

if (!function_exists('getPDO')) {
    function getPDO(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        $host = getenv('DB_HOST') ?: DB_HOST;
        $port = getenv('DB_PORT') ?: DB_PORT;
        $nama = getenv('DB_NAME') ?: DB_NAME;
        $user = getenv('DB_USER') ?: DB_USER;
        $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : DB_PASS;

        try {
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$nama;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // error jadi exception
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // hasil berupa array asosiatif
                PDO::ATTR_EMULATE_PREPARES   => false,                    // prepared statement asli
            ]);
            $pdo->exec("SET time_zone = '+07:00'");                     // WIB
        } catch (PDOException $e) {
            http_response_code(500);
            die('<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;border:2px solid #b91c2c;border-radius:12px">'
              . '<h2 style="color:#b91c2c;margin-top:0">Gagal menyambung ke database</h2>'
              . '<p>' . htmlspecialchars($e->getMessage()) . '</p>'
              . '<p>Periksa: (1) MySQL sudah <b>Start</b> di XAMPP, (2) file <code>database/db_simrs_ushi.sql</code> '
              . 'sudah di-Import di phpMyAdmin, (3) pengaturan di <code>config/database.php</code>.</p></div>');
        }
        return $pdo;
    }
}
