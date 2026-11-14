<?php
/*
 * MODUL 2: DATA PASIEN (PPT P10 CRUD) -- cari, lihat riwayat kunjungan, ubah data, hapus.
 * RBAC sesuai SOP Patient Registration:
 *   Petugas Registrasi    : memperbarui data demografi (alamat, HP, BPJS, gol. darah, alergi)
 *   Supervisor Registrasi : + koreksi IDENTITAS (nama, NIK, tgl lahir, JK) dengan alasan, hapus data salah input
 *   Dokter, Perawat, Kasir, Rekam Medis : hanya melihat
 */
require_once __DIR__ . '/../includes/app.php';
wajib_akses('registrasi');
$pdo = getPDO();
$bisa_ubah = boleh('registrasi', true) && role_is('pendaftaran', 'supervisorreg');
$supervisor = $bisa_ubah && role_is('supervisorreg');
$IDENTITAS = ['nama_lengkap' => 'nama', 'nik' => 'NIK', 'tanggal_lahir' => 'tanggal lahir', 'jenis_kelamin' => 'jenis kelamin'];
$errors = [];

if (is_post() && $bisa_ubah) {
    $id = (int) post('id');
    if (post('aksi') === 'hapus') {
        if (!$supervisor) { flash('error', 'Penghapusan data pasien hanya oleh Supervisor Registrasi.'); kembali(); }
        try {
            $pdo->prepare('DELETE FROM pasien WHERE id = ?')->execute([$id]);
            audit('Patient Registration', 'Hapus Pasien', "Pasien #$id");
            flash('sukses', 'Data pasien dihapus.');
            redirect('registrasi/pasien.php');
        } catch (PDOException $e) {
            flash('error', 'Pasien ini sudah memiliki riwayat kunjungan/rekam medis, sehingga tidak boleh dihapus (rekam medis wajib disimpan).');
            kembali();
        }
    }
    if (post('aksi') === 'ubah') {
        $d = ['nama_lengkap' => preg_replace('/\s+/', ' ', post('nama_lengkap')), 'nik' => post('nik'), 'tanggal_lahir' => post('tanggal_lahir'), 'jenis_kelamin' => post('jenis_kelamin'),
              'golongan_darah' => post('golongan_darah'), 'alamat' => post('alamat'), 'no_hp' => post('no_hp'), 'no_bpjs' => post('no_bpjs'), 'alergi' => post('alergi')];
        if ($e = v_nama($d['nama_lengkap'])) $errors['nama_lengkap'] = $e;
        if ($e = v_nik($d['nik'])) $errors['nik'] = $e;
        if ($e = v_tgl_lahir($d['tanggal_lahir'])) $errors['tanggal_lahir'] = $e;
        if (!in_array($d['jenis_kelamin'], ['L', 'P'], true)) $errors['jenis_kelamin'] = 'Pilih jenis kelamin.';
        if (!in_array($d['golongan_darah'], ['A', 'B', 'AB', 'O', '?'], true)) $errors['golongan_darah'] = 'Tidak valid.';
        if (mb_strlen($d['alamat']) < 10) $errors['alamat'] = 'Alamat minimal 10 karakter.';
        if ($e = v_hp($d['no_hp'])) $errors['no_hp'] = $e;
        if ($e = v_bpjs($d['no_bpjs'])) $errors['no_bpjs'] = $e;
        foreach (['nik' => 'NIK', 'no_bpjs' => 'No. BPJS'] as $k => $l) {
            if ($d[$k] === '' || isset($errors[$k])) continue;
            $s = $pdo->prepare("SELECT nomor_rm, nama_lengkap FROM pasien WHERE $k = ? AND id <> ?");
            $s->execute([$d[$k], $id]);
            if ($x = $s->fetch()) $errors[$k] = "$l sudah dipakai {$x['nama_lengkap']} ({$x['nomor_rm']}).";
        }
        $lama = $pdo->prepare('SELECT * FROM pasien WHERE id = ?');
        $lama->execute([$id]);
        $lama = $lama->fetch() ?: [];
        $berubah = array_keys(array_filter($IDENTITAS, fn($l, $k) => (string) ($lama[$k] ?? '') !== (string) $d[$k], ARRAY_FILTER_USE_BOTH));
        if ($berubah && !$supervisor) $errors['_identitas'] = 'Perubahan ' . implode(', ', array_map(fn($k) => $IDENTITAS[$k], $berubah)) . ' harus divalidasi Supervisor Registrasi (SOP). Petugas hanya boleh memperbarui data demografi.';
        if ($berubah && $supervisor && mb_strlen(post('alasan_koreksi')) < 10) $errors['alasan_koreksi'] = 'Tulis alasan koreksi identitas + dokumen yang dicek (min. 10 karakter), contoh: "Salah ketik NIK, dicek dari KTP asli".';
        if (!$errors) {
            $d['no_bpjs'] = $d['no_bpjs'] ?: null;
            $d['alergi'] = $d['alergi'] ?: null;
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
            $pdo->prepare("UPDATE pasien SET $set WHERE id = ?")->execute([...array_values($d), $id]);
            $log = $berubah ? implode('; ', array_map(fn($k) => $IDENTITAS[$k] . ': ' . $lama[$k] . ' -> ' . $d[$k], $berubah)) . ' | alasan: ' . post('alasan_koreksi') : 'data demografi';
            audit('Patient Registration', $berubah ? 'Koreksi Identitas (Supervisor)' : 'Ubah Data Demografi', "{$lama['nomor_rm']} $log");
            flash('sukses', $berubah ? 'Koreksi identitas tervalidasi Supervisor dan tercatat di audit log.' : 'Data demografi pasien diperbarui.');
            kembali(['ubah' => null]);
        }
    }
}

// ---------- DETAIL PASIEN ----------
if (get('id') !== '') {
    $s = $pdo->prepare('SELECT * FROM pasien WHERE id = ?');
    $s->execute([(int) get('id')]);
    $p = $s->fetch();
    if (!$p) redirect('registrasi/pasien.php');
    wajib_pasien_sendiri((int) $p['id']);
    $s = $pdo->prepare("SELECT k.*, pl.nama AS poli, d.nama_lengkap AS dokter, pj.nama AS penjamin, t.status AS status_tagihan
                        FROM kunjungan k JOIN unit_layanan pl ON pl.id=k.id_unit JOIN pegawai d ON d.id=k.id_dokter JOIN penjamin pj ON pj.id=k.id_penjamin
                        LEFT JOIN tagihan t ON t.id_kunjungan = k.id WHERE k.id_pasien = ? ORDER BY k.tanggal DESC, k.id DESC");
    $s->execute([$p['id']]);
    $riwayat = $s->fetchAll();
    $ubah = $bisa_ubah && (get('ubah') === '1' || $errors);
    $v = fn($k) => aman($_POST[$k] ?? $p[$k]);
    tampil_header('Detail Pasien', 'Data administratif pasien. Isi klinis (SOAP, diagnosis) hanya di modul EMR.',
        '<a href="pasien.php" class="btn btn-secondary">&larr; Daftar pasien</a>' . ($bisa_ubah ? ' <a href="pendaftaran.php?pasien=' . $p['id'] . '#form" class="btn btn-primary">Daftarkan Kunjungan</a>' : ''));
    echo kartu_pasien($p, 'NIK ' . aman($p['nik']) . ' · Gol. darah ' . aman($p['golongan_darah']) . ' · Terdaftar ' . tgl($p['created_at']) . ' di ' . aman(nama_cabang($p['id_rs_daftar'] ? (int) $p['id_rs_daftar'] : null)));
    ?>
    <div class="grid lg:grid-cols-2 gap-4">
    <div class="card"><div class="card-head"><h3>Identitas</h3><?php if ($bisa_ubah && !$ubah): ?><a href="?id=<?= $p['id'] ?>&ubah=1" class="btn btn-secondary btn-sm"><?= $supervisor ? 'Koreksi Identitas' : 'Perbarui Data' ?></a><?php endif; ?></div>
      <?php if ($ubah): ?>
      <form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="aksi" value="ubah"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <?php if (isset($errors['_identitas'])): ?><div class="alert alert-error"><?= aman($errors['_identitas']) ?></div><?php endif; ?>
        <?php if (!$supervisor): ?><div class="notice mb-3 text-sm">Nama, NIK, tanggal lahir, dan jenis kelamin hanya dapat dikoreksi oleh <b>Supervisor Registrasi</b>. Anda dapat memperbarui alamat, No. HP, No. BPJS, golongan darah, dan alergi.</div><?php endif; ?>
        <div class="field"><label>No. RM (tidak bisa diubah)</label><input value="<?= aman($p['nomor_rm']) ?>" readonly></div>
        <div class="form-grid">
          <div class="field"><label>Nama lengkap *</label><input name="nama_lengkap" <?= $supervisor ? '' : 'readonly' ?> value="<?= $v('nama_lengkap') ?>" class="<?= err_class($errors, 'nama_lengkap') ?>"><?= err($errors, 'nama_lengkap') ?></div>
          <div class="field"><label>NIK *</label><input name="nik" data-digits="16" <?= $supervisor ? '' : 'readonly' ?> value="<?= $v('nik') ?>" class="<?= err_class($errors, 'nik') ?>"><?= err($errors, 'nik') ?></div>
          <div class="field"><label>Tanggal lahir *</label><input type="date" name="tanggal_lahir" <?= $supervisor ? '' : 'readonly' ?> value="<?= $v('tanggal_lahir') ?>" max="<?= date('Y-m-d') ?>" class="<?= err_class($errors, 'tanggal_lahir') ?>"><?= err($errors, 'tanggal_lahir') ?></div>
          <div class="field"><label>Jenis kelamin *</label><?php if ($supervisor): ?><select name="jenis_kelamin"><?= opsi(['L' => 'Laki-laki', 'P' => 'Perempuan'], $_POST['jenis_kelamin'] ?? $p['jenis_kelamin']) ?></select><?php else: ?><input type="hidden" name="jenis_kelamin" value="<?= aman($p['jenis_kelamin']) ?>"><input value="<?= jk($p['jenis_kelamin']) ?>" readonly><?php endif; ?></div>
          <div class="field"><label>Golongan darah</label><select name="golongan_darah"><?= opsi(['?' => 'Tidak diketahui', 'A' => 'A', 'B' => 'B', 'AB' => 'AB', 'O' => 'O'], $_POST['golongan_darah'] ?? $p['golongan_darah']) ?></select></div>
          <div class="field"><label>No. HP *</label><input name="no_hp" data-hp value="<?= $v('no_hp') ?>" class="<?= err_class($errors, 'no_hp') ?>"><?= err($errors, 'no_hp') ?></div>
          <div class="field"><label>No. BPJS</label><input name="no_bpjs" data-digits="13" value="<?= $v('no_bpjs') ?>" class="<?= err_class($errors, 'no_bpjs') ?>"><?= err($errors, 'no_bpjs') ?></div>
          <div class="field"><label>Alergi</label><input name="alergi" value="<?= $v('alergi') ?>" maxlength="255"></div>
          <?php if ($supervisor): ?><div class="field md:col-span-2"><label>Alasan koreksi identitas <span class="muted">(wajib bila nama/NIK/tgl lahir/JK diubah)</span></label><input name="alasan_koreksi" value="<?= aman($_POST['alasan_koreksi'] ?? '') ?>" placeholder="contoh: Salah ketik NIK saat pendaftaran, dicek dari KTP asli" class="<?= err_class($errors, 'alasan_koreksi') ?>"><?= err($errors, 'alasan_koreksi') ?></div><?php endif; ?>
        </div>
        <div class="field"><label>Alamat *</label><textarea name="alamat" class="<?= err_class($errors, 'alamat') ?>"><?= $v('alamat') ?></textarea><?= err($errors, 'alamat') ?></div>
        <div class="flex gap-2"><button class="btn btn-primary">Simpan Perubahan</button><a href="?id=<?= $p['id'] ?>" class="btn btn-secondary">Batal</a></div>
      </form>
      <?php else: ?>
      <dl class="kv card-body"><dt>No. RM</dt><dd class="mono"><?= aman($p['nomor_rm']) ?></dd><dt>NIK</dt><dd class="mono"><?= aman($p['nik']) ?></dd><dt>Tanggal lahir</dt><dd><?= tgl($p['tanggal_lahir']) ?> (<?= umur($p['tanggal_lahir']) ?>)</dd>
        <dt>Jenis kelamin</dt><dd><?= jk($p['jenis_kelamin']) ?></dd><dt>No. HP</dt><dd><?= aman($p['no_hp']) ?></dd><dt>No. BPJS</dt><dd class="mono"><?= aman($p['no_bpjs'] ?: '-') ?></dd>
        <dt>Alamat</dt><dd><?= aman($p['alamat']) ?></dd><dt>Alergi</dt><dd><?= aman($p['alergi'] ?: 'Tidak ada') ?></dd></dl>
      <?php if ($supervisor && !$riwayat): ?><form method="post" class="px-4 pb-4" data-confirm="Hapus pasien ini?"><?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-danger btn-sm">Hapus Pasien (belum ada kunjungan)</button></form><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="card"><div class="card-head"><h3>Riwayat Kunjungan (<?= count($riwayat) ?>)</h3></div>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Tanggal</th><th>Cabang · Poli / Dokter</th><th>Penjamin</th><th>Status</th></tr></thead><tbody>
      <?php if (!$riwayat): ?><tr><td colspan="4" class="empty">Belum pernah berkunjung.</td></tr><?php endif; ?>
      <?php foreach ($riwayat as $r): ?><tr><td><?= tgl($r['tanggal']) ?><div class="muted mono"><?= aman($r['no_antrian'] ?? $r['kode_booking']) ?></div></td><td><span class="col-cabang"><?= aman(nama_cabang((int) $r['id_rs'])) ?></span><div><?= aman($r['poli']) ?></div><div class="muted"><?= aman($r['dokter']) ?></div></td><td><?= aman($r['penjamin']) ?></td>
        <td><?= badge($r['status']) ?><?= $r['jenis'] === 'rawat_inap' ? ' <span class="badge badge-purple">Rawat Inap</span>' : '' ?><?= $r['status_tagihan'] ? '<div class="muted">Tagihan: ' . aman($r['status_tagihan']) . '</div>' : '' ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    </div>
    <?php tampil_footer();
    exit;
}

// ---------- DAFTAR PASIEN ----------
$q = get('q');
$hal = max(1, (int) get('hal', '1'));
$per = 25;
$where = ' WHERE 1=1' . sql_pasien_dokter('id');   // dokter: hanya pasiennya sendiri
$par = [];
if ($q !== '') { $where .= ' AND (nama_lengkap LIKE ? OR nomor_rm LIKE ? OR nik LIKE ? OR no_hp LIKE ? OR no_bpjs LIKE ?)'; $par = array_fill(0, 5, "%$q%"); }
if (get('cabang') !== '' && isset(daftar_cabang()[(int) get('cabang')])) { $where .= ' AND id_rs_daftar = ?'; $par[] = (int) get('cabang'); }
$s = $pdo->prepare("SELECT COUNT(*) FROM pasien$where");
$s->execute($par);
$total = (int) $s->fetchColumn();
$s = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM kunjungan k WHERE k.id_pasien = p.id) AS jml_kunjungan, (SELECT MAX(tanggal) FROM kunjungan k WHERE k.id_pasien = p.id) AS terakhir
                    FROM pasien p$where ORDER BY p.id DESC LIMIT $per OFFSET " . (($hal - 1) * $per));
$s->execute($par);
$rows = $s->fetchAll();
$n = fn($sql) => (int) $pdo->query($sql)->fetchColumn();

tampil_header('Data Pasien', 'Master pasien semua cabang. Satu orang = satu No. RM (dibedakan oleh NIK), berlaku di semua cabang.', $bisa_ubah ? '<a href="pendaftaran.php" class="btn btn-primary">+ Pendaftaran</a>' : '');
echo kartu([['Pasien Terdaftar', $n('SELECT COUNT(*) FROM pasien'), 'teal'], ['Peserta BPJS', $n('SELECT COUNT(*) FROM pasien WHERE no_bpjs IS NOT NULL'), 'green'],
            ['Punya Riwayat Alergi', $n("SELECT COUNT(*) FROM pasien WHERE alergi IS NOT NULL AND alergi <> ''"), 'red'], ['Pasien Baru Bulan Ini', $n("SELECT COUNT(*) FROM pasien WHERE created_at >= '" . date('Y-m-01') . "'"), 'purple']]);
?>
<div class="card"><div class="card-head"><h3>Daftar Pasien <span class="muted">(<?= $total ?>)</span></h3>
  <form method="get" class="flex gap-2"><select name="cabang" class="input" style="width:auto" onchange="this.form.submit()"><?= opsi(array_map(fn($c) => 'Daftar di ' . $c['nama_cabang'], daftar_cabang()), get('cabang'), 'Semua cabang') ?></select>
    <input name="q" value="<?= aman($q) ?>" class="input" style="width:260px" placeholder="Nama / No. RM / NIK / HP / BPJS"><button class="btn btn-secondary">Cari</button></form></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pasien</th><th>No. RM</th><th>Cabang daftar</th><th>NIK</th><th>Lahir / Umur</th><th>Kontak</th><th>Kunjungan</th><th></th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="empty">Pasien tidak ditemukan.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="?id=<?= $r['id'] ?>" class="pname hover:underline"><?= aman($r['nama_lengkap']) ?></a><div class="muted"><?= jk($r['jenis_kelamin']) ?><?= $r['alergi'] ? ' · <span class="text-red-700">alergi ' . aman($r['alergi']) . '</span>' : '' ?></div></td>
      <td class="mono"><?= aman($r['nomor_rm']) ?></td><td class="col-cabang"><?= aman(nama_cabang($r['id_rs_daftar'] ? (int) $r['id_rs_daftar'] : null)) ?></td><td class="mono"><?= aman($r['nik']) ?></td><td><?= tgl($r['tanggal_lahir']) ?><div class="muted"><?= umur($r['tanggal_lahir']) ?></div></td>
      <td><?= aman($r['no_hp']) ?><div class="muted"><?= $r['no_bpjs'] ? 'BPJS ' . aman($r['no_bpjs']) : 'Non-BPJS' ?></div></td>
      <td><?= (int) $r['jml_kunjungan'] ?>x<div class="muted"><?= $r['terakhir'] ? 'terakhir ' . tgl($r['terakhir']) : '-' ?></div></td>
      <td class="num"><a href="?id=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">Detail</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php if ($total > $per): ?><div class="card-body flex gap-2 justify-end"><?php for ($i = 1; $i <= ceil($total / $per); $i++): ?><a class="tab<?= $i === $hal ? ' active' : '' ?>" href="?q=<?= urlencode($q) ?>&cabang=<?= urlencode(get('cabang')) ?>&hal=<?= $i ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
</div>
<?php tampil_footer();
