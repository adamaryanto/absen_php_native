<?php
// Export Excel "Detail OS" daftar realisasi per tanggal (OS + rentang tanggal dipilih).
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
if ($sdari > $ssampai) { $tmp = $sdari; $sdari = $ssampai; $ssampai = $tmp; }

$osNames = [];
$q_osn = $koneksi->query("SELECT OS_DHK FROM ms_os_dhk ORDER BY OS_DHK ASC");
if ($q_osn) { while ($r_osn = $q_osn->fetch_assoc()) $osNames[] = (string)$r_osn['OS_DHK']; }
if (!in_array('Tanpa OS', $osNames, true)) $osNames[] = 'Tanpa OS';

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

$osEff = 0;
if ($sos !== 'Tanpa OS') {
    $q_id = $koneksi->query("SELECT id_os_dhk FROM ms_os_dhk WHERE OS_DHK = '" . $koneksi->real_escape_string($sos) . "' LIMIT 1");
    if ($q_id && ($r_id = $q_id->fetch_assoc())) $osEff = (int)$r_id['id_os_dhk'];
}
$sdE = $koneksi->real_escape_string($sdari);
$seE = $koneksi->real_escape_string($ssampai);
$osCond2 = "COALESCE(NULLIF(BK2.id_os_dhk, 0), RD2.id_os_dhk) = $osEff";
$person_key_jml = "IF(RD.id_karyawan = 0, CONCAT('M:', IFNULL(RD.nama_karyawan_manual, '')), CONCAT('K:', RD.id_karyawan))";

$rows = [];
$tot = ['jml' => 0, 'ttl' => 0, 'p_telat' => 0, 'p_istirahat' => 0, 'p_pulang' => 0, 'p_log' => 0, 'potlainnya' => 0];
$totOrang = 0;
try {
    $tampil = $koneksi->query("SELECT A.id_realisasi, A.tgl_realisasi, A.keterangan, A.status_realisasi,
            S.ttl, S.potlainnya, S.p_telat, S.p_istirahat, S.p_pulang, S.p_log,
            (SELECT COUNT(DISTINCT $person_key_jml)
             FROM tb_realisasi_detail RD
             LEFT JOIN ms_karyawan BKJ ON BKJ.id_karyawan = RD.id_karyawan
             LEFT JOIN tb_rkk_detail RKD ON RD.id_rkk_detail = RKD.id_rkk_detail
             WHERE RD.id_realisasi = A.id_realisasi AND (RKD.id_rkk_detail IS NULL OR RKD.status_rkk != 'Digantikan')
               AND COALESCE(NULLIF(BKJ.id_os_dhk, 0), RD.id_os_dhk) = $osEff
            ) AS jml
        FROM tb_realisasi A
        LEFT JOIN (
            SELECT RD.id_realisasi AS sid,
                SUM((IFNULL(RD.r_upah, 0) + IFNULL(RD.lembur, 0)) -
                    (IFNULL(RD.r_potongan_telat, 0) +
                     IFNULL(RD.r_potongan_istirahat_awal, 0) +
                     IFNULL(RD.r_potongan_istirahat_telat, 0) +
                     IFNULL(RD.r_potongan_pulang, 0) +
                     IFNULL(RD.r_potongan_tidak_lengkap, 0) +
                     IFNULL(RD.r_potongan_lainnya, 0))) AS ttl,
                SUM(IFNULL(RD.r_potongan_lainnya, 0)) AS potlainnya,
                SUM(IFNULL(RD.r_potongan_telat, 0)) AS p_telat,
                SUM(IFNULL(RD.r_potongan_istirahat_awal, 0) + IFNULL(RD.r_potongan_istirahat_telat, 0)) AS p_istirahat,
                SUM(IFNULL(RD.r_potongan_pulang, 0)) AS p_pulang,
                SUM(IFNULL(RD.r_potongan_tidak_lengkap, 0)) AS p_log
            FROM tb_realisasi_detail RD
            LEFT JOIN ms_karyawan BK ON BK.id_karyawan = RD.id_karyawan
            LEFT JOIN tb_rkk_detail RKD ON RD.id_rkk_detail = RKD.id_rkk_detail
            WHERE (RKD.id_rkk_detail IS NULL OR RKD.status_rkk != 'Digantikan')
              AND RD.tgl_realisasi_detail BETWEEN '$sdE' AND '$seE'
              AND COALESCE(NULLIF(BK.id_os_dhk, 0), RD.id_os_dhk) = $osEff
            GROUP BY RD.id_realisasi
        ) S ON S.sid = A.id_realisasi
        WHERE A.tgl_realisasi BETWEEN '$sdE' AND '$seE'
          AND EXISTS (
              SELECT 1 FROM tb_realisasi_detail RD2
              LEFT JOIN ms_karyawan BK2 ON BK2.id_karyawan = RD2.id_karyawan
              WHERE RD2.id_realisasi = A.id_realisasi AND $osCond2
          )
        ORDER BY A.tgl_realisasi ASC, A.id_realisasi ASC");
    while ($tampil && ($d = $tampil->fetch_assoc())) {
        $rows[] = $d;
        $tot['ttl'] += (int)round((float)($d['ttl'] ?? 0));
        $tot['p_telat'] += (int)round((float)($d['p_telat'] ?? 0));
        $tot['p_istirahat'] += (int)round((float)($d['p_istirahat'] ?? 0));
        $tot['p_pulang'] += (int)round((float)($d['p_pulang'] ?? 0));
        $tot['p_log'] += (int)round((float)($d['p_log'] ?? 0));
        $tot['potlainnya'] += (int)round((float)($d['potlainnya'] ?? 0));
    }
    $tot['jml'] = count($rows);
    $qOr = $koneksi->query("SELECT COUNT(DISTINCT $person_key_jml) AS n FROM tb_realisasi_detail RD LEFT JOIN ms_karyawan BK ON BK.id_karyawan = RD.id_karyawan LEFT JOIN tb_rkk_detail RKD ON RD.id_rkk_detail = RKD.id_rkk_detail WHERE (RKD.id_rkk_detail IS NULL OR RKD.status_rkk != 'Digantikan') AND RD.tgl_realisasi_detail BETWEEN '$sdE' AND '$seE' AND COALESCE(NULLIF(BK.id_os_dhk,0), RD.id_os_dhk) = $osEff");
    if ($qOr && ($rOr = $qOr->fetch_assoc())) $totOrang = (int)$rOr['n'];
} catch (Throwable $e) { $rows = []; }

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

$label = 'OS ' . $sos . ' - Tanggal ' . date('d/m/Y', strtotime($sdari)) . ' s/d ' . date('d/m/Y', strtotime($ssampai));
$sh = $x->sheet('REALISASI');
$r = 1;
$x->cell($sh, $r, 1, 'REALISASI OS ' . strtoupper($sos), $sTitle); $x->merge($sh, $r, 1, $r, 8); $r++;
$x->cell($sh, $r, 1, $label, $sSub); $x->merge($sh, $r, 1, $r, 8); $r++;
$r++;
$heads = ['No', 'Tanggal', 'Keterangan', 'Jumlah Karyawan', 'Total Upah', 'Telat', 'Denda Pulang', 'Denda Tidak Absen'];
$c = 1;
foreach ($heads as $h2) { $x->cell($sh, $r, $c, $h2, $sHead); $c++; }
$r++;

if (!count($rows)) {
    $x->cell($sh, $r, 1, 'Tidak ada realisasi pada filter ini.', $sText); $x->merge($sh, $r, 1, $r, 8); $r++;
}
$n = 1;
foreach ($rows as $d) {
    $x->cell($sh, $r, 1, $n, $sCenter);
    $x->cell($sh, $r, 2, date('d/m/Y', strtotime($d['tgl_realisasi'])), $sCenter);
    $x->cell($sh, $r, 3, (string)$d['keterangan'], $sText);
    $x->cell($sh, $r, 4, (int)$d['jml'], $sCenter);
    $x->cell($sh, $r, 5, (int)round((float)($d['ttl'] ?? 0)), $sMoney);
    $x->cell($sh, $r, 6, (int)round((float)($d['p_telat'] ?? 0)), $sMoney);
    $x->cell($sh, $r, 7, (int)round((float)($d['p_pulang'] ?? 0)), $sMoney);
    $x->cell($sh, $r, 8, (int)round((float)($d['p_log'] ?? 0)), $sMoney);
    $r++;
    $n++;
}
if (count($rows)) {
    $x->cell($sh, $r, 1, 'TOTAL (' . $tot['jml'] . ' tanggal)', $sTotL);
    $x->merge($sh, $r, 1, $r, 3);
    $x->cell($sh, $r, 4, $totOrang, $sTotC);
    $x->cell($sh, $r, 5, $tot['ttl'], $sTotV);
    $x->cell($sh, $r, 6, $tot['p_telat'], $sTotV);
    $x->cell($sh, $r, 7, $tot['p_pulang'], $sTotV);
    $x->cell($sh, $r, 8, $tot['p_log'], $sTotV);
}
$x->autofit($sh);

$fn = 'Realisasi-OS-' . preg_replace('/[^A-Za-z0-9]+/', '-', $sos) . '-' . $sdari . '_' . $ssampai . '.xlsx';
$x->send($fn);
