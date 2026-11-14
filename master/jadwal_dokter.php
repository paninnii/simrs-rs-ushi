<?php
/*
 * KEPEGAWAIAN: JADWAL PRAKTIK DOKTER & SHIFT PERAWAT/BIDAN -- satu tabel `jadwal_pegawai` (beda di kolom jenis).
 * Jadwal Praktik dipakai Pendaftaran, Janji Temu, dan halaman publik. Unit menentukan cabang tempat praktik.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/crud.php';

$pdo = getPDO();
$pegawai = $pdo->query("SELECT g.id, CONCAT(g.nama_lengkap, ' · ', g.jenis_tenaga, ' · ', rs.nama_cabang) FROM pegawai g JOIN konfigurasi_rs rs ON rs.id = g.id_rs
                        WHERE g.jenis_tenaga IN ('Dokter','Perawat','Bidan') AND g.status = 'Aktif'" . sql_rs('g.id_rs') . " ORDER BY g.jenis_tenaga, g.nama_lengkap")->fetchAll(PDO::FETCH_KEY_PAIR);
$unit = $pdo->query("SELECT u.id, CONCAT(u.nama, ' · ', rs.nama_cabang) FROM unit_layanan u JOIN konfigurasi_rs rs ON rs.id = u.id_rs WHERE u.aktif = 1" . sql_rs('u.id_rs') . " ORDER BY u.id_rs, u.jenis DESC, u.nama")->fetchAll(PDO::FETCH_KEY_PAIR);
$hari = array_combine(['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'], ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu']);

master_crud([
    'judul' => 'Jadwal', 'judul_halaman' => 'Jadwal Praktik Dokter & Shift Perawat', 'tabel' => 'jadwal_pegawai',
    'sub' => 'Satu pegawai hanya boleh punya satu jadwal per unit per hari. Praktik = jadwal dokter (kuota & slot janji temu); Shift = jadwal jaga perawat / bidan.',
    'sql_list' => "SELECT j.*, p.nama_lengkap, u.nama AS unit FROM jadwal_pegawai j JOIN pegawai p ON p.id=j.id_pegawai JOIN unit_layanan u ON u.id=j.id_unit WHERE 1=1" . sql_rs('u.id_rs'),
    'urut' => "j.jenis, p.nama_lengkap, CASE j.hari WHEN 'Senin' THEN 1 WHEN 'Selasa' THEN 2 WHEN 'Rabu' THEN 3 WHEN 'Kamis' THEN 4 WHEN 'Jumat' THEN 5 WHEN 'Sabtu' THEN 6 ELSE 7 END",
    'cari' => ['p.nama_lengkap', 'u.nama'],
    'filter' => ['jns' => ['label' => 'Praktik & Shift', 'sql' => 'j.jenis = ?', 'opsi' => ['Praktik' => 'Praktik dokter', 'Shift' => 'Shift perawat / bidan']],
                 'pegawai' => ['label' => 'Semua pegawai', 'sql' => 'j.id_pegawai = ?', 'opsi' => $pegawai], 'unit' => ['label' => 'Semua unit', 'sql' => 'j.id_unit = ?', 'opsi' => $unit]],
    'kolom' => [
        'jenis'       => ['label' => 'Jenis Jadwal', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => ['Praktik' => 'Praktik (dokter)', 'Shift' => 'Shift (perawat / bidan)'], 'default' => 'Praktik'],
        'id_pegawai'  => ['label' => 'Pegawai', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $pegawai],
        'id_unit'     => ['label' => 'Unit & Cabang', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $unit],
        'hari'        => ['label' => 'Hari', 'tipe' => 'select', 'wajib' => true, 'list' => true, 'opsi' => $hari],
        'jam_mulai'   => ['label' => 'Jam Mulai', 'tipe' => 'time', 'wajib' => true, 'list' => true, 'format' => 'jam'],
        'jam_selesai' => ['label' => 'Jam Selesai', 'tipe' => 'time', 'wajib' => true, 'list' => true, 'format' => 'jam'],
        'durasi_slot' => ['label' => 'Durasi Slot Janji Temu (menit)', 'label_list' => 'Slot', 'tipe' => 'number', 'wajib' => true, 'min' => 5, 'maks' => 60, 'step' => 5, 'default' => 15, 'list' => true],
        'kuota'       => ['label' => 'Kuota Pasien/Hari', 'label_list' => 'Kuota', 'tipe' => 'number', 'wajib' => true, 'min' => 1, 'maks' => 200, 'step' => 1, 'default' => 20, 'list' => true, 'hint' => 'Untuk shift isi 1.'],
        'aktif'       => ['label' => 'Status Aktif', 'tipe' => 'aktif', 'list' => true],
    ],
    'validasi_form' => function (array $d, $id) use ($pdo) {
        $e = [];
        if ($d['jam_selesai'] <= $d['jam_mulai']) $e['jam_selesai'] = 'Jam selesai harus setelah jam mulai.';
        $jt = $pdo->prepare('SELECT jenis_tenaga FROM pegawai WHERE id = ?'); $jt->execute([$d['id_pegawai']]); $jt = $jt->fetchColumn();
        if ($d['jenis'] === 'Praktik' && $jt !== 'Dokter') $e['jenis'] = 'Jadwal Praktik hanya untuk dokter. Pilih Shift untuk perawat / bidan.';
        if ($d['jenis'] === 'Shift' && $jt === 'Dokter') $e['jenis'] = 'Dokter memakai jenis Praktik.';
        $s = $pdo->prepare('SELECT COUNT(*) FROM jadwal_pegawai WHERE id_pegawai=? AND id_unit=? AND hari=?' . ($id !== '' ? ' AND id<>?' : ''));
        $s->execute($id !== '' ? [$d['id_pegawai'], $d['id_unit'], $d['hari'], $id] : [$d['id_pegawai'], $d['id_unit'], $d['hari']]);
        if ($s->fetchColumn()) $e['hari'] = 'Pegawai ini sudah punya jadwal di unit tersebut pada hari ' . $d['hari'] . '.';
        return $e;
    },
]);
