<?php
/* DATA LAYANAN: PENJAMIN / ASURANSI */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/crud.php';

master_crud([
    'judul' => 'Penjamin', 'tabel' => 'penjamin', 'urut' => 'id', 'cari' => ['kode', 'nama'],
    'sub' => 'Tipe BPJS dan Asuransi wajib melalui verifikasi Verifikator sebelum dibayar.',
    'kolom' => [
        'kode'  => ['label' => 'Kode', 'wajib' => true, 'max' => 20, 'unik' => true, 'upper' => true, 'list' => true],
        'nama'  => ['label' => 'Nama Penjamin', 'wajib' => true, 'max' => 100, 'unik' => true, 'list' => true, 'tebal' => true],
        'tipe'  => ['label' => 'Tipe', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => ['Umum' => 'Umum / Pribadi', 'BPJS' => 'BPJS', 'Asuransi' => 'Asuransi Swasta']],
        'aktif' => ['label' => 'Status Aktif', 'tipe' => 'aktif', 'list' => true],
    ],
]);
