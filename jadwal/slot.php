<?php
/*
 * DATA JAM PRAKTIK (JSON) untuk pilihan jam janji temu tanpa memuat ulang halaman.
 * Dipakai: Portal Pasien (Janji temu) dan Jadwal & Appointment (petugas registrasi).
 * GET dokter=<id_dokter>-<id_unit>&tanggal=YYYY-MM-DD[&kecuali=id_kunjungan janji temu]
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_login();
header('Content-Type: application/json; charset=utf-8');
if (!boleh('jadwal') && !boleh('portal')) { http_response_code(403); echo json_encode(['pesan' => 'Akses ditolak.']); exit; }

$HARI = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
[$dok, $pol] = array_map('intval', explode('-', get('dokter') . '-0'));
$tgl = get('tanggal');
$out = ['slot' => [], 'pesan' => '', 'info' => '', 'tanggal_lain' => []];

$s = getPDO()->prepare("SELECT j.hari, d.nama_lengkap FROM jadwal_pegawai j JOIN pegawai d ON d.id = j.id_pegawai WHERE j.id_pegawai = ? AND j.id_unit = ? AND j.jenis = 'Praktik' AND j.aktif = 1");
$s->execute([$dok, $pol]);
$hari_praktik = $s->fetchAll(PDO::FETCH_COLUMN);
usort($hari_praktik, fn($a, $b) => array_search($a, $HARI) <=> array_search($b, $HARI));

if (!$dok || !$hari_praktik) {
    $out['pesan'] = 'Pilih poliklinik & dokter terlebih dahulu.';
} elseif (v_tanggal($tgl) !== null) {
    $out['pesan'] = 'Pilih tanggal kunjungan.';
} elseif ($tgl < date('Y-m-d')) {
    $out['pesan'] = 'Tanggal sudah lewat. Pilih hari ini atau tanggal berikutnya.';
} else {
    [$j, $slot] = slot_tersedia($dok, $pol, $tgl, (int) get('kecuali'));
    if (!$j) {
        $out['pesan'] = 'Dokter tidak praktik pada hari ' . hari_ini($tgl) . '. Hari praktik: ' . implode(', ', $hari_praktik) . '.';
    } else {
        $out['info'] = hari_ini($tgl) . ', ' . tgl($tgl) . ' · praktik ' . jam($j['jam_mulai']) . '–' . jam($j['jam_selesai']) . ' · ' . (int) $j['durasi_slot'] . ' menit per pasien';
        foreach ($slot as $jam => $st) $out['slot'][] = ['jam' => $jam, 'status' => $st];
        if (!$slot) $out['pesan'] = $tgl === date('Y-m-d') ? 'Jam praktik hari ini sudah selesai. Pilih tanggal lain.' : 'Tidak ada jam tersisa pada tanggal ini.';
        elseif (!in_array('Tersedia', $slot, true)) $out['pesan'] = 'Semua jam pada tanggal ini sudah terisi. Pilih tanggal lain.';
    }
    // saran tanggal terdekat yang masih ada jam kosong
    if ($out['pesan'] !== '') {
        for ($i = 0, $t = max(strtotime($tgl), strtotime(date('Y-m-d'))); $i < 30 && count($out['tanggal_lain']) < 4; $i++, $t += 86400) {
            $d = date('Y-m-d', $t);
            if ($d === $tgl) continue;
            [$jj, $ss] = slot_tersedia($dok, $pol, $d);
            if ($jj && in_array('Tersedia', $ss, true)) $out['tanggal_lain'][] = ['tanggal' => $d, 'label' => mb_substr(hari_ini($d), 0, 3) . ', ' . tgl($d)];
        }
    }
}
echo json_encode($out);
