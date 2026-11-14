<?php
/*
 * FORM ORDER BERSAMA (CPOE -- Modul 4): tindakan, laboratorium, radiologi, e-resep.
 * Dipakai di Rawat Jalan (periksa.php), IGD, Rawat Inap, dan halaman CPOE.
 *   validasi_order($k)  -> [errors, data]   (membaca $_POST)
 *   simpan_order($k, $data)                 (dipanggil di dalam transaksi)
 *   form_order($k, $errors)                 (HTML: 4 tab + ringkasan order)
 *
 * Pre-otorisasi penjamin: pasien ASURANSI SWASTA dengan pemeriksaan >= batas di Konfigurasi RS (per cabang)
 * masuk status jaminan "Menunggu Persetujuan" dan baru dikerjakan Lab/Radiologi
 * setelah disetujui Verifikator. BPJS tidak memerlukan persetujuan per order
 * (jaminan sudah dicek saat SEP diterbitkan di pendaftaran).
 */

/** Batas biaya pemeriksaan yang perlu surat jaminan asuransi: diatur per cabang di Konfigurasi RS (konfigurasi_rs). */
function batas_gl(array $k): float
{
    return (float) konfig('batas_persetujuan_asuransi', (int) ($k['id_rs'] ?? 0) ?: null, '1000000');
}

/** Paket pemeriksaan lab dari tabel layanan (kategori = Paket Lab): [nama paket => [kode, ...]] */
function paket_lab(): array
{
    $h = [];
    foreach (getPDO()->query("SELECT nama, isi_paket FROM layanan WHERE kategori = 'Paket Lab' AND aktif = 1 ORDER BY kode") as $r) {
        $h[$r['nama']] = array_filter(array_map('trim', explode(',', (string) $r['isi_paket'])));
    }
    return $h;
}

function perlu_gl(array $k, float $biaya): bool
{
    return ($k['tipe_penjamin'] ?? '') === 'Asuransi' && $biaya >= batas_gl($k);
}

/** Perempuan usia subur (12-50 th) -> status kehamilan wajib untuk X-Ray / CT (keselamatan radiasi) */
function perlu_status_hamil(array $k): bool
{
    if (($k['jenis_kelamin'] ?? '') !== 'P' || empty($k['tanggal_lahir'])) return false;
    $umur = (int) date_diff(date_create($k['tanggal_lahir']), date_create('today'))->y;
    return $umur >= 12 && $umur <= 50;
}

function alergi_kontras(array $k): bool
{
    return (bool) preg_match('/kontras|iodin|iodium|yodium|gadolinium/i', (string) $k['alergi']);
}

function validasi_order(array $k): array
{
    $pdo = getPDO();
    $e = [];
    $d = ['tindakan' => [], 'lab' => [], 'rad' => [], 'obat' => []];

    // ---- Tindakan medis
    $dipilih = [];
    foreach ((array) ($_POST['tindakan'] ?? []) as $i => $t) {
        if (!is_array($t) || !(int) ($t['id'] ?? 0)) continue;
        $f = "tindakan_$i";
        $s = $pdo->prepare("SELECT * FROM layanan WHERE id = ? AND kategori = 'Tindakan' AND aktif = 1");
        $s->execute([(int) $t['id']]);
        if (!($tr = $s->fetch())) { $e[$f] = 'Tindakan tidak valid.'; continue; }
        if (isset($dipilih[$tr['id']])) { $e[$f] = "{$tr['nama']} ditulis dua kali. Gabungkan jumlahnya."; continue; }
        $dipilih[$tr['id']] = 1;
        $qty = (int) ($t['jumlah'] ?? 0);
        $pel = ($t['pelaksana'] ?? '') === 'Dokter' ? 'Dokter' : 'Perawat';
        $ket = trim((string) ($t['keterangan'] ?? ''));
        $ok = !empty($t['persetujuan']) ? 1 : 0;
        if ($qty < 1 || $qty > 50) { $e[$f] = "Jumlah {$tr['nama']} harus 1-50."; continue; }
        if ($tr['wajib_keterangan'] && mb_strlen($ket) < 5) { $e[$f] = "{$tr['nama']}: isi keterangan / lokasi (min. 5 karakter)."; continue; }
        if (mb_strlen($ket) > 255) { $e[$f] = 'Keterangan maksimal 255 karakter.'; continue; }
        if ($tr['invasif'] && !$ok) { $e[$f] = "{$tr['nama']} adalah tindakan invasif: centang bahwa persetujuan tindakan sudah ditandatangani pasien/keluarga."; continue; }
        $d['tindakan'][] = [$tr['id'], $qty, $tr['harga'], $pel, $ket ?: null, $ok, $tr['nama']];
    }

    $ada_lab = array_filter(array_map('intval', (array) ($_POST['lab'] ?? [])));
    $ada_rad = array_filter((array) ($_POST['rad'] ?? []), fn($r) => is_array($r) && (int) ($r['id'] ?? 0) > 0);
    $ind = post('order_indikasi');
    $urg = post('order_urgensi') === 'CITO' ? 'CITO' : 'Rutin';
    if (($ada_lab || $ada_rad) && mb_strlen($ind) < 5) $e['order_indikasi'] = 'Indikasi / diagnosis klinis untuk pemeriksaan penunjang wajib diisi (min. 5 karakter).';

    // ---- Laboratorium
    if ($ada_lab) {
        $puasa = post('lab_puasa');
        $butuh_puasa = false;
        foreach (array_unique($ada_lab) as $id) {
            $s = $pdo->prepare("SELECT *, harga AS biaya FROM layanan WHERE id = ? AND kategori = 'Laboratorium' AND aktif = 1");
            $s->execute([$id]);
            if (!($j = $s->fetch())) { $e['lab'] = 'Pemeriksaan laboratorium tidak valid.'; continue; }
            $s = $pdo->prepare("SELECT COUNT(*) FROM order_pelayanan WHERE id_kunjungan = ? AND id_layanan = ? AND jenis = 'Laboratorium' AND status <> 'Ditolak'");
            $s->execute([$k['id'], $id]);
            if ($s->fetchColumn()) { $e['lab'] = "{$j['nama']} sudah pernah diorder pada kunjungan ini."; continue; }
            if ($j['perlu_puasa']) $butuh_puasa = true;
            $d['lab'][] = [$j['id'], $urg, $ind, (float) $j['biaya'], $j['perlu_puasa'] ? 'P' : '-', $j['nama']];
        }
        if ($butuh_puasa && !in_array($puasa, ['Puasa', 'Tidak puasa'], true)) $e['lab_puasa'] = 'Ada pemeriksaan yang memerlukan puasa: pilih status puasa pasien.';
        foreach ($d['lab'] as &$x) $x[4] = $x[4] === 'P' ? $puasa : '-';
        unset($x);
    }

    // ---- Radiologi: modalitas, bagian tubuh, kontras, sisi, keterangan
    if ($ada_rad) {
        $alergi = alergi_kontras($k);
        $hamil = '-';
        $radiasi = false;
        $sudah = [];
        foreach ($ada_rad as $i => $r) {
            $f = "rad_$i";
            $s = $pdo->prepare("SELECT *, harga AS biaya, sub_kategori AS modalitas FROM layanan WHERE id = ? AND kategori = 'Radiologi' AND aktif = 1");
            $s->execute([(int) $r['id']]);
            if (!($j = $s->fetch())) { $e[$f] = 'Pemeriksaan radiologi tidak valid.'; continue; }
            if (($r['modalitas'] ?? '') !== '' && $r['modalitas'] !== $j['modalitas']) { $e[$f] = 'Bagian tubuh tidak sesuai dengan jenis alat yang dipilih.'; continue; }
            $kontras = 0;
            if ($j['bisa_kontras']) {
                $kv = (string) ($r['kontras'] ?? '');
                if ($kv !== '0' && $kv !== '1') { $e[$f] = "{$j['nama']}: pilih dengan atau tanpa kontras."; continue; }
                $kontras = (int) $kv;
                if ($kontras && $alergi && mb_strlen(trim((string) ($r['alasan'] ?? ''))) < 10) {
                    $e[$f] = "PERINGATAN ALERGI: pasien tercatat alergi \"{$k['alergi']}\". Kontras hanya boleh diberikan dengan alasan klinis (min. 10 karakter)."; continue;
                }
            }
            $sisi = '-';
            if ($j['pilih_sisi']) {
                $sisi = (string) ($r['sisi'] ?? '');
                if (!in_array($sisi, ['Kanan', 'Kiri', 'Bilateral'], true)) { $e[$f] = "{$j['nama']}: pilih sisi kanan, kiri, atau bilateral."; continue; }
            }
            $ket = trim((string) ($r['keterangan'] ?? ''));
            if ($j['bagian_tubuh'] === 'Ekstremitas' && mb_strlen($ket) < 3) { $e[$f] = 'X-Ray Ekstremitas: tulis bagian yang difoto (contoh: antebrachii 1/3 distal).'; continue; }
            if (mb_strlen($ket) > 150) { $e[$f] = 'Keterangan maksimal 150 karakter.'; continue; }
            if (in_array($j['modalitas'], ['X-Ray', 'CT Scan'], true)) $radiasi = true;
            $label = $j['nama'] . ($ket !== '' ? " ($ket)" : '') . ($j['bisa_kontras'] ? ($kontras ? ' · Dengan kontras' : ' · Tanpa kontras') : '') . ($sisi !== '-' ? " · $sisi" : '');
            $kunci = "{$j['id']}|$kontras|$sisi";
            if (isset($sudah[$kunci])) { $e[$f] = "{$j['nama']} ditulis dua kali."; continue; }
            $sudah[$kunci] = 1;
            $s = $pdo->prepare("SELECT COUNT(*) FROM order_pelayanan WHERE id_kunjungan = ? AND id_layanan = ? AND kontras = ? AND sisi = ? AND jenis = 'Radiologi' AND status <> 'Ditolak'");
            $s->execute([$k['id'], $j['id'], $kontras, $sisi]);
            if ($s->fetchColumn()) { $e[$f] = "$label sudah pernah diorder pada kunjungan ini."; continue; }
            $biaya = (float) $j['biaya'] + ($kontras ? (float) $j['biaya_kontras'] : 0);
            $alasan = $kontras && $alergi ? 'Kontras pada pasien alergi: ' . trim((string) $r['alasan']) : '';
            $d['rad'][] = [$j['id'], mb_substr($label, 0, 160), $kontras, $sisi, $urg, $ind, $biaya, $ket ?: null, $alasan];
        }
        if ($radiasi && perlu_status_hamil($k)) {
            $hamil = post('rad_hamil');
            if (!in_array($hamil, ['Tidak hamil', 'Hamil', 'Tidak tahu'], true)) $e['rad_hamil'] = 'Pasien perempuan usia subur: status kehamilan wajib diisi untuk X-Ray / CT Scan.';
            elseif ($hamil !== 'Tidak hamil' && mb_strlen(post('rad_alasan_hamil')) < 10) $e['rad_hamil'] = 'Pasien hamil / belum pasti: tulis alasan klinis pemeriksaan dengan radiasi (min. 10 karakter), atau pertimbangkan USG / MRI.';
        }
        $alasan_hamil = $hamil !== '-' && $hamil !== 'Tidak hamil' ? 'Radiasi pada kehamilan (' . $hamil . '): ' . post('rad_alasan_hamil') : '';
        foreach ($d['rad'] as &$x) {
            $x[] = $hamil;
            $radiasi_x = in_array($pdo->query('SELECT sub_kategori FROM layanan WHERE id = ' . (int) $x[0])->fetchColumn(), ['X-Ray', 'CT Scan'], true);
            $x[8] = mb_substr(implode(' · ', array_filter([$x[8], $radiasi_x ? $alasan_hamil : ''])), 0, 255) ?: null;
        }
        unset($x);
    }

    // ---- E-resep
    $dipakai = [];
    foreach ((array) ($_POST['obat'] ?? []) as $i => $o) {
        $id = (int) ($o['id'] ?? 0);
        if (!$id) continue;
        $s = $pdo->prepare("SELECT * FROM obat WHERE id = ? AND aktif = 1");
        $s->execute([$id]);
        $ob = $s->fetch();
        $f = "obat_$i";
        if (!$ob) { $e[$f] = 'Obat tidak valid.'; continue; }
        if (isset($dipakai[$id])) { $e[$f] = "{$ob['nama_obat']} ditulis dua kali. Gabungkan jumlahnya."; continue; }
        $dipakai[$id] = 1;
        $jml = (int) ($o['jumlah'] ?? 0);
        $dosis = trim((string) ($o['dosis'] ?? ''));
        $frek = trim((string) ($o['frekuensi'] ?? ''));
        $atur = trim((string) ($o['aturan'] ?? ''));
        if ($jml < 1 || $jml > 500) $e[$f] = "Jumlah {$ob['nama_obat']} harus 1-500.";
        elseif ($jml > (int) $ob['stok']) $e[$f] = "Stok {$ob['nama_obat']} tidak mencukupi (tersedia {$ob['stok']} {$ob['satuan']}).";
        elseif ($dosis === '' || $frek === '' || $atur === '') $e[$f] = "Dosis, frekuensi, dan aturan pakai {$ob['nama_obat']} wajib diisi.";
        $alasan = trim((string) ($o['alasan'] ?? ''));
        if ($kata = cek_alergi($k['alergi'], $ob['nama_obat'])) {
            if (mb_strlen($alasan) < 10) $e[$f] = "PERINGATAN ALERGI: pasien alergi \"$kata\". {$ob['nama_obat']} hanya boleh diresepkan dengan alasan klinis (min. 10 karakter).";
        } else $alasan = '';
        $d['obat'][] = [$id, $jml, $dosis, $frek, $atur, $alasan ?: null];
    }
    $d['catatan_resep'] = post('resep_catatan');
    return [$e, $d];
}

function simpan_order(array $k, array $d): array
{
    $pdo = getPDO();
    $ringkas = [];
    $gl = 0;
    foreach ($d['tindakan'] as [$id, $qty, $harga, $pel, $ket, $ok]) {
        $dokter = $pel === 'Dokter';
        $nama = $pdo->query('SELECT nama FROM layanan WHERE id = ' . (int) $id)->fetchColumn();
        $pdo->prepare("INSERT INTO order_pelayanan (id_kunjungan, jenis, id_layanan, uraian, jumlah, biaya, id_dokter, pelaksana, keterangan, persetujuan, status, id_petugas, waktu_dikerjakan, catatan, urgensi) VALUES (?,'Tindakan',?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$k['id'], $id, $nama, $qty, $harga, uid(), $pel, $ket, $ok, $dokter ? 'Dikerjakan' : 'Order Baru', $dokter ? uid() : null, $dokter ? date('Y-m-d H:i:s') : null, $dokter ? 'Dikerjakan langsung oleh dokter' : null, $k['jenis'] === 'igd' ? 'CITO' : 'Rutin']);
    }
    if ($d['tindakan']) $ringkas[] = count($d['tindakan']) . ' tindakan';
    foreach ($d['lab'] as [$id, $urg, $ind, $biaya, $puasa]) {
        $jam = perlu_gl($k, $biaya) ? 'Menunggu Persetujuan' : '-';
        if ($jam !== '-') $gl++;
        $nama = $pdo->query('SELECT nama FROM layanan WHERE id = ' . (int) $id)->fetchColumn();
        $pdo->prepare("INSERT INTO order_pelayanan (id_kunjungan, jenis, id_layanan, uraian, id_dokter, urgensi, status_puasa, indikasi, biaya, jaminan, kunci_unik) VALUES (?,'Laboratorium',?,?,?,?,?,?,?,?,?)")
            ->execute([$k['id'], $id, $nama, uid(), $urg, $puasa, $ind, $biaya, $jam, "LAB-{$k['id']}-$id"]);
    }
    if ($d['lab']) $ringkas[] = count($d['lab']) . ' order lab';
    foreach ($d['rad'] as [$id, $label, $kontras, $sisi, $urg, $ind, $biaya, $ket, $alasan, $hamil]) {
        $jam = perlu_gl($k, $biaya) ? 'Menunggu Persetujuan' : '-';
        if ($jam !== '-') $gl++;
        $pdo->prepare("INSERT INTO order_pelayanan (id_kunjungan, jenis, id_layanan, id_dokter, uraian, kontras, sisi, keterangan, status_hamil, alasan_klinis, urgensi, indikasi, biaya, jaminan, kunci_unik) VALUES (?,'Radiologi',?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$k['id'], $id, uid(), $label, $kontras, $sisi, $ket, $hamil, $alasan, $urg, $ind, $biaya, $jam, "RAD-{$k['id']}-$id-$kontras-$sisi"]);
    }
    if ($d['rad']) $ringkas[] = count($d['rad']) . ' order radiologi';
    if ($d['obat']) {
        $pdo->prepare('INSERT INTO resep (id_kunjungan, id_dokter, catatan_dokter) VALUES (?,?,?)')->execute([$k['id'], uid(), $d['catatan_resep'] ?: null]);
        $id_resep = (int) $pdo->lastInsertId();
        foreach ($d['obat'] as $o) {
            $pdo->prepare('INSERT INTO resep_item (id_resep, id_obat, jumlah, dosis, frekuensi, aturan_pakai, alasan_alergi) VALUES (?,?,?,?,?,?,?)')->execute([$id_resep, ...$o]);
        }
        $ringkas[] = 'e-resep ' . count($d['obat']) . ' obat';
    }
    if ($gl) $ringkas[] = "$gl pemeriksaan menunggu persetujuan {$k['penjamin']}";
    if ($ringkas) audit('CPOE', 'Order', "Kunjungan #{$k['id']} {$k['nomor_rm']}: " . implode(', ', $ringkas));
    return $ringkas;
}

function form_order(array $k, array $errors, bool $tampil_tindakan = true): void
{
    $pdo = getPDO();
    $tindakan = $pdo->query("SELECT id, kode, nama, harga, wajib_keterangan, invasif, petunjuk FROM layanan WHERE kategori = 'Tindakan' AND aktif = 1 ORDER BY nama")->fetchAll();
    $lab = $pdo->query("SELECT id, kode, nama, sub_kategori AS kategori, harga AS biaya, spesimen, perlu_puasa, persiapan FROM layanan WHERE kategori = 'Laboratorium' AND aktif = 1 ORDER BY sub_kategori, nama")->fetchAll();
    $urut_kat = ['Hematologi' => 1, 'Kimia Klinik' => 2, 'Urinalisis' => 3, 'Imunologi' => 4, 'Koagulasi' => 5, 'Mikrobiologi' => 6];
    usort($lab, fn($a, $b) => [$urut_kat[$a['kategori']] ?? 9, $a['nama']] <=> [$urut_kat[$b['kategori']] ?? 9, $b['nama']]);
    $rad = $pdo->query("SELECT id, nama, sub_kategori AS modalitas, bagian_tubuh, bisa_kontras, pilih_sisi, harga AS biaya, biaya_kontras, persiapan FROM layanan WHERE kategori = 'Radiologi' AND aktif = 1 ORDER BY sub_kategori, bagian_tubuh")->fetchAll();
    $alergi_kontras = alergi_kontras($k);
    $cek_hamil = perlu_status_hamil($k);
    $asuransi = ($k['tipe_penjamin'] ?? '') === 'Asuransi';
    $obat = daftar_obat();
    $obat_map = array_column($obat, null, 'id');
    $kode_id = array_column($lab, 'id', 'kode');
    $pl = array_map('strval', (array) ($_POST['lab'] ?? []));
    $pt = array_values(array_filter((array) ($_POST['tindakan'] ?? []), 'is_array'));
    if (!$pt) $pt[] = [];
    $pr = array_values(array_filter((array) ($_POST['rad'] ?? []), 'is_array'));
    if (!$pr) $pr[] = [];

    // tab yang dibuka pertama: tab yang ada error-nya
    $tab = $tampil_tindakan ? 'tindakan' : 'lab';
    foreach (array_keys($errors) as $ek) {
        if (str_starts_with($ek, 'obat')) { $tab = 'resep'; break; }
        if (str_starts_with($ek, 'rad')) { $tab = 'rad'; break; }
        if (str_starts_with($ek, 'lab')) { $tab = 'lab'; break; }
        if (str_starts_with($ek, 'tindakan')) { $tab = 'tindakan'; break; }
    }
    $tabs = ['tindakan' => 'Tindakan Medis', 'lab' => 'Laboratorium', 'rad' => 'Radiologi', 'resep' => 'E-Resep'];
    if (!$tampil_tindakan) unset($tabs['tindakan']);
    $opsi_tindakan = function (string $sel) use ($tindakan) {
        $h = '<option value="">— Pilih tindakan —</option>';
        foreach ($tindakan as $t) $h .= '<option value="' . $t['id'] . '" data-harga="' . (float) $t['harga'] . '" data-ket="' . (int) $t['wajib_keterangan'] . '" data-invasif="' . (int) $t['invasif'] . '" data-petunjuk="' . aman($t['petunjuk'] ?? '') . '"' . ($sel === (string) $t['id'] ? ' selected' : '') . '>' . aman($t['nama']) . ' — ' . rupiah($t['harga']) . '</option>';
        return $h;
    };
    ?>
    <div class="card mb-3" id="orderBox">
      <div class="card-head"><h3>Order Pelayanan &amp; Penunjang</h3><span class="muted">dikirim bersama saat disimpan</span></div>
      <div class="card-body">
        <div class="form-grid mb-3">
          <div class="field" style="grid-column:span 2"><label for="orderIndikasi">Indikasi / diagnosis klinis untuk lab &amp; radiologi</label>
            <input id="orderIndikasi" name="order_indikasi" maxlength="255" value="<?= old('order_indikasi') ?>" class="<?= err_class($errors, 'order_indikasi') ?>" placeholder="contoh: Demam hari ke-3, suspek DBD">
            <?= err($errors, 'order_indikasi') ?><span class="hint" id="indHint" hidden>Terisi otomatis dari Assessment bila masih kosong.</span></div>
          <div class="field"><label>Prioritas</label><select name="order_urgensi"><?= opsi(['Rutin' => 'Rutin', 'CITO' => 'CITO (segera)'], $_POST['order_urgensi'] ?? 'Rutin') ?></select></div>
        </div>
        <?php if ($asuransi): ?><div class="alert alert-info">Penjamin pasien: <b><?= aman($k['penjamin']) ?></b>. Pemeriksaan lab/radiologi dengan biaya ≥ <?= rupiah(batas_gl($k)) ?> menunggu persetujuan asuransi (diproses Verifikator) sebelum dikerjakan.</div><?php endif; ?>

        <div class="tabs" role="tablist">
          <?php foreach ($tabs as $tk => $tl): ?><button type="button" class="tab <?= $tab === $tk ? 'active' : '' ?>" data-otab="<?= $tk ?>" role="tab"><?= $tl ?> <span class="otab-n" data-n="<?= $tk ?>"></span></button><?php endforeach; ?>
        </div>

        <?php if ($tampil_tindakan): ?>
        <div class="opanel" data-panel="tindakan" <?= $tab !== 'tindakan' ? 'hidden' : '' ?>>
          <p class="text-sm muted mb-3">Pilih tindakan dan siapa yang mengerjakan. Tindakan oleh perawat masuk ke daftar kerja perawat; tindakan yang Anda kerjakan sendiri langsung tercatat selesai.</p>
          <div id="tndRows" class="space-y-3 mb-3">
          <?php foreach ($pt as $i => $t): ?>
            <div class="o-row tnd-row border border-slate-200 rounded-lg p-3">
              <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="field lg:col-span-2"><label>Tindakan</label><select name="tindakan[<?= $i ?>][id]" class="tnd-sel"><?= $opsi_tindakan((string) ($t['id'] ?? '')) ?></select></div>
                <div class="field"><label>Jumlah</label><input type="number" name="tindakan[<?= $i ?>][jumlah]" min="1" max="50" value="<?= aman($t['jumlah'] ?? '1') ?>" class="tnd-qty"></div>
                <div class="field"><label>Dikerjakan oleh</label><select name="tindakan[<?= $i ?>][pelaksana]"><?= opsi(['Perawat' => 'Perawat (dikirim ke perawat)', 'Dokter' => 'Dokter (saya kerjakan sendiri)'], $t['pelaksana'] ?? 'Perawat') ?></select></div>
              </div>
              <div class="field mt-2"><label>Keterangan / lokasi <span class="tnd-wajib">*</span></label><input name="tindakan[<?= $i ?>][keterangan]" maxlength="255" value="<?= aman($t['keterangan'] ?? '') ?>" class="tnd-ket"></div>
              <label class="flex items-start gap-2 text-sm mt-2 tnd-ic" hidden><input type="checkbox" name="tindakan[<?= $i ?>][persetujuan]" value="1" <?= !empty($t['persetujuan']) ? 'checked' : '' ?> class="mt-1"> <span><b>Persetujuan tindakan (informed consent)</b> sudah dijelaskan dan ditandatangani pasien / keluarga.</span></label>
              <div class="flex flex-wrap items-center justify-between gap-2 mt-2 text-sm"><span class="o-info muted"></span><button type="button" class="btn btn-outline-danger btn-sm o-hapus">Hapus baris</button></div>
              <?= err($errors, "tindakan_$i") ?>
            </div>
          <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-soft btn-sm o-tambah" data-target="tndRows">+ Tambah tindakan</button>
        </div>
        <?php endif; ?>

        <div class="opanel" data-panel="lab" <?= $tab !== 'lab' ? 'hidden' : '' ?>>
          <?= err($errors, 'lab') ?>
          <div class="flex flex-wrap items-center gap-2 mb-3">
            <input type="search" id="labCari" class="input" style="max-width:260px" placeholder="Cari pemeriksaan, contoh: gula">
            <span class="text-sm muted ml-1">Paket cepat:</span>
            <?php foreach (paket_lab() as $pn => $kodes): $ids = array_values(array_filter(array_map(fn($c) => $kode_id[$c] ?? null, $kodes))); ?>
              <button type="button" class="btn btn-soft btn-sm lab-paket" data-ids="<?= implode(',', $ids) ?>"><?= aman($pn) ?></button>
            <?php endforeach; ?>
          </div>
          <?php $kat = null; foreach ($lab as $l): if ($l['kategori'] !== $kat): if ($kat !== null) echo '</div></div>'; $kat = $l['kategori']; ?>
            <div class="lab-grup mb-3"><h4 class="lab-kat"><?= aman($kat) ?></h4><div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
          <?php endif; ?>
            <label class="lab-item flex items-start gap-2 border border-slate-200 rounded-lg px-3 py-2 text-sm" data-cari="<?= aman(strtolower($l['nama'] . ' ' . $l['kode'] . ' ' . $l['kategori'])) ?>">
              <input type="checkbox" name="lab[]" value="<?= $l['id'] ?>" data-harga="<?= (float) $l['biaya'] ?>" data-puasa="<?= (int) $l['perlu_puasa'] ?>" data-nama="<?= aman($l['nama']) ?>" class="mt-1" <?= in_array((string) $l['id'], $pl, true) ? 'checked' : '' ?>>
              <span><?= aman($l['nama']) ?>
                <span class="block muted"><?= aman($l['spesimen']) ?> · <?= rupiah($l['biaya']) ?></span>
                <?php if ($l['perlu_puasa']): ?><span class="tag-puasa"><?= aman($l['persiapan'] ?: 'Perlu puasa') ?></span><?php elseif ($l['persiapan']): ?><span class="block text-xs muted"><?= aman($l['persiapan']) ?></span><?php endif; ?>
              </span></label>
          <?php endforeach; if ($kat !== null) echo '</div></div>'; ?>
          <p id="labKosong" class="muted text-sm" hidden>Pemeriksaan tidak ditemukan.</p>
          <div class="field mt-2" id="labPuasaBox" hidden style="max-width:420px"><label>Status puasa pasien *</label>
            <select name="lab_puasa" class="<?= err_class($errors, 'lab_puasa') ?>"><?= opsi(['' => '— Pilih —', 'Puasa' => 'Sudah puasa sesuai persiapan', 'Tidak puasa' => 'Tidak / belum puasa'], $_POST['lab_puasa'] ?? '') ?></select>
            <span class="hint" id="labPuasaInfo"></span></div>
          <?= err($errors, 'lab_puasa') ?>
        </div>

        <div class="opanel" data-panel="rad" <?= $tab !== 'rad' ? 'hidden' : '' ?>>
          <p class="text-sm muted mb-3">Pilih jenis alat, bagian tubuh, lalu tentukan pemakaian kontras dan sisi bila diminta. Biaya terhitung otomatis.</p>
          <?php if ($alergi_kontras): ?><div class="alert alert-warning">Pasien tercatat alergi <b><?= aman($k['alergi']) ?></b>. Hindari kontras atau isi alasan klinis bila tetap diperlukan.</div><?php endif; ?>
          <div id="radRows" class="space-y-3 mb-3">
          <?php foreach ($pr as $i => $r): $sel = (string) ($r['id'] ?? ''); $mod = (string) ($r['modalitas'] ?? ''); ?>
            <div class="o-row rad-row border border-slate-200 rounded-lg p-3">
              <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="field"><label>Jenis alat (modalitas)</label>
                  <select name="rad[<?= $i ?>][modalitas]" class="rad-mod"><option value="">— Pilih —</option><?php foreach (['X-Ray' => 'X-Ray (Rontgen)', 'CT Scan' => 'CT Scan', 'MRI' => 'MRI', 'USG' => 'USG'] as $mv => $ml): ?><option value="<?= $mv ?>" <?= $mod === $mv ? 'selected' : '' ?>><?= $ml ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Bagian tubuh</label>
                  <select name="rad[<?= $i ?>][id]" class="rad-bagian"><option value="">— Pilih bagian —</option>
                  <?php foreach ($rad as $x): ?><option value="<?= $x['id'] ?>" data-mod="<?= aman($x['modalitas']) ?>" data-nama="<?= aman($x['nama']) ?>" data-bagian="<?= aman($x['bagian_tubuh']) ?>" data-kontras="<?= (int) $x['bisa_kontras'] ?>" data-sisi="<?= (int) $x['pilih_sisi'] ?>" data-biaya="<?= (float) $x['biaya'] ?>" data-biaya-kontras="<?= (float) $x['biaya_kontras'] ?>" data-persiapan="<?= aman($x['persiapan'] ?? '') ?>" <?= $sel === (string) $x['id'] ? 'selected' : '' ?>><?= aman($x['bagian_tubuh']) ?> (<?= aman($x['modalitas']) ?>)</option><?php endforeach; ?></select></div>
                <div class="field"><label>Kontras</label>
                  <select name="rad[<?= $i ?>][kontras]" class="rad-kontras"><option value="">— Pilih —</option><option value="0" <?= ($r['kontras'] ?? '') === '0' ? 'selected' : '' ?>>Tanpa kontras</option><option value="1" <?= ($r['kontras'] ?? '') === '1' ? 'selected' : '' ?>>Dengan kontras</option></select>
                  <span class="hint rad-kontras-na">Tidak memakai kontras</span></div>
                <div class="field"><label>Sisi</label>
                  <select name="rad[<?= $i ?>][sisi]" class="rad-sisi"><option value="">— Pilih —</option><?php foreach (['Kanan', 'Kiri', 'Bilateral'] as $sv): ?><option value="<?= $sv ?>" <?= ($r['sisi'] ?? '') === $sv ? 'selected' : '' ?>><?= $sv === 'Bilateral' ? 'Bilateral (kanan & kiri)' : $sv ?></option><?php endforeach; ?></select>
                  <span class="hint rad-sisi-na">Tidak perlu sisi</span></div>
              </div>
              <div class="field mt-2"><label>Keterangan bagian <span class="rad-ket-wajib">*</span><span class="rad-ket-ops muted">(opsional)</span></label><input name="rad[<?= $i ?>][keterangan]" maxlength="150" value="<?= aman($r['keterangan'] ?? '') ?>" class="rad-ket" placeholder="contoh: antebrachii 1/3 distal, curiga fraktur"></div>
              <div class="field mt-2 rad-alasan-box" hidden><label>Alasan klinis pemakaian kontras (pasien alergi) *</label><input name="rad[<?= $i ?>][alasan]" maxlength="255" value="<?= aman($r['alasan'] ?? '') ?>"></div>
              <div class="flex flex-wrap items-center justify-between gap-2 mt-2 text-sm"><span class="o-info muted"></span><button type="button" class="btn btn-outline-danger btn-sm o-hapus">Hapus baris</button></div>
              <?= err($errors, "rad_$i") ?>
            </div>
          <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-soft btn-sm o-tambah mb-3" data-target="radRows">+ Tambah pemeriksaan radiologi</button>
          <?php if ($cek_hamil): ?>
          <div class="form-grid" id="radHamilBox" hidden>
            <div class="field"><label>Status kehamilan *</label><select name="rad_hamil" class="<?= err_class($errors, 'rad_hamil') ?>"><?= opsi(['' => '— Pilih —', 'Tidak hamil' => 'Tidak hamil', 'Hamil' => 'Hamil', 'Tidak tahu' => 'Belum pasti / tidak tahu'], $_POST['rad_hamil'] ?? '') ?></select><span class="hint">Wajib untuk X-Ray / CT Scan pada perempuan usia 12–50 tahun (keselamatan radiasi).</span></div>
            <div class="field" style="grid-column:span 2" id="radHamilAlasan" hidden><label>Alasan klinis pemeriksaan dengan radiasi *</label><input name="rad_alasan_hamil" maxlength="255" value="<?= old('rad_alasan_hamil') ?>" placeholder="contoh: Trauma kepala berat, manfaat lebih besar; pakai apron pelindung"></div>
          </div>
          <?php endif; ?>
          <?= err($errors, 'rad_hamil') ?>
        </div>

        <div class="opanel" data-panel="resep" <?= $tab !== 'resep' ? 'hidden' : '' ?>>
          <p class="text-sm muted mb-3">Cari nama obat untuk melihat stok, lalu pilih dan isi dosis sesuai kondisi pasien. Obat yang stoknya habis tidak bisa dipilih.</p>
          <?php if ($k['alergi']): ?><div class="alert alert-error">Pasien memiliki riwayat alergi: <b><?= aman($k['alergi']) ?></b>. Obat yang cocok dengan alergi ditandai dan hanya bisa diresepkan dengan alasan klinis.</div><?php endif; ?>
          <div class="obat-cari-box mb-3">
            <input type="search" id="obatCari" class="input" placeholder="Ketik nama obat, contoh: amlo, para, omep" autocomplete="off" style="max-width:420px">
            <div id="obatHasil" class="obat-hasil mt-2"></div>
          </div>
          <div id="obatPilih">
            <div class="tbl-wrap"><table class="tbl" id="obatTbl"><thead><tr><th style="min-width:200px">Obat</th><th>Jumlah</th><th>Dosis</th><th>Frekuensi</th><th>Aturan pakai</th><th></th></tr></thead><tbody>
            <?php foreach ((array) ($_POST['obat'] ?? []) as $i => $po): if (!is_array($po) || !($oid = (int) ($po['id'] ?? 0)) || !isset($obat_map[$oid])) continue; $o = $obat_map[$oid]; $al = cek_alergi($k['alergi'], $o['nama_obat']); ?>
              <tr class="obat-row" data-id="<?= $oid ?>"><td><input type="hidden" name="obat[<?= aman((string) $i) ?>][id]" value="<?= $oid ?>" class="obat-id" data-harga="<?= (float) $o['harga_satuan'] ?>" data-nama="<?= aman($o['nama_obat']) ?>">
                  <b><?= aman($o['nama_obat']) ?></b><div class="muted text-xs">Stok <?= (int) $o['stok'] ?> <?= aman($o['satuan']) ?></div>
                  <?php if ($al): ?><input name="obat[<?= aman((string) $i) ?>][alasan]" value="<?= aman($po['alasan'] ?? '') ?>" class="input mt-1" placeholder="Alasan klinis (pasien alergi <?= aman($al) ?>)" style="border-color:#b91c2c"><?php endif; ?>
                  <?= err($errors, "obat_$i") ?></td>
                <td><input type="number" name="obat[<?= aman((string) $i) ?>][jumlah]" min="1" max="<?= max(1, (int) $o['stok']) ?>" value="<?= aman($po['jumlah'] ?? '') ?>" class="input obat-jml" style="width:80px"></td>
                <td><input name="obat[<?= aman((string) $i) ?>][dosis]" maxlength="50" value="<?= aman($po['dosis'] ?? '') ?>" class="input" placeholder="contoh: 500 mg"></td>
                <td><input name="obat[<?= aman((string) $i) ?>][frekuensi]" maxlength="50" value="<?= aman($po['frekuensi'] ?? '') ?>" class="input" placeholder="contoh: 3x sehari"></td>
                <td><input name="obat[<?= aman((string) $i) ?>][aturan]" maxlength="100" value="<?= aman($po['aturan'] ?? '') ?>" class="input" placeholder="contoh: Sesudah makan"></td>
                <td><button type="button" class="btn btn-outline-danger btn-sm obat-hapus">Hapus</button></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <p class="muted text-sm mt-2" id="obatKosong">Belum ada obat dipilih.</p>
          </div>
          <div class="field mt-3"><label>Catatan untuk apoteker</label><input name="resep_catatan" maxlength="255" value="<?= old('resep_catatan') ?>" placeholder="contoh: Racik puyer bila pasien sulit menelan"></div>
          <script>window.__OBAT = <?= json_encode(array_map(fn($o) => ['id' => (int) $o['id'], 'nama' => $o['nama_obat'], 'satuan' => $o['satuan'], 'stok' => (int) $o['stok'], 'harga' => (float) $o['harga_satuan'], 'ha' => (int) $o['high_alert'], 'lasa' => (int) $o['lasa'], 'alergi' => cek_alergi($k['alergi'], $o['nama_obat']) ?? ''], array_values($obat)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
        </div>

        <div class="o-ringkas mt-4" id="orderRingkas">
          <h4>Ringkasan order</h4>
          <p class="muted text-sm" id="ringkasKosong">Belum ada order yang dipilih.</p>
          <div class="tbl-wrap" id="ringkasTbl" hidden><table class="tbl"><thead><tr><th>Jenis</th><th>Order</th><th class="num">Perkiraan biaya</th></tr></thead><tbody></tbody>
            <tfoot><tr><td colspan="2" class="num"><b>Total perkiraan</b></td><td class="num"><b id="ringkasTotal">Rp 0</b></td></tr></tfoot></table></div>
          <p class="hint">Perkiraan biaya. Tagihan akhir dihitung Kasir dari pelayanan yang benar-benar dikerjakan.</p>
        </div>
      </div>
    </div>
    <script>
    (function () {
      var box = document.getElementById('orderBox'); if (!box) return;
      var alergiKontras = <?= $alergi_kontras ? 'true' : 'false' ?>, cekHamil = <?= $cek_hamil ? 'true' : 'false' ?>, asuransi = <?= $asuransi ? 'true' : 'false' ?>, BATAS = <?= batas_gl($k) ?>;
      var rp = function (n) { return 'Rp ' + Math.round(+n).toLocaleString('id-ID'); };
      var esc = function (t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; };

      box.querySelectorAll('[data-otab]').forEach(function (b) { b.addEventListener('click', function () {
        box.querySelectorAll('[data-otab]').forEach(function (x) { x.classList.toggle('active', x === b); });
        box.querySelectorAll('.opanel').forEach(function (p) { p.hidden = p.dataset.panel !== b.dataset.otab; });
      }); });

      var ind = document.getElementById('orderIndikasi'), asm = document.querySelector('[name=assessment]'), icd = document.querySelector('[name=kode_icd10]');
      if (asm) document.getElementById('indHint').hidden = false;
      function isiInd() { if (ind && asm && !ind.value.trim() && asm.value.trim()) ind.value = asm.value.trim() + (icd && icd.value.trim() ? ' (' + icd.value.trim() + ')' : ''); }

      function aturTnd(row) {
        var s = row.querySelector('.tnd-sel'), o = s.options[s.selectedIndex], ada = o && o.value;
        var wajib = ada && o.dataset.ket === '1';
        row.querySelector('.tnd-wajib').hidden = !wajib;
        row.querySelector('.tnd-ket').placeholder = ada && o.dataset.petunjuk ? o.dataset.petunjuk : 'Opsional';
        row.querySelector('.tnd-ic').hidden = !(ada && o.dataset.invasif === '1');
        var q = parseInt(row.querySelector('.tnd-qty').value || '1', 10);
        row.querySelector('.o-info').innerHTML = ada ? 'Biaya: <b>' + rp(o.dataset.harga * q) + '</b>' + (o.dataset.invasif === '1' ? ' · <span style="color:#92400e">tindakan invasif, perlu persetujuan tindakan</span>' : '') : '';
      }
      function aturRad(row) {
        var mod = row.querySelector('.rad-mod'), bag = row.querySelector('.rad-bagian'), kon = row.querySelector('.rad-kontras'), sisi = row.querySelector('.rad-sisi');
        Array.prototype.forEach.call(bag.options, function (o) { if (o.value) { o.hidden = !!mod.value && o.dataset.mod !== mod.value; o.disabled = o.hidden; } });
        var o = bag.options[bag.selectedIndex];
        if (o && o.value && mod.value && o.dataset.mod !== mod.value) { bag.value = ''; o = null; }
        if (o && o.value && !mod.value) mod.value = o.dataset.mod;
        var ada = o && o.value, adaK = ada && o.dataset.kontras === '1', adaS = ada && o.dataset.sisi === '1', eks = ada && o.dataset.bagian === 'Ekstremitas';
        kon.hidden = !adaK; kon.disabled = !adaK; row.querySelector('.rad-kontras-na').hidden = adaK;
        sisi.hidden = !adaS; sisi.disabled = !adaS; row.querySelector('.rad-sisi-na').hidden = adaS;
        row.querySelector('.rad-ket-wajib').hidden = !eks; row.querySelector('.rad-ket-ops').hidden = eks;
        row.querySelector('.rad-alasan-box').hidden = !(adaK && kon.value === '1' && alergiKontras);
        var info = row.querySelector('.o-info');
        if (ada) {
          var b = +o.dataset.biaya + (adaK && kon.value === '1' ? +o.dataset.biayaKontras : 0);
          info.innerHTML = 'Biaya: <b>' + rp(b) + '</b>' + (adaK && kon.value !== '1' ? ' <span class="muted">(+' + rp(o.dataset.biayaKontras) + ' bila dengan kontras)</span>' : '') + (asuransi && b >= BATAS ? ' · <span style="color:#92400e">perlu persetujuan asuransi</span>' : '') + (o.dataset.persiapan ? '<br>Persiapan: ' + esc(o.dataset.persiapan) : '');
        } else info.textContent = 'Pilih jenis alat lalu bagian tubuh.';
      }
      function atur(row) { if (row.classList.contains('tnd-row')) aturTnd(row); else aturRad(row); ringkas(); }
      function kosongkan(x) { if (x.type === 'checkbox') x.checked = false; else if (x.type === 'number') x.value = 1; else if (x.tagName === 'SELECT' && x.name.indexOf('[pelaksana]') > 0) x.value = 'Perawat'; else x.value = ''; }
      function pasang(row) {
        row.querySelectorAll('select,input').forEach(function (s) { s.addEventListener('change', function () { atur(row); }); if (s.type === 'number') s.addEventListener('input', function () { atur(row); }); });
        row.querySelector('.o-hapus').addEventListener('click', function () {
          var wrap = row.parentNode;
          if (wrap.querySelectorAll('.o-row').length > 1) { row.remove(); ringkas(); }
          else { row.querySelectorAll('select,input').forEach(kosongkan); atur(row); }
        });
        atur(row);
      }
      var n = 100;
      box.querySelectorAll('.o-row').forEach(pasang);
      box.querySelectorAll('.o-tambah').forEach(function (btn) { btn.addEventListener('click', function () {
        var wrap = document.getElementById(btn.dataset.target); if (wrap.querySelectorAll('.o-row').length >= 8) return;
        var row = wrap.querySelector('.o-row').cloneNode(true), i = n++;
        row.querySelectorAll('[name]').forEach(function (x) { x.name = x.name.replace(/^(\w+)\[\d+\]/, '$1[' + i + ']'); kosongkan(x); });
        row.querySelectorAll('.field-error').forEach(function (x) { x.remove(); });
        wrap.appendChild(row); pasang(row);
      }); });

      var cari = document.getElementById('labCari');
      cari.addEventListener('input', function () { var q = cari.value.trim().toLowerCase(), ada = 0;
        box.querySelectorAll('.lab-item').forEach(function (l) { var m = !q || l.dataset.cari.indexOf(q) >= 0; l.hidden = !m; if (m) ada++; });
        box.querySelectorAll('.lab-grup').forEach(function (g) { g.hidden = !g.querySelector('.lab-item:not([hidden])'); });
        document.getElementById('labKosong').hidden = ada > 0; });
      cari.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
      box.querySelectorAll('.lab-paket').forEach(function (b) { b.addEventListener('click', function () {
        b.dataset.ids.split(',').forEach(function (id) { var c = box.querySelector('[name="lab[]"][value="' + id + '"]'); if (c) c.checked = true; });
        ringkas(); }); });
      box.querySelectorAll('[name="lab[]"]').forEach(function (c) { c.addEventListener('change', ringkas); });
      var hamilSel = document.querySelector('[name=rad_hamil]');
      if (hamilSel) hamilSel.addEventListener('change', ringkas);

      function ringkas() {
        var rows = [], total = 0, cnt = { tindakan: 0, lab: 0, rad: 0, resep: 0 }, puasa = [], radiasi = false;
        box.querySelectorAll('.tnd-row').forEach(function (r) { var o = r.querySelector('.tnd-sel').selectedOptions[0]; if (!o || !o.value) return;
          var q = parseInt(r.querySelector('.tnd-qty').value || '1', 10), b = o.dataset.harga * q; cnt.tindakan++; total += b;
          rows.push(['Tindakan', o.textContent.split(' — ')[0] + (q > 1 ? ' × ' + q : ''), b]); });
        box.querySelectorAll('[name="lab[]"]:checked').forEach(function (c) { cnt.lab++; total += +c.dataset.harga; rows.push(['Laboratorium', c.dataset.nama, +c.dataset.harga, asuransi && +c.dataset.harga >= BATAS]); if (c.dataset.puasa === '1') puasa.push(c.dataset.nama); });
        box.querySelectorAll('.rad-row').forEach(function (r) { var o = r.querySelector('.rad-bagian').selectedOptions[0]; if (!o || !o.value) return;
          var k = r.querySelector('.rad-kontras'), s = r.querySelector('.rad-sisi'), kt = r.querySelector('.rad-ket').value.trim();
          var b = +o.dataset.biaya + (o.dataset.kontras === '1' && k.value === '1' ? +o.dataset.biayaKontras : 0); cnt.rad++; total += b;
          if (o.dataset.mod === 'X-Ray' || o.dataset.mod === 'CT Scan') radiasi = true;
          rows.push(['Radiologi', o.dataset.nama + (kt ? ' (' + kt + ')' : '') + (o.dataset.kontras === '1' ? (k.value === '1' ? ' · Dengan kontras' : k.value === '0' ? ' · Tanpa kontras' : ' · kontras belum dipilih') : '') + (o.dataset.sisi === '1' && s.value ? ' · ' + s.value : ''), b, asuransi && b >= BATAS]); });
        box.querySelectorAll('.obat-row').forEach(function (tr) { var o = tr.querySelector('.obat-id'), j = parseInt(tr.querySelector('.obat-jml').value || '0', 10);
          var b = (+o.dataset.harga || 0) * j; cnt.resep++; total += b; rows.push(['E-Resep', o.dataset.nama + (j ? ' × ' + j : ''), b]); });
        var ok = document.getElementById('obatKosong'); if (ok) { ok.hidden = cnt.resep > 0; document.getElementById('obatTbl').hidden = !cnt.resep; }
        document.getElementById('labPuasaBox').hidden = !puasa.length;
        document.getElementById('labPuasaInfo').textContent = puasa.length ? 'Perlu puasa: ' + puasa.join(', ') + '.' : '';
        var hb = document.getElementById('radHamilBox'), hs = document.querySelector('[name=rad_hamil]'); if (hb && hs) { hb.hidden = !(cekHamil && radiasi); document.getElementById('radHamilAlasan').hidden = !(hs.value === 'Hamil' || hs.value === 'Tidak tahu'); }
        Object.keys(cnt).forEach(function (k) { var e = box.querySelector('[data-n="' + k + '"]'); if (e) e.textContent = cnt[k] ? '(' + cnt[k] + ')' : ''; });
        var tb = document.querySelector('#ringkasTbl tbody'); tb.innerHTML = '';
        rows.forEach(function (r) { var tr = document.createElement('tr'); tr.innerHTML = '<td>' + r[0] + '</td><td>' + esc(r[1]) + (r[3] ? ' <span class="badge badge-amber">perlu persetujuan asuransi</span>' : '') + '</td><td class="num">' + rp(r[2]) + '</td>'; tb.appendChild(tr); });
        document.getElementById('ringkasTbl').hidden = !rows.length; document.getElementById('ringkasKosong').hidden = !!rows.length;
        document.getElementById('ringkasTotal').textContent = rp(total);
        if (cnt.lab || cnt.rad) isiInd();
      }

      // ---- E-resep: cari obat -> lihat stok -> pilih -> isi dosis
      var OB = window.__OBAT || [], oc = document.getElementById('obatCari'), oh = document.getElementById('obatHasil'), otb = document.querySelector('#obatTbl tbody'), on = 0;
      function stokLabel(o) { if (o.stok <= 0) return '<span class="badge badge-red">Stok habis</span>'; if (o.stok <= 20) return '<span class="badge badge-amber">Stok menipis · ' + o.stok + ' ' + esc(o.satuan) + '</span>'; return '<span class="badge badge-green">Tersedia · ' + o.stok + ' ' + esc(o.satuan) + '</span>'; }
      function sudahDipilih(id) { return !!otb.querySelector('.obat-row[data-id="' + id + '"]'); }
      function cariObat() {
        var q = oc.value.trim().toLowerCase(); oh.innerHTML = '';
        if (q.length < 2) { oh.hidden = true; return; }
        var hasil = OB.filter(function (o) { return o.nama.toLowerCase().indexOf(q) >= 0; }).slice(0, 8); oh.hidden = false;
        if (!hasil.length) { oh.innerHTML = '<p class="muted text-sm p-2">Obat "' + esc(oc.value) + '" tidak ada di formularium.</p>'; return; }
        hasil.forEach(function (o) {
          var d = document.createElement('div'); d.className = 'obat-opsi' + (o.stok <= 0 ? ' habis' : '');
          var dip = sudahDipilih(o.id);
          d.innerHTML = '<div><b>' + esc(o.nama) + '</b> ' + (o.ha ? '<span class="badge badge-red">High Alert</span> ' : '') + (o.lasa ? '<span class="badge badge-amber">LASA</span> ' : '') + (o.alergi ? '<span class="badge badge-red">Alergi ' + esc(o.alergi) + '</span>' : '') +
            '<div class="text-xs muted">' + rp(o.harga) + ' / ' + esc(o.satuan) + '</div></div><div class="flex items-center gap-2">' + stokLabel(o) +
            (o.stok > 0 ? '<button type="button" class="btn btn-primary btn-sm"' + (dip ? ' disabled' : '') + '>' + (dip ? 'Sudah dipilih' : 'Pilih') + '</button>' : '') + '</div>';
          var b = d.querySelector('button'); if (b) b.addEventListener('click', function () { tambahObat(o); });
          oh.appendChild(d);
        });
      }
      function tambahObat(o) {
        if (sudahDipilih(o.id)) return;
        if (o.alergi && !confirm('Pasien alergi "' + o.alergi + '". Tetap resepkan ' + o.nama + ' dengan alasan klinis?')) return;
        var i = 'n' + (on++), tr = document.createElement('tr'); tr.className = 'obat-row'; tr.dataset.id = o.id;
        tr.innerHTML = '<td><input type="hidden" name="obat[' + i + '][id]" value="' + o.id + '" class="obat-id" data-harga="' + o.harga + '" data-nama="' + esc(o.nama) + '"><b>' + esc(o.nama) + '</b><div class="muted text-xs">Stok ' + o.stok + ' ' + esc(o.satuan) + '</div>' +
          (o.alergi ? '<input name="obat[' + i + '][alasan]" class="input mt-1" placeholder="Alasan klinis (pasien alergi ' + esc(o.alergi) + ')" style="border-color:#b91c2c">' : '') + '</td>' +
          '<td><input type="number" name="obat[' + i + '][jumlah]" min="1" max="' + o.stok + '" class="input obat-jml" style="width:80px"></td>' +
          '<td><input name="obat[' + i + '][dosis]" maxlength="50" class="input" placeholder="contoh: 500 mg"></td>' +
          '<td><input name="obat[' + i + '][frekuensi]" maxlength="50" class="input" placeholder="contoh: 3x sehari"></td>' +
          '<td><input name="obat[' + i + '][aturan]" maxlength="100" class="input" placeholder="contoh: Sesudah makan"></td>' +
          '<td><button type="button" class="btn btn-outline-danger btn-sm obat-hapus">Hapus</button></td>';
        otb.appendChild(tr); pasangObat(tr); oc.value = ''; cariObat(); tr.querySelector('.obat-jml').focus(); ringkas();
      }
      function pasangObat(tr) {
        tr.querySelector('.obat-hapus').addEventListener('click', function () { tr.remove(); ringkas(); });
        tr.querySelectorAll('input').forEach(function (x) { x.addEventListener('input', ringkas); });
      }
      otb.querySelectorAll('.obat-row').forEach(pasangObat);
      oc.addEventListener('input', cariObat);
      oc.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var b = oh.querySelector('button:not([disabled])'); if (b) b.click(); } });
      cariObat();
      ringkas();
    })();
    </script>
    <?php
}
