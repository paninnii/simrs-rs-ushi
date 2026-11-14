<?php
/*
 * MODUL 12: SCHEDULING -- JADWAL PRAKTIK DOKTER
 * RBAC sesuai SOP Scheduling & Appointment:
 *   Dokter            : menentukan & memperbarui jadwal praktik MILIKNYA SENDIRI (jam, kuota, durasi slot, aktif/libur)
 *   Petugas Registrasi: mengatur jadwal pelayanan semua dokter (jam, kuota, aktif/libur)
 *   Admin Sistem      : menambah/menghapus jadwal baru lewat Kepegawaian > Jadwal Praktik Dokter (master data)
 * Perubahan ditolak bila membuat janji temu yang sudah ada berada di luar jam praktik baru.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('jadwal');
$pdo = getPDO();
$dokter = role_is('dokter') && boleh('jadwal', true);
$petugas = role_is('pendaftaran', 'supervisorreg') && boleh('jadwal', true);
$HARI = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

if (is_post()) {
    $s = $pdo->prepare("SELECT j.*, j.id_pegawai AS id_dokter, d.nama_lengkap AS dokter, pl.nama AS poli FROM jadwal_pegawai j JOIN pegawai d ON d.id = j.id_pegawai JOIN unit_layanan pl ON pl.id = j.id_unit WHERE j.id = ? AND j.jenis = 'Praktik'");
    $s->execute([(int) post('id')]);
    $j = $s->fetch();
    try {
        if (!$j) throw new Exception('Jadwal tidak ditemukan.');
        if (!$petugas && !($dokter && (int) $j['id_dokter'] === id_pegawai())) throw new Exception('Dokter hanya dapat mengubah jadwal praktiknya sendiri.');
        $mulai = post('jam_mulai'); $selesai = post('jam_selesai');
        $kuota = (int) post('kuota'); $durasi = (int) post('durasi_slot'); $aktif = post('aktif') === '1' ? 1 : 0;
        if (!preg_match('/^\d{2}:\d{2}$/', $mulai) || !preg_match('/^\d{2}:\d{2}$/', $selesai) || $selesai <= $mulai) throw new Exception('Jam selesai harus lebih besar dari jam mulai.');
        if ($kuota < 1 || $kuota > 200) throw new Exception('Kuota pasien 1 sampai 200.');
        if ($durasi < 5 || $durasi > 60) throw new Exception('Durasi per pasien 5 sampai 60 menit.');
        // janji temu mendatang yang akan "terlempar" ke luar jam praktik / jadwal dinonaktifkan
        $c = $pdo->prepare("SELECT COUNT(*) FROM kunjungan WHERE id_jadwal = ? AND tanggal >= ? AND status IN ('Menunggu Konfirmasi','Terkonfirmasi') AND (? = 0 OR jam_janji < ? OR jam_janji >= ?)");
        $c->execute([$j['id'], date('Y-m-d'), $aktif, $mulai . ':00', $selesai . ':00']);
        if ($n = $c->fetchColumn()) throw new Exception("Ada $n janji temu mendatang di luar jam baru / pada jadwal yang akan diliburkan. Reschedule atau batalkan janji temu tersebut dulu di menu Janji Temu.");
        $pdo->prepare('UPDATE jadwal_pegawai SET jam_mulai = ?, jam_selesai = ?, kuota = ?, durasi_slot = ?, aktif = ? WHERE id = ?')->execute([$mulai, $selesai, $kuota, $durasi, $aktif, $j['id']]);
        audit('Scheduling & Appointment', 'Ubah Jadwal Praktik', "{$j['dokter']} {$j['poli']} {$j['hari']} $mulai-$selesai kuota $kuota" . ($aktif ? '' : ' (LIBUR)'));
        flash('sukses', "Jadwal {$j['dokter']} · {$j['poli']} hari {$j['hari']} diperbarui: $mulai–$selesai, kuota $kuota" . ($aktif ? '.' : ' (libur / nonaktif).'));
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    kembali();
}

$sql = "SELECT j.*, j.id_pegawai AS id_dokter, d.nama_lengkap AS dokter, d.spesialisasi, pl.nama AS poli, pl.id_rs FROM jadwal_pegawai j JOIN pegawai d ON d.id = j.id_pegawai JOIN unit_layanan pl ON pl.id = j.id_unit
        WHERE d.status = 'Aktif' AND j.jenis = 'Praktik'";
$par = [];
if (role_is('dokter')) { $sql .= ' AND j.id_pegawai = ?'; $par[] = id_pegawai(); } else $sql .= sql_rs('pl.id_rs');
$s = $pdo->prepare($sql . ' ORDER BY d.nama_lengkap, pl.nama');
$s->execute($par);
$rows = $s->fetchAll();
usort($rows, fn($a, $b) => [$a['dokter'], $a['poli'], array_search($a['hari'], $HARI)] <=> [$b['dokter'], $b['poli'], array_search($b['hari'], $HARI)]);
$hari_ini = hari_ini();
tampil_header('Jadwal Praktik Dokter', role_is('dokter') ? 'Atur jam praktik, kuota, dan hari libur Anda sendiri. Perubahan langsung dipakai untuk janji temu & pendaftaran.' : ($petugas ? 'Atur jadwal pelayanan dokter (jam, kuota, libur). Jadwal baru ditambahkan Admin di Kepegawaian.' : 'Jadwal praktik dokter.'));
echo kartu([['Jadwal Aktif', count(array_filter($rows, fn($r) => $r['aktif'])), 'teal'], ['Praktik Hari Ini (' . $hari_ini . ')', count(array_filter($rows, fn($r) => $r['aktif'] && $r['hari'] === $hari_ini)), 'green'], ['Libur / Nonaktif', count(array_filter($rows, fn($r) => !$r['aktif'])), 'red']]);
?>
<div class="card"><div class="card-head"><b><?= count($rows) ?> jadwal</b><input class="input input-sm" data-filter="tblJadwal" placeholder="Cari dokter / poli / hari"></div>
<div class="tbl-wrap"><table class="tbl" id="tblJadwal"><thead><tr><th>Dokter</th><th>Poliklinik</th><th>Hari</th><th>Jam</th><th class="num">Kuota</th><th class="num">Menit/pasien</th><th>Status</th><th class="num">Aksi</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="empty">Belum ada jadwal praktik. Hubungi Admin untuk menambahkan jadwal.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $bisa = $petugas || ($dokter && (int) $r['id_dokter'] === id_pegawai()); ?>
  <tr><td><b><?= aman($r['dokter']) ?></b><div class="muted"><?= aman($r['spesialisasi']) ?></div></td><td><?= aman($r['poli']) ?><div class="col-cabang"><?= aman(nama_cabang((int) $r['id_rs'])) ?></div></td><td><?= $r['hari'] === $hari_ini ? '<b>' . $r['hari'] . '</b>' : $r['hari'] ?></td>
    <td class="mono"><?= jam($r['jam_mulai']) ?>–<?= jam($r['jam_selesai']) ?></td><td class="num"><?= (int) $r['kuota'] ?></td><td class="num"><?= (int) $r['durasi_slot'] ?></td><td><?= badge($r['aktif'] ? 'Aktif' : 'Libur') ?></td>
    <td class="num"><?php if ($bisa): ?><button class="btn btn-secondary btn-sm" data-open="j<?= $r['id'] ?>">Ubah</button>
      <dialog class="modal" id="j<?= $r['id'] ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
        <div class="modal-head"><h3><?= aman($r['dokter']) ?> · <?= aman($r['poli']) ?> · <?= $r['hari'] ?></h3></div>
        <div class="modal-body form-grid text-left">
          <div class="field"><label>Jam mulai</label><input type="time" name="jam_mulai" value="<?= jam($r['jam_mulai']) ?>" required></div>
          <div class="field"><label>Jam selesai</label><input type="time" name="jam_selesai" value="<?= jam($r['jam_selesai']) ?>" required></div>
          <div class="field"><label>Kuota pasien (1–200)</label><input type="number" name="kuota" min="1" max="200" value="<?= (int) $r['kuota'] ?>" required></div>
          <div class="field"><label>Menit per pasien (5–60)</label><input type="number" name="durasi_slot" min="5" max="60" value="<?= (int) $r['durasi_slot'] ?>" required></div>
          <div class="field"><label>Status</label><select name="aktif"><?= opsi(['1' => 'Praktik (aktif)', '0' => 'Libur / cuti (nonaktif)'], (string) $r['aktif']) ?></select></div></div>
        <div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Batal</button><button class="btn btn-primary">Simpan Jadwal</button></div></form></dialog>
    <?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php tampil_footer();
