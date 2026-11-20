<?php
// Export Excel Rekap Pengeluaran per tanggal.
// jenis=semua (semua kategori, multi-sheet) | os | borongan | boneless | mobil.
error_reporting(0);
ini_set('display_errors', '0');
while (ob_get_level() > 0) { @ob_end_clean(); }

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../koneksi.php';
require_once __DIR__ . '/../../os_helper.php';
require_once __DIR__ . '/../realisasi/xlsx_mini.php';
require_once __DIR__ . '/_rekap_lib.php';

if (empty($_SESSION['role'])) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$jenis = trim((string)($_GET['jenis'] ?? 'semua'));
if (!in_array($jenis, ['semua', 'os', 'borongan', 'boneless', 'mobil'], true)) $jenis = 'semua';
$tgl = rekap_tgl();
$osRows = rekap_os($koneksi, $tgl);
$borRows = rekap_borongan($koneksi, $tgl);
$blRows = rekap_boneless($koneksi, $tgl);
$mobRows = rekap_mobil($koneksi, $tgl);
$tot = rekap_totals($osRows, $borRows, $blRows, $mobRows);

$x = new MiniXlsx();
$sTitle = $x->style(['b' => true, 'sz' => 14]);
$sSub   = $x->style(['sz' => 10, 'color' => '555555']);
$sHead  = $x->style(['b' => true, 'fill' => 'D9E1F2', 'border' => true, 'align' => 'center']);
$sText  = $x->style(['border' => true]);
$sCenter = $x->style(['border' => true, 'align' => 'center']);
$sMoney = $x->style(['border' => true, 'num' => 'rp', 'align' => 'right']);
$sTotL  = $x->style(['b' => true, 'fill' => 'FFF2CC', 'border' => true]);
$sTotV  = $x->style(['b' => true, 'fill' => 'FFF2CC', 'border' => true, 'num' => 'rp', 'align' => 'right']);
$sTotC  = $x->style(['b' => true, 'fill' => 'FFF2CC', 'border' => true, 'align' => 'center']);
$tglCap = date('d/m/Y', strtotime($tgl));

function sheet_os($x, $osRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV, $sTotC) {
    $sh = $x->sheet('OS');
    $r = 1;
    $x->cell($sh, $r, 1, 'BIAYA OS ' . $tglCap, $sTitle); $x->merge($sh, $r, 1, $r, 3); $r++;
    $x->cell($sh, $r, 1, 'Gaji semua jenis - Tanggal ' . $tglCap, $sSub); $x->merge($sh, $r, 1, $r, 3); $r++;
    $r++;
    $x->cell($sh, $r, 1, 'OS', $sHead); $x->cell($sh, $r, 2, 'Jumlah Karyawan', $sHead); $x->cell($sh, $r, 3, 'Total Upah', $sHead); $r++;
    if (!count($osRows)) { $x->cell($sh, $r, 1, 'Tidak ada data OS pada tanggal ini.', $sText); $x->merge($sh, $r, 1, $r, 3); $r++; }
    foreach ($osRows as $row) {
        $x->cell($sh, $r, 1, (string)$row['os'], $sText);
        $x->cell($sh, $r, 2, (int)$row['n'], $sCenter);
        $x->cell($sh, $r, 3, (int)$row['total'], $sMoney);
        $r++;
    }
    if (count($osRows)) {
        $x->cell($sh, $r, 1, 'TOTAL OS', $sTotL);
        $x->cell($sh, $r, 2, (int)$tot['os_n'], $sTotC);
        $x->cell($sh, $r, 3, (int)$tot['os'], $sTotV);
    }
    $x->autofit($sh);
}

function sheet_borongan($x, $borRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV) {
    $sh = $x->sheet('BORONGAN');
    $r = 1;
    $x->cell($sh, $r, 1, 'BIAYA BORONGAN', $sTitle); $x->merge($sh, $r, 1, $r, 4); $r++;
    $x->cell($sh, $r, 1, 'Upah borongan karyawan - Tanggal ' . $tglCap, $sSub); $x->merge($sh, $r, 1, $r, 4); $r++;
    $r++;
    $x->cell($sh, $r, 1, 'No', $sHead); $x->cell($sh, $r, 2, 'Nama', $sHead); $x->cell($sh, $r, 3, 'OS', $sHead); $x->cell($sh, $r, 4, 'Total Borongan', $sHead); $r++;
    if (!count($borRows)) { $x->cell($sh, $r, 1, 'Tidak ada data borongan pada tanggal ini.', $sText); $x->merge($sh, $r, 1, $r, 4); $r++; }
    $n = 1;
    foreach ($borRows as $row) {
        $x->cell($sh, $r, 1, $n, $sCenter);
        $x->cell($sh, $r, 2, (string)$row['nama'], $sText);
        $x->cell($sh, $r, 3, (string)$row['os'], $sCenter);
        $x->cell($sh, $r, 4, (int)$row['total'], $sMoney);
        $r++; $n++;
    }
    if (count($borRows)) {
        $x->cell($sh, $r, 1, 'TOTAL BORONGAN', $sTotL); $x->merge($sh, $r, 1, $r, 3);
        $x->cell($sh, $r, 4, (int)$tot['bor'], $sTotV);
    }
    $x->autofit($sh);
}

function sheet_boneless($x, $blRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV) {
    $sh = $x->sheet('BONELESS');
    $r = 1;
    $x->cell($sh, $r, 1, 'BIAYA BONELESS', $sTitle); $x->merge($sh, $r, 1, $r, 6); $r++;
    $x->cell($sh, $r, 1, 'Item + truck - Tanggal ' . $tglCap, $sSub); $x->merge($sh, $r, 1, $r, 6); $r++;
    $r++;
    $x->cell($sh, $r, 1, 'No', $sHead); $x->cell($sh, $r, 2, 'Keterangan', $sHead); $x->cell($sh, $r, 3, 'Jumlah Mobil', $sHead); $x->cell($sh, $r, 4, 'Biaya Mobil', $sHead); $x->cell($sh, $r, 5, 'Biaya Item', $sHead); $x->cell($sh, $r, 6, 'Total', $sHead); $r++;
    if (!count($blRows)) { $x->cell($sh, $r, 1, 'Tidak ada data boneless pada tanggal ini.', $sText); $x->merge($sh, $r, 1, $r, 6); $r++; }
    $n = 1;
    foreach ($blRows as $row) {
        $x->cell($sh, $r, 1, $n, $sCenter);
        $x->cell($sh, $r, 2, (string)$row['ket'], $sText);
        $x->cell($sh, $r, 3, (int)$row['mobil'], $sCenter);
        $x->cell($sh, $r, 4, (int)$row['biaya_mobil'], $sMoney);
        $x->cell($sh, $r, 5, (int)$row['biaya_item'], $sMoney);
        $x->cell($sh, $r, 6, (int)$row['total'], $sMoney);
        $r++; $n++;
    }
    if (count($blRows)) {
        $x->cell($sh, $r, 1, 'TOTAL BONELESS', $sTotL); $x->merge($sh, $r, 1, $r, 2);
        $x->cell($sh, $r, 3, (int)array_sum(array_column($blRows, 'mobil')), $sTotL);
        $x->cell($sh, $r, 4, (int)$tot['bl_mobil'], $sTotV);
        $x->cell($sh, $r, 5, (int)$tot['bl_item'], $sTotV);
        $x->cell($sh, $r, 6, (int)$tot['bl'], $sTotV);
    }
    $x->autofit($sh);
}

function sheet_mobil($x, $mobRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV, $sTotC) {
    $sh = $x->sheet('PERMOBIL');
    $r = 1;
    $x->cell($sh, $r, 1, 'BIAYA PERMOBIL', $sTitle); $x->merge($sh, $r, 1, $r, 4); $r++;
    $x->cell($sh, $r, 1, 'Dipakai per truck - Tanggal ' . $tglCap, $sSub); $x->merge($sh, $r, 1, $r, 4); $r++;
    $r++;
    $x->cell($sh, $r, 1, 'No', $sHead); $x->cell($sh, $r, 2, 'Mobil', $sHead); $x->cell($sh, $r, 3, 'Frekuensi', $sHead); $x->cell($sh, $r, 4, 'Total Biaya', $sHead); $r++;
    if (!count($mobRows)) { $x->cell($sh, $r, 1, 'Tidak ada truck pada tanggal ini.', $sText); $x->merge($sh, $r, 1, $r, 4); $r++; }
    $n = 1;
    foreach ($mobRows as $row) {
        $x->cell($sh, $r, 1, $n, $sCenter);
        $x->cell($sh, $r, 2, (string)$row['nama'], $sText);
        $x->cell($sh, $r, 3, (int)$row['freq'], $sCenter);
        $x->cell($sh, $r, 4, (int)$row['total'], $sMoney);
        $r++; $n++;
    }
    if (count($mobRows)) {
        $x->cell($sh, $r, 1, 'TOTAL PERMOBIL', $sTotL); $x->merge($sh, $r, 1, $r, 2);
        $x->cell($sh, $r, 3, (int)$tot['mob_freq'], $sTotC);
        $x->cell($sh, $r, 4, (int)$tot['mob'], $sTotV);
    }
    $x->autofit($sh);
}

function sheet_ringkasan($x, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sMoney, $sTotL, $sTotV) {
    $sh = $x->sheet('RINGKASAN');
    $r = 1;
    $x->cell($sh, $r, 1, 'REKAP PENGELUARAN', $sTitle); $x->merge($sh, $r, 1, $r, 2); $r++;
    $x->cell($sh, $r, 1, 'Tanggal ' . $tglCap . ' - Biaya OS sudah termasuk Biaya Borongan', $sSub); $x->merge($sh, $r, 1, $r, 2); $r++;
    $r++;
    $x->cell($sh, $r, 1, 'Kategori', $sHead); $x->cell($sh, $r, 2, 'Total', $sHead); $r++;
    $x->cell($sh, $r, 1, 'Biaya OS (gaji semua jenis)', $sText); $x->cell($sh, $r, 2, (int)$tot['os'], $sMoney); $r++;
    $x->cell($sh, $r, 1, 'Biaya Borongan (upah borongan - sudah termasuk di Biaya OS)', $sText); $x->cell($sh, $r, 2, (int)$tot['bor'], $sMoney); $r++;
    $x->cell($sh, $r, 1, 'Biaya Boneless (item + truck)', $sText); $x->cell($sh, $r, 2, (int)$tot['bl'], $sMoney); $r++;
    $x->cell($sh, $r, 1, 'Biaya permobil (dipakai truck)', $sText); $x->cell($sh, $r, 2, (int)$tot['mob'], $sMoney); $r++;
    $grandTotal = (int)$tot['os'] + (int)$tot['bl'] + (int)$tot['mob']; // 2026-11-17: borongan SUDAH termasuk di Biaya OS, jangan ditambah lagi (dulu double-count).
    $x->cell($sh, $r, 1, 'TOTAL KESELURUHAN', $sTotL); $x->cell($sh, $r, 2, $grandTotal, $sTotV); $r++;
    $x->autofit($sh);
}

$sheetMap = [
    'semua' => function () use ($x, $osRows, $borRows, $blRows, $mobRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV, $sTotC) {
        sheet_ringkasan($x, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sMoney, $sTotL, $sTotV);
        sheet_os($x, $osRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV, $sTotC);
        sheet_borongan($x, $borRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV);
        sheet_boneless($x, $blRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV);
        sheet_mobil($x, $mobRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV, $sTotC);
    },
    'os' => function () use ($x, $osRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV, $sTotC) {
        sheet_os($x, $osRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV, $sTotC);
    },
    'borongan' => function () use ($x, $borRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV) {
        sheet_borongan($x, $borRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV);
    },
    'boneless' => function () use ($x, $blRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV) {
        sheet_boneless($x, $blRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV);
    },
    'mobil' => function () use ($x, $mobRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV, $sTotC) {
        sheet_mobil($x, $mobRows, $tot, $tglCap, $sTitle, $sSub, $sHead, $sText, $sCenter, $sMoney, $sTotL, $sTotV, $sTotC);
    },
];
$sheetMap[$jenis]();

$fnName = ($jenis === 'semua') ? 'Rekap-Pengeluaran' : ('Rekap-Biaya-' . ['os' => 'OS', 'borongan' => 'Borongan', 'boneless' => 'Boneless', 'mobil' => 'Permobil'][$jenis]);
$x->send($fnName . '-' . $tgl . '.xlsx');
