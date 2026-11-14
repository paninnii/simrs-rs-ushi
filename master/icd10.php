<?php
/* DATA LAYANAN: DIAGNOSIS ICD-10 (dipakai di EMR) */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/crud.php';

master_crud([
    'judul' => 'Diagnosis ICD-10', 'tabel' => 'icd10', 'pk' => 'kode', 'urut' => 'kode', 'cari' => ['kode', 'nama'],
    'sub' => 'Kode diagnosis standar WHO yang dipilih dokter saat mengisi Assessment SOAP.',
    'kolom_nama' => 'kode',
    'kolom' => [
        'kode'  => ['label' => 'Kode ICD-10', 'wajib' => true, 'max' => 10, 'unik' => true, 'upper' => true, 'list' => true, 'hanya_baru' => true, 'tebal' => true,
                    'pola' => '/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/', 'pesan_pola' => 'Format kode: 1 huruf + 2 angka, opsional titik + 1-4 karakter (contoh: J18.9, I10).', 'hint' => 'Kode tidak bisa diubah setelah disimpan.'],
        'nama'  => ['label' => 'Nama Diagnosis', 'wajib' => true, 'max' => 255, 'list' => true],
        'aktif' => ['label' => 'Status Aktif', 'tipe' => 'aktif', 'list' => true],
    ],
]);
