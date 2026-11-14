<?php
/*
 * MODUL 11: BILLING & CLAIM
 *   Verifikator : Menunggu Verifikasi -> Terverifikasi (cek kepesertaan, plafon asuransi) ; ajukan & putuskan klaim
 *   Kasir       : Terverifikasi -> Dihitung (komponen otomatis dari pelayanan) -> Dibayar (kwitansi) -> Final (dikunci)
 *   Pasien Umum langsung Terverifikasi. Penjamin BPJS/Asuransi: Final hanya jika klaim Disetujui.
 * Tabel: tagihan, tagihan_detail, pembayaran (jenis Pembayaran Pasien = kwitansi, jenis Klaim Penjamin = klaim -- satu tabel).
 * Kasir per cabang; verifikator pusat melayani semua cabang.
 */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
require_once __DIR__ . '/../includes/order.php';
wajib_akses('billing');
$pdo = getPDO();
$TAHAP = ['Menunggu Verifikasi', 'Terverifikasi', 'Dihitung', 'Dibayar', 'Final'];
$ubah_ok = boleh('billing', true);
$kasir = role_is('kasir') && $ubah_ok;
$verif = role_is('verifikator') && $ubah_ok;

function ambil_tagihan(int $id): ?array
{
    $s = getPDO()->prepare("SELECT t.*, k.id_pasien, k.tanggal, k.jenis, k.no_kartu, k.id_penjamin, p.nama_lengkap, p.nomor_rm, p.no_bpjs, p.alamat, p.tanggal_lahir, p.jenis_kelamin, p.alergi,
        pj.nama AS penjamin, pj.tipe AS tipe_penjamin, pl.nama AS poli, d.nama_lengkap AS dokter,
        kl.id AS id_klaim, kl.no_transaksi AS no_klaim, kl.status AS status_klaim, kl.jumlah_tagihan AS jumlah_klaim, kl.catatan AS catatan_klaim,
        pb.no_transaksi AS no_kwitansi, pb.metode, pb.jumlah_dibayar, pb.kembalian, pb.created_at AS waktu_bayar, kas.nama_lengkap AS kasir, k.id_rs
        FROM tagihan t JOIN kunjungan k ON k.id = t.id_kunjungan JOIN pasien p ON p.id = k.id_pasien JOIN penjamin pj ON pj.id = k.id_penjamin
        JOIN unit_layanan pl ON pl.id = k.id_unit JOIN pegawai d ON d.id = k.id_dokter
        LEFT JOIN pembayaran kl ON kl.id_tagihan = t.id AND kl.jenis = 'Klaim Penjamin' LEFT JOIN pembayaran pb ON pb.id_tagihan = t.id AND pb.jenis = 'Pembayaran Pasien'
        LEFT JOIN pegawai kas ON kas.id = pb.dibuat_oleh WHERE t.id = ?");
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

// ---------- PRE-OTORISASI ASURANSI (guarantee letter) untuk order lab/radiologi mahal ----------
if (is_post() && in_array(post('aksi'), ['gl_setuju', 'gl_tolak'], true)) {
    if (!wajib_role_aksi(['verifikator'], 'Persetujuan penjamin diproses Verifikator Asuransi.', 'billing')) kembali();
    $tbl = 'order_pelayanan';
    $jenis_o = post('jenis') === 'rad' ? 'Radiologi' : 'Laboratorium';
    $id = (int) post('id_order');
    $catatan = post('catatan');
    if (post('aksi') === 'gl_setuju') {
        if (mb_strlen($catatan) < 3) { flash('error', 'Isi nomor / catatan surat jaminan (guarantee letter) dari asuransi.'); kembali(); }
        $u = $pdo->prepare("UPDATE $tbl SET jaminan = 'Disetujui', catatan_jaminan = ?, id_verif_jaminan = ? WHERE id = ? AND jaminan = 'Menunggu Persetujuan'");
        $u->execute([mb_substr('GL: ' . $catatan, 0, 255), uid(), $id]);
        $pesan = 'disetujui asuransi. Order diteruskan ke ' . $jenis_o . '.';
    } else {
        $lanjut = post('keputusan') === 'bayar_sendiri';
        if (mb_strlen($catatan) < 5) { flash('error', 'Tulis alasan penolakan dari asuransi (min. 5 karakter).'); kembali(); }
        if ($lanjut) {
            $u = $pdo->prepare("UPDATE $tbl SET jaminan = 'Bayar Sendiri', catatan_jaminan = ?, id_verif_jaminan = ? WHERE id = ? AND jaminan = 'Menunggu Persetujuan'");
            $u->execute([mb_substr('Ditolak asuransi: ' . $catatan . ' · pasien setuju biaya pribadi', 0, 255), uid(), $id]);
        } else {
            $u = $pdo->prepare("UPDATE $tbl SET jaminan = 'Ditolak', status = 'Ditolak', catatan = ?, catatan_jaminan = ?, id_verif_jaminan = ? WHERE id = ? AND jaminan = 'Menunggu Persetujuan'");
            $u->execute(['Dibatalkan: tidak disetujui asuransi', mb_substr('Ditolak asuransi: ' . $catatan, 0, 255), uid(), $id]);
        }
        $pesan = $lanjut ? 'tidak ditanggung asuransi; pasien setuju bayar sendiri, order diteruskan.' : 'tidak ditanggung asuransi; order dibatalkan.';
    }
    if ($u->rowCount()) { audit('Billing', 'Persetujuan Penjamin', "$tbl #$id: $pesan"); flash('sukses', "Order #$id $pesan"); }
    else flash('error', 'Order sudah diproses sebelumnya.');
    redirect('billing/billing.php?tab=persetujuan');
}

if (is_post()) {
    $t = ambil_tagihan((int) post('id'));
    if (!$t) { flash('error', 'Tagihan tidak ditemukan.'); kembali(); }
    if (role_is('kasir') && (int) $t['id_rs'] !== rs_user()) { flash('error', 'Tagihan ini milik cabang lain.'); kembali(); }
    $aksi = post('aksi');
    $ubah = function (string $dari, string $ke, array $set = []) use ($pdo, $t) {
        $cols = array_merge(['status' => $ke], $set);
        $u = $pdo->prepare('UPDATE tagihan SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols))) . ' WHERE id = ? AND status = ?');
        $u->execute([...array_values($cols), $t['id'], $dari]);
        return $u->rowCount() > 0;
    };
    try {
        if (in_array($aksi, ['verifikasi', 'ajukan_klaim', 'putus_klaim'], true)) {
            if (!wajib_role_aksi(['verifikator'], 'Verifikasi penjamin & klaim dilakukan Verifikator Asuransi.', 'billing')) kembali();
            if ($aksi === 'verifikasi') {
                $no = preg_replace('/\D/', '', post('no_kartu'));
                if ($t['tipe_penjamin'] === 'BPJS' && !preg_match('/^\d{13}$/', $no)) throw new Exception('Nomor kartu BPJS harus 13 digit.');
                if ($t['tipe_penjamin'] === 'Asuransi' && mb_strlen(post('no_kartu')) < 5) throw new Exception('Nomor polis/kartu asuransi wajib diisi.');
                $plafon = null;
                if ($t['tipe_penjamin'] === 'Asuransi') {
                    $plafon = (float) str_replace(['.', ','], ['', '.'], post('plafon'));
                    if ($plafon <= 0 || $plafon > 1000000000) throw new Exception('Plafon asuransi wajib diisi (Rp 1 - 1.000.000.000).');
                }
                if (post('hasil') === 'tidak') {   // kepesertaan tidak aktif -> dialihkan ke Umum
                    $umum = (int) $pdo->query("SELECT id FROM penjamin WHERE tipe = 'Umum' ORDER BY id LIMIT 1")->fetchColumn();
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE kunjungan SET id_penjamin = ?, no_kartu = NULL WHERE id = ?')->execute([$umum, $t['id_kunjungan']]);
                    if (!$ubah('Menunggu Verifikasi', 'Terverifikasi', ['catatan_verifikasi' => 'Kepesertaan tidak aktif, dialihkan ke Umum: ' . post('catatan'), 'diverifikasi_oleh' => uid()])) throw new Exception('Status tagihan sudah berubah.');
                    $pdo->commit();
                    $pesan = 'kepesertaan TIDAK aktif, tagihan dialihkan ke pasien Umum';
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE kunjungan SET no_kartu = ? WHERE id = ?')->execute([$t['tipe_penjamin'] === 'BPJS' ? $no : post('no_kartu'), $t['id_kunjungan']]);
                    if (!$ubah('Menunggu Verifikasi', 'Terverifikasi', ['plafon_penjamin' => $plafon, 'catatan_verifikasi' => post('catatan') ?: 'Kepesertaan aktif', 'diverifikasi_oleh' => uid()])) throw new Exception('Status tagihan sudah berubah.');
                    $pdo->commit();
                    $pesan = 'penjamin terverifikasi' . ($plafon ? ', plafon ' . rupiah($plafon) : '');
                }
            } elseif ($aksi === 'ajukan_klaim') {
                if ($t['tipe_penjamin'] === 'Umum' || !in_array($t['status'], ['Dihitung', 'Dibayar'], true)) throw new Exception('Klaim diajukan setelah tagihan dihitung, khusus pasien BPJS/Asuransi.');
                if ($t['id_klaim'] && $t['status_klaim'] !== 'Perlu Perbaikan') throw new Exception('Klaim sudah diajukan.');
                if ($t['id_klaim']) $pdo->prepare("UPDATE pembayaran SET status = 'Diajukan', jumlah_tagihan = ?, catatan = ? WHERE id = ?")->execute([$t['porsi_penjamin'], mb_substr('Diajukan ulang: ' . post('catatan'), 0, 255), $t['id_klaim']]);
                else $pdo->prepare("INSERT INTO pembayaran (jenis, no_transaksi, id_tagihan, id_penjamin, metode, jumlah_tagihan, status, catatan, dibuat_oleh) VALUES ('Klaim Penjamin',?,?,?,?,?,'Diajukan',?,?)")
                    ->execute([kode_berikut('pembayaran', 'no_transaksi', 'KLM'), $t['id'], $t['id_penjamin'], $t['tipe_penjamin'] === 'BPJS' ? 'bpjs' : 'asuransi', $t['porsi_penjamin'],
                               mb_substr(post('catatan') ?: 'Berkas: resume medis, SEP/surat jaminan, rincian tagihan', 0, 255), uid()]);
                $pesan = 'klaim ' . rupiah($t['porsi_penjamin']) . ' diajukan ke ' . $t['penjamin'];
            } else {
                $hasil = post('hasil');
                if (!in_array($hasil, ['Disetujui', 'Perlu Perbaikan', 'Ditolak'], true) || !$t['id_klaim'] || $t['status_klaim'] !== 'Diajukan') throw new Exception('Klaim tidak dalam status Diajukan.');
                if ($hasil !== 'Disetujui' && mb_strlen(post('catatan')) < 5) throw new Exception('Tulis catatan/alasan dari penjamin.');
                $pdo->prepare('UPDATE pembayaran SET status = ?, catatan = ?, jumlah_dibayar = ? WHERE id = ?')->execute([$hasil, mb_substr(post('catatan') ?: 'Klaim disetujui penjamin', 0, 255), $hasil === 'Disetujui' ? $t['jumlah_klaim'] : 0, $t['id_klaim']]);
                $pesan = "klaim $hasil";
            }
        } elseif (in_array($aksi, ['hitung', 'bayar', 'kunci'], true)) {
            if (!wajib_role_aksi(['kasir'], 'Perhitungan, pembayaran & penguncian dilakukan Kasir.', 'billing')) kembali();
            if ($aksi === 'hitung') {
                $h = hitung_komponen((int) $t['id_kunjungan']);
                if ($h['tertunda']) throw new Exception('Belum bisa dihitung: ' . implode(', ', $h['tertunda']) . '.');
                $total = $h['total'];
                $dijamin = $total - $h['pribadi'];   // item "biaya pribadi" (ditolak asuransi) tidak ditanggung penjamin
                $pj = $t['tipe_penjamin'] === 'BPJS' ? $dijamin : ($t['tipe_penjamin'] === 'Asuransi' ? min($dijamin, (float) $t['plafon_penjamin']) : 0);
                $pdo->beginTransaction();
                if (!$ubah('Terverifikasi', 'Dihitung', ['total' => $total, 'porsi_penjamin' => $pj, 'porsi_pasien' => $total - $pj, 'dihitung_oleh' => uid()])) throw new Exception('Status tagihan sudah berubah.');
                $pdo->prepare('DELETE FROM tagihan_detail WHERE id_tagihan = ?')->execute([$t['id']]);
                $ins = $pdo->prepare('INSERT INTO tagihan_detail (id_tagihan, komponen, uraian, qty, harga, subtotal) VALUES (?,?,?,?,?,?)');
                foreach ($h['rincian'] as [$komp, $ur, $q, $hr]) $ins->execute([$t['id'], $komp, mb_substr($ur, 0, 200), $q, $hr, $q * $hr]);
                $pdo->commit();
                $pesan = 'total ' . rupiah($total) . ' (penjamin ' . rupiah($pj) . ', pasien ' . rupiah($total - $pj) . ')';
            } elseif ($aksi === 'bayar') {
                $metode = post('metode');
                $harus = (float) $t['porsi_pasien'];
                $boleh = $harus > 0 ? ['tunai', 'debit', 'qris', 'transfer'] : ($t['tipe_penjamin'] === 'BPJS' ? ['bpjs'] : ['asuransi']);
                if (!in_array($metode, $boleh, true)) throw new Exception('Metode pembayaran tidak sesuai. Pilihan: ' . implode(', ', $boleh) . '.');
                $dibayar = $metode === 'tunai' ? (float) preg_replace('/\D/', '', post('dibayar')) : $harus;
                if ($dibayar < $harus) throw new Exception('Uang yang diterima kurang dari ' . rupiah($harus) . '.');
                if ($metode === 'tunai' && $dibayar > $harus + 10000000) throw new Exception('Nominal tunai tidak wajar, periksa kembali.');
                if (in_array($metode, ['debit', 'qris', 'transfer'], true) && mb_strlen(post('ref')) < 4) throw new Exception('Nomor referensi/approval transaksi wajib diisi.');
                $pdo->beginTransaction();
                if (!$ubah('Dihitung', 'Dibayar')) throw new Exception('Status tagihan sudah berubah (mungkin sudah dibayar).');
                $pdo->prepare("INSERT INTO pembayaran (jenis, no_transaksi, id_tagihan, metode, jumlah_tagihan, jumlah_dibayar, kembalian, status, catatan, dibuat_oleh) VALUES ('Pembayaran Pasien',?,?,?,?,?,?,'Lunas',?,?)")
                    ->execute([kode_berikut('pembayaran', 'no_transaksi', 'KW'), $t['id'], $metode, $harus, $dibayar, $dibayar - $harus, post('ref') ? 'Ref ' . post('ref') : null, uid()]);
                $pdo->commit();
                $pesan = 'pembayaran ' . strtoupper($metode) . ' tercatat' . ($dibayar > $harus ? ', kembalian ' . rupiah($dibayar - $harus) : '');
            } else {
                if ($t['tipe_penjamin'] !== 'Umum' && $t['status_klaim'] !== 'Disetujui') throw new Exception('Tagihan penjamin hanya bisa dikunci setelah klaim DISETUJUI (status klaim: ' . ($t['status_klaim'] ?: 'belum diajukan') . ').');
                if (!$ubah('Dibayar', 'Final', ['dikunci_oleh' => uid(), 'dikunci_at' => date('Y-m-d H:i:s')])) throw new Exception('Status tagihan sudah berubah.');
                $pesan = 'tagihan FINAL dan dikunci';
            }
        } else throw new Exception('Aksi tidak dikenal.');
        audit('Billing', ucfirst(str_replace('_', ' ', $aksi)), "{$t['no_tagihan']} {$t['nomor_rm']}");
        flash('sukses', "{$t['no_tagihan']} ({$t['nama_lengkap']}): $pesan.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e instanceof PDOException ? (is_duplikat($e) ? 'Data sudah tercatat sebelumnya (tidak boleh dobel).' : 'Gagal menyimpan: ' . $e->getMessage()) : $e->getMessage());
    }
    kembali();
}

// ---------- DETAIL / INVOICE ----------
if (get('id') !== '') {
    $t = ambil_tagihan((int) get('id'));
    if (!$t) { flash('error', 'Tagihan tidak ditemukan.'); redirect('billing/billing.php'); }
    if (role_is('kasir') && (int) $t['id_rs'] !== rs_user()) { flash('error', 'Tagihan ini milik cabang lain.'); redirect('billing/billing.php'); }
    $cetak = get('cetak');
    $detail = $pdo->prepare('SELECT * FROM tagihan_detail WHERE id_tagihan = ? ORDER BY id');
    $detail->execute([$t['id']]);
    $detail = $detail->fetchAll();
    $pratinjau = null;
    if (!$detail) $pratinjau = hitung_komponen((int) $t['id_kunjungan']);
    $jenis = ['rawat_jalan' => 'Rawat Jalan', 'igd' => 'IGD', 'rawat_inap' => 'Rawat Inap'][$t['jenis']] ?? $t['jenis'];
    tampil_header($cetak === 'kwitansi' ? 'Kwitansi ' . $t['no_kwitansi'] : 'Tagihan ' . $t['no_tagihan'], aman($t['nama_lengkap']) . ' · ' . $jenis . ' · ' . tgl($t['tanggal']),
        ($detail ? '<a href="?id=' . $t['id'] . '&cetak=invoice" class="btn btn-secondary">Invoice</a> ' : '') . ($t['no_kwitansi'] ? '<a href="?id=' . $t['id'] . '&cetak=kwitansi" class="btn btn-secondary">Kwitansi</a> ' : '')
        . ($cetak ? '<button onclick="window.print()" class="btn btn-primary">Cetak</button> ' : '') . '<a href="' . ($cetak ? '?id=' . $t['id'] : 'billing.php') . '" class="btn btn-secondary">Kembali</a>');

    if ($cetak === 'kwitansi' && $t['no_kwitansi']): ?>
      <div class="card inv-paper print-area"><div class="card-body">
        <div class="flex items-center gap-3 border-b pb-3 mb-4"><img src="<?= url('assets/logo.png') ?>" class="w-12 h-12" alt=""><div><b class="text-[#004c6d] text-lg"><?= aman(konfig('nama_rs', (int) $t['id_rs'])) ?> · <?= aman(konfig('nama_cabang', (int) $t['id_rs'])) ?></b><div class="muted"><?= aman(konfig('alamat', (int) $t['id_rs'])) ?> · <?= aman(konfig('telepon', (int) $t['id_rs'])) ?></div></div><div class="ml-auto text-right"><b>KWITANSI</b><div class="mono"><?= aman($t['no_kwitansi']) ?></div></div></div>
        <dl class="kv"><dt>Telah terima dari</dt><dd><?= aman($t['nama_lengkap']) ?> (<?= aman($t['nomor_rm']) ?>)</dd><dt>Untuk pembayaran</dt><dd>Biaya <?= $jenis ?> <?= aman($t['poli']) ?> · <?= aman($t['no_tagihan']) ?></dd>
          <dt>Total tagihan</dt><dd><?= rupiah($t['total']) ?></dd><?php if ($t['tipe_penjamin'] !== 'Umum'): ?><dt>Ditanggung <?= aman($t['penjamin']) ?></dt><dd><?= rupiah($t['porsi_penjamin']) ?></dd><?php endif; ?>
          <dt>Dibayar pasien</dt><dd class="text-lg"><?= rupiah($t['porsi_pasien']) ?></dd><dt>Metode</dt><dd><?= strtoupper(aman($t['metode'])) ?></dd><dt>Uang diterima</dt><dd><?= rupiah($t['jumlah_dibayar']) ?></dd><dt>Kembalian</dt><dd><?= rupiah($t['kembalian']) ?></dd></dl>
        <div class="text-right mt-8 text-sm"><?= aman(konfig('kota', (int) $t['id_rs'])) ?>, <?= tgl($t['waktu_bayar']) ?><br>Kasir<br><br><br><b><?= aman($t['kasir']) ?></b></div>
      </div></div>
    <?php tampil_footer(); exit; endif;

    if (!$cetak) echo kartu_pasien($t, aman($t['penjamin']) . ($t['no_kartu'] ? ' · No. kartu ' . aman($t['no_kartu']) : '') . ' · ' . $jenis . ' · ' . aman($t['poli']) . ' · ' . aman($t['dokter']));
    ?>
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="card lg:col-span-2 <?= $cetak ? 'inv-paper print-area lg:col-span-3' : '' ?>">
        <?php if ($cetak): ?><div class="card-body border-b"><div class="flex items-center gap-3"><img src="<?= url('assets/logo.png') ?>" class="w-12 h-12" alt=""><div><b class="text-[#004c6d] text-lg"><?= aman(konfig('nama_rs', (int) $t['id_rs'])) ?> · <?= aman(konfig('nama_cabang', (int) $t['id_rs'])) ?></b><div class="muted"><?= aman(konfig('alamat', (int) $t['id_rs'])) ?></div></div><div class="ml-auto text-right"><b>INVOICE</b><div class="mono"><?= aman($t['no_tagihan']) ?></div><div class="muted"><?= tgl($t['created_at']) ?></div></div></div>
          <dl class="kv mt-3"><dt>Pasien</dt><dd><?= aman($t['nama_lengkap']) ?> (<?= aman($t['nomor_rm']) ?>)</dd><dt>Layanan</dt><dd><?= $jenis ?> · <?= aman($t['poli']) ?> · <?= aman($t['dokter']) ?></dd><dt>Penjamin</dt><dd><?= aman($t['penjamin']) ?> <?= aman($t['no_kartu']) ?></dd></dl></div>
        <?php else: ?><div class="card-head"><b>Rincian biaya</b><?= stepper($TAHAP, $t['status']) ?></div><?php endif; ?>
        <?php if ($pratinjau): ?><div class="notice m-3">Pratinjau otomatis dari data pelayanan. Rincian disimpan saat kasir menekan <b>Hitung Tagihan</b>.<?= $pratinjau['tertunda'] ? '<br><b class="text-red-700">Tertunda: ' . aman(implode(', ', $pratinjau['tertunda'])) . '</b>' : '' ?></div><?php endif; ?>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Komponen</th><th>Uraian</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead><tbody>
        <?php $baris = $detail ?: array_map(fn($x) => ['komponen' => $x[0], 'uraian' => $x[1], 'qty' => $x[2], 'harga' => $x[3], 'subtotal' => $x[2] * $x[3]], $pratinjau['rincian']);
        foreach ($baris as $d): ?><tr><td><?= aman($d['komponen']) ?></td><td><?= aman($d['uraian']) ?></td><td class="num"><?= (int) $d['qty'] ?></td><td class="num"><?= rupiah($d['harga']) ?></td><td class="num"><?= rupiah($d['subtotal']) ?></td></tr><?php endforeach; ?>
        <?php $tot = $detail ? $t['total'] : $pratinjau['total']; ?>
          <tr><td colspan="4" class="num"><b>Total</b></td><td class="num"><b><?= rupiah($tot) ?></b></td></tr>
          <?php if ($detail && $t['tipe_penjamin'] !== 'Umum'): ?><tr><td colspan="4" class="num">Ditanggung <?= aman($t['penjamin']) ?></td><td class="num">- <?= rupiah($t['porsi_penjamin']) ?></td></tr><?php endif; ?>
          <?php if ($detail): ?><tr><td colspan="4" class="num"><b>Harus dibayar pasien</b></td><td class="num text-lg"><b><?= rupiah($t['porsi_pasien']) ?></b></td></tr><?php endif; ?>
        </tbody></table></div>
      </div>
      <?php if (!$cetak): ?>
      <div class="space-y-4">
        <div class="card"><div class="card-head"><b>Penjamin & klaim</b></div><div class="card-body text-sm">
          <dl class="kv"><dt>Tipe</dt><dd><?= aman($t['tipe_penjamin']) ?> · <?= aman($t['penjamin']) ?></dd><dt>Plafon</dt><dd><?= $t['plafon_penjamin'] !== null ? rupiah($t['plafon_penjamin']) : '-' ?></dd>
            <dt>Verifikasi</dt><dd><?= aman($t['catatan_verifikasi'] ?: 'Belum') ?></dd><dt>Klaim</dt><dd><?= $t['no_klaim'] ? aman($t['no_klaim']) . ' ' . badge($t['status_klaim']) . '<div class="muted">' . aman($t['catatan_klaim']) . '</div>' : '-' ?></dd></dl>
          <?php if ($verif && $t['status'] === 'Menunggu Verifikasi'): ?>
            <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="aksi" value="verifikasi"><input type="hidden" name="id" value="<?= $t['id'] ?>">
              <div class="field"><label>No. kartu <?= $t['tipe_penjamin'] === 'BPJS' ? 'BPJS (13 digit)' : 'polis asuransi' ?></label><input name="no_kartu" value="<?= aman($t['no_kartu'] ?: $t['no_bpjs']) ?>" <?= $t['tipe_penjamin'] === 'BPJS' ? 'data-digits="13" inputmode="numeric"' : '' ?>></div>
              <?php if ($t['tipe_penjamin'] === 'Asuransi'): ?><div class="field"><label>Plafon tanggungan (Rp)</label><input name="plafon" inputmode="numeric" placeholder="contoh: 500000"></div><?php endif; ?>
              <div class="field"><label>Hasil cek kepesertaan</label><select name="hasil"><option value="aktif">Aktif / dijamin</option><option value="tidak">Tidak aktif → alihkan ke Umum</option></select></div>
              <div class="field"><label>Catatan</label><input name="catatan" placeholder="contoh: SEP terbit, kelas 2"></div><button class="btn btn-primary w-full">Simpan Verifikasi</button></form>
          <?php endif; ?>
          <?php if ($verif && $t['tipe_penjamin'] !== 'Umum' && in_array($t['status'], ['Dihitung', 'Dibayar'], true) && (!$t['id_klaim'] || $t['status_klaim'] === 'Perlu Perbaikan')): ?>
            <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="aksi" value="ajukan_klaim"><input type="hidden" name="id" value="<?= $t['id'] ?>"><div class="field"><label>Catatan berkas</label><input name="catatan" placeholder="Resume medis, SEP, rincian biaya"></div><button class="btn btn-primary w-full"><?= $t['id_klaim'] ? 'Ajukan Ulang Klaim' : 'Ajukan Klaim ' . rupiah($t['porsi_penjamin']) ?></button></form>
          <?php elseif ($verif && $t['status_klaim'] === 'Diajukan'): ?>
            <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="aksi" value="putus_klaim"><input type="hidden" name="id" value="<?= $t['id'] ?>"><div class="field"><label>Respon penjamin</label><select name="hasil"><option>Disetujui</option><option>Perlu Perbaikan</option><option>Ditolak</option></select></div>
              <div class="field"><label>Catatan</label><input name="catatan" placeholder="wajib bila tidak disetujui"></div><button class="btn btn-primary w-full">Simpan Status Klaim</button></form>
          <?php endif; ?>
        </div></div>
        <div class="card"><div class="card-head"><b>Kasir</b></div><div class="card-body text-sm">
          <?php if ($t['no_kwitansi']): ?><dl class="kv mb-2"><dt>Kwitansi</dt><dd class="mono"><?= aman($t['no_kwitansi']) ?></dd><dt>Metode</dt><dd><?= strtoupper(aman($t['metode'])) ?></dd><dt>Dibayar</dt><dd><?= rupiah($t['jumlah_dibayar']) ?></dd><dt>Kembalian</dt><dd><?= rupiah($t['kembalian']) ?></dd></dl><?php endif; ?>
          <?php if (!$kasir): ?><p class="muted">Perhitungan & pembayaran dilakukan oleh Kasir.</p>
          <?php elseif ($t['status'] === 'Menunggu Verifikasi'): ?><div class="notice">Menunggu verifikasi penjamin oleh Verifikator.</div>
          <?php elseif ($t['status'] === 'Terverifikasi'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="hitung"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-primary w-full" <?= $pratinjau && $pratinjau['tertunda'] ? 'disabled title="Masih ada layanan tertunda"' : '' ?>>Hitung Tagihan</button></form>
          <?php elseif ($t['status'] === 'Dihitung'): $nol = (float) $t['porsi_pasien'] <= 0; ?>
            <form method="post" id="fBayar"><?= csrf_field() ?><input type="hidden" name="aksi" value="bayar"><input type="hidden" name="id" value="<?= $t['id'] ?>">
              <div class="readonly-box mb-2">Harus dibayar pasien: <b class="text-lg"><?= rupiah($t['porsi_pasien']) ?></b></div>
              <div class="field"><label>Metode</label><select name="metode" id="metode"><?= $nol ? opsi([$t['tipe_penjamin'] === 'BPJS' ? 'bpjs' : 'asuransi' => $t['tipe_penjamin'] === 'BPJS' ? 'Dijamin BPJS (Rp 0)' : 'Dijamin Asuransi (Rp 0)']) : opsi(['tunai' => 'Tunai', 'debit' => 'Kartu Debit', 'qris' => 'QRIS', 'transfer' => 'Transfer Bank']) ?></select></div>
              <?php if (!$nol): ?><div class="field" id="fTunai"><label>Uang diterima (Rp)</label><input name="dibayar" inputmode="numeric" value="<?= (int) $t['porsi_pasien'] ?>" id="dibayar"><div class="muted mt-1">Kembalian: <b id="kembali">Rp 0</b></div></div>
              <div class="field hidden" id="fRef"><label>No. referensi / approval</label><input name="ref" placeholder="contoh: 004512"></div><?php endif; ?>
              <button class="btn btn-success w-full">Proses Pembayaran</button></form>
            <script>(function(){const m=document.getElementById('metode'),d=document.getElementById('dibayar'),k=document.getElementById('kembali');if(!d)return;const h=<?= (float) $t['porsi_pasien'] ?>;
              function u(){const t=m.value==='tunai';document.getElementById('fTunai').classList.toggle('hidden',!t);document.getElementById('fRef').classList.toggle('hidden',t);const v=parseInt((d.value||'0').replace(/\D/g,''))||0;k.textContent='Rp '+Math.max(0,v-h).toLocaleString('id-ID');k.style.color=v<h?'#dc2626':'';}
              m.addEventListener('change',u);d.addEventListener('input',u);u();})();</script>
          <?php elseif ($t['status'] === 'Dibayar'): ?>
            <form method="post" data-confirm="Kunci tagihan? Setelah final tagihan tidak bisa diubah."><?= csrf_field() ?><input type="hidden" name="aksi" value="kunci"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-primary w-full">Kunci Tagihan (Final)</button></form>
            <?php if ($t['tipe_penjamin'] !== 'Umum' && $t['status_klaim'] !== 'Disetujui'): ?><p class="muted mt-2">Syarat final: klaim penjamin disetujui (sekarang: <?= aman($t['status_klaim'] ?: 'belum diajukan') ?>).</p><?php endif; ?>
          <?php else: ?><div class="notice">Tagihan final, dikunci <?= tgl_jam($t['dikunci_at']) ?>.</div><?php endif; ?>
        </div></div>
      </div>
      <?php endif; ?>
    </div>
    <?php tampil_footer();
    exit;
}

// ---------- DAFTAR ----------
$tab = get('tab', $verif ? 'verifikasi' : 'proses');
$filter = ['verifikasi' => "t.status = 'Menunggu Verifikasi'", 'proses' => "t.status IN ('Terverifikasi','Dihitung','Dibayar')", 'klaim' => "pj.tipe <> 'Umum' AND t.status IN ('Dihitung','Dibayar','Final') AND (kl.status IS NULL OR kl.status <> 'Disetujui')", 'final' => "t.status = 'Final'"];
$where = ($filter[$tab] ?? $filter['proses']) . sql_rs('k.id_rs');
$gl_sql = "SELECT CASE x.jenis WHEN 'Radiologi' THEN 'rad' ELSE 'lab' END AS jenis, x.id, x.id_kunjungan, x.uraian AS item, x.biaya, x.indikasi, x.urgensi, x.created_at,
        p.nama_lengkap, p.nomor_rm, pj.nama AS penjamin, k.no_kartu, k.id_rs, d.nama_lengkap AS dokter
    FROM order_pelayanan x JOIN kunjungan k ON k.id = x.id_kunjungan JOIN pasien p ON p.id = k.id_pasien JOIN penjamin pj ON pj.id = k.id_penjamin JOIN pegawai d ON d.id = x.id_dokter
    WHERE x.jaminan = 'Menunggu Persetujuan'" . sql_rs('k.id_rs') . " ORDER BY x.urgensi = 'CITO' DESC, x.created_at";
$gl_rows = $pdo->query($gl_sql)->fetchAll();
$q = get('q');
$par = [];
if ($q !== '') { $where .= ' AND (p.nama_lengkap LIKE ? OR p.nomor_rm LIKE ? OR t.no_tagihan LIKE ?)'; $par = ["%$q%", "%$q%", "%$q%"]; }
$s = $pdo->prepare("SELECT t.*, p.nama_lengkap, p.nomor_rm, pj.nama AS penjamin, pj.tipe, k.jenis, k.tanggal, k.id_rs, pl.nama AS poli, kl.status AS status_klaim
    FROM tagihan t JOIN kunjungan k ON k.id = t.id_kunjungan JOIN pasien p ON p.id = k.id_pasien JOIN penjamin pj ON pj.id = k.id_penjamin JOIN unit_layanan pl ON pl.id = k.id_unit
    LEFT JOIN pembayaran kl ON kl.id_tagihan = t.id AND kl.jenis = 'Klaim Penjamin'
    WHERE $where ORDER BY t.created_at DESC LIMIT 200");
$s->execute($par);
$rows = $s->fetchAll();
$st = $pdo->query('SELECT t.status, COUNT(*) n FROM tagihan t JOIN kunjungan k ON k.id = t.id_kunjungan WHERE 1=1' . sql_rs('k.id_rs') . ' GROUP BY t.status')->fetchAll(PDO::FETCH_KEY_PAIR);
$hari = $pdo->prepare("SELECT COALESCE(SUM(b.jumlah_tagihan),0) FROM pembayaran b JOIN tagihan t ON t.id = b.id_tagihan JOIN kunjungan k ON k.id = t.id_kunjungan WHERE b.jenis = 'Pembayaran Pasien' AND b.created_at >= ?" . sql_rs('k.id_rs'));
$hari->execute([date('Y-m-d')]);
$klaim_proses = (int) $pdo->query("SELECT COUNT(*) FROM pembayaran b JOIN tagihan t ON t.id = b.id_tagihan JOIN kunjungan k ON k.id = t.id_kunjungan WHERE b.jenis = 'Klaim Penjamin' AND b.status IN ('Diajukan','Perlu Perbaikan')" . sql_rs('k.id_rs'))->fetchColumn();
tampil_header('Billing & Klaim', $kasir ? 'Kasir: hitung tagihan otomatis dari layanan, terima pembayaran, kunci tagihan.' : ($verif ? 'Verifikator: verifikasi penjamin (BPJS/Asuransi) dan kelola klaim.' : 'Daftar tagihan pasien.'));
echo kartu([['Menunggu Verifikasi', $st['Menunggu Verifikasi'] ?? 0, 'amber'], ['Siap Dihitung', $st['Terverifikasi'] ?? 0, 'teal'], ['Belum Dibayar', $st['Dihitung'] ?? 0, 'red'], ['Klaim Diproses', $klaim_proses, 'purple'], ['Penerimaan Kas Hari Ini', rupiah($hari->fetchColumn()), 'green']]);
?>
<div class="tabs"><?php foreach (['verifikasi' => 'Verifikasi penjamin', 'persetujuan' => 'Persetujuan asuransi' . ($gl_rows ? ' (' . count($gl_rows) . ')' : ''), 'proses' => 'Proses kasir', 'klaim' => 'Klaim', 'final' => 'Final'] as $k => $v): ?><a class="tab<?= $tab === $k ? ' active' : '' ?>" href="?tab=<?= $k ?>"><?= $v ?></a><?php endforeach; ?></div>
<?php if ($tab === 'persetujuan'): ?>
<div class="card"><div class="card-head"><div><h3>Persetujuan Asuransi (Pre-otorisasi)</h3><p class="muted text-sm">Pemeriksaan pasien asuransi swasta dengan biaya ≥ batas di Konfigurasi RS (<?= implode(', ', array_map(fn($c) => $c['nama_cabang'] . ' ' . rupiah($c['batas_persetujuan_asuransi']), daftar_cabang())) ?>) menunggu surat jaminan (guarantee letter) sebelum dikerjakan. Pasien BPJS tidak memerlukan persetujuan per order.</p></div></div>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Pasien</th><th>Penjamin</th><th>Pemeriksaan</th><th class="num">Biaya</th><th>Dokter</th><th class="num" style="min-width:320px">Keputusan</th></tr></thead><tbody>
<?php if (!$gl_rows): ?><tr><td colspan="6" class="empty">Tidak ada order yang menunggu persetujuan asuransi.</td></tr><?php endif; ?>
<?php foreach ($gl_rows as $g): ?>
  <tr><td><b class="pname"><?= aman($g['nama_lengkap']) ?></b><div class="muted"><?= aman($g['nomor_rm']) ?> · <?= aman(nama_cabang((int) $g['id_rs'])) ?></div></td>
    <td><?= aman($g['penjamin']) ?><div class="muted">No. polis <?= aman($g['no_kartu'] ?: '-') ?></div></td>
    <td><b><?= aman($g['item']) ?></b> <?= $g['urgensi'] === 'CITO' ? badge('CITO') : '' ?><div class="muted"><?= $g['jenis'] === 'lab' ? 'Laboratorium' : 'Radiologi' ?> · <?= aman($g['indikasi']) ?></div></td>
    <td class="num"><?= rupiah($g['biaya']) ?></td><td><?= aman($g['dokter']) ?><div class="muted"><?= tgl_jam($g['created_at']) ?></div></td>
    <td class="num">
      <?php if ($verif): ?>
      <form method="post" class="flex gap-2 justify-end mb-2"><?= csrf_field() ?><input type="hidden" name="aksi" value="gl_setuju"><input type="hidden" name="jenis" value="<?= $g['jenis'] ?>"><input type="hidden" name="id_order" value="<?= $g['id'] ?>">
        <input name="catatan" class="input input-sm" placeholder="No. surat jaminan" style="max-width:190px"><button class="btn btn-primary btn-sm">Setujui</button></form>
      <form method="post" class="flex gap-2 justify-end flex-wrap" data-confirm="Catat bahwa asuransi menolak pemeriksaan ini?"><?= csrf_field() ?><input type="hidden" name="aksi" value="gl_tolak"><input type="hidden" name="jenis" value="<?= $g['jenis'] ?>"><input type="hidden" name="id_order" value="<?= $g['id'] ?>">
        <input name="catatan" class="input input-sm" placeholder="Alasan ditolak asuransi" style="max-width:190px">
        <select name="keputusan" class="input input-sm" style="max-width:200px"><option value="bayar_sendiri">Pasien bayar sendiri</option><option value="batal">Batalkan order</option></select>
        <button class="btn btn-outline-danger btn-sm">Tolak</button></form>
      <?php else: ?><span class="badge badge-amber">Menunggu Verifikator</span><?php endif; ?>
    </td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php tampil_footer(); exit; endif; ?>
<div class="card"><div class="card-head"><b><?= count($rows) ?> tagihan</b><form class="flex gap-2"><input type="hidden" name="tab" value="<?= aman($tab) ?>"><input name="q" value="<?= aman($q) ?>" class="input input-sm" placeholder="Nama / No. RM / No. tagihan"><button class="btn btn-secondary btn-sm">Cari</button></form></div>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>No. Tagihan</th><th>Pasien</th><th>Layanan</th><th>Penjamin</th><th class="num">Total</th><th class="num">Porsi Pasien</th><th>Status</th><th class="num">Aksi</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="empty">Tidak ada tagihan pada tab ini.</td></tr><?php endif; ?>
<?php foreach ($rows as $t): ?>
  <tr><td class="mono"><?= aman($t['no_tagihan']) ?><div class="muted"><?= tgl($t['tanggal']) ?></div></td><td><b class="pname"><?= aman($t['nama_lengkap']) ?></b><div class="muted"><?= aman($t['nomor_rm']) ?></div></td>
    <td><?= ['rawat_jalan' => 'Rawat Jalan', 'igd' => 'IGD', 'rawat_inap' => 'Rawat Inap'][$t['jenis']] ?? '' ?><div class="muted"><?= aman($t['poli']) ?> · <?= aman(nama_cabang((int) $t['id_rs'])) ?></div></td>
    <td><?= aman($t['penjamin']) ?><?= $t['status_klaim'] ? '<div>Klaim ' . badge($t['status_klaim']) . '</div>' : '' ?></td>
    <td class="num"><?= $t['total'] > 0 ? rupiah($t['total']) : '<span class="muted">belum dihitung</span>' ?></td><td class="num"><?= $t['total'] > 0 ? rupiah($t['porsi_pasien']) : '-' ?></td>
    <td><?= badge($t['status']) ?></td><td class="num"><a class="btn btn-sm btn-<?= ($kasir && in_array($t['status'], ['Terverifikasi', 'Dihitung', 'Dibayar'], true)) || ($verif && $tab !== 'final') ? 'primary' : 'secondary' ?>" href="?id=<?= $t['id'] ?>">Buka</a></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php tampil_footer();
