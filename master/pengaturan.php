<?php
/*
 * KONFIGURASI RS & CABANG (tabel konfigurasi_rs)
 * Satu baris = satu rumah sakit (pusat) atau cabang. Semua transaksi (kunjungan, rekam medis, pegawai, unit, audit)
 * menyimpan id_rs, sehingga selalu jelas data itu dari RS / cabang mana.
 * Nilai di sini dipakai di halaman publik, nota pembayaran, label obat, hasil lab/radiologi, laporan, dan batas persetujuan asuransi.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/crud.php';

$pusat = getPDO()->query("SELECT id, CONCAT(singkatan, ' ', nama_cabang) FROM konfigurasi_rs WHERE jenis = 'Pusat' ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
master_crud([
    'judul' => 'Cabang', 'judul_halaman' => 'Konfigurasi RS & Cabang', 'tabel' => 'konfigurasi_rs', 'urut' => 'jenis DESC, id', 'cari' => ['nama_cabang', 'kota', 'kode'],
    'sub' => 'Identitas rumah sakit pusat dan cabang. Dipakai di halaman publik, nota, hasil pemeriksaan, laporan, dan batas persetujuan asuransi.',
    'info' => 'Setiap data pelayanan (kunjungan, rekam medis, pegawai, unit, jadwal, audit) menyimpan <b>id_rs</b> sehingga selalu tercatat di cabang mana. '
            . 'Cabang tidak bisa dihapus bila sudah punya data; nonaktifkan saja.',
    'kolom' => [
        'kode'         => ['label' => 'Kode Cabang', 'wajib' => true, 'max' => 10, 'unik' => true, 'upper' => true, 'list' => true, 'pola' => '/^[A-Z]{3}$/', 'pesan_pola' => 'Kode 3 huruf kapital, contoh: BGR.', 'hanya_baru' => true],
        'jenis'        => ['label' => 'Status', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => ['Pusat' => 'Pusat', 'Cabang' => 'Cabang'], 'default' => 'Cabang'],
        'id_pusat'     => ['label' => 'Induk (untuk cabang)', 'tipe' => 'select', 'opsi' => $pusat, 'hint' => 'Kosongkan untuk RS pusat.'],
        'nama_cabang'  => ['label' => 'Nama Cabang', 'wajib' => true, 'max' => 60, 'list' => true, 'tebal' => true, 'placeholder' => 'contoh: Bogor'],
        'nama_rs'      => ['label' => 'Nama Resmi Rumah Sakit', 'wajib' => true, 'max' => 150],
        'singkatan'    => ['label' => 'Nama Singkat (logo & judul)', 'wajib' => true, 'max' => 30, 'list' => true],
        'slogan'       => ['label' => 'Slogan', 'max' => 150],
        'alamat'       => ['label' => 'Alamat Lengkap', 'wajib' => true, 'max' => 255, 'list' => true, 'lebar' => true],
        'kota'         => ['label' => 'Kota', 'wajib' => true, 'max' => 60, 'list' => true],
        'telepon'      => ['label' => 'Telepon Pendaftaran', 'wajib' => true, 'max' => 20, 'list' => true, 'pola' => '/^[0-9 ()+-]{6,20}$/', 'pesan_pola' => 'Nomor telepon tidak valid.'],
        'telepon_igd'  => ['label' => 'Telepon IGD 24 Jam', 'wajib' => true, 'max' => 20, 'list' => true, 'pola' => '/^[0-9 ()+-]{3,20}$/', 'pesan_pola' => 'Nomor telepon tidak valid.'],
        'email'        => ['label' => 'Email', 'max' => 100, 'validasi' => fn($v) => v_email($v)],
        'kode_faskes'  => ['label' => 'Kode Faskes (SATUSEHAT / BPJS)', 'wajib' => true, 'max' => 20, 'unik' => true, 'list' => true, 'pola' => '/^[0-9]{7,8}$/', 'pesan_pola' => 'Kode faskes 7-8 digit angka.'],
        'lokasi_igd'   => ['label' => 'Lokasi IGD', 'wajib' => true, 'max' => 100, 'placeholder' => 'contoh: Gedung B lantai 1'],
        'jam_besuk'    => ['label' => 'Jam Besuk Rawat Inap', 'wajib' => true, 'max' => 100, 'placeholder' => 'contoh: 11.00–13.00 dan 17.00–19.00'],
        'batas_persetujuan_asuransi' => ['label' => 'Batas Biaya Perlu Persetujuan Asuransi (Rp)', 'label_list' => 'Batas Persetujuan', 'tipe' => 'number', 'wajib' => true, 'min' => 0, 'maks' => 100000000, 'step' => 50000, 'list' => true, 'format' => 'rupiah',
                                         'hint' => 'Order lab/radiologi pasien asuransi swasta dengan biaya ≥ nilai ini harus disetujui Verifikator dulu.'],
        'aktif'        => ['label' => 'Status Aktif', 'tipe' => 'aktif', 'list' => true],
    ],
    'validasi_form' => function (array $d, $id) {
        $e = [];
        if ($d['jenis'] === 'Pusat' && $d['id_pusat']) $e['id_pusat'] = 'RS pusat tidak punya induk. Kosongkan.';
        if ($d['jenis'] === 'Cabang' && !$d['id_pusat']) $e['id_pusat'] = 'Cabang wajib memilih RS induk (pusat).';
        if ($id !== '' && (string) $d['id_pusat'] === (string) $id) $e['id_pusat'] = 'Cabang tidak boleh menjadi induk dirinya sendiri.';
        return $e;
    },
]);
