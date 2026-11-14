<?php
/*
 * DATA LAYANAN: SATU TABEL `unit_layanan` UNTUK SEMUA UNIT PELAYANAN (payung besar), per cabang.
 *   unit.php?jenis=Poliklinik  -> poliklinik & IGD (huruf antrean, tarif konsultasi)
 *   unit.php?jenis=Rawat Inap  -> ruang rawat inap (kelas, tarif per hari) + bed (tabel bed)
 */
require_once __DIR__ . '/../includes/app.php';
wajib_akses('master');
$pdo = getPDO();
$bisa_ubah = boleh('master', true);
$ranap = get('jenis') === 'Rawat Inap';
$GLOBALS['TABEL_HALAMAN'] = $ranap ? 'unit_layanan (jenis = Rawat Inap), bed' : 'unit_layanan (jenis = Poliklinik / IGD)';
$KELAS = ['VIP' => 'VIP', 'I' => 'Kelas I', 'II' => 'Kelas II', 'III' => 'Kelas III', 'ICU' => 'ICU', 'Isolasi' => 'Isolasi'];
$CABANG = array_map(fn($c) => $c['singkatan'] . ' ' . $c['nama_cabang'], daftar_cabang());
$errors = [];

if (is_post() && $bisa_ubah) {
    $aksi = post('aksi');
    if ($aksi === 'simpan') {
        $id = (int) post('_id');
        $d = ['id_rs' => (int) post('id_rs'), 'kode' => strtoupper(post('kode')), 'nama' => post('nama'), 'jenis' => $ranap ? 'Rawat Inap' : post('jenis_unit'),
              'kode_antrian' => $ranap ? null : (strtoupper(post('kode_antrian')) ?: null), 'kelas' => $ranap ? post('kelas') : null,
              'lokasi' => post('lokasi'), 'tarif' => post('tarif'), 'aktif' => isset($_POST['aktif']) ? 1 : 0];
        $jml_bed = (int) post('jumlah_bed', '1');
        if (!isset($CABANG[$d['id_rs']])) $errors['id_rs'] = 'Pilih cabang.';
        if (!preg_match('/^[A-Z]{3}-[A-Z0-9]{2,10}$/', $d['kode'])) $errors['kode'] = 'Kode diawali kode cabang, contoh: TBT-R206 atau DPK-UMUM.';
        if ($e = v_wajib($d['nama'], 'Nama unit')) $errors['nama'] = $e;
        if (!$ranap && !in_array($d['jenis'], ['Poliklinik', 'IGD'], true)) $errors['jenis_unit'] = 'Pilih jenis unit.';
        if (!$ranap && !preg_match('/^[A-Z]$/', (string) $d['kode_antrian'])) $errors['kode_antrian'] = 'Isi satu huruf A-Z untuk nomor antrean.';
        if ($ranap && !isset($KELAS[$d['kelas']])) $errors['kelas'] = 'Pilih kelas ruang.';
        if ($e = v_wajib($d['lokasi'], 'Lokasi')) $errors['lokasi'] = $e;
        if ($e = v_angka($d['tarif'], $ranap ? 'Tarif per hari' : 'Tarif konsultasi', 0, 100000000)) $errors['tarif'] = $e;
        if ($ranap && $id === 0 && ($jml_bed < 1 || $jml_bed > 12)) $errors['jumlah_bed'] = 'Jumlah bed 1 sampai 12.';
        foreach (['kode' => 'SELECT COUNT(*) FROM unit_layanan WHERE kode = ? AND id <> ?', 'nama' => 'SELECT COUNT(*) FROM unit_layanan WHERE nama = ? AND id_rs = ' . (int) $d['id_rs'] . ' AND id <> ?',
                  'kode_antrian' => 'SELECT COUNT(*) FROM unit_layanan WHERE kode_antrian = ? AND id_rs = ' . (int) $d['id_rs'] . ' AND id <> ?'] as $k => $q) {
            if ($d[$k] === null || isset($errors[$k])) continue;
            $s = $pdo->prepare($q); $s->execute([$d[$k], $id]);
            if ($s->fetchColumn()) $errors[$k] = $k === 'kode' ? 'Kode unit sudah dipakai.' : ($k === 'nama' ? 'Nama unit sudah ada di cabang ini.' : 'Huruf antrean sudah dipakai unit lain di cabang ini.');
        }
        if (!$errors) {
            $pdo->beginTransaction();
            if ($id === 0) {
                $pdo->prepare('INSERT INTO unit_layanan (id_rs, kode, nama, jenis, kode_antrian, kelas, lokasi, tarif, aktif) VALUES (?,?,?,?,?,?,?,?,?)')->execute(array_values($d));
                $uid = (int) $pdo->lastInsertId();
                if ($ranap) { $ins = $pdo->prepare('INSERT INTO bed (id_unit, kode_bed) VALUES (?, ?)'); for ($i = 0; $i < $jml_bed; $i++) $ins->execute([$uid, chr(65 + $i)]); }
                audit('Master Data', 'Tambah Unit', "{$d['kode']} {$d['nama']}" . ($ranap ? " dengan $jml_bed bed" : ''));
                flash('sukses', "{$d['nama']} tersimpan" . ($ranap ? " dengan $jml_bed bed." : '.'));
            } else {
                $pdo->prepare('UPDATE unit_layanan SET id_rs=?, kode=?, nama=?, jenis=?, kode_antrian=?, kelas=?, lokasi=?, tarif=?, aktif=? WHERE id=?')->execute([...array_values($d), $id]);
                audit('Master Data', 'Ubah Unit', "{$d['kode']} {$d['nama']}");
                flash('sukses', "{$d['nama']} diperbarui.");
            }
            $pdo->commit();
            kembali(['edit' => null, 'form' => null]);
        }
    }
    if ($aksi === 'tambah_bed') {
        $uid = (int) post('id_unit');
        $s = $pdo->prepare('SELECT MAX(kode_bed) FROM bed WHERE id_unit = ?');
        $s->execute([$uid]);
        $last = $s->fetchColumn();
        $next = $last ? chr(ord($last) + 1) : 'A';
        if ($next > 'L') flash('error', 'Maksimal 12 bed per ruang.');
        else {
            $pdo->prepare('INSERT INTO bed (id_unit, kode_bed) VALUES (?, ?)')->execute([$uid, $next]);
            audit('Master Data', 'Tambah Bed', "Bed $next di unit #$uid");
            flash('sukses', "Bed $next ditambahkan.");
        }
        kembali();
    }
    if ($aksi === 'status_bed') {
        $bid = (int) post('id_bed');
        $baru = post('status') === 'Perbaikan' ? 'Perbaikan' : 'Tersedia';
        $s = $pdo->prepare("UPDATE bed SET status = ? WHERE id = ? AND status <> 'Terisi'");   // bed berisi pasien tidak boleh diubah
        $s->execute([$baru, $bid]);
        flash($s->rowCount() ? 'sukses' : 'error', $s->rowCount() ? "Status bed diubah menjadi $baru." : 'Bed sedang terisi pasien, status tidak bisa diubah.');
        if ($s->rowCount()) audit('Master Data', 'Status Bed', "Bed #$bid -> $baru");
        kembali();
    }
}

$where = ($ranap ? "u.jenis = 'Rawat Inap'" : "u.jenis IN ('Poliklinik','IGD')") . sql_rs('u.id_rs');
$unit = $pdo->query("SELECT u.*, COUNT(b.id) AS total_bed, SUM(CASE WHEN b.status='Terisi' THEN 1 ELSE 0 END) AS terisi
                     FROM unit_layanan u LEFT JOIN bed b ON b.id_unit = u.id WHERE $where GROUP BY u.id ORDER BY u.id_rs, u.jenis DESC, u.kode")->fetchAll();
$beds = [];
foreach ($pdo->query('SELECT * FROM bed ORDER BY kode_bed') as $b) $beds[$b['id_unit']][] = $b;
$edit = null;
if (get('edit') !== '') { $s = $pdo->prepare('SELECT * FROM unit_layanan WHERE id = ?'); $s->execute([(int) get('edit')]); $edit = $s->fetch() ?: null; }
$v = fn($k, $d = '') => aman($_POST[$k] ?? ($edit[$k] ?? $d));
$q = fn(array $x = []) => '?' . http_build_query(array_merge(['jenis' => $ranap ? 'Rawat Inap' : 'Poliklinik'], $x));

tampil_header($ranap ? 'Ruang Rawat Inap & Bed' : 'Poliklinik & IGD',
    $ranap ? 'Satu ruang berisi beberapa bed. Okupansi (BOR) dihitung dari bed yang terisi.' : 'Unit rawat jalan & IGD tiap cabang. Huruf antrean dipakai untuk nomor antrean (contoh: B-003).',
    $bisa_ubah ? '<a href="' . $q(['form' => 'baru']) . '#form" class="btn btn-primary">+ Tambah ' . ($ranap ? 'Ruang' : 'Unit') . '</a>' : '');
if ($ranap) {
    $total = array_sum(array_column($unit, 'total_bed')); $terisi = array_sum(array_column($unit, 'terisi'));
    echo kartu([['Jumlah Ruang', count($unit), 'teal'], ['Total Bed', $total, 'teal'], ['Bed Terisi', $terisi, 'red'], ['Bed Tersedia', $total - $terisi, 'green'], ['BOR Saat Ini', $total ? round($terisi / $total * 100, 1) . '%' : '0%', 'purple']]);
}
if ($bisa_ubah && ($edit || get('form') === 'baru' || $errors)): ?>
<div class="card mb-4" id="form"><div class="card-head"><h3><?= $edit ? 'Ubah ' . aman($edit['nama']) : 'Tambah ' . ($ranap ? 'Ruang Rawat Inap' : 'Poliklinik / IGD') ?></h3></div>
  <form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="_id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div class="form-grid">
      <div class="field"><label>Cabang <span class="req">*</span></label><select name="id_rs" class="<?= err_class($errors, 'id_rs') ?>"><?= opsi($CABANG, $_POST['id_rs'] ?? ($edit['id_rs'] ?? rs_simpan()), '-- Pilih --') ?></select><?= err($errors, 'id_rs') ?></div>
      <div class="field"><label>Kode <span class="req">*</span></label><input name="kode" value="<?= $v('kode') ?>" maxlength="15" class="<?= err_class($errors, 'kode') ?>" placeholder="<?= $ranap ? 'TBT-R206' : 'DPK-BEDAH' ?>"><?= err($errors, 'kode') ?></div>
      <div class="field"><label>Nama <span class="req">*</span></label><input name="nama" value="<?= $v('nama') ?>" maxlength="100" class="<?= err_class($errors, 'nama') ?>" placeholder="<?= $ranap ? 'Melati 206' : 'Poli Bedah' ?>"><?= err($errors, 'nama') ?></div>
      <?php if ($ranap): ?>
      <div class="field"><label>Kelas <span class="req">*</span></label><select name="kelas" class="<?= err_class($errors, 'kelas') ?>"><?= opsi($KELAS, $_POST['kelas'] ?? ($edit['kelas'] ?? ''), '-- Pilih --') ?></select><?= err($errors, 'kelas') ?></div>
      <?php else: ?>
      <div class="field"><label>Jenis unit <span class="req">*</span></label><select name="jenis_unit" class="<?= err_class($errors, 'jenis_unit') ?>"><?= opsi(['Poliklinik' => 'Poliklinik', 'IGD' => 'IGD'], $_POST['jenis_unit'] ?? ($edit['jenis'] ?? 'Poliklinik')) ?></select><?= err($errors, 'jenis_unit') ?></div>
      <div class="field"><label>Huruf antrean <span class="req">*</span></label><input name="kode_antrian" value="<?= $v('kode_antrian') ?>" maxlength="1" class="<?= err_class($errors, 'kode_antrian') ?>" placeholder="B"><?= err($errors, 'kode_antrian') ?></div>
      <?php endif; ?>
      <div class="field"><label>Lokasi <span class="req">*</span></label><input name="lokasi" value="<?= $v('lokasi') ?>" maxlength="60" class="<?= err_class($errors, 'lokasi') ?>" placeholder="Gedung A lantai 2"><?= err($errors, 'lokasi') ?></div>
      <div class="field"><label><?= $ranap ? 'Tarif per hari' : 'Tarif konsultasi' ?> (Rp) <span class="req">*</span></label><input type="number" name="tarif" min="0" step="1000" value="<?= $v('tarif') ?>" class="<?= err_class($errors, 'tarif') ?>"><?= err($errors, 'tarif') ?></div>
      <?php if ($ranap && !$edit): ?><div class="field"><label>Jumlah bed <span class="req">*</span></label><input type="number" name="jumlah_bed" min="1" max="12" value="<?= old('jumlah_bed', '2') ?>" class="<?= err_class($errors, 'jumlah_bed') ?>"><?= err($errors, 'jumlah_bed') ?><p class="hint">Bed diberi kode A, B, C, ...</p></div><?php endif; ?>
      <div class="field"><label class="lbl">&nbsp;</label><label class="inline-flex gap-2 items-center text-sm font-semibold"><input type="checkbox" name="aktif" value="1" <?= (int) ($_POST['aktif'] ?? ($edit['aktif'] ?? 1)) ? 'checked' : '' ?>> Aktif</label></div>
    </div>
    <div class="flex gap-2"><button class="btn btn-primary">Simpan</button><a href="<?= $q() ?>" class="btn btn-secondary">Batal</a></div>
  </form></div>
<?php endif; ?>

<div class="card"><div class="card-head"><h3><?= $ranap ? 'Daftar Ruang &amp; Bed' : 'Daftar Poliklinik &amp; IGD' ?> <span class="muted">(<?= count($unit) ?> unit<?= rs_aktif() ? ' · ' . aman(nama_cabang(rs_aktif())) : ' · semua cabang' ?>)</span></h3><?php if ($ranap): ?><span class="muted">Hijau tersedia · merah terisi · abu-abu perbaikan</span><?php endif; ?></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Cabang</th><th>Kode</th><th>Nama</th><th><?= $ranap ? 'Kelas' : 'Jenis / Antrean' ?></th><th>Lokasi</th><th class="num"><?= $ranap ? 'Tarif/hari' : 'Tarif konsultasi' ?></th><?php if ($ranap): ?><th>Bed</th><?php endif; ?><th>Status</th><?php if ($bisa_ubah): ?><th class="num">Aksi</th><?php endif; ?></tr></thead><tbody>
  <?php if (!$unit): ?><tr><td colspan="9" class="empty">Belum ada data.</td></tr><?php endif; ?>
  <?php foreach ($unit as $u): ?>
    <tr><td class="col-cabang"><?= aman(nama_cabang((int) $u['id_rs'])) ?></td><td class="mono"><?= aman($u['kode']) ?></td><td class="pname"><?= aman($u['nama']) ?></td>
      <td><?= $ranap ? aman($KELAS[$u['kelas']]) : aman($u['jenis']) . ' · <b>' . aman($u['kode_antrian']) . '</b>' ?></td><td><?= aman($u['lokasi']) ?></td><td class="num"><?= rupiah($u['tarif']) ?></td>
      <?php if ($ranap): ?><td><div class="flex flex-wrap gap-1.5">
        <?php foreach ($beds[$u['id']] ?? [] as $b): ?>
          <?php if ($bisa_ubah && $b['status'] !== 'Terisi'): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="status_bed"><input type="hidden" name="id_bed" value="<?= $b['id'] ?>"><input type="hidden" name="status" value="<?= $b['status'] === 'Perbaikan' ? 'Tersedia' : 'Perbaikan' ?>">
              <button class="bed bed-<?= $b['status'] ?>" style="padding:3px 8px" title="Klik untuk ubah status <?= $b['status'] === 'Perbaikan' ? 'menjadi Tersedia' : 'menjadi Perbaikan' ?>"><b class="inline"><?= aman($b['kode_bed']) ?></b> <?= aman($b['status']) ?></button></form>
          <?php else: ?><span class="bed bed-<?= $b['status'] ?>" style="padding:3px 8px"><b class="inline"><?= aman($b['kode_bed']) ?></b> <?= aman($b['status']) ?></span><?php endif; ?>
        <?php endforeach; ?></div></td><?php endif; ?>
      <td><?= badge($u['aktif'] ? 'Aktif' : 'Nonaktif') ?></td>
      <?php if ($bisa_ubah): ?><td class="num">
        <a class="btn btn-secondary btn-sm" href="<?= $q(['edit' => $u['id']]) ?>#form">Ubah</a>
        <?php if ($ranap): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="tambah_bed"><input type="hidden" name="id_unit" value="<?= $u['id'] ?>"><button class="btn btn-secondary btn-sm">+ Bed</button></form><?php endif; ?>
      </td><?php endif; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php tampil_footer();
