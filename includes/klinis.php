<?php
/*
 * FUNGSI BERSAMA MODUL PELAYANAN
 * (kunjungan & antrean, jadwal dokter, stok obat, tagihan, FHIR)
 */

/** Data lengkap satu kunjungan beserta pasien, unit (poli/IGD), dokter, penjamin, cabang. */
function ambil_kunjungan(int $id): ?array
{
    $s = getPDO()->prepare("SELECT k.*, k.id AS id_kunjungan, p.nomor_rm, p.nama_lengkap, p.nik, p.tanggal_lahir, p.jenis_kelamin, p.golongan_darah,
               p.alamat, p.no_hp, p.no_bpjs, p.alergi, u.nama AS poli, u.jenis AS jenis_unit, u.tarif AS tarif_konsultasi,
               d.nama_lengkap AS dokter, d.spesialisasi, pj.nama AS penjamin, pj.tipe AS tipe_penjamin, rs.nama_cabang
        FROM kunjungan k JOIN pasien p ON p.id = k.id_pasien JOIN unit_layanan u ON u.id = k.id_unit
        JOIN pegawai d ON d.id = k.id_dokter JOIN penjamin pj ON pj.id = k.id_penjamin JOIN konfigurasi_rs rs ON rs.id = k.id_rs WHERE k.id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Unit pelayanan aktif di cabang: [id => nama]. $jenis: 'Poliklinik', 'IGD', 'Rawat Inap' atau kosong (poli + IGD). */
function daftar_unit(int $id_rs, string $jenis = ''): array
{
    $sql = 'SELECT id, nama FROM unit_layanan WHERE aktif = 1' . ($id_rs ? ' AND id_rs = ' . (int) $id_rs : '')
         . ($jenis !== '' ? ' AND jenis = ' . getPDO()->quote($jenis) : " AND jenis IN ('Poliklinik','IGD')") . ' ORDER BY jenis DESC, nama';
    return getPDO()->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Jadwal praktik dokter di unit tertentu pada tanggal tertentu (null = tidak praktik). */
function jadwal_praktik(int $id_dokter, int $id_unit, string $tanggal): ?array
{
    $s = getPDO()->prepare("SELECT * FROM jadwal_pegawai WHERE id_pegawai = ? AND id_unit = ? AND hari = ? AND jenis = 'Praktik' AND aktif = 1");
    $s->execute([$id_dokter, $id_unit, hari_ini($tanggal)]);
    return $s->fetch() ?: null;
}

/** Dokter yang praktik di unit pada tanggal itu: [id_pegawai => "nama (jam)"] */
function dokter_praktik(int $id_unit, string $tanggal): array
{
    $s = getPDO()->prepare("SELECT d.id, d.nama_lengkap, j.jam_mulai, j.jam_selesai FROM jadwal_pegawai j JOIN pegawai d ON d.id = j.id_pegawai
                            WHERE j.id_unit = ? AND j.hari = ? AND j.jenis = 'Praktik' AND j.aktif = 1 AND d.status = 'Aktif' ORDER BY d.nama_lengkap");
    $s->execute([$id_unit, hari_ini($tanggal)]);
    $h = [];
    foreach ($s as $r) $h[$r['id']] = $r['nama_lengkap'] . ' (' . jam($r['jam_mulai']) . '-' . jam($r['jam_selesai']) . ')';
    return $h;
}

/**
 * Buat kunjungan baru + nomor antrean otomatis (huruf poli + urutan hari ini, contoh: B-004).
 * $d: id_pasien, id_unit, id_dokter, id_penjamin, no_kartu, keluhan, [tanggal], [id_kunjungan_janji]
 * Bila id_kunjungan_janji diisi, janji temu itu yang diubah menjadi kunjungan (status Menunggu + nomor antrean).
 * Mengembalikan [id_kunjungan, no_antrian] atau melempar Exception berisi pesan yang ramah.
 */
function buat_kunjungan(array $d): array
{
    $pdo = getPDO();
    $tanggal = $d['tanggal'] ?? date('Y-m-d');
    $s = $pdo->prepare("SELECT * FROM unit_layanan WHERE id = ? AND aktif = 1 AND jenis IN ('Poliklinik','IGD')");
    $s->execute([$d['id_unit']]);
    $unit = $s->fetch();
    if (!$unit) throw new Exception('Poliklinik / IGD tidak ditemukan atau nonaktif.');
    $igd = $unit['jenis'] === 'IGD';

    $j = jadwal_praktik((int) $d['id_dokter'], (int) $d['id_unit'], $tanggal);
    if (!$j) throw new Exception('Dokter tidak memiliki jadwal praktik di ' . $unit['nama'] . ' pada hari ' . hari_ini($tanggal) . '.');
    $s = $pdo->prepare("SELECT COUNT(*) FROM kunjungan WHERE id_dokter = ? AND id_unit = ? AND tanggal = ? AND no_antrian IS NOT NULL AND status <> 'Batal'");
    $s->execute([$d['id_dokter'], $d['id_unit'], $tanggal]);
    if (!$igd && $s->fetchColumn() >= $j['kuota']) throw new Exception('Kuota pasien dokter ini hari ini sudah penuh (' . $j['kuota'] . ' pasien).');

    // cegah pendaftaran ganda: pasien yang sama masih aktif di unit yang sama hari ini
    $s = $pdo->prepare("SELECT no_antrian FROM kunjungan WHERE id_pasien = ? AND id_unit = ? AND tanggal = ? AND status IN ('Menunggu','Dipanggil')");
    $s->execute([$d['id_pasien'], $d['id_unit'], $tanggal]);
    if ($ada = $s->fetchColumn()) throw new Exception("Pasien ini sudah terdaftar di {$unit['nama']} hari ini (antrean $ada) dan belum selesai dilayani.");

    for ($coba = 0; $coba < 5; $coba++) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM kunjungan WHERE id_unit = ? AND tanggal = ? AND no_antrian IS NOT NULL');
        $s->execute([$d['id_unit'], $tanggal]);
        $no = $unit['kode_antrian'] . '-' . str_pad((string) ($s->fetchColumn() + 1 + $coba), 3, '0', STR_PAD_LEFT);
        try {
            if (!empty($d['id_kunjungan_janji'])) {
                $pdo->prepare("UPDATE kunjungan SET no_antrian = ?, status = 'Menunggu', waktu_masuk = NOW(), id_penjamin = ?, no_kartu = ?, keluhan_awal = COALESCE(?, keluhan_awal), id_jadwal = ?
                               WHERE id = ? AND status IN ('Terkonfirmasi','Menunggu Konfirmasi')")
                    ->execute([$no, $d['id_penjamin'], $d['no_kartu'] ?: null, $d['keluhan'] ?: null, $j['id'], $d['id_kunjungan_janji']]);
                return [(int) $d['id_kunjungan_janji'], $no];
            }
            $pdo->prepare("INSERT INTO kunjungan (id_rs, id_pasien, tanggal, jam_janji, no_antrian, id_unit, id_dokter, id_jadwal, id_penjamin, no_kartu, keluhan_awal, jenis, sumber, status, waktu_masuk, dibuat_oleh)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'Langsung','Menunggu',NOW(),?)")
                ->execute([$unit['id_rs'], $d['id_pasien'], $tanggal, $d['jam'] ?? null, $no, $d['id_unit'], $d['id_dokter'], $j['id'], $d['id_penjamin'], $d['no_kartu'] ?: null,
                           $d['keluhan'] ?: null, $igd ? 'igd' : 'rawat_jalan', uid()]);
            return [(int) $pdo->lastInsertId(), $no];
        } catch (PDOException $e) {
            if (!is_duplikat($e)) throw $e;
        }
    }
    throw new Exception('Gagal membuat nomor antrean, silakan coba lagi.');
}

/** Validasi penjamin untuk kunjungan. Mengembalikan [error|null, no_kartu]. */
function cek_penjamin(int $id_penjamin, string $no_kartu, ?string $no_bpjs_pasien): array
{
    $s = getPDO()->prepare('SELECT * FROM penjamin WHERE id = ? AND aktif = 1');
    $s->execute([$id_penjamin]);
    $pj = $s->fetch();
    if (!$pj) return ['Pilih penjamin.', ''];
    if ($pj['tipe'] === 'BPJS') {
        $no = $no_kartu !== '' ? $no_kartu : (string) $no_bpjs_pasien;
        return [v_bpjs($no, true), $no];
    }
    if ($pj['tipe'] === 'Asuransi') {
        if (!preg_match('/^[A-Za-z0-9-]{5,30}$/', $no_kartu)) return ['No. polis / kartu asuransi wajib diisi (5-30 huruf/angka).', $no_kartu];
        return [null, strtoupper($no_kartu)];
    }
    return [null, ''];
}

/** Buat tagihan kosong saat pelayanan selesai. Umum langsung Terverifikasi; BPJS/Asuransi menunggu Verifikator. */
function buat_tagihan(int $id_kunjungan): void
{
    $pdo = getPDO();
    $s = $pdo->prepare('SELECT pj.tipe FROM kunjungan k JOIN penjamin pj ON pj.id = k.id_penjamin WHERE k.id = ?');
    $s->execute([$id_kunjungan]);
    $tipe = $s->fetchColumn();
    $s = $pdo->prepare('SELECT COUNT(*) FROM tagihan WHERE id_kunjungan = ?');
    $s->execute([$id_kunjungan]);
    if ($s->fetchColumn()) return;
    $pdo->prepare('INSERT INTO tagihan (no_tagihan, id_kunjungan, status) VALUES (?,?,?)')
        ->execute([kode_berikut('tagihan', 'no_tagihan', 'INV'), $id_kunjungan, $tipe === 'Umum' ? 'Terverifikasi' : 'Menunggu Verifikasi']);
}

/**
 * Hitung komponen tagihan OTOMATIS dari data pelayanan (PPT Bab 9 / P15).
 * Mengembalikan ['rincian' => [...], 'total' => n, 'tertunda' => [pesan...]]
 */
function hitung_komponen(int $id_kunjungan): array
{
    $pdo = getPDO();
    $k = ambil_kunjungan($id_kunjungan);
    $r = [];
    $adm = $pdo->query("SELECT kode, nama, harga FROM layanan WHERE kode IN ('ADM-01','ADM-02')")->fetchAll(PDO::FETCH_UNIQUE);
    $r[] = ['Administrasi', $adm['ADM-01']['nama'] ?? 'Administrasi Pendaftaran', 1, (float) ($adm['ADM-01']['harga'] ?? 15000)];
    $r[] = ['Konsultasi', ($k['jenis_unit'] === 'IGD' ? 'Jasa Dokter IGD ' : 'Jasa Konsultasi ') . $k['dokter'], 1, (float) $k['tarif_konsultasi']];

    $pribadi = 0;   // biaya pemeriksaan yang ditolak asuransi & disetujui pasien dibayar sendiri
    $s = $pdo->prepare("SELECT jenis, uraian, keterangan, jumlah, biaya, jaminan FROM order_pelayanan WHERE id_kunjungan = ?
                        AND ((jenis = 'Tindakan' AND status = 'Dikerjakan') OR (jenis <> 'Tindakan' AND status = 'Diterbitkan')) ORDER BY FIELD(jenis,'Tindakan','Laboratorium','Radiologi'), id");
    $s->execute([$id_kunjungan]);
    foreach ($s as $x) {
        $bs = $x['jaminan'] === 'Bayar Sendiri';
        if ($bs) $pribadi += (float) $x['biaya'] * (int) $x['jumlah'];
        $r[] = [$x['jenis'], $x['uraian'] . ($x['jenis'] === 'Tindakan' && $x['keterangan'] ? " ({$x['keterangan']})" : '') . ($bs ? ' (biaya pribadi)' : ''), (int) $x['jumlah'], (float) $x['biaya']];
    }

    $s = $pdo->prepare("SELECT o.nama_obat, o.satuan, o.harga_satuan, ri.jumlah - ri.jumlah_retur AS qty FROM resep re JOIN resep_item ri ON ri.id_resep = re.id
                        JOIN obat o ON o.id = ri.id_obat WHERE re.id_kunjungan = ? AND re.status IN ('Siap Diserahkan','Selesai')");
    $s->execute([$id_kunjungan]);
    foreach ($s as $x) if ($x['qty'] > 0) $r[] = ['Obat', $x['nama_obat'] . ' (' . $x['satuan'] . ')', (int) $x['qty'], (float) $x['harga_satuan']];

    $s = $pdo->prepare("SELECT ra.*, u.nama, u.kelas, u.tarif AS tarif_per_hari, b.kode_bed FROM rawat_inap ra JOIN bed b ON b.id = ra.id_bed JOIN unit_layanan u ON u.id = b.id_unit WHERE ra.id_kunjungan = ?");
    $s->execute([$id_kunjungan]);
    if ($ra = $s->fetch()) {
        $hari = max(1, (int) round((strtotime(substr($ra['tanggal_keluar'] ?? date('Y-m-d'), 0, 10)) - strtotime(substr($ra['tanggal_masuk'], 0, 10))) / 86400));
        $r[] = ['Administrasi', $adm['ADM-02']['nama'] ?? 'Administrasi Rawat Inap', 1, (float) ($adm['ADM-02']['harga'] ?? 50000)];
        $r[] = ['Kamar', "Kamar {$ra['nama']} (Kelas {$ra['kelas']}) bed {$ra['kode_bed']}", $hari, (float) $ra['tarif_per_hari']];
    }

    $tertunda = [];
    $hit = function (string $sql) use ($pdo, $id_kunjungan) { $s = $pdo->prepare($sql); $s->execute([$id_kunjungan]); return (int) $s->fetchColumn(); };
    if ($n = $hit("SELECT COUNT(*) FROM order_pelayanan WHERE id_kunjungan = ? AND jenis = 'Tindakan' AND status = 'Order Baru'")) $tertunda[] = "$n order tindakan belum dikerjakan perawat";
    if ($n = $hit("SELECT COUNT(*) FROM resep WHERE id_kunjungan = ? AND status NOT IN ('Selesai','Batal')")) $tertunda[] = "$n resep belum diserahkan Farmasi";
    if ($n = $hit("SELECT COUNT(*) FROM order_pelayanan WHERE id_kunjungan = ? AND jenis = 'Laboratorium' AND status NOT IN ('Diterbitkan','Ditolak')")) $tertunda[] = "$n pemeriksaan lab belum diterbitkan";
    if ($n = $hit("SELECT COUNT(*) FROM order_pelayanan WHERE id_kunjungan = ? AND jenis = 'Radiologi' AND status NOT IN ('Diterbitkan','Ditolak')")) $tertunda[] = "$n pemeriksaan radiologi belum diterbitkan";
    if ($n = $hit("SELECT COUNT(*) FROM order_pelayanan WHERE id_kunjungan = ? AND jaminan = 'Menunggu Persetujuan'")) $tertunda[] = "$n pemeriksaan masih menunggu persetujuan asuransi";
    if ($ra && $ra['status'] === 'Aktif') $tertunda[] = 'pasien masih dirawat (belum pulang)';

    $total = 0;
    foreach ($r as $x) $total += $x[2] * $x[3];
    return ['rincian' => $r, 'total' => $total, 'tertunda' => $tertunda, 'pribadi' => $pribadi];
}

/** Potong stok obat dari batch yang paling cepat kedaluwarsa (FEFO). Dipanggil DI DALAM transaksi. */
function potong_stok(int $id_obat, int $jumlah, string $jenis, string $ket, ?int $id_resep_item = null, ?int $id_pengadaan = null): void
{
    $pdo = getPDO();
    // "stok = stok - ?" + syarat stok >= ? -> aman walau dua apoteker memproses bersamaan
    $u = $pdo->prepare('UPDATE obat SET stok = stok - ? WHERE id = ? AND stok >= ?');
    $u->execute([$jumlah, $id_obat, $jumlah]);
    if ($u->rowCount() === 0) {
        $s = $pdo->prepare('SELECT nama_obat, stok, satuan FROM obat WHERE id = ?');
        $s->execute([$id_obat]);
        $o = $s->fetch();
        throw new Exception("Stok {$o['nama_obat']} tidak mencukupi (tersedia {$o['stok']} {$o['satuan']}, dibutuhkan $jumlah).");
    }
    $sisa = $jumlah;
    $s = $pdo->prepare('SELECT id, qty_sisa FROM stok_batch WHERE id_obat = ? AND qty_sisa > 0 ORDER BY tgl_kedaluwarsa, id');
    $s->execute([$id_obat]);
    foreach ($s->fetchAll() as $b) {
        if ($sisa <= 0) break;
        $ambil = min($sisa, (int) $b['qty_sisa']);
        $pdo->prepare('UPDATE stok_batch SET qty_sisa = qty_sisa - ? WHERE id = ?')->execute([$ambil, $b['id']]);
        $pdo->prepare('INSERT INTO mutasi_stok (id_obat, id_batch, jenis, jumlah, id_resep_item, id_pengadaan, keterangan, oleh) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id_obat, $b['id'], $jenis, -$ambil, $id_resep_item, $id_pengadaan, $ket, uid()]);
        $sisa -= $ambil;
    }
    if ($sisa > 0) {   // stok total ada tapi tidak tercatat di batch (data lama)
        $pdo->prepare('INSERT INTO mutasi_stok (id_obat, jenis, jumlah, id_resep_item, id_pengadaan, keterangan, oleh) VALUES (?,?,?,?,?,?,?)')->execute([$id_obat, $jenis, -$sisa, $id_resep_item, $id_pengadaan, $ket, uid()]);
    }
}

/** Tambah stok ke batch tertentu (penerimaan, retur). Dipanggil DI DALAM transaksi. */
function tambah_stok(int $id_obat, int $jumlah, ?int $id_batch, string $jenis, string $ket, ?int $id_resep_item = null, ?int $id_pengadaan = null): void
{
    $pdo = getPDO();
    $pdo->prepare('UPDATE obat SET stok = stok + ? WHERE id = ?')->execute([$jumlah, $id_obat]);
    if ($id_batch) $pdo->prepare('UPDATE stok_batch SET qty_sisa = qty_sisa + ? WHERE id = ?')->execute([$jumlah, $id_batch]);
    $pdo->prepare('INSERT INTO mutasi_stok (id_obat, id_batch, jenis, jumlah, id_resep_item, id_pengadaan, keterangan, oleh) VALUES (?,?,?,?,?,?,?,?)')->execute([$id_obat, $id_batch, $jenis, $jumlah, $id_resep_item, $id_pengadaan, $ket, uid()]);
}

/** Tanda vital kunjungan (pengkajian perawat atau triase IGD) dari tabel rekam_medis. */
function ttv_kunjungan(int $id_kunjungan): ?array
{
    $s = getPDO()->prepare("SELECT jenis_catatan, triase_kategori AS kategori, tekanan_darah, nadi, respirasi, suhu, spo2, berat_badan, tinggi_badan, gcs, skala_nyeri, keluhan_utama, riwayat, waktu_catat AS created_at
                            FROM rekam_medis WHERE id_kunjungan = ? AND jenis_catatan IN ('Pengkajian Awal','Triase IGD') ORDER BY waktu_catat LIMIT 1");
    $s->execute([$id_kunjungan]);
    if (!($r = $s->fetch())) return null;
    if ($r['jenis_catatan'] === 'Triase IGD') { unset($r['berat_badan'], $r['tinggi_badan']); return $r + ['sumber' => 'Triase IGD ' . $r['kategori']]; }
    unset($r['gcs']);
    return $r + ['sumber' => 'Pengkajian perawat'];
}

function teks_ttv(?array $t): string
{
    if (!$t) return 'Belum ada pemeriksaan tanda vital.';
    $x = "TD {$t['tekanan_darah']} mmHg, Nadi {$t['nadi']}x/mnt, RR {$t['respirasi']}x/mnt, Suhu {$t['suhu']} °C, SpO2 {$t['spo2']}%";
    if (!empty($t['berat_badan'])) {
        $bmi = $t['tinggi_badan'] > 0 ? round($t['berat_badan'] / (($t['tinggi_badan'] / 100) ** 2), 1) : '-';
        $x .= ", BB {$t['berat_badan']} kg, TB {$t['tinggi_badan']} cm (BMI $bmi)";
    }
    if (isset($t['gcs'])) $x .= ", GCS {$t['gcs']}";
    return $x . ", Nyeri {$t['skala_nyeri']}/10";
}

/** Dokumen HL7 FHIR R4 (Bundle) untuk satu kunjungan -- PPT Bab 13. */
function fhir_bundle(int $id_kunjungan): array
{
    $k = ambil_kunjungan($id_kunjungan);
    $pdo = getPDO();
    $s = $pdo->prepare("SELECT rm.*, i.nama AS nama_icd FROM rekam_medis rm JOIN icd10 i ON i.kode = rm.kode_icd10 WHERE rm.id_kunjungan = ? AND rm.jenis_catatan = 'SOAP Dokter'");
    $s->execute([$id_kunjungan]);
    $rm = $s->fetch();
    $t = ttv_kunjungan($id_kunjungan);
    $pid = 'Patient/' . $k['nomor_rm'];
    $eid = 'Encounter/' . $id_kunjungan;
    $entry = [];
    $entry[] = ['fullUrl' => 'urn:uuid:patient-' . $k['id_pasien'], 'resource' => [
        'resourceType' => 'Patient',
        'identifier' => [['system' => 'https://fhir.kemkes.go.id/id/nik', 'value' => $k['nik']], ['system' => 'https://rsushi.id/no-rm', 'value' => $k['nomor_rm']]],
        'name' => [['use' => 'official', 'text' => $k['nama_lengkap']]],
        'gender' => $k['jenis_kelamin'] === 'L' ? 'male' : 'female',
        'birthDate' => $k['tanggal_lahir'],
        'telecom' => [['system' => 'phone', 'value' => $k['no_hp'], 'use' => 'mobile']],
    ]];
    $entry[] = ['fullUrl' => 'urn:uuid:encounter-' . $id_kunjungan, 'resource' => [
        'resourceType' => 'Encounter', 'status' => $k['status'] === 'Selesai' ? 'finished' : 'in-progress',
        'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => $k['jenis'] === 'igd' ? 'EMER' : ($k['jenis'] === 'rawat_inap' ? 'IMP' : 'AMB')],
        'subject' => ['reference' => $pid, 'display' => $k['nama_lengkap']],
        'participant' => [['individual' => ['display' => $k['dokter']]]],
        'period' => ['start' => date('c', strtotime($k['waktu_masuk'] ?? $k['created_at']))],
        'serviceProvider' => ['identifier' => ['system' => 'https://fhir.kemkes.go.id/id/kode-faskes', 'value' => konfig('kode_faskes', (int) $k['id_rs'])],
                              'display' => konfig('nama_rs', (int) $k['id_rs']) . ' ' . $k['nama_cabang'] . ' · ' . $k['poli']],
    ]];
    if ($rm) {
        $entry[] = ['fullUrl' => 'urn:uuid:condition-' . $rm['id'], 'resource' => [
            'resourceType' => 'Condition', 'clinicalStatus' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical', 'code' => 'active']]],
            'code' => ['coding' => [['system' => 'http://hl7.org/fhir/sid/icd-10', 'code' => $rm['kode_icd10'], 'display' => $rm['nama_icd']]], 'text' => $rm['assessment']],
            'subject' => ['reference' => $pid], 'encounter' => ['reference' => $eid],
        ]];
    }
    if ($t) {
        $obs = [['8310-5', 'Body temperature', (float) $t['suhu'], 'Cel'], ['8867-4', 'Heart rate', (int) $t['nadi'], '/min'],
                ['9279-1', 'Respiratory rate', (int) $t['respirasi'], '/min'], ['59408-5', 'Oxygen saturation', (int) $t['spo2'], '%']];
        [$sis, $dia] = array_map('intval', explode('/', $t['tekanan_darah']));
        $obs[] = ['8480-6', 'Systolic blood pressure', $sis, 'mm[Hg]'];
        $obs[] = ['8462-4', 'Diastolic blood pressure', $dia, 'mm[Hg]'];
        foreach ($obs as $i => $o) {
            $entry[] = ['fullUrl' => 'urn:uuid:obs-' . $id_kunjungan . '-' . $i, 'resource' => [
                'resourceType' => 'Observation', 'status' => 'final',
                'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'vital-signs']]]],
                'code' => ['coding' => [['system' => 'http://loinc.org', 'code' => $o[0], 'display' => $o[1]]]],
                'subject' => ['reference' => $pid], 'encounter' => ['reference' => $eid],
                'effectiveDateTime' => date('c', strtotime($t['created_at'])),
                'valueQuantity' => ['value' => $o[2], 'unit' => $o[3], 'system' => 'http://unitsofmeasure.org', 'code' => $o[3]],
            ]];
        }
    }
    return ['resourceType' => 'Bundle', 'type' => 'collection', 'timestamp' => date('c'), 'entry' => $entry];
}

/** Daftar opsi obat aktif untuk form resep. */
function daftar_obat(): array
{
    return getPDO()->query("SELECT id, nama_obat, satuan, stok, harga_satuan, high_alert, lasa FROM obat WHERE aktif = 1 AND kategori = 'Obat' ORDER BY nama_obat")->fetchAll();
}

/** Slot janji temu dokter pada tanggal: [jadwal, ['08:00' => 'Tersedia'|'Terisi', ...]] (janji temu tersimpan di tabel kunjungan) */
function slot_tersedia(int $id_dokter, int $id_unit, string $tanggal, int $kecuali = 0): array
{
    $j = jadwal_praktik($id_dokter, $id_unit, $tanggal);
    if (!$j) return [null, []];
    $s = getPDO()->prepare("SELECT jam_janji FROM kunjungan WHERE id_dokter = ? AND id_unit = ? AND tanggal = ? AND jam_janji IS NOT NULL AND status <> 'Batal' AND id <> ?");
    $s->execute([$id_dokter, $id_unit, $tanggal, $kecuali]);
    $terpakai = array_map(fn($x) => substr($x, 0, 5), $s->fetchAll(PDO::FETCH_COLUMN));
    $slot = [];
    $t = strtotime($tanggal . ' ' . $j['jam_mulai']);
    $akhir = strtotime($tanggal . ' ' . $j['jam_selesai']);
    while ($t < $akhir && count($slot) < $j['kuota']) {
        $jam = date('H:i', $t);
        if ($tanggal !== date('Y-m-d') || $t > time()) $slot[$jam] = in_array($jam, $terpakai, true) ? 'Terisi' : 'Tersedia';
        $t += $j['durasi_slot'] * 60;
    }
    return [$j, $slot];
}

/** Tulis satu baris catatan klinis ke tabel rekam_medis. Kolom sumber (cabang, unit, dokter, jam masuk) diisi otomatis dari kunjungan. */
function tulis_rm(int $id_kunjungan, string $jenis, array $kolom, ?string $layanan = null, ?int $id_unit = null, ?int $id_dokter = null, ?string $waktu_masuk = null): int
{
    $pdo = getPDO();
    $k = ambil_kunjungan($id_kunjungan);
    if (!$k) throw new Exception('Kunjungan tidak ditemukan.');
    if ($layanan === null) $layanan = $k['jenis'] === 'igd' ? 'IGD' : ($k['jenis'] === 'rawat_inap' ? 'Rawat Inap' : 'Rawat Jalan');
    if ($layanan === 'Rawat Inap' && ($id_unit === null || $id_dokter === null)) {
        $s = $pdo->prepare('SELECT b.id_unit, ra.id_dokter, ra.tanggal_masuk FROM rawat_inap ra JOIN bed b ON b.id = ra.id_bed WHERE ra.id_kunjungan = ?');
        $s->execute([$id_kunjungan]);
        if ($ra = $s->fetch()) { $id_unit ??= (int) $ra['id_unit']; $id_dokter ??= (int) $ra['id_dokter']; $waktu_masuk ??= $ra['tanggal_masuk']; }
    }
    $data = ['id_rs' => $k['id_rs'], 'id_kunjungan' => $id_kunjungan, 'id_pasien' => $k['id_pasien'], 'jenis_layanan' => $layanan,
             'id_unit' => $id_unit ?? $k['id_unit'], 'id_dokter' => $id_dokter ?? $k['id_dokter'], 'waktu_masuk' => $waktu_masuk ?? ($k['waktu_masuk'] ?: $k['created_at']),
             'jenis_catatan' => $jenis, 'ditulis_oleh' => uid()] + $kolom;
    $pdo->prepare('INSERT INTO rekam_medis (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')')->execute(array_values($data));
    return (int) $pdo->lastInsertId();
}

/** Kartu hasil ekspertise radiologi: foto + temuan + kesan/kesimpulan (EMR, akun pasien). */
function kartu_hasil_rad(array $r): string
{
    $img = '';
    if (!empty($r['file_gambar'])) {
        $src = url('uploads/radiologi/' . rawurlencode($r['file_gambar']));
        $img = '<a href="' . $src . '" target="_blank" class="rad-foto" title="Buka gambar ukuran penuh"><img src="' . $src . '" alt="Gambar ' . aman($r['nama']) . '" loading="lazy"><span>Perbesar gambar</span></a>';
    }
    return '<div class="rad-wrap"><div class="rad-hasil">' . $img . '<div class="rad-teks">'
        . '<div class="flex flex-wrap justify-between gap-2"><b>' . aman($r['nama']) . '</b><span class="muted text-xs">' . (!empty($r['waktu_terbit']) ? 'Terbit ' . tgl_jam($r['waktu_terbit']) : '') . '</span></div>'
        . '<h5>Temuan</h5><p>' . nl2br(aman((string) $r['temuan'])) . '</p>'
        . '<div class="rad-kesan"><h5>Kesan / Kesimpulan</h5><p>' . nl2br(aman((string) $r['kesimpulan'])) . '</p></div>'
        . (!empty($r['radiolog']) ? '<p class="muted text-xs mt-2">Dokter Sp. Radiologi: ' . aman($r['radiolog']) . '</p>' : '')
        . '</div></div></div>';
}
