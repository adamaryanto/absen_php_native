<?php
// cek_slip_KOSONG.php — probe baca-saja (READ ONLY, tidak mengubah data).
// Taruh di ROOT payroll (sejajar koneksi.php), buka:
//   cek_slip_KOSONG.php?id=477&ttgl1=2026-10-31&ttgl2=2026-11-07
// Hapus file ini setelah dipakai.
include "koneksi.php";
$id = (int)($_GET['id'] ?? 477);
$ttgl1 = $_GET['ttgl1'] ?? '2026-10-31';
$ttgl2 = $_GET['ttgl2'] ?? '2026-11-07';
echo "<pre>PHP: " . PHP_VERSION . "\n";
$d = $koneksi->query("SELECT DATABASE() AS db");
echo "DB aktif: " . ($d ? $d->fetch_assoc()['db'] : '(gagal)') . "\n\n";

// 1) Versi file slip yang ter-install
$f = @file_get_contents("page/realisasi/slip_simple.php");
echo "== File page/realisasi/slip_simple.php ==\n";
if ($f === false) { echo "TIDAK KETEMU!\n"; }
else {
    echo "ada 'dedupe 1 baris' (versi DEDUPE): " . (strpos($f, 'dedupe 1 baris') !== false ? 'YA' : 'TIDAK') . "\n";
    echo "ada 'realisasi_tgl' (versi DEDUPE/REALISASI): " . (strpos($f, 'realisasi_tgl') !== false ? 'YA' : 'TIDAK') . "\n";
    echo "ada 'tb_realisasi_detail' (baca Realisasi): " . (strpos($f, 'tb_realisasi_detail') !== false ? 'YA' : 'TIDAK') . "\n";
    echo "ada '\$grouped' (versi LAMA baca tap): " . (strpos($f, '$grouped') !== false ? 'YA' : 'TIDAK') . "\n";
    echo "ukuran file: " . strlen($f) . " byte\n";
}
echo "\n";

// 2) Query persis seperti di slip (dengan JOIN + dedupe dihitung di PHP)
$sql = "SELECT r.*, R.tgl_realisasi AS realisasi_tgl FROM tb_realisasi_detail r LEFT JOIN tb_realisasi R ON R.id_realisasi = r.id_realisasi WHERE r.id_karyawan = '$id' AND r.tgl_realisasi_detail BETWEEN '$ttgl1' AND '$ttgl2' ORDER BY r.tgl_realisasi_detail ASC";
$q = $koneksi->query($sql);
echo "== Query slip (id=$id, $ttgl1 s/d $ttgl2) ==\n";
if (!$q) { echo "QUERY ERROR: " . $koneksi->error . "\n"; }
else {
    echo "baris mentah: " . $q->num_rows . "\n";
    $rows = [];
    while ($rr = $q->fetch_assoc()) {
        $rows[] = $rr;
        echo "  detail " . $rr['id_realisasi_detail'] . " | realisasi " . $rr['id_realisasi'] . " (tgl realisasi: " . ($rr['realisasi_tgl'] ?? '?') . ") | tgl_detail: " . $rr['tgl_realisasi_detail'] . " | masuk " . $rr['ra_masuk'] . " pulang " . $rr['ra_keluar'] . "\n";
    }
    // dedupe (logika sama kayak slip)
    $dd = [];
    foreach ($rows as $rr) {
        $dk = $rr['id_karyawan'] . '|' . $rr['tgl_realisasi_detail'];
        $dm = (!empty($rr['realisasi_tgl']) && $rr['realisasi_tgl'] === $rr['tgl_realisasi_detail']) ? 1 : 0;
        if (!isset($dd[$dk]) || $dm > $dd[$dk]['m'] || ($dm === $dd[$dk]['m'] && (int)$rr['id_realisasi_detail'] < (int)$dd[$dk]['row']['id_realisasi_detail'])) $dd[$dk] = ['row' => $rr, 'm' => $dm];
    }
    echo "setelah dedupe: " . count($dd) . " baris\n";
}
echo "\n";

// 3) Total baris karyawan ini di tb_realisasi_detail + tanggal terakhir
$q2 = $koneksi->query("SELECT COUNT(*) AS j, MAX(tgl_realisasi_detail) AS mx, MIN(tgl_realisasi_detail) AS mn FROM tb_realisasi_detail WHERE id_karyawan = '$id'");
$r2 = $q2 ? $q2->fetch_assoc() : null;
echo "== Total di tb_realisasi_detail (id=$id) ==\n";
echo "jumlah: " . ($r2['j'] ?? '?') . " | terlama: " . ($r2['mn'] ?? '?') . " | terbaru: " . ($r2['mx'] ?? '?') . "\n\n";

// 4) Tap mentah di tb_record (PIN karyawan ini) di range yang sama
$q3 = $koneksi->query("SELECT no_absen FROM ms_karyawan WHERE id_karyawan = '$id' LIMIT 1");
$pin = $q3 ? ($q3->fetch_assoc()['no_absen'] ?? '?') : '?';
$q4 = $koneksi->query("SELECT COUNT(*) AS j FROM tb_record WHERE userid = '$pin' AND tgl BETWEEN '$ttgl1' AND '$ttgl2'");
$r4 = $q4 ? $q4->fetch_assoc() : null;
echo "== Tap tb_record (PIN $pin, $ttgl1 s/d $ttgl2) ==\n";
echo "jumlah tap: " . ($r4['j'] ?? '?') . "\n\n";

$e = error_get_last();
echo "error terakhir: " . ($e ? $e['message'] . " @ " . $e['file'] . ":" . $e['line'] : '(tidak ada)') . "\n";
echo "</pre>";
