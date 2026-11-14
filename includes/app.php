<?php
/*
 * BOOTSTRAP: di-include di baris pertama SETIAP halaman.
 *   require_once __DIR__ . '/../includes/app.php';   (halaman di dalam folder modul)
 *   require_once __DIR__ . '/includes/app.php';      (halaman di folder utama)
 */
date_default_timezone_set('Asia/Jakarta');
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('APP_ROOT', str_replace('\\', '/', dirname(__DIR__)));

// Sesi login (PPT Bab 3). Cookie httponly agar tidak bisa dibaca JavaScript.
if (session_status() === PHP_SESSION_NONE) {
    session_name('SIMRS_USHI');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require_once APP_ROOT . '/config/database.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/validasi.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/layout.php';
