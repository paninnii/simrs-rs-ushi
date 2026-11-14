<?php
/*
 * MODUL 9: RADIOLOGY
 *   Admin Radiologi : Order Baru -> Terverifikasi -> Terjadwal          (bisa tolak order)
 *   Radiografer     : Terjadwal -> Pemeriksaan Selesai -> Gambar Terunggah (upload JPG/PNG/WEBP) -> Menunggu Ekspertise
 *   Dokter Sp.Rad   : Menunggu Ekspertise -> Diterbitkan (temuan + kesimpulan)
 * Tabel: order_pelayanan (jenis = Radiologi). Kolom petugas: id_petugas = admin radiologi, id_petugas2 = radiografer, id_validator = Sp.Rad.
 */
require_once __DIR__ . '/../includes/app.php';
wajib_akses('radiologi');
$pdo = getPDO();
$TAHAP = ['Order Baru', 'Terverifikasi', 'Terjadwal', 'Pemeriksaan Selesai', 'Gambar Terunggah', 'Menunggu Ekspertise', 'Diterbitkan'];
$ubah_ok = boleh('radiologi', true);
$adm = role_is('adminrad') && $ubah_ok;
$rgf = role_is('radiografer') && $ubah_ok;
$rad = role_is('sprad') && $ubah_ok;

if (is_post()) {
    $s = $pdo->prepare("SELECT o.*, o.uraian AS nama, p.nomor_rm FROM order_pelayanan o JOIN kunjungan k ON k.id = o.id_kunjungan JOIN pasien p ON p.id = k.id_pasien WHERE o.id = ? AND o.jenis = 'Radiologi'");
    $s->execute([(int) post('id')]);
    $o = $s->fetch();
    $aksi = post('aksi');
    if (!$o) { flash('error', 'Order tidak ditemukan.'); kembali(); }
    if ($o['jaminan'] === 'Menunggu Persetujuan') { flash('error', 'Order ini masih menunggu persetujuan asuransi. Hubungi Verifikator.'); kembali(); }
    $ubah = function (string $dari, string $ke, array $set = []) use ($pdo, $o) {
        $cols = array_merge(['status' => $ke], $set);
        $u = $pdo->prepare('UPDATE order_pelayanan SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols))) . ' WHERE id = ? AND status = ?');
        $u->execute([...array_values($cols), $o['id'], $dari]);
        return $u->rowCount() > 0;
    };
    $ok = false;
    $pesan = '';
    if (in_array($aksi, ['verifikasi', 'tolak', 'jadwal'], true)) {
        if (!wajib_role_aksi(['adminrad'], 'Verifikasi & penjadwalan dilakukan Admin Radiologi.', 'radiologi')) kembali();
        if ($aksi === 'verifikasi') { $ok = $ubah('Order Baru', 'Terverifikasi', ['id_petugas' => uid()]); $pesan = 'order terverifikasi, silakan jadwalkan'; }
        if ($aksi === 'tolak') {
            if (mb_strlen(post('alasan')) < 5) { flash('error', 'Tulis alasan penolakan.'); kembali(); }
            $ok = $ubah('Order Baru', 'Ditolak', ['catatan' => mb_substr('Ditolak: ' . post('alasan'), 0, 255), 'id_petugas' => uid()]);
            $pesan = 'order ditolak';
        }
        if ($aksi === 'jadwal') {
            $jw = str_replace('T', ' ', post('jadwal'));
            $t = strtotime($jw);
            if (!$t || $t < strtotime('-10 minutes')) { flash('error', 'Jadwal tidak valid / sudah lewat.'); kembali(); }
            $ok = $ubah('Terverifikasi', 'Terjadwal', ['jadwal' => date('Y-m-d H:i:s', $t)]) || $ubah('Terjadwal', 'Terjadwal', ['jadwal' => date('Y-m-d H:i:s', $t)]);
            $pesan = 'dijadwalkan ' . tgl_jam(date('Y-m-d H:i', $t));
        }
    } elseif (in_array($aksi, ['selesai', 'unggah', 'kirim'], true)) {
        if (!wajib_role_aksi(['radiografer'], 'Pelaksanaan & unggah gambar dilakukan Radiografer.', 'radiologi')) kembali();
        if ($aksi === 'selesai') { $ok = $ubah('Terjadwal', 'Pemeriksaan Selesai', ['id_petugas2' => uid(), 'waktu_dikerjakan' => date('Y-m-d H:i:s'), 'catatan' => mb_substr(post('catatan') ?: 'Pemeriksaan berjalan lancar', 0, 255)]); $pesan = 'pemeriksaan selesai, unggah gambar'; }
        if ($aksi === 'unggah') {
            $f = $_FILES['gambar'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('error', 'Pilih file gambar (maks. 5 MB).'); kembali(); }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $info = @getimagesize($f['tmp_name']);
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !$info || $f['size'] > 5 * 1024 * 1024) { flash('error', 'File harus gambar JPG/PNG/WEBP, maksimal 5 MB.'); kembali(); }
            $nama = 'RAD-' . $o['id'] . '-' . date('YmdHis') . '.' . $ext;
            $dir = APP_ROOT . '/uploads/radiologi';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            if (!move_uploaded_file($f['tmp_name'], "$dir/$nama")) { flash('error', 'Gagal menyimpan file.'); kembali(); }
            $ok = $ubah('Pemeriksaan Selesai', 'Gambar Terunggah', ['file_gambar' => $nama]) || $ubah('Gambar Terunggah', 'Gambar Terunggah', ['file_gambar' => $nama]);
            $pesan = 'gambar terunggah';
        }
        if ($aksi === 'kirim') { $ok = $ubah('Gambar Terunggah', 'Menunggu Ekspertise'); $pesan = 'dikirim ke Dokter Sp.Rad untuk ekspertise'; }
    } elseif (in_array($aksi, ['terbit', 'kembalikan'], true)) {
        if (!wajib_role_aksi(['sprad'], 'Ekspertise hanya oleh Dokter Spesialis Radiologi.', 'radiologi')) kembali();
        if ($aksi === 'terbit') {
            if (mb_strlen(post('temuan')) < 10 || mb_strlen(post('kesimpulan')) < 5) { flash('error', 'Temuan (min. 10 karakter) dan kesimpulan wajib diisi.'); kembali(); }
            $ok = $ubah('Menunggu Ekspertise', 'Diterbitkan', ['temuan' => post('temuan'), 'kesimpulan' => post('kesimpulan'), 'id_validator' => uid(), 'waktu_terbit' => date('Y-m-d H:i:s')]);
            $pesan = 'ekspertise DITERBITKAN dan tampil di EMR dokter';
        }
        if ($aksi === 'kembalikan') { $ok = $ubah('Menunggu Ekspertise', 'Gambar Terunggah', ['catatan' => mb_substr('Ulang/lengkapi gambar: ' . post('temuan'), 0, 255)]); $pesan = 'dikembalikan ke Radiografer'; }
    }
    if ($ok) {
        audit('Radiology', ucfirst($aksi), "Order rad #{$o['id']} {$o['nama']} {$o['nomor_rm']}");
        flash('sukses', "{$o['nama']} ({$o['nomor_rm']}): $pesan.");
    } else flash('error', 'Status order sudah berubah atau tahap tidak sesuai. Muat ulang halaman.');
    kembali();
}

// ---------- LAPORAN EKSPERTISE ----------
if (get('laporan') !== '') {
    $s = $pdo->prepare("SELECT o.*, o.uraian AS nama, j.sub_kategori AS modalitas, j.persiapan, p.id AS id_pasien, p.nama_lengkap, p.nomor_rm, p.tanggal_lahir, p.jenis_kelamin, d.nama_lengkap AS dokter_pengirim, r.nama_lengkap AS radiolog, g.nama_lengkap AS radiografer, k.id_rs
        FROM order_pelayanan o JOIN layanan j ON j.id = o.id_layanan JOIN kunjungan k ON k.id = o.id_kunjungan JOIN pasien p ON p.id = k.id_pasien JOIN pegawai d ON d.id = o.id_dokter
        LEFT JOIN pegawai r ON r.id = o.id_validator LEFT JOIN pegawai g ON g.id = o.id_petugas2 WHERE o.id = ? AND o.jenis = 'Radiologi' AND o.status = 'Diterbitkan'");
    $s->execute([(int) get('laporan')]);
    $l = $s->fetch();
    if (!$l) { flash('error', 'Ekspertise belum diterbitkan.'); redirect('radiologi/radiologi.php'); }
    wajib_pasien_sendiri((int) $l['id_pasien']);
    tampil_header('Hasil Ekspertise Radiologi', '', '<button onclick="window.print()" class="btn btn-primary">Cetak</button> <a href="radiologi.php" class="btn btn-secondary">Kembali</a>');
    ?>
    <div class="card max-w-3xl print-area"><div class="card-body">
      <div class="flex items-center gap-3 border-b pb-3 mb-3"><img src="<?= url('assets/logo.png') ?>" class="w-12 h-12" alt=""><div><b class="text-[#004c6d]"><?= aman(konfig('nama_rs', (int) $l['id_rs'])) ?> · <?= aman(konfig('nama_cabang', (int) $l['id_rs'])) ?></b><div class="muted">Instalasi Radiologi · <?= aman(konfig('alamat', (int) $l['id_rs'])) ?></div></div></div>
      <dl class="kv mb-4"><dt>Pasien</dt><dd><?= aman($l['nama_lengkap']) ?> (<?= aman($l['nomor_rm']) ?>), <?= umur($l['tanggal_lahir']) ?>, <?= jk($l['jenis_kelamin']) ?></dd>
        <dt>Pemeriksaan</dt><dd><?= aman($l['nama']) ?> (<?= aman($l['modalitas']) ?>)</dd><dt>Dokter pengirim</dt><dd><?= aman($l['dokter_pengirim']) ?></dd><dt>Indikasi</dt><dd><?= aman($l['indikasi']) ?></dd><?php if ($l['status_hamil'] !== '-'): ?><dt>Status kehamilan</dt><dd><?= aman($l['status_hamil']) ?></dd><?php endif; ?><?php if ($l['persiapan']): ?><dt>Persiapan</dt><dd><?= aman($l['persiapan']) ?></dd><?php endif; ?></dl>
      <?php if ($l['file_gambar']): ?><img src="<?= url('uploads/radiologi/' . rawurlencode($l['file_gambar'])) ?>" alt="Gambar radiologi" class="rad-img mb-4"><?php endif; ?>
      <h4 class="font-semibold">Temuan</h4><p class="mb-3"><?= nl2br(aman($l['temuan'])) ?></p>
      <div class="rad-kesan"><h4 class="font-semibold">Kesan / Kesimpulan</h4><p><b><?= nl2br(aman($l['kesimpulan'])) ?></b></p></div>
      <div class="grid grid-cols-2 gap-4 mt-8 text-sm text-center"><div>Radiografer<br><br><br><b><?= aman($l['radiografer']) ?></b></div><div>Dokter Spesialis Radiologi<br><br><br><b><?= aman($l['radiolog']) ?></b><br><?= tgl_jam($l['waktu_terbit']) ?></div></div>
    </div></div>
    <?php tampil_footer();
    exit;
}

// ---------- DAFTAR ----------
$tab = get('tab', 'aktif');
$batas = date('Y-m-d', strtotime('-30 days'));
if ($adm || $rgf || $rad) {
    $where = ($tab === 'selesai' ? "o.status IN ('Diterbitkan','Ditolak') AND o.created_at >= ?" : "o.status NOT IN ('Diterbitkan','Ditolak') AND o.created_at >= ?") . sql_rs('k.id_rs');
    $par = [$tab === 'selesai' ? $batas : '2000-01-01'];
} else { $where = "o.status = 'Diterbitkan' AND o.created_at >= ?" . (role_is('dokter') ? ' AND o.id_dokter = ' . (int) uid() : sql_rs('k.id_rs')); $par = [$batas]; }
$s = $pdo->prepare("SELECT o.*, o.uraian AS nama, o.catatan AS catatan_pelaksanaan, j.sub_kategori AS modalitas, j.persiapan, p.nama_lengkap, p.nomor_rm, p.tanggal_lahir, p.jenis_kelamin, d.nama_lengkap AS dokter, pl.nama AS poli, k.id_rs
    FROM order_pelayanan o JOIN layanan j ON j.id = o.id_layanan JOIN kunjungan k ON k.id = o.id_kunjungan JOIN pasien p ON p.id = k.id_pasien JOIN pegawai d ON d.id = o.id_dokter JOIN unit_layanan pl ON pl.id = k.id_unit
    WHERE o.jenis = 'Radiologi' AND $where ORDER BY CASE o.urgensi WHEN 'CITO' THEN 0 ELSE 1 END, o.created_at");
$s->execute($par);
$rows = $s->fetchAll();
$semua = $pdo->query("SELECT o.status FROM order_pelayanan o JOIN kunjungan k ON k.id = o.id_kunjungan WHERE o.jenis = 'Radiologi' AND o.created_at >= '$batas'" . sql_rs('k.id_rs'))->fetchAll(PDO::FETCH_COLUMN);
$c = fn(...$st) => count(array_filter($semua, fn($x) => in_array($x, $st, true)));
$sub = $adm ? 'Admin Radiologi: verifikasi order dan atur jadwal pemeriksaan.' : ($rgf ? 'Radiografer: laksanakan pemeriksaan dan unggah gambar.' : ($rad ? 'Dokter Sp.Rad: baca gambar dan terbitkan ekspertise.' : 'Hasil radiologi yang sudah diterbitkan.'));
tampil_header('Radiologi', $sub);
echo kartu([['Perlu Verifikasi', $c('Order Baru'), 'amber'], ['Terjadwal / Pelaksanaan', $c('Terverifikasi', 'Terjadwal', 'Pemeriksaan Selesai', 'Gambar Terunggah'), 'teal'], ['Menunggu Ekspertise', $c('Menunggu Ekspertise'), 'purple'], ['Diterbitkan (30 hari)', $c('Diterbitkan'), 'green']]);
?>
<?php if ($adm || $rgf || $rad): ?><div class="tabs"><a class="tab<?= $tab === 'aktif' ? ' active' : '' ?>" href="?tab=aktif">Order aktif</a><a class="tab<?= $tab === 'selesai' ? ' active' : '' ?>" href="?tab=selesai">Diterbitkan / ditolak</a></div><?php endif; ?>
<div class="card"><div class="card-head"><h3>Daftar Order Radiologi</h3></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pasien</th><th>Pemeriksaan</th><th>Pengirim / Jadwal</th><th>Alur</th><th class="num">Tindakan</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="5" class="empty">Tidak ada order.</td></tr><?php endif; ?>
<?php foreach ($rows as $o): $id = $o['id']; ?>
  <tr><td><b class="pname"><?= aman($o['nama_lengkap']) ?></b><div class="muted"><?= aman($o['nomor_rm']) ?> · <?= jk($o['jenis_kelamin']) ?> · <?= umur($o['tanggal_lahir']) ?></div></td>
    <td><b><?= aman($o['nama']) ?></b> <?= $o['urgensi'] === 'CITO' ? badge('CITO') : '' ?><?= in_array($o['jaminan'], ['Disetujui', 'Bayar Sendiri'], true) ? ' <span class="badge badge-gray">' . ($o['jaminan'] === 'Disetujui' ? 'Disetujui asuransi' : 'Biaya pribadi') . '</span>' : '' ?><div class="muted"><?= aman($o['modalitas']) ?> · <?= aman($o['indikasi']) ?></div><?php if ($o['kontras']): ?><div class="text-xs mt-1" style="color:#92400e">Dengan kontras — pastikan cek fungsi ginjal &amp; riwayat alergi</div><?php endif; ?><?php if ($o['alasan_klinis']): ?><div class="text-xs mt-1" style="color:#991b1b">Catatan dokter: <?= aman($o['alasan_klinis']) ?></div><?php endif; ?><?php if ($o['status_hamil'] !== '-'): ?><div class="text-xs mt-1"<?= $o['status_hamil'] !== 'Tidak hamil' ? ' style="color:#991b1b"' : '' ?>>Status kehamilan: <b><?= aman($o['status_hamil']) ?></b><?= $o['status_hamil'] !== 'Tidak hamil' ? ' — gunakan pelindung (apron) & konfirmasi ke dokter' : '' ?></div><?php endif; ?>
      <?= $o['file_gambar'] ? '<a class="text-xs text-teal-700 underline" target="_blank" href="' . url('uploads/radiologi/' . rawurlencode($o['file_gambar'])) . '">Lihat gambar</a>' : '' ?></td>
    <td><?= aman($o['dokter']) ?><div class="muted"><?= aman($o['poli']) ?> · <?= aman(nama_cabang((int) $o['id_rs'])) ?></div><?= $o['jadwal'] ? '<div class="text-xs mt-1">Jadwal: <b>' . tgl_jam($o['jadwal']) . '</b></div>' : '' ?></td>
    <td><?= $o['status'] === 'Ditolak' ? badge('Ditolak') . '<div class="muted">' . aman($o['catatan_pelaksanaan']) . '</div>' : stepper($TAHAP, $o['status']) ?></td>
    <td class="num" style="min-width:180px">
    <?php if ($o['jaminan'] === 'Menunggu Persetujuan' && $o['status'] === 'Order Baru'): ?><span class="badge badge-amber">Menunggu persetujuan asuransi</span>
      <?php elseif ($adm && $o['status'] === 'Order Baru'): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-primary btn-sm">Verifikasi</button></form>
      <button class="btn btn-secondary btn-sm" data-open="tolak<?= $id ?>">Tolak</button>
      <dialog class="modal" id="tolak<?= $id ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="tolak"><input type="hidden" name="id" value="<?= $id ?>"><div class="modal-head"><h3>Tolak order radiologi</h3></div>
        <div class="modal-body"><input name="alasan" class="input" required placeholder="contoh: Pasien hamil, kontraindikasi X-Ray"></div><div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Batal</button><button class="btn btn-danger">Tolak</button></div></form></dialog>
    <?php elseif ($adm && in_array($o['status'], ['Terverifikasi', 'Terjadwal'], true)): ?>
      <form method="post" class="flex gap-1 justify-end"><?= csrf_field() ?><input type="hidden" name="aksi" value="jadwal"><input type="hidden" name="id" value="<?= $id ?>">
        <input type="datetime-local" name="jadwal" class="input input-sm" required value="<?= $o['jadwal'] ? date('Y-m-d\TH:i', strtotime($o['jadwal'])) : date('Y-m-d\TH:i', strtotime('+1 hour')) ?>"><button class="btn btn-primary btn-sm"><?= $o['status'] === 'Terjadwal' ? 'Ubah' : 'Jadwalkan' ?></button></form>
    <?php elseif ($rgf && $o['status'] === 'Terjadwal'): ?>
      <button class="btn btn-primary btn-sm" data-open="sel<?= $id ?>">Pemeriksaan Selesai</button>
      <dialog class="modal" id="sel<?= $id ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="selesai"><input type="hidden" name="id" value="<?= $id ?>"><div class="modal-head"><h3>Pelaksanaan <?= aman($o['nama']) ?></h3></div>
        <div class="modal-body"><input name="catatan" class="input" placeholder="Catatan pelaksanaan, contoh: Posisi PA, kooperatif"></div><div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Batal</button><button class="btn btn-primary">Simpan</button></div></form></dialog>
    <?php elseif ($rgf && in_array($o['status'], ['Pemeriksaan Selesai', 'Gambar Terunggah'], true)): ?>
      <form method="post" enctype="multipart/form-data" class="flex flex-col gap-1 items-end"><?= csrf_field() ?><input type="hidden" name="aksi" value="unggah"><input type="hidden" name="id" value="<?= $id ?>">
        <input type="file" name="gambar" accept=".jpg,.jpeg,.png,.webp" required class="text-xs"><button class="btn btn-secondary btn-sm"><?= $o['file_gambar'] ? 'Ganti Gambar' : 'Unggah Gambar' ?></button></form>
      <?php if ($o['status'] === 'Gambar Terunggah'): ?><form method="post" class="mt-1"><?= csrf_field() ?><input type="hidden" name="aksi" value="kirim"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-success btn-sm">Kirim ke Sp.Rad</button></form><?php endif; ?>
    <?php elseif ($rad && $o['status'] === 'Menunggu Ekspertise'): ?>
      <button class="btn btn-success btn-sm" data-open="eks<?= $id ?>">Tulis Ekspertise</button>
      <dialog class="modal modal-lg" id="eks<?= $id ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><div class="modal-head"><h3>Ekspertise <?= aman($o['nama']) ?> · <?= aman($o['nama_lengkap']) ?></h3></div>
        <div class="modal-body text-left">
          <?php if ($o['file_gambar']): ?><img src="<?= url('uploads/radiologi/' . rawurlencode($o['file_gambar'])) ?>" class="rad-img mb-3" alt=""><?php endif; ?>
          <div class="muted mb-2">Indikasi: <?= aman($o['indikasi']) ?> · <?= aman($o['catatan_pelaksanaan']) ?></div>
          <label class="lbl">Temuan</label><textarea name="temuan" class="input mb-2" rows="4" placeholder="contoh: Cor tidak membesar, pulmo tidak tampak infiltrat..."></textarea>
          <label class="lbl">Kesan / Kesimpulan</label><input name="kesimpulan" class="input" placeholder="contoh: Foto thorax dalam batas normal"></div>
        <div class="modal-foot"><button name="aksi" value="kembalikan" class="btn btn-secondary">Minta Ulang Gambar</button><button name="aksi" value="terbit" class="btn btn-success">Terbitkan</button></div></form></dialog>
    <?php elseif ($o['status'] === 'Diterbitkan'): ?><a href="?laporan=<?= $id ?>" class="btn btn-secondary btn-sm">Lihat Ekspertise</a>
    <?php else: ?><span class="muted">Menunggu tahap lain</span><?php endif; ?>
    </td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php tampil_footer();
