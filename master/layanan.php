<?php
/*
 * DATA LAYANAN: SATU TABEL `layanan` UNTUK SEMUA TARIF (payung besar)
 *   layanan.php?kategori=Tindakan      -> tarif tindakan & administrasi
 *   layanan.php?kategori=Laboratorium  -> pemeriksaan lab
 *   layanan.php?kategori=Paket Lab     -> paket lab (isi = daftar kode lab)
 *   layanan.php?kategori=Radiologi     -> pemeriksaan radiologi (modalitas di kolom sub_kategori)
 * Kolom yang tidak relevan untuk suatu kategori dibiarkan kosong.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/crud.php';

$KAT = ['Tindakan' => 'Tarif Tindakan & Administrasi', 'Laboratorium' => 'Pemeriksaan Laboratorium', 'Paket Lab' => 'Paket Laboratorium', 'Radiologi' => 'Pemeriksaan Radiologi'];
$kat = get('kategori', 'Tindakan');
if (!isset($KAT[$kat])) $kat = 'Tindakan';
$GLOBALS['TABEL_HALAMAN'] = 'layanan (kategori = ' . ($kat === 'Tindakan' ? 'Tindakan / Administrasi' : $kat) . ')';
$ya = ['0' => 'Tidak', '1' => 'Ya'];
$umum = [
    'kode'  => ['label' => 'Kode', 'wajib' => true, 'max' => 20, 'unik' => true, 'upper' => true, 'list' => true, 'pola' => '/^[A-Z0-9-]{3,20}$/', 'pesan_pola' => 'Kode 3-20 huruf kapital/angka/strip, contoh: TND-17.'],
    'nama'  => ['label' => 'Nama', 'wajib' => true, 'max' => 150, 'list' => true, 'tebal' => true],
];
$aktif = ['aktif' => ['label' => 'Status Aktif', 'tipe' => 'aktif', 'list' => true]];

if ($kat === 'Tindakan') {
    $kolom = $umum + [
        'kategori' => ['label' => 'Kategori', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => ['Tindakan' => 'Tindakan', 'Administrasi' => 'Administrasi'], 'default' => 'Tindakan'],
        'sub_kategori' => ['label' => 'Kelompok', 'max' => 30, 'list' => true, 'placeholder' => 'contoh: Tindakan Medis'],
        'harga' => ['label' => 'Harga (Rp)', 'tipe' => 'number', 'wajib' => true, 'min' => 0, 'maks' => 100000000, 'step' => 500, 'list' => true, 'format' => 'rupiah'],
        'wajib_keterangan' => ['label' => 'Dokter wajib isi keterangan/lokasi', 'label_list' => 'Wajib Ket.', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $ya, 'default' => '0'],
        'invasif' => ['label' => 'Tindakan invasif (wajib persetujuan tindakan)', 'label_list' => 'Invasif', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $ya, 'default' => '0'],
        'petunjuk' => ['label' => 'Contoh isian keterangan', 'max' => 120, 'placeholder' => 'contoh: luka robek regio cruris kanan 3 cm, 4 jahitan'],
    ] + $aktif;
    $sql = "SELECT * FROM layanan t WHERE kategori IN ('Tindakan','Administrasi')";
    $filter = ['jenis_tarif' => ['label' => 'Tindakan & Administrasi', 'sql' => 'kategori = ?', 'opsi' => ['Tindakan' => 'Tindakan', 'Administrasi' => 'Administrasi']]];
    $sub = 'Dipakai otomatis oleh Order Klinis dan Billing. Tarif konsultasi diatur di Poliklinik & IGD, tarif kamar di Ruang Rawat & Bed.';
} elseif ($kat === 'Laboratorium') {
    $sk = ['Hematologi' => 'Hematologi', 'Kimia Klinik' => 'Kimia Klinik', 'Urinalisis' => 'Urinalisis', 'Imunologi' => 'Imunologi', 'Koagulasi' => 'Koagulasi', 'Mikrobiologi' => 'Mikrobiologi'];
    $kolom = $umum + [
        'kategori' => ['label' => 'Kategori', 'tipe' => 'select', 'wajib' => true, 'opsi' => ['Laboratorium' => 'Laboratorium'], 'default' => 'Laboratorium'],
        'sub_kategori' => ['label' => 'Bagian Lab', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $sk],
        'spesimen' => ['label' => 'Jenis Spesimen', 'wajib' => true, 'max' => 40, 'list' => true, 'placeholder' => 'contoh: Darah vena (EDTA)'],
        'perlu_puasa' => ['label' => 'Perlu Puasa', 'label_list' => 'Puasa', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $ya, 'default' => '0'],
        'persiapan' => ['label' => 'Persiapan Pasien', 'max' => 255, 'placeholder' => 'contoh: Puasa 8–10 jam, boleh minum air putih.'],
        'harga' => ['label' => 'Biaya (Rp)', 'tipe' => 'number', 'wajib' => true, 'min' => 0, 'maks' => 50000000, 'step' => 500, 'list' => true, 'format' => 'rupiah'],
        'nilai_normal' => ['label' => 'Nilai Rujukan / Normal', 'tipe' => 'textarea', 'wajib' => true, 'max' => 255, 'list' => true, 'lebar' => true],
    ] + $aktif;
    $sql = "SELECT * FROM layanan t WHERE kategori = 'Laboratorium'";
    $filter = ['sub_kategori' => ['label' => 'Semua bagian', 'sql' => 'sub_kategori = ?', 'opsi' => $sk]];
    $sub = 'Daftar pemeriksaan yang bisa diorder dokter. Hasil diinput ATLM dan divalidasi dokter Sp.PK.';
} elseif ($kat === 'Paket Lab') {
    $kode_lab = getPDO()->query("SELECT kode FROM layanan WHERE kategori = 'Laboratorium'")->fetchAll(PDO::FETCH_COLUMN);
    $kolom = $umum + [
        'kategori' => ['label' => 'Kategori', 'tipe' => 'select', 'wajib' => true, 'opsi' => ['Paket Lab' => 'Paket Lab'], 'default' => 'Paket Lab'],
        'sub_kategori' => ['label' => 'Kelompok', 'tipe' => 'select', 'wajib' => true, 'opsi' => ['Paket' => 'Paket'], 'default' => 'Paket'],
        'isi_paket' => ['label' => 'Isi Paket (kode lab, pisahkan koma)', 'wajib' => true, 'max' => 255, 'list' => true, 'upper' => true, 'placeholder' => 'contoh: HEM-01,IM-01,IM-02',
                        'validasi' => function ($v) use ($kode_lab) { $salah = array_diff(array_map('trim', explode(',', $v)), $kode_lab); return $salah ? 'Kode lab tidak dikenal: ' . implode(', ', $salah) : null; }],
        'harga' => ['label' => 'Harga Paket (Rp)', 'tipe' => 'number', 'wajib' => true, 'min' => 0, 'maks' => 50000000, 'step' => 500, 'list' => true, 'format' => 'rupiah'],
        'perlu_puasa' => ['label' => 'Perlu Puasa', 'label_list' => 'Puasa', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $ya, 'default' => '0'],
        'persiapan' => ['label' => 'Persiapan Pasien', 'max' => 255],
    ] + $aktif;
    $sql = "SELECT * FROM layanan t WHERE kategori = 'Paket Lab'";
    $filter = [];
    $sub = 'Paket muncul sebagai tombol "Paket cepat" di form order laboratorium. Isi paket = kode pemeriksaan lab.';
} else {
    $mod = ['X-Ray' => 'X-Ray', 'USG' => 'USG', 'CT Scan' => 'CT Scan', 'MRI' => 'MRI'];
    $kolom = $umum + [
        'kategori' => ['label' => 'Kategori', 'tipe' => 'select', 'wajib' => true, 'opsi' => ['Radiologi' => 'Radiologi'], 'default' => 'Radiologi'],
        'sub_kategori' => ['label' => 'Modalitas', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $mod],
        'bagian_tubuh' => ['label' => 'Bagian Tubuh', 'wajib' => true, 'max' => 60, 'list' => true, 'placeholder' => 'contoh: Thorax'],
        'bisa_kontras' => ['label' => 'Bisa dengan Kontras', 'label_list' => 'Kontras', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $ya, 'default' => '0'],
        'pilih_sisi' => ['label' => 'Perlu Pilih Sisi (Kanan/Kiri)', 'label_list' => 'Sisi', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $ya, 'default' => '0'],
        'harga' => ['label' => 'Biaya (Rp)', 'tipe' => 'number', 'wajib' => true, 'min' => 0, 'maks' => 50000000, 'step' => 500, 'list' => true, 'format' => 'rupiah'],
        'biaya_kontras' => ['label' => 'Tambahan Biaya Kontras (Rp)', 'label_list' => '+ Kontras', 'tipe' => 'number', 'wajib' => true, 'min' => 0, 'maks' => 20000000, 'step' => 500, 'list' => true, 'format' => 'rupiah', 'default' => 0],
        'persiapan' => ['label' => 'Persiapan Pasien', 'max' => 255, 'placeholder' => 'contoh: Puasa 6 jam sebelum pemeriksaan'],
    ] + $aktif;
    $sql = "SELECT * FROM layanan t WHERE kategori = 'Radiologi'";
    $filter = ['sub_kategori' => ['label' => 'Semua modalitas', 'sql' => 'sub_kategori = ?', 'opsi' => $mod]];
    $sub = 'Jenis pemeriksaan radiologi per modalitas dan bagian tubuh.';
}

master_crud([
    'judul' => $kat === 'Tindakan' ? 'Tarif' : ($kat === 'Paket Lab' ? 'Paket Lab' : 'Pemeriksaan ' . ($kat === 'Laboratorium' ? 'Lab' : 'Radiologi')),
    'judul_halaman' => $KAT[$kat], 'tabel' => 'layanan', 'sql_list' => $sql, 'urut' => 'kategori, sub_kategori, kode', 'cari' => ['kode', 'nama'],
    'sub' => $sub, 'filter' => $filter, 'kolom' => $kolom,
    'validasi_form' => function (array $d, $id) use ($kat) {
        $e = [];
        $pdo = getPDO();
        $s = $pdo->prepare('SELECT COUNT(*) FROM layanan WHERE kategori = ? AND nama = ?' . ($id !== '' ? ' AND id <> ?' : ''));
        $s->execute($id !== '' ? [$d['kategori'], $d['nama'], $id] : [$d['kategori'], $d['nama']]);
        if ($s->fetchColumn()) $e['nama'] = "Nama \"{$d['nama']}\" sudah terdaftar di kategori {$d['kategori']}.";
        if ($kat === 'Radiologi') {
            $s = $pdo->prepare("SELECT COUNT(*) FROM layanan WHERE kategori = 'Radiologi' AND sub_kategori = ? AND bagian_tubuh = ?" . ($id !== '' ? ' AND id <> ?' : ''));
            $s->execute($id !== '' ? [$d['sub_kategori'], $d['bagian_tubuh'], $id] : [$d['sub_kategori'], $d['bagian_tubuh']]);
            if ($s->fetchColumn()) $e['bagian_tubuh'] = "{$d['sub_kategori']} {$d['bagian_tubuh']} sudah terdaftar.";
            if ((string) $d['bisa_kontras'] === '0' && (float) $d['biaya_kontras'] > 0) $e['biaya_kontras'] = 'Isi 0 bila pemeriksaan ini tidak memakai kontras.';
        }
        return $e;
    },
]);
