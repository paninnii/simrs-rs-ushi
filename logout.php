<?php
require_once __DIR__ . '/includes/app.php';
if (user()) audit('Authentication', 'Logout', 'Pengguna keluar');
$_SESSION = [];
session_destroy();
session_start();
flash('sukses', 'Anda telah keluar dari SIMRS.');
redirect('login.php');
