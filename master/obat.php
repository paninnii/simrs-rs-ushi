<?php
/* DATA LAYANAN: OBAT & ALKES. Stok diubah lewat Inventori/Farmasi, bukan di sini. */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/crud.php';

master_crud([
    'judul' => 'Obat & Alkes', 'judul_halaman' => 'Data Obat & Alat Kesehatan', 'tabel' => 'obat', 'urut' => 'kategori, nama_obat', 'cari' => ['kode', 'nama_obat'],
    'kolom_nama' => 'nama_obat',
    'sub' => 'Formularium RS. Stok bertambah lewat penerimaan barang (Gudang) dan berkurang saat dispensing (Farmasi).',
    'filter' => ['kategori' => ['label' => 'Semua kategori', 'sql' => 'kategori = ?', 'opsi' => ['Obat' => 'Obat', 'Alkes/BHP' => 'Alkes/BHP']]],
    'kolom' => [
        'kode'         => ['label' => 'Kode', 'wajib' => true, 'max' => 20, 'unik' => true, 'upper' => true, 'list' => true],
        'nama_obat'    => ['label' => 'Nama Obat / Barang', 'wajib' => true, 'max' => 150, 'unik' => true, 'list' => true, 'tebal' => true],
        'kategori'     => ['label' => 'Kategori', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => ['Obat' => 'Obat', 'Alkes/BHP' => 'Alkes / BHP']],
        'satuan'       => ['label' => 'Satuan', 'wajib' => true, 'max' => 30, 'list' => true, 'placeholder' => 'tablet, botol, vial, box'],
        'harga_satuan' => ['label' => 'Harga per Satuan (Rp)', 'tipe' => 'number', 'wajib' => true, 'min' => 0, 'maks' => 100000000, 'step' => 100, 'list' => true, 'format' => 'rupiah'],
        'stok_minimum' => ['label' => 'Stok Minimum', 'tipe' => 'number', 'wajib' => true, 'min' => 0, 'maks' => 100000, 'step' => 1, 'list' => true, 'hint' => 'Di bawah angka ini muncul peringatan stok menipis.'],
        'high_alert'   => ['label' => 'High Alert', 'tipe' => 'aktif', 'list' => false],
        'lasa'         => ['label' => 'LASA (mirip nama/rupa)', 'tipe' => 'aktif', 'list' => false],
        'aktif'        => ['label' => 'Status Aktif', 'tipe' => 'aktif', 'list' => true],
    ],
    'kolom_tambahan' => [
        'Stok' => fn($r) => '<b class="' . ($r['stok'] < $r['stok_minimum'] ? 'text-red-700' : '') . '">' . (int) $r['stok'] . '</b> ' . aman($r['satuan']),
        'Penanda' => fn($r) => ($r['high_alert'] ? '<span class="badge badge-red">High Alert</span> ' : '') . ($r['lasa'] ? '<span class="badge badge-purple">LASA</span>' : ''),
    ],
]);
