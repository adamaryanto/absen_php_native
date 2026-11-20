<?php
// Export Excel "Kelola Realisasi Periode" (view-only per OS, hanya untuk halaman periode).
error_reporting(0);
ini_set('display_errors', '0');
while (ob_get_level() > 0) { @ob_end_clean(); }

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../koneksi.php';
require_once __DIR__ . '/../../os_helper.php';
require_once __DIR__ . '/../realisasi/xlsx_mini.php';

if (empty($_SESSION['role'])) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$sos = trim((string)($_GET['sos'] ?? ''));
if ($sos === '' || strlen($sos) > 50) $sos = 'Tanpa OS';
$sdari = trim((string)($_GET['sdari'] ?? ''));
$ssampai = trim((string)($_GET['ssampai'] ?? ''));
if ($sdari === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sdari)) $sdari = date('Y-m-01');
if ($ssampai === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ssampai)) $ssampai = date('Y-m-d');
$idrealisasi = (int)($_GET['id'] ?? 0);
if ($idrealisasi <= 0) {
    http_response_code(400);
    echo 'ID realisasi tidak valid.';
    exit;
}

// Kumpulan nama OS valid
$osNames = [];
$q_osn = $koneksi->query("SELECT OS_DHK FROM ms_os_dhk ORDER BY OS_DHK ASC");
if ($q_osn) { while ($r_osn = $q_osn->fetch_assoc()) $osNames[] = (string)$r_osn['OS_DHK']; }
if (!in_array('Tanpa OS', $osNames, true)) $osNames[] = 'Tanpa OS';

// Role OS: dipaksa OS milik akunnya
if (function_exists('os_is_os') && os_is_os()) {
    $osSaya = '';
    if (function_exists('os_id') && os_id() > 0) {
        $q_me = $koneksi->query("SELECT OS_DHK FROM ms_os_dhk WHERE id_os_dhk = " . (int)os_id() . " LIMIT 1");
        if ($q_me && ($r_me = $q_me->fetch_assoc())) $osSaya = (string)$r_me['OS_DHK'];
    }
    $sos = $osSaya !== '' ? $osSaya : 'Tanpa OS';
}
if (!in_array($sos, $osNames, true)) {
    http_response_code(400);
    echo 'OS tidak valid.';
    exit;
}

// Jalankan kelola.php dalam mode view-only periode + tangkap baris untuk Excel
$FORCE_SOS_KELOLA = $sos;
$__capture_kelola_excel = true;
$_GET['id'] = $idrealisasi;
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
include __DIR__ . '/../realisasi/kelola.php';
$_html = ob_get_clean();
unset($_html);

$rows = (array)($GLOBALS['__kelola_excel'] ?? []);
$info = (array)($GLOBALS['__kelola_excel_info'] ?? ['jml' => 0, 'total' => 0]);

// Judul tanggal & keterangan
$tglTxt = '';
$ketTxt = '';
$qH = $koneksi->query("SELECT tgl_realisasi, keterangan FROM tb_realisasi WHERE id_realisasi = '$idrealisasi' LIMIT 1");
if ($qH && ($rH = $qH->fetch_assoc())) {
    $tglTxt = $rH['tgl_realisasi'] ? date('d/m/Y', strtotime($rH['tgl_realisasi'])) : $tglTxt;
    $ketTxt = (string)$rH['keterangan'];
}

$x = new MiniXlsx();
$sTitle = $x->style(['b' => true, 'sz' => 14]);
$sSub   = $x->style(['sz' => 10, 'color' => '555555']);
$sHead  = $x->style(['b' => true, 'fill' => 'D9E1F2', 'border' => true, 'align' => 'center', 'wrap' => true]);
$sText  = $x->style(['border' => true]);
$sCenter = $x->style(['border' => true, 'align' => 'center']);
$sKtp   = $x->style(['border' => true, 'sz' => 9, 'color' => '666666']);
$sMoney = $x->style(['border' => true, 'num' => 'rp', 'align' => 'right']);
$sTotL  = $x->style(['b' => true, 'fill' => 'FFF2CC', 'border' => true]);
$sTotV  = $x->style(['b' => true, 'fill' => 'FFF2CC', 'border' => true, 'num' => 'rp', 'align' => 'right']);

$sh = $x->sheet('KARYAWAN');
$r = 1;
$x->cell($sh, $r, 1, 'KELOLA REALISASI - OS ' . strtoupper($sos), $sTitle); $x->merge($sh, $r, 1, $r, 16); $r++;
$sub = 'Tanggal realisasi: ' . $tglTxt;
if ($ketTxt !== '') $sub .= ' - ' . $ketTxt;
$x->cell($sh, $r, 1, $sub, $sSub); $x->merge($sh, $r, 1, $r, 16); $r++;
$r++;

$cols = ['No', 'Nama Sesuai KTP', 'KTP', 'OS/DHK', 'Golongan', 'Posisi', 'Jam Kerja', 'Jam Masuk', 'Istirahat Masuk', 'Jam Pulang', 'Detail Borongan', 'Hasil Kerja', 'Upah', 'Potongan', 'Lembur', 'Upah Dibayar'];
$c = 1;
foreach ($cols as $col) { $x->cell($sh, $r, $c, $col, $sHead); $c++; }
$r++;

$n = 1;
foreach ($rows as $d) {
    $x->cell($sh, $r, 1, $n, $sCenter);
    $x->cell($sh, $r, 2, (string)$d['nama'], $sText);
    $x->cell($sh, $r, 3, (string)$d['ktp'], $sText);
    $x->cell($sh, $r, 4, (string)$d['os'], $sCenter);
    $x->cell($sh, $r, 5, (string)$d['gol'], $sCenter);
    $x->cell($sh, $r, 6, (string)$d['posisi'], $sText);
    $x->cell($sh, $r, 7, (string)$d['jam_kerja'], $sCenter);
    $x->cell($sh, $r, 8, (string)$d['jam_masuk'], $sCenter);
    $x->cell($sh, $r, 9, (string)$d['istirahat'], $sCenter);
    $x->cell($sh, $r, 10, (string)$d['pulang'], $sCenter);
    $x->cell($sh, $r, 11, (string)$d['detail'], $sText);
    $x->cell($sh, $r, 12, (string)$d['hasil'], $sText);
    $x->cell($sh, $r, 13, (int)$d['upah'], $sMoney);
    $x->cell($sh, $r, 14, (int)$d['potongan'], $sMoney);
    $x->cell($sh, $r, 15, (int)$d['lembur'], $sMoney);
    $x->cell($sh, $r, 16, (int)$d['dibayar'], $sMoney);
    $r++;
    $n++;
}
if (!count($rows)) {
    $x->cell($sh, $r, 1, 'Tidak ada data karyawan untuk OS ini.', $sText); $x->merge($sh, $r, 1, $r, 16); $r++;
} else {
    $x->cell($sh, $r, 1, 'TOTAL REALISASI UPAH (' . ($n - 1) . ' KARYAWAN)', $sTotL); $x->merge($sh, $r, 1, $r, 15);
    $x->cell($sh, $r, 16, (int)($info['total'] ?? 0), $sTotV);
}
$x->autofit($sh);

$fn = 'Kelola-OS-' . preg_replace('/[^A-Za-z0-9]+/', '-', $sos) . '-' . ($tglTxt !== '' ? str_replace('/', '-', $tglTxt) : $idrealisasi) . '.xlsx';
$x->send($fn);
