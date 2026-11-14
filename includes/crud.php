<?php
/*
 * MESIN CRUD DATA REFERENSI (PPT Bab 3 / P10: Create-Read-Update-Delete)
 * ----------------------------------------------------------------
 * Dipakai halaman master/poliklinik.php, tarif.php, obat.php, dst.
 * Setiap halaman cukup menuliskan daftar kolom + aturan validasinya,
 * lalu memanggil master_crud($konfigurasi).
 *
 * Contoh satu kolom:
 *   'nama' => ['label' => 'Nama Poli', 'wajib' => true, 'max' => 100, 'unik' => true, 'list' => true]
 * Tipe yang tersedia: text, number, select, textarea, time, aktif (checkbox)
 */

function master_crud(array $c): void
{
    wajib_akses('master');
    $pdo = getPDO();
    $tabel = $c['tabel'];
    $pk = $c['pk'] ?? 'id';
    $kolom = $c['kolom'];
    $bisa_ubah = boleh('master', true);
    $errors = [];
    $data = [];

    // opsi select bisa berupa fungsi (diambil dari tabel lain)
    foreach ($kolom as $k => $def) {
        if (isset($def['opsi']) && is_callable($def['opsi'])) $kolom[$k]['opsi'] = ($def['opsi'])();
    }

    if (is_post()) {
        if (!$bisa_ubah) {
            flash('error', 'Role Anda hanya boleh melihat data ini.');
            kembali();
        }
        $aksi = post('aksi');
        $id = post('_id');

        if ($aksi === 'hapus') {
            try {
                $pdo->prepare("DELETE FROM $tabel WHERE $pk = ?")->execute([$id]);
                audit('Master Data', 'Hapus', "$tabel #$id dihapus");
                flash('sukses', $c['judul'] . ' berhasil dihapus.');
            } catch (PDOException $e) {
                flash('error', is_fk($e)
                    ? 'Data tidak bisa dihapus karena sudah dipakai di transaksi/riwayat. Nonaktifkan saja agar tidak dipakai di transaksi baru.'
                    : 'Gagal menghapus: ' . $e->getMessage());
            }
            kembali(['edit' => null, 'form' => null]);
        }

        if ($aksi === 'simpan') {
            foreach ($kolom as $k => $def) {
                $tipe = $def['tipe'] ?? 'text';
                if ($tipe === 'aktif') { $data[$k] = isset($_POST[$k]) ? 1 : 0; continue; }
                if (!empty($def['hanya_baru']) && $id !== '') continue;   // contoh: kode ICD tidak bisa diubah
                $v = post($k);
                if (!empty($def['upper'])) $v = strtoupper($v);
                $data[$k] = $v === '' ? null : $v;
                $label = $def['label'];
                if (!empty($def['wajib']) && $v === '') { $errors[$k] = "$label wajib diisi."; continue; }
                if ($v === '') continue;
                if (isset($def['max']) && mb_strlen($v) > $def['max']) $errors[$k] = "$label maksimal {$def['max']} karakter.";
                if ($tipe === 'number') {
                    if (!is_numeric($v)) $errors[$k] = "$label harus angka.";
                    elseif (isset($def['min']) && $v < $def['min']) $errors[$k] = "$label minimal " . number_format($def['min'], 0, ',', '.') . '.';
                    elseif (isset($def['maks']) && $v > $def['maks']) $errors[$k] = "$label maksimal " . number_format($def['maks'], 0, ',', '.') . '.';
                }
                if ($tipe === 'select' && !array_key_exists($v, $def['opsi'])) $errors[$k] = "Pilihan $label tidak valid.";
                if (isset($def['pola']) && !preg_match($def['pola'], $v)) $errors[$k] = $def['pesan_pola'] ?? "Format $label tidak valid.";
                if (isset($def['validasi']) && ($e = ($def['validasi'])($v, $_POST))) $errors[$k] = $e;
                if (!empty($def['unik']) && !isset($errors[$k])) {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM $tabel WHERE $k = ?" . ($id !== '' ? " AND $pk <> ?" : ''));
                    $s->execute($id !== '' ? [$v, $id] : [$v]);
                    if ($s->fetchColumn() > 0) $errors[$k] = "$label \"$v\" sudah terdaftar. Data tidak boleh kembar.";
                }
            }
            if (isset($c['validasi_form']) && !$errors) $errors = ($c['validasi_form'])($data, $id);

            if (!$errors) {
                try {
                    if ($id === '') {
                        $cols = array_keys($data);
                        $pdo->prepare("INSERT INTO $tabel (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                            ->execute(array_values($data));
                        audit('Master Data', 'Tambah', $c['judul'] . ': ' . ($data[$c['kolom_nama'] ?? 'nama'] ?? ''));
                        flash('sukses', $c['judul'] . ' baru berhasil disimpan ke database.');
                    } else {
                        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
                        $pdo->prepare("UPDATE $tabel SET $set WHERE $pk = ?")->execute([...array_values($data), $id]);
                        audit('Master Data', 'Ubah', $c['judul'] . " #$id diperbarui");
                        flash('sukses', 'Perubahan ' . strtolower($c['judul']) . ' berhasil disimpan.');
                    }
                    kembali(['edit' => null, 'form' => null]);
                } catch (PDOException $e) {
                    $errors['_db'] = is_duplikat($e) ? 'Data kembar: ada nilai unik yang sudah dipakai data lain.' : 'Database menolak data: ' . $e->getMessage();
                }
            }
        }
    }

    // ---------- Data untuk form ----------
    $edit = null;
    $edit_id = get('edit');
    if ($edit_id !== '') {
        $s = $pdo->prepare("SELECT * FROM $tabel WHERE $pk = ?");
        $s->execute([$edit_id]);
        $edit = $s->fetch() ?: null;
    }
    $tampil_form = $bisa_ubah && ($edit || get('form') === 'baru' || $errors);
    $nilai = function (string $k) use ($edit, $kolom) {
        if (isset($_POST[$k]) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($kolom[$k]['tipe'] ?? '') === 'aktif')) {
            return ($kolom[$k]['tipe'] ?? '') === 'aktif' ? (isset($_POST[$k]) ? 1 : 0) : $_POST[$k];
        }
        if ($edit) return $edit[$k] ?? '';
        return $kolom[$k]['default'] ?? (($kolom[$k]['tipe'] ?? '') === 'aktif' ? 1 : '');
    };

    // ---------- Daftar data ----------
    $q = get('q');
    $sql = $c['sql_list'] ?? "SELECT * FROM $tabel t";
    $where = [];
    $par = [];
    if ($q !== '' && !empty($c['cari'])) {
        $where[] = '(' . implode(' OR ', array_map(fn($k) => "$k LIKE ?", $c['cari'])) . ')';
        foreach ($c['cari'] as $_) $par[] = "%$q%";
    }
    foreach (($c['filter'] ?? []) as $fk => $f) {
        if (get($fk) !== '') { $where[] = $f['sql']; $par[] = get($fk); }
    }
    if ($where) $sql .= (stripos($sql, ' where ') !== false ? ' AND ' : ' WHERE ') . implode(' AND ', $where);
    $sql .= ' ORDER BY ' . ($c['urut'] ?? "$pk");
    $s = $pdo->prepare($sql);
    $s->execute($par);
    $rows = $s->fetchAll();

    $aksi_head = $bisa_ubah ? '<a href="?' . http_build_query(array_merge($_GET, ['form' => 'baru', 'edit' => null])) . '" class="btn btn-primary">+ Tambah ' . aman($c['judul']) . '</a>' : '';
    tampil_header($c['judul_halaman'] ?? ('Data ' . $c['judul']), $c['sub'] ?? '', $aksi_head);
    if (!$bisa_ubah) echo '<div class="notice mb-4">Anda hanya dapat melihat data ini. Perubahan data referensi dilakukan oleh Administrator.</div>';
    if (isset($c['info'])) echo '<div class="notice mb-4">' . $c['info'] . '</div>';

    if ($tampil_form): ?>
    <div class="card mb-4" id="form">
      <div class="card-head"><h3><?= $edit ? 'Ubah' : 'Tambah' ?> <?= aman($c['judul']) ?></h3></div>
      <form method="post" class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="simpan">
        <input type="hidden" name="_id" value="<?= aman($edit[$pk] ?? post('_id')) ?>">
        <?php if (isset($errors['_db'])): ?><div class="alert alert-error"><?= aman($errors['_db']) ?></div><?php endif; ?>
        <div class="form-grid">
        <?php foreach ($kolom as $k => $def):
            $tipe = $def['tipe'] ?? 'text';
            $v = $nilai($k);
            $ro = !empty($def['hanya_baru']) && $edit;
            $req = !empty($def['wajib']) ? ' <span class="req">*</span>' : '';
            $cls = err_class($errors, $k);
            $full = !empty($def['lebar']) ? ' style="grid-column:1/-1"' : ''; ?>
          <div class="field"<?= $full ?>>
            <?php if ($tipe === 'aktif'): ?>
              <label class="lbl">&nbsp;</label>
              <label class="inline-flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="<?= $k ?>" value="1" <?= (int) $v ? 'checked' : '' ?>> <?= aman($def['label']) ?></label>
            <?php else: ?>
              <label for="f_<?= $k ?>"><?= aman($def['label']) . $req ?></label>
              <?php if ($tipe === 'select'): ?>
                <select id="f_<?= $k ?>" name="<?= $k ?>" class="<?= $cls ?>"><?= opsi($def['opsi'], $v, '-- Pilih --') ?></select>
              <?php elseif ($tipe === 'textarea'): ?>
                <textarea id="f_<?= $k ?>" name="<?= $k ?>" class="<?= $cls ?>" maxlength="<?= (int) ($def['max'] ?? 1000) ?>" placeholder="<?= aman($def['placeholder'] ?? '') ?>"><?= aman($v) ?></textarea>
              <?php else: ?>
                <input id="f_<?= $k ?>" name="<?= $k ?>" value="<?= aman($tipe === 'time' ? jam($v) : $v) ?>" class="<?= $cls ?>"
                       type="<?= $tipe === 'number' ? 'number' : ($tipe === 'time' ? 'time' : 'text') ?>"
                       <?= isset($def['max']) ? 'maxlength="' . (int) $def['max'] . '"' : '' ?>
                       <?= isset($def['min']) ? 'min="' . $def['min'] . '"' : '' ?> <?= isset($def['maks']) ? 'max="' . $def['maks'] . '"' : '' ?>
                       <?= $tipe === 'number' ? 'step="' . ($def['step'] ?? 'any') . '"' : '' ?>
                       placeholder="<?= aman($def['placeholder'] ?? '') ?>" <?= $ro ? 'readonly' : '' ?> <?= !empty($def['wajib']) && !$ro ? 'required' : '' ?>>
              <?php endif; ?>
              <?php if (isset($def['hint'])): ?><p class="hint"><?= aman($def['hint']) ?></p><?php endif; ?>
              <?= err($errors, $k) ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
        <div class="flex gap-2 mt-2">
          <button class="btn btn-primary">Simpan</button>
          <a href="?<?= http_build_query(array_diff_key($_GET, ['edit' => 1, 'form' => 1])) ?>" class="btn btn-secondary">Batal</a>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head">
        <h3>Daftar <?= aman($c['judul']) ?> <span class="muted">(<?= count($rows) ?> data)</span></h3>
        <form method="get" class="flex gap-2 flex-wrap">
          <?php foreach (($c['filter'] ?? []) as $fk => $f): ?>
            <select name="<?= $fk ?>" class="input" style="width:auto" onchange="this.form.submit()"><?= opsi($f['opsi'], get($fk), $f['label']) ?></select>
          <?php endforeach; ?>
          <?php if (!empty($c['cari'])): ?><input name="q" value="<?= aman($q) ?>" class="input" style="width:220px" placeholder="Cari..."><button class="btn btn-secondary">Cari</button><?php endif; ?>
        </form>
      </div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr>
          <?php foreach ($kolom as $k => $def) if (!empty($def['list'])) echo '<th' . (($def['tipe'] ?? '') === 'number' ? ' class="num"' : '') . '>' . aman($def['label_list'] ?? $def['label']) . '</th>'; ?>
          <?php foreach (($c['kolom_tambahan'] ?? []) as $label => $_) echo '<th>' . aman($label) . '</th>'; ?>
          <?php if ($bisa_ubah): ?><th class="num">Aksi</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="20" class="empty">Belum ada data.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= ($edit && $edit[$pk] == $r[$pk]) ? 'row-hl' : '' ?>">
          <?php foreach ($kolom as $k => $def): if (empty($def['list'])) continue;
              $v = $r[$k] ?? '';
              $fmt = $def['format'] ?? '';
              if (($def['tipe'] ?? '') === 'aktif') $out = (int) $v ? badge('Aktif') : badge('Nonaktif');
              elseif ($fmt === 'rupiah') $out = rupiah($v);
              elseif ($fmt === 'jam') $out = aman(jam($v));
              elseif (($def['tipe'] ?? '') === 'select') $out = aman($def['opsi'][$v] ?? $v);
              else $out = aman($v);
              $cls = (($def['tipe'] ?? '') === 'number') ? ' class="num"' : (!empty($def['tebal']) ? ' class="pname"' : ''); ?>
            <td<?= $cls ?>><?= $out ?></td>
          <?php endforeach; ?>
          <?php foreach (($c['kolom_tambahan'] ?? []) as $fn) echo '<td>' . $fn($r) . '</td>'; ?>
          <?php if ($bisa_ubah): ?>
            <td class="num">
              <a class="btn btn-secondary btn-sm" href="?<?= http_build_query(array_merge($_GET, ['edit' => $r[$pk], 'form' => null])) ?>#form">Ubah</a>
              <?php if (($c['hapus'] ?? true)): ?>
              <form method="post" class="inline" data-confirm="Hapus data ini? Data yang sudah dipakai transaksi tidak bisa dihapus.">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="_id" value="<?= aman($r[$pk]) ?>">
                <button class="btn btn-danger btn-sm">Hapus</button>
              </form>
              <?php endif; ?>
            </td>
          <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php
    tampil_footer();
}
