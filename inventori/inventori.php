<?php
/*
 * MODUL 14: INVENTORY (Gudang & Persediaan)
 *   Stok per batch + kedaluwarsa (FEFO), permintaan unit, purchase request (disetujui Manajemen),
 *   penerimaan barang (batch baru), stock opname / penyesuaian, kartu stok (mutasi).
 *   Gudang = operasional · Perawat/Apoteker = mengajukan permintaan unit · Manajemen = menyetujui PR.
 * Tabel: obat, stok_batch, mutasi_stok, pengadaan (Permintaan Unit & Purchase Request + penerimaannya -- satu tabel, beda di kolom jenis).
 * Gudang pusat melayani semua cabang; permintaan unit mencatat cabang peminta (id_rs).
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('inventori');
$pdo = getPDO();
$gudang = role_is('gudang') && boleh('inventori', true);
$bisa_minta = role_is('gudang', 'perawat', 'apoteker');
$bisa_setuju = role_is('manajemen');
$UNIT = ['Farmasi Rawat Jalan', 'Farmasi Rawat Inap', 'IGD', 'Rawat Inap', 'Poli Umum', 'Poli Anak', 'Poli Penyakit Dalam', 'Poli Bedah', 'Poli Kandungan', 'Laboratorium', 'Radiologi'];
$daftar_barang = $pdo->query('SELECT id, kode, nama_obat, satuan, stok, stok_minimum, kategori FROM obat WHERE aktif = 1 ORDER BY nama_obat')->fetchAll();
$opsi_barang = [];
foreach ($daftar_barang as $b) $opsi_barang[$b['id']] = "{$b['nama_obat']} ({$b['satuan']}) · stok {$b['stok']}";

if (is_post()) {
    $aksi = post('aksi');
    try {
        if ($aksi === 'minta') {
            if (!$bisa_minta) throw new Exception('Permintaan unit diajukan oleh Perawat, Apoteker, atau Gudang.');
            $unit = post('unit'); $id_obat = (int) post('id_obat'); $n = (int) post('jumlah');
            if (!in_array($unit, $UNIT, true)) throw new Exception('Pilih unit peminta.');
            if (!isset($opsi_barang[$id_obat])) throw new Exception('Pilih barang.');
            if ($n < 1 || $n > 10000) throw new Exception('Jumlah 1 sampai 10.000.');
            $kode = kode_berikut('pengadaan', 'kode', 'REQ');
            $pdo->prepare("INSERT INTO pengadaan (id_rs, kode, jenis, unit_peminta, id_obat, jumlah, alasan, diajukan_oleh) VALUES (?,?,'Permintaan Unit',?,?,?,?,?)")
                ->execute([rs_simpan(), $kode, mb_substr($unit . (rs_simpan() !== 1 ? ' ' . konfig('nama_cabang', rs_simpan()) : ''), 0, 60), $id_obat, $n, post('keterangan') ?: null, uid()]);
            $pesan = "permintaan $kode diajukan ke Gudang";
        } elseif (in_array($aksi, ['keluarkan', 'tolak_minta'], true)) {
            if (!$gudang) throw new Exception('Hanya petugas Gudang yang memproses permintaan.');
            $r = $pdo->prepare("SELECT pu.*, pu.unit_peminta AS unit, o.nama_obat FROM pengadaan pu JOIN obat o ON o.id = pu.id_obat WHERE pu.id = ? AND pu.jenis = 'Permintaan Unit' AND pu.status = ?");
            $r->execute([(int) post('id'), 'Diajukan']);
            if (!$r = $r->fetch()) throw new Exception('Permintaan sudah diproses.');
            if ($aksi === 'keluarkan') {
                $pdo->beginTransaction();
                potong_stok((int) $r['id_obat'], (int) $r['jumlah'], 'Keluar', "{$r['kode']} ke {$r['unit']}", null, (int) $r['id']);
                $pdo->prepare("UPDATE pengadaan SET status = 'Dikeluarkan', diproses_oleh = ?, catatan = ? WHERE id = ? AND status = 'Diajukan'")->execute([uid(), post('catatan') ?: 'Dikeluarkan (FEFO)', $r['id']]);
                $pdo->commit();
                $pesan = "{$r['jumlah']} {$r['nama_obat']} dikeluarkan ke {$r['unit']}";
            } else {
                if (mb_strlen(post('catatan')) < 5) throw new Exception('Tulis alasan penolakan.');
                $pdo->prepare("UPDATE pengadaan SET status = 'Ditolak', diproses_oleh = ?, catatan = ? WHERE id = ?")->execute([uid(), mb_substr(post('catatan'), 0, 255), $r['id']]);
                $pesan = "permintaan {$r['kode']} ditolak";
            }
        } elseif ($aksi === 'pr') {
            if (!$gudang) throw new Exception('Purchase request dibuat oleh petugas Gudang.');
            $id_obat = (int) post('id_obat'); $n = (int) post('jumlah');
            if (!isset($opsi_barang[$id_obat])) throw new Exception('Pilih barang.');
            if ($n < 1 || $n > 100000) throw new Exception('Jumlah 1 sampai 100.000.');
            if (mb_strlen(post('alasan')) < 5) throw new Exception('Alasan pengadaan wajib diisi.');
            $ada = $pdo->prepare("SELECT kode FROM pengadaan WHERE jenis = 'Purchase Request' AND id_obat = ? AND status IN ('Diajukan','Disetujui')");
            $ada->execute([$id_obat]);
            if ($x = $ada->fetchColumn()) throw new Exception("Barang ini masih punya PR aktif ($x). Selesaikan dulu.");
            $kode = kode_berikut('pengadaan', 'kode', 'PR');
            $pdo->prepare("INSERT INTO pengadaan (id_rs, kode, jenis, id_obat, jumlah, alasan, diajukan_oleh) VALUES (?,?,'Purchase Request',?,?,?,?)")->execute([rs_user(), $kode, $id_obat, $n, mb_substr(post('alasan'), 0, 255), uid()]);
            $pesan = "$kode diajukan, menunggu persetujuan Manajemen";
        } elseif (in_array($aksi, ['setujui', 'tolak_pr'], true)) {
            if (!$bisa_setuju) throw new Exception('Persetujuan purchase request oleh Manajemen.');
            $u = $pdo->prepare("UPDATE pengadaan SET status = ?, diproses_oleh = ? WHERE id = ? AND jenis = 'Purchase Request' AND status = 'Diajukan'");
            $u->execute([$aksi === 'setujui' ? 'Disetujui' : 'Ditolak', uid(), (int) post('id')]);
            if (!$u->rowCount()) throw new Exception('PR sudah diputuskan.');
            $pesan = $aksi === 'setujui' ? 'PR disetujui, Gudang dapat menerima barang' : 'PR ditolak';
        } elseif ($aksi === 'terima') {
            if (!$gudang) throw new Exception('Penerimaan barang oleh petugas Gudang.');
            $pr = $pdo->prepare("SELECT pr.*, o.nama_obat FROM pengadaan pr JOIN obat o ON o.id = pr.id_obat WHERE pr.id = ? AND pr.jenis = 'Purchase Request' AND pr.status = 'Disetujui'");
            $pr->execute([(int) post('id_pr')]);
            if (!$pr = $pr->fetch()) throw new Exception('Pilih PR yang sudah disetujui.');
            $n = (int) post('jumlah'); $batch = strtoupper(post('no_batch')); $ed = post('ed');
            if ($n < 1 || $n > $pr['jumlah']) throw new Exception("Jumlah diterima 1 sampai {$pr['jumlah']} (sesuai PR).");
            if (!preg_match('/^[A-Z0-9\-]{3,30}$/', $batch)) throw new Exception('No. batch 3-30 karakter (huruf/angka/-).');
            if (v_tanggal($ed) !== null || $ed <= date('Y-m-d', strtotime('+30 days'))) throw new Exception('Tanggal kedaluwarsa minimal 30 hari dari hari ini (barang mendekati ED tidak diterima).');
            if (mb_strlen(post('supplier')) < 3 || mb_strlen(post('no_po')) < 3) throw new Exception('No. PO dan supplier wajib diisi.');
            $kondisi = post('kondisi') === 'Rusak Sebagian' ? 'Rusak Sebagian' : 'Baik';
            $pdo->beginTransaction();
            $b = $pdo->prepare('SELECT id, tgl_kedaluwarsa FROM stok_batch WHERE id_obat = ? AND no_batch = ?');
            $b->execute([$pr['id_obat'], $batch]);
            if ($row = $b->fetch()) {
                if ($row['tgl_kedaluwarsa'] !== $ed) throw new Exception("Batch $batch sudah ada dengan ED berbeda (" . tgl($row['tgl_kedaluwarsa']) . ').');
                $id_batch = (int) $row['id'];
            } else {
                $pdo->prepare('INSERT INTO stok_batch (id_obat, no_batch, tgl_kedaluwarsa, qty_sisa, lokasi) VALUES (?,?,?,0,?)')->execute([$pr['id_obat'], $batch, $ed, post('lokasi') ?: 'Gudang Farmasi']);
                $id_batch = (int) $pdo->lastInsertId();
            }
            // penerimaan barang dicatat di baris pengadaan yang sama (no_po, supplier, batch, jumlah diterima)
            tambah_stok((int) $pr['id_obat'], $n, $id_batch, 'Masuk', mb_substr("Penerimaan {$pr['kode']} PO " . strtoupper(post('no_po')) . ' dari ' . post('supplier'), 0, 255), null, (int) $pr['id']);
            $pdo->prepare("UPDATE pengadaan SET status = 'Diterima', no_po = ?, supplier = ?, id_batch = ?, jumlah_diterima = ?, kondisi = ?, diterima_oleh = ?, waktu_terima = ? WHERE id = ?")
                ->execute([strtoupper(post('no_po')), mb_substr(post('supplier'), 0, 100), $id_batch, $n, $kondisi, uid(), date('Y-m-d H:i:s'), $pr['id']]);
            $pdo->commit();
            $pesan = "$n {$pr['nama_obat']} diterima ke batch $batch (ED " . tgl($ed) . ')';
        } elseif ($aksi === 'opname') {
            if (!$gudang) throw new Exception('Stock opname oleh petugas Gudang.');
            $b = $pdo->prepare('SELECT b.*, o.nama_obat FROM stok_batch b JOIN obat o ON o.id = b.id_obat WHERE b.id = ?');
            $b->execute([(int) post('id_batch')]);
            if (!$b = $b->fetch()) throw new Exception('Batch tidak ditemukan.');
            $fisik = post('fisik');
            if (!preg_match('/^\d{1,6}$/', $fisik)) throw new Exception('Jumlah fisik harus angka 0 atau lebih.');
            $selisih = (int) $fisik - (int) $b['qty_sisa'];
            if ($selisih === 0) throw new Exception('Jumlah fisik sama dengan sistem, tidak ada penyesuaian.');
            if (mb_strlen(post('alasan')) < 5) throw new Exception('Alasan penyesuaian wajib diisi (contoh: rusak, kedaluwarsa, salah hitung).');
            $pdo->beginTransaction();
            $u = $pdo->prepare('UPDATE obat SET stok = stok + ? WHERE id = ? AND stok + ? >= 0');
            $u->execute([$selisih, $b['id_obat'], $selisih]);
            if (!$u->rowCount()) throw new Exception('Penyesuaian membuat stok minus.');
            $pdo->prepare('UPDATE stok_batch SET qty_sisa = ? WHERE id = ?')->execute([(int) $fisik, $b['id']]);
            $pdo->prepare("INSERT INTO mutasi_stok (id_obat, id_batch, jenis, jumlah, keterangan, oleh) VALUES (?,?,'Penyesuaian',?,?,?)")->execute([$b['id_obat'], $b['id'], $selisih, 'Opname: ' . post('alasan'), uid()]);
            $pdo->commit();
            $pesan = "stok {$b['nama_obat']} batch {$b['no_batch']} disesuaikan " . ($selisih > 0 ? '+' : '') . $selisih;
        } else throw new Exception('Aksi tidak dikenal.');
        audit('Inventory', ucfirst(str_replace('_', ' ', $aksi)), $pesan);
        flash('sukses', ucfirst($pesan) . '.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e instanceof PDOException ? (is_duplikat($e) ? 'Data duplikat.' : 'Gagal menyimpan: ' . $e->getMessage()) : $e->getMessage());
    }
    kembali();
}

$tab = get('tab', role_is('perawat', 'apoteker') ? 'permintaan' : (role_is('manajemen') ? 'pr' : 'stok'));
$hari_ini = date('Y-m-d');
$ed90 = date('Y-m-d', strtotime('+90 days'));
$kpi = [
    ['Jenis Barang Aktif', count($daftar_barang), 'teal'],
    ['Di Bawah Minimum', count(array_filter($daftar_barang, fn($b) => $b['stok'] < $b['stok_minimum'])), 'red'],
    ['Batch ED ≤ 90 hari', (int) $pdo->query("SELECT COUNT(*) FROM stok_batch WHERE qty_sisa > 0 AND tgl_kedaluwarsa <= '$ed90'")->fetchColumn(), 'amber'],
    ['Permintaan Unit Baru', (int) $pdo->query("SELECT COUNT(*) FROM pengadaan WHERE jenis = 'Permintaan Unit' AND status = 'Diajukan'" . sql_rs('id_rs'))->fetchColumn(), 'purple'],
    ['PR Menunggu', (int) $pdo->query("SELECT COUNT(*) FROM pengadaan WHERE jenis = 'Purchase Request' AND status IN ('Diajukan','Disetujui')")->fetchColumn(), 'green'],
];
tampil_header('Gudang & Persediaan', $gudang ? 'Kelola stok per batch, permintaan unit, pengadaan, penerimaan, dan stock opname.' : ($bisa_setuju ? 'Persetujuan purchase request dan pemantauan persediaan.' : 'Ajukan permintaan barang ke gudang dan pantau stok.'));
echo kartu($kpi);
$TAB = ['stok' => 'Stok & batch', 'permintaan' => 'Permintaan unit', 'pr' => 'Purchase request', 'penerimaan' => 'Penerimaan barang', 'mutasi' => 'Kartu stok'];
?>
<div class="tabs"><?php foreach ($TAB as $k => $v): ?><a class="tab<?= $tab === $k ? ' active' : '' ?>" href="?tab=<?= $k ?>"><?= $v ?></a><?php endforeach; ?></div>

<?php if ($tab === 'stok'):
    $batch = $pdo->query('SELECT b.*, o.nama_obat, o.satuan, o.kode FROM stok_batch b JOIN obat o ON o.id = b.id_obat WHERE b.qty_sisa > 0 ORDER BY o.nama_obat, b.tgl_kedaluwarsa')->fetchAll();
    $per = [];
    foreach ($batch as $b) $per[$b['id_obat']][] = $b; ?>
  <div class="card"><div class="card-head"><b>Persediaan per barang (batch kedaluwarsa terdekat dipakai lebih dulu)</b><input class="input input-sm" placeholder="Cari barang..." data-filter="tblInv"></div>
  <div class="tbl-wrap"><table class="tbl" id="tblInv"><thead><tr><th>Barang</th><th class="num">Stok</th><th class="num">Min.</th><th>Batch (sisa · ED · lokasi)</th></tr></thead><tbody>
  <?php foreach ($daftar_barang as $o): ?>
    <tr><td><span class="mono text-xs"><?= aman($o['kode']) ?></span> <b><?= aman($o['nama_obat']) ?></b><div class="muted"><?= aman($o['kategori']) ?> · <?= aman($o['satuan']) ?></div></td>
      <td class="num <?= $o['stok'] < $o['stok_minimum'] ? 'text-red-600 font-bold' : '' ?>"><?= (int) $o['stok'] ?><?= $o['stok'] < $o['stok_minimum'] ? '<div>' . badge('Menipis') . '</div>' : '' ?></td><td class="num"><?= (int) $o['stok_minimum'] ?></td>
      <td><?php foreach ($per[$o['id']] ?? [] as $b): $exp = $b['tgl_kedaluwarsa'] < $hari_ini; $dekat = !$exp && $b['tgl_kedaluwarsa'] <= $ed90; ?>
        <div class="flex items-center gap-2 text-xs py-0.5"><span class="mono"><?= aman($b['no_batch']) ?></span> · <b><?= (int) $b['qty_sisa'] ?></b> · <span class="<?= $exp ? 'text-red-600 font-bold' : ($dekat ? 'text-amber-700 font-semibold' : '') ?>">ED <?= tgl($b['tgl_kedaluwarsa']) ?><?= $exp ? ' (KEDALUWARSA)' : ($dekat ? ' (≤90 hari)' : '') ?></span> · <span class="muted"><?= aman($b['lokasi']) ?></span>
          <?php if ($gudang): ?><button class="btn btn-secondary btn-sm ml-auto" data-open="op<?= $b['id'] ?>">Opname</button>
          <dialog class="modal" id="op<?= $b['id'] ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="opname"><input type="hidden" name="id_batch" value="<?= $b['id'] ?>">
            <div class="modal-head"><h3>Stock opname <?= aman($o['nama_obat']) ?> · <?= aman($b['no_batch']) ?></h3></div><div class="modal-body form-grid text-left text-sm">
            <div class="field"><label>Jumlah di sistem</label><input value="<?= (int) $b['qty_sisa'] ?>" readonly></div><div class="field"><label>Jumlah fisik</label><input name="fisik" type="number" min="0" required value="<?= (int) $b['qty_sisa'] ?>"></div>
            <div class="field md:col-span-2"><label>Alasan</label><input name="alasan" required placeholder="contoh: 5 strip rusak / pemusnahan kedaluwarsa"></div></div>
            <div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Batal</button><button class="btn btn-primary">Simpan Penyesuaian</button></div></form></dialog><?php endif; ?></div>
      <?php endforeach; ?><?= empty($per[$o['id']]) ? '<span class="muted">Tidak ada batch bersisa</span>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'permintaan'):
    $rows = $pdo->query("SELECT pu.*, pu.unit_peminta AS unit, pu.alasan AS keterangan, o.nama_obat, o.satuan, o.stok, a.nama_lengkap AS peminta, b.nama_lengkap AS pemroses FROM pengadaan pu JOIN obat o ON o.id = pu.id_obat
        JOIN pegawai a ON a.id = pu.diajukan_oleh LEFT JOIN pegawai b ON b.id = pu.diproses_oleh WHERE pu.jenis = 'Permintaan Unit'" . sql_rs('pu.id_rs') . " ORDER BY pu.status = 'Diajukan' DESC, pu.created_at DESC LIMIT 100")->fetchAll(); ?>
  <div class="grid lg:grid-cols-3 gap-4">
    <?php if ($bisa_minta): ?><div class="card"><div class="card-head"><b>Ajukan permintaan</b></div><form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="aksi" value="minta">
      <div class="field"><label>Unit peminta <span class="req">*</span></label><select name="unit" required><?= opsi(array_combine($UNIT, $UNIT), null, '- pilih unit -') ?></select></div>
      <div class="field"><label>Barang <span class="req">*</span></label><select name="id_obat" required><?= opsi($opsi_barang, null, '- pilih barang -') ?></select></div>
      <div class="field"><label>Jumlah <span class="req">*</span></label><input type="number" name="jumlah" min="1" max="10000" required></div>
      <div class="field"><label>Keterangan</label><input name="keterangan" maxlength="255" placeholder="contoh: stok ruang Melati menipis"></div><button class="btn btn-primary w-full">Kirim ke Gudang</button></form></div><?php endif; ?>
    <div class="card <?= $bisa_minta ? 'lg:col-span-2' : 'lg:col-span-3' ?>"><div class="card-head"><b>Daftar permintaan</b></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Kode</th><th>Unit / Barang</th><th class="num">Jumlah</th><th>Status</th><th class="num">Aksi</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty">Belum ada permintaan.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td class="mono"><?= aman($r['kode']) ?><div class="muted"><?= tgl_jam($r['created_at']) ?></div></td>
      <td><b><?= aman($r['unit']) ?></b> · <?= aman($r['nama_obat']) ?><div class="muted"><?= aman(nama_cabang((int) $r['id_rs'])) ?> · oleh <?= aman($r['peminta']) ?><?= $r['keterangan'] ? ' · ' . aman($r['keterangan']) : '' ?></div></td>
      <td class="num"><?= (int) $r['jumlah'] ?> <?= aman($r['satuan']) ?><div class="muted">stok <?= (int) $r['stok'] ?></div></td><td><?= badge($r['status']) ?><?= $r['catatan'] ? '<div class="muted">' . aman($r['catatan']) . '</div>' : '' ?></td>
      <td class="num"><?php if ($gudang && $r['status'] === 'Diajukan'): ?>
        <form method="post" class="inline" data-confirm="Keluarkan barang? Stok gudang dipotong (FEFO)."><?= csrf_field() ?><input type="hidden" name="aksi" value="keluarkan"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-primary btn-sm" <?= $r['stok'] < $r['jumlah'] ? 'disabled title="Stok tidak cukup"' : '' ?>>Keluarkan</button></form>
        <button class="btn btn-secondary btn-sm" data-open="tm<?= $r['id'] ?>">Tolak</button>
        <dialog class="modal" id="tm<?= $r['id'] ?>"><form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="tolak_minta"><input type="hidden" name="id" value="<?= $r['id'] ?>"><div class="modal-head"><h3>Tolak permintaan <?= aman($r['kode']) ?></h3></div>
          <div class="modal-body"><input name="catatan" class="input" required placeholder="contoh: Stok kosong, PR sedang diproses"></div><div class="modal-foot"><button type="button" class="btn btn-secondary" data-close>Batal</button><button class="btn btn-danger">Tolak</button></div></form></dialog>
      <?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  </div>

<?php elseif ($tab === 'pr'):
    $rows = $pdo->query("SELECT pr.*, o.nama_obat, o.satuan, o.stok, o.stok_minimum, a.nama_lengkap AS pengaju, b.nama_lengkap AS pemutus FROM pengadaan pr JOIN obat o ON o.id = pr.id_obat JOIN pegawai a ON a.id = pr.diajukan_oleh LEFT JOIN pegawai b ON b.id = pr.diproses_oleh WHERE pr.jenis = 'Purchase Request' ORDER BY pr.created_at DESC LIMIT 100")->fetchAll();
    $saran = array_filter($daftar_barang, fn($b) => $b['stok'] < $b['stok_minimum']); ?>
  <div class="grid lg:grid-cols-3 gap-4">
    <?php if ($gudang): ?><div class="card"><div class="card-head"><b>Buat purchase request</b></div><form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="aksi" value="pr">
      <?php if ($saran): ?><div class="notice text-xs mb-3"><b>Di bawah minimum:</b> <?= aman(implode(', ', array_map(fn($b) => "{$b['nama_obat']} ({$b['stok']}/{$b['stok_minimum']})", $saran))) ?></div><?php endif; ?>
      <div class="field"><label>Barang <span class="req">*</span></label><select name="id_obat" required><?= opsi($opsi_barang, null, '- pilih barang -') ?></select></div>
      <div class="field"><label>Jumlah <span class="req">*</span></label><input type="number" name="jumlah" min="1" max="100000" required></div>
      <div class="field"><label>Alasan <span class="req">*</span></label><input name="alasan" required placeholder="contoh: Stok di bawah minimum"></div><button class="btn btn-primary w-full">Ajukan ke Manajemen</button></form></div><?php endif; ?>
    <div class="card <?= $gudang ? 'lg:col-span-2' : 'lg:col-span-3' ?>"><div class="card-head"><h3>Daftar Purchase Request</h3></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Kode</th><th>Barang</th><th class="num">Jumlah</th><th>Alasan</th><th>Status</th><th class="num">Aksi</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada purchase request.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td class="mono"><?= aman($r['kode']) ?><div class="muted"><?= tgl($r['created_at']) ?></div></td><td><b><?= aman($r['nama_obat']) ?></b><div class="muted">stok <?= (int) $r['stok'] ?> / min <?= (int) $r['stok_minimum'] ?></div></td>
      <td class="num"><?= number_format($r['jumlah'], 0, ',', '.') ?> <?= aman($r['satuan']) ?></td><td><?= aman($r['alasan']) ?><div class="muted">oleh <?= aman($r['pengaju']) ?></div></td>
      <td><?= badge($r['status']) ?><?= $r['pemutus'] ? '<div class="muted">' . aman($r['pemutus']) . '</div>' : '' ?></td>
      <td class="num"><?php if ($bisa_setuju && $r['status'] === 'Diajukan'): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button name="aksi" value="setujui" class="btn btn-success btn-sm">Setujui</button> <button name="aksi" value="tolak_pr" class="btn btn-secondary btn-sm">Tolak</button></form>
      <?php elseif ($gudang && $r['status'] === 'Disetujui'): ?><a href="?tab=penerimaan&pr=<?= $r['id'] ?>" class="btn btn-primary btn-sm">Terima Barang</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  </div>

<?php elseif ($tab === 'penerimaan'):
    $prs = $pdo->query("SELECT pr.id, pr.kode, pr.jumlah, o.nama_obat, o.satuan FROM pengadaan pr JOIN obat o ON o.id = pr.id_obat WHERE pr.jenis = 'Purchase Request' AND pr.status = 'Disetujui' ORDER BY pr.created_at")->fetchAll();
    $opsi_pr = [];
    foreach ($prs as $p) $opsi_pr[$p['id']] = "{$p['kode']} · {$p['nama_obat']} · {$p['jumlah']} {$p['satuan']}";
    $rows = $pdo->query("SELECT pb.*, pb.waktu_terima AS created_at, pb.jumlah_diterima AS jumlah, pb.kode AS kode_pr, o.nama_obat, o.satuan, b.no_batch, b.tgl_kedaluwarsa, u.nama_lengkap AS penerima
        FROM pengadaan pb JOIN obat o ON o.id = pb.id_obat JOIN stok_batch b ON b.id = pb.id_batch JOIN pegawai u ON u.id = pb.diterima_oleh WHERE pb.jenis = 'Purchase Request' AND pb.status = 'Diterima' ORDER BY pb.waktu_terima DESC LIMIT 100")->fetchAll(); ?>
  <div class="grid lg:grid-cols-3 gap-4">
    <?php if ($gudang): ?><div class="card"><div class="card-head"><b>Terima barang</b></div><form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="aksi" value="terima">
      <?php if (!$opsi_pr): ?><div class="notice">Belum ada PR yang disetujui Manajemen.</div><?php else: ?>
      <div class="field"><label>Purchase request <span class="req">*</span></label><select name="id_pr" required><?= opsi($opsi_pr, get('pr'), '- pilih PR disetujui -') ?></select></div>
      <div class="form-grid"><div class="field"><label>No. PO <span class="req">*</span></label><input name="no_po" required placeholder="PO-2026-0101"></div><div class="field"><label>Supplier <span class="req">*</span></label><input name="supplier" required placeholder="PT Kimia Farma"></div>
      <div class="field"><label>No. batch <span class="req">*</span></label><input name="no_batch" required pattern="[A-Za-z0-9\-]{3,30}" placeholder="AMX2611A"></div><div class="field"><label>Kedaluwarsa <span class="req">*</span></label><input type="date" name="ed" required min="<?= date('Y-m-d', strtotime('+31 days')) ?>"></div>
      <div class="field"><label>Jumlah diterima <span class="req">*</span></label><input type="number" name="jumlah" min="1" required></div><div class="field"><label>Kondisi</label><select name="kondisi"><option>Baik</option><option>Rusak Sebagian</option></select></div></div>
      <div class="field"><label>Lokasi simpan</label><input name="lokasi" value="Gudang Farmasi"></div><button class="btn btn-primary w-full">Simpan Penerimaan</button><?php endif; ?></form></div><?php endif; ?>
    <div class="card <?= $gudang ? 'lg:col-span-2' : 'lg:col-span-3' ?>"><div class="card-head"><b>Riwayat penerimaan</b></div><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Tanggal</th><th>PO / PR</th><th>Barang</th><th>Batch</th><th class="num">Jumlah</th><th>Kondisi</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada penerimaan.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td><?= tgl_jam($r['created_at']) ?></td><td class="mono"><?= aman($r['no_po']) ?><div class="muted"><?= aman($r['kode_pr']) ?></div></td><td><b><?= aman($r['nama_obat']) ?></b><div class="muted"><?= aman($r['supplier']) ?></div></td>
      <td class="mono"><?= aman($r['no_batch']) ?><div class="muted">ED <?= tgl($r['tgl_kedaluwarsa']) ?></div></td><td class="num"><?= (int) $r['jumlah'] ?> <?= aman($r['satuan']) ?></td><td><?= badge($r['kondisi']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  </div>

<?php else:
    $id_obat = (int) get('obat');
    $sql = 'SELECT m.*, o.nama_obat, o.satuan, b.no_batch, u.nama_lengkap AS oleh_nama FROM mutasi_stok m JOIN obat o ON o.id = m.id_obat LEFT JOIN stok_batch b ON b.id = m.id_batch LEFT JOIN pegawai u ON u.id = m.oleh';
    $s = $pdo->prepare($sql . ($id_obat ? ' WHERE m.id_obat = ?' : '') . ' ORDER BY m.id DESC LIMIT 200');
    $s->execute($id_obat ? [$id_obat] : []);
    $rows = $s->fetchAll(); ?>
  <div class="card"><div class="card-head"><b>Kartu stok (mutasi)</b><form class="flex gap-2"><input type="hidden" name="tab" value="mutasi"><select name="obat" class="input input-sm" onchange="this.form.submit()"><?= opsi($opsi_barang, $id_obat ?: null, 'Semua barang') ?></select></form></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Waktu</th><th>Barang</th><th>Batch</th><th>Jenis</th><th class="num">Jumlah</th><th>Keterangan</th><th>Oleh</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Belum ada mutasi.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr><td><?= tgl_jam($r['created_at']) ?></td><td><?= aman($r['nama_obat']) ?></td><td class="mono"><?= aman($r['no_batch'] ?: '-') ?></td><td><?= badge($r['jenis']) ?></td>
    <td class="num font-bold <?= $r['jumlah'] < 0 ? 'text-red-600' : 'text-green-700' ?>"><?= $r['jumlah'] > 0 ? '+' : '' ?><?= (int) $r['jumlah'] ?></td><td class="text-xs"><?= aman($r['keterangan']) ?></td><td class="text-xs"><?= aman($r['oleh_nama'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif;
tampil_footer();
