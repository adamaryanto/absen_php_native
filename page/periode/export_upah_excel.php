<?php
// Export Excel ringkasan upah per periode  -  1 sheet per OS + sheet RINGKASAN.
// Mendukung filter OS, data Borongan/Non-Borongan, dan total hari kerja.
error_reporting(0);
ini_set('display_errors', '0');
while (ob_get_level() > 0) { @ob_end_clean(); }

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../koneksi.php';
require_once __DIR__ . '/../../os_helper.php';
require_once __DIR__ . '/../realisasi/xlsx_mini.php';

if (empty($_SESSION['role']) || (function_exists('os_is_os') && os_is_os())) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$PERIODE_OPTIONS = ['Harian', '1 Minggu', '2 Minggu', '3 Minggu', '4 Minggu'];
$GOL_OPTIONS = ['semua' => 'Semua', 'non' => 'Non-Borongan', 'borongan' => 'Borongan'];

$speriode = trim((string)($_GET['speriode'] ?? 'Harian'));
if (!in_array($speriode, $PERIODE_OPTIONS, true)) $speriode = 'Harian';
$sgol = trim((string)($_GET['sgol'] ?? 'semua'));
if (!isset($GOL_OPTIONS[$sgol])) $sgol = 'semua';
$sos = trim((string)($_GET['sos'] ?? 'semua'));
if ($sos !== 'semua' && !ctype_digit($sos)) $sos = 'semua';
$q = trim((string)($_GET['q'] ?? ''));
$sdari = trim((string)($_GET['sdari'] ?? ''));
$ssampai = trim((string)($_GET['ssampai'] ?? ''));
if ($sdari === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sdari)) $sdari = date('Y-m-01');
if ($ssampai === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ssampai)) $ssampai = date('Y-m-d');
if ($sdari > $ssampai) { $tmp = $sdari; $sdari = $ssampai; $ssampai = $tmp; }

$sdE = $koneksi->real_escape_string($sdari);
$seE = $koneksi->real_escape_string($ssampai);
$dateCond = "d.tgl_realisasi_detail BETWEEN '$sdE' AND '$seE'
    AND (rd.id_rkk_detail IS NULL OR rd.status_rkk <> 'Digantikan')
    AND d.id_karyawan <> 0";

$hasBor = false;
$ckBor = $koneksi->query("SHOW TABLES LIKE 'tb_realisasi_borongan'");
if ($ckBor && $ckBor->num_rows) $hasBor = true;
$borJoin = $hasBor
    ? "LEFT JOIN (SELECT id_realisasi_detail, SUM(ROUND(qty * tarif)) AS total_bor FROM tb_realisasi_borongan GROUP BY id_realisasi_detail) bt ON bt.id_realisasi_detail = d.id_realisasi_detail"
    : "LEFT JOIN (SELECT NULL AS id_realisasi_detail, 0 AS total_bor) bt ON 1 = 0";

$agg = [];
try {
    $normExpr = "GREATEST(IFNULL(d.r_upah,0) + IFNULL(d.lembur,0)
        - IF(IFNULL(d.r_upah,0) = 0, 0,
            (IFNULL(d.r_potongan_telat,0) + IFNULL(d.r_potongan_istirahat_awal,0) + IFNULL(d.r_potongan_istirahat_telat,0)
             + IFNULL(d.r_potongan_pulang,0) + IFNULL(d.r_potongan_tidak_lengkap,0) + IFNULL(d.r_potongan_lainnya,0))), 0)";
    $borExpr = "GREATEST(IFNULL(bt.total_bor,0) - IFNULL(d.r_potongan_telat,0) - IFNULL(d.r_potongan_pulang,0), 0)";
    $q_agg = $koneksi->query("SELECT d.id_karyawan,
            SUM(IF(IFNULL(bt.total_bor,0) > 0, 0, $normExpr)) AS non_total,
            COUNT(DISTINCT IF(IFNULL(bt.total_bor,0) > 0, NULL, d.tgl_realisasi_detail)) AS non_hari,
            SUM(IF(IFNULL(bt.total_bor,0) > 0, $borExpr, 0)) AS bor_total,
            COUNT(DISTINCT IF(IFNULL(bt.total_bor,0) > 0, d.tgl_realisasi_detail, NULL)) AS bor_hari,
            COUNT(DISTINCT d.tgl_realisasi_detail) AS all_hari
        FROM tb_realisasi_detail d
        $borJoin
        LEFT JOIN tb_rkk_detail rd ON rd.id_rkk_detail = d.id_rkk_detail
        WHERE $dateCond
        GROUP BY d.id_karyawan");
    if ($q_agg) {
        while ($r = $q_agg->fetch_assoc()) {
            $agg[(int)$r['id_karyawan']] = [
                'non_total' => (int)round((float)$r['non_total']),
                'non_hari' => (int)$r['non_hari'],
                'bor_total' => (int)round((float)$r['bor_total']),
                'bor_hari' => (int)$r['bor_hari'],
                'all_hari' => (int)$r['all_hari'],
            ];
        }
    }
} catch (Throwable $e) { $agg = []; }

$borDetail = [];
if ($hasBor) {
    try {
        $q_bd = $koneksi->query("SELECT d.id_karyawan, b.nama_pekerjaan, b.satuan,
                SUM(b.qty) AS qty, SUM(ROUND(b.qty * b.tarif)) AS upah
            FROM tb_realisasi_detail d
            JOIN tb_realisasi_borongan b ON b.id_realisasi_detail = d.id_realisasi_detail
            LEFT JOIN tb_rkk_detail rd ON rd.id_rkk_detail = d.id_rkk_detail
            WHERE $dateCond
            GROUP BY d.id_karyawan, b.nama_pekerjaan, b.satuan
            ORDER BY d.id_karyawan ASC, b.nama_pekerjaan ASC");
        if ($q_bd) {
            while ($b = $q_bd->fetch_assoc()) {
                $borDetail[(int)$b['id_karyawan']][] = [
                    'nama' => (string)$b['nama_pekerjaan'],
                    'satuan' => (string)$b['satuan'],
                    'qty' => (float)$b['qty'],
                    'upah' => (int)round((float)$b['upah']),
                ];
            }
        }
    } catch (Throwable $e) { $borDetail = []; }
}

$where = [];
$where[] = "COALESCE(NULLIF(TRIM(k.periode),''),'Harian') = '" . $koneksi->real_escape_string($speriode) . "'";
if ($sos !== 'semua') $where[] = "k.id_os_dhk = " . (int)$sos;
if ($q !== '') {
    $qe = $koneksi->real_escape_string($q);
    $where[] = "(k.nama_karyawan LIKE '%$qe%' OR k.no_absen LIKE '%$qe%')";
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

$rows = [];
// 2026-11-17 (Reza): karyawan yang dihapus dari master tetap tampil di Periode
// (pakai snapshot nama/no_absen di tb_realisasi_detail). Periode aslinya ikut
// hilang saat dihapus, jadi baris snapshot masuk ke 'Harian' saja (default sistem).
$rows_sql = "SELECT k.id_karyawan, k.no_absen, k.nama_karyawan, k.no_ktp,
        COALESCE(NULLIF(TRIM(k.periode),''),'Harian') AS periode,
        COALESCE(NULLIF(TRIM(o.OS_DHK),''),'Tanpa OS') AS os
    FROM ms_karyawan k
    LEFT JOIN ms_os_dhk o ON o.id_os_dhk = k.id_os_dhk
    $where_sql";
if ($speriode === 'Harian') {
    $__ck_snap = @$koneksi->query("SHOW COLUMNS FROM tb_realisasi_detail LIKE 'nama_karyawan_manual'");
    if ($__ck_snap && $__ck_snap->num_rows > 0) {
        $snap_where = [];
        if ($sos !== 'semua') $snap_where[] = "d.id_os_dhk = " . (int)$sos;
        if ($q !== '') { $qe2 = $koneksi->real_escape_string($q); $snap_where[] = "(d.nama_karyawan_manual LIKE '%$qe2%' OR d.no_absen_manual LIKE '%$qe2%')"; }
        $snap_sql = $snap_where ? ' AND ' . implode(' AND ', $snap_where) : '';
        $rows_sql .= " UNION ALL
        SELECT d.id_karyawan, d.no_absen_manual AS no_absen, d.nama_karyawan_manual AS nama_karyawan, '' AS no_ktp,
            'Harian' AS periode,
            COALESCE(NULLIF(TRIM(o2.OS_DHK),''),'Tanpa OS') AS os
        FROM (SELECT id_karyawan, MAX(no_absen_manual) AS no_absen_manual, MAX(nama_karyawan_manual) AS nama_karyawan_manual, MAX(id_os_dhk) AS id_os_dhk FROM tb_realisasi_detail WHERE nama_karyawan_manual IS NOT NULL AND TRIM(nama_karyawan_manual) <> '' GROUP BY id_karyawan) d
        LEFT JOIN ms_karyawan k2 ON k2.id_karyawan = d.id_karyawan
        LEFT JOIN ms_os_dhk o2 ON o2.id_os_dhk = d.id_os_dhk
        WHERE k2.id_karyawan IS NULL" . $snap_sql;
    }
}
$rows_sql .= " ORDER BY os ASC, nama_karyawan ASC";
$q_rows = $koneksi->query($rows_sql);
if ($q_rows) {
    while ($r = $q_rows->fetch_assoc()) {
        $id = (int)$r['id_karyawan'];
        $a = $agg[$id] ?? ['non_total' => 0, 'non_hari' => 0, 'bor_total' => 0, 'bor_hari' => 0, 'all_hari' => 0];
        if ($sgol === 'borongan') {
            if ($a['bor_hari'] <= 0) continue;
            $r['total'] = $a['bor_total'];
            $r['hari'] = $a['bor_hari'];
        } elseif ($sgol === 'non') {
            if ($a['non_hari'] <= 0) continue;
            $r['total'] = $a['non_total'];
            $r['hari'] = $a['non_hari'];
        } else {
            if ($a['all_hari'] <= 0) continue;
            $r['total'] = $a['non_total'] + $a['bor_total'];
            $r['hari'] = $a['all_hari'];
        }
        $r['bor_detail'] = ($sgol === 'non') ? [] : ($borDetail[$id] ?? []);
        $rows[] = $r;
    }
}

$byOs = [];
foreach ($rows as $r) $byOs[(string)$r['os']][] = $r;

$used = [];
function upah_sheet_name($name, &$used) {
    $n = preg_replace('/[\[\]\:\*\?\/\\\\]/', '', (string)$name);
    $n = trim($n);
    if ($n === '') $n = 'OS';
    $n = mb_substr($n, 0, 31);
    $base = $n; $i = 2;
    while (isset($used[strtoupper($n)])) {
        $suf = ' (' . $i . ')';
        $n = mb_substr($base, 0, 31 - mb_strlen($suf)) . $suf;
        $i++;
    }
    $used[strtoupper($n)] = true;
    return $n;
}

$x = new MiniXlsx();
$sTitle  = $x->style(['b' => true, 'sz' => 14]);
$sSub    = $x->style(['sz' => 10, 'color' => '555555']);
$sHead   = $x->style(['b' => true, 'fill' => 'D9E1F2', 'border' => true, 'align' => 'center']);
$sText   = $x->style(['border' => true]);
$sKtp    = $x->style(['border' => true, 'sz' => 9, 'color' => '666666']);
$sTextR  = $x->style(['border' => true, 'align' => 'right']);
$sCenter = $x->style(['border' => true, 'align' => 'center']);
$sMoney  = $x->style(['border' => true, 'num' => 'rp', 'align' => 'right']);
$sTotLbl = $x->style(['b' => true, 'fill' => 'FFF2CC', 'border' => true]);
$sTotVal = $x->style(['b' => true, 'fill' => 'FFF2CC', 'border' => true, 'num' => 'rp', 'align' => 'right']);
$sTotCtr = $x->style(['b' => true, 'fill' => 'FFF2CC', 'border' => true, 'align' => 'center']);
$sQtyTxt = $x->style(['border' => true, 'align' => 'right']);
$sSubTot = $x->style(['b' => true, 'fill' => 'FFF9E0', 'border' => true]);
$sSubVal = $x->style(['b' => true, 'fill' => 'FFF9E0', 'border' => true, 'num' => 'rp', 'align' => 'right']);

$label_periode = 'Periode: ' . date('d-m-Y', strtotime($sdari)) . ' s/d ' . date('d-m-Y', strtotime($ssampai));
$label_filter = 'Jenis periode: ' . $speriode . '  -  Data: ' . $GOL_OPTIONS[$sgol];

// Sheet RINGKASAN
$sh = $x->sheet('RINGKASAN');
$r = 1;
$x->cell($sh, $r, 1, 'RINGKASAN UPAH ' . strtoupper($speriode), $sTitle); $x->merge($sh, $r, 1, $r, 4); $r++;
$x->cell($sh, $r, 1, $label_periode . '  -  ' . $label_filter, $sSub); $x->merge($sh, $r, 1, $r, 4); $r++;
$r++;
$x->cell($sh, $r, 1, 'OS', $sHead);
$x->cell($sh, $r, 2, 'Jumlah Karyawan', $sHead);
$x->cell($sh, $r, 3, 'Total Hari Kerja', $sHead);
$x->cell($sh, $r, 4, 'Total Upah', $sHead);
$r++;
$grand = 0; $totalKaryawan = 0; $totalHari = 0;
if (!count($byOs)) {
    $x->cell($sh, $r, 1, 'Tidak ada data upah pada filter ini.', $sText); $x->merge($sh, $r, 1, $r, 4); $r++;
}
foreach ($byOs as $os => $list) {
    $sub = 0; $subHari = 0;
    foreach ($list as $lr) { $sub += (int)$lr['total']; $subHari += (int)$lr['hari']; }
    $grand += $sub; $totalKaryawan += count($list); $totalHari += $subHari;
    $x->cell($sh, $r, 1, (string)$os, $sText);
    $x->cell($sh, $r, 2, count($list), $sTextR);
    $x->cell($sh, $r, 3, $subHari, $sTextR);
    $x->cell($sh, $r, 4, $sub, $sMoney);
    $r++;
}
if (count($byOs)) {
    $x->cell($sh, $r, 1, 'TOTAL SEMUA OS', $sTotLbl);
    $x->cell($sh, $r, 2, $totalKaryawan, $sTotCtr);
    $x->cell($sh, $r, 3, $totalHari, $sTotCtr);
    $x->cell($sh, $r, 4, $grand, $sTotVal);
}
$x->autofit($sh);

// Sheet BORONGAN Ã¢â‚¬â€ detail kerja terakumulasi per pekerjaan (qty per satuan)
$sh = $x->sheet('BORONGAN');
$qtyFmt = function ($q) { $s = number_format((float)$q, 2, ',', ''); $s = rtrim(rtrim($s, '0'), ','); return $s === '' ? '0' : $s; };
$r = 1;
$x->cell($sh, $r, 1, 'DETAIL KERJA BORONGAN ' . strtoupper($speriode), $sTitle); $x->merge($sh, $r, 1, $r, 6); $r++;
$x->cell($sh, $r, 1, $label_periode . ' - ' . $label_filter, $sSub); $x->merge($sh, $r, 1, $r, 6); $r++;
$r++;
$x->cell($sh, $r, 1, 'Nama', $sHead);
$x->cell($sh, $r, 2, 'No. Absen', $sHead);
$x->cell($sh, $r, 3, 'OS', $sHead);
$x->cell($sh, $r, 4, 'Pekerjaan', $sHead);
$x->cell($sh, $r, 5, 'Total Qty', $sHead);
$x->cell($sh, $r, 6, 'Total Upah', $sHead);
$r++;
$anyBor = false;
$borGrand = 0;
foreach ($byOs as $os => $list) {
    foreach ($list as $lr) {
        if (empty($lr['bor_detail'])) continue;
        $anyBor = true;
        $empTotal = 0;
        $x->cell($sh, $r, 1, (string)$lr['nama_karyawan'], $sText);
        $x->cell($sh, $r, 2, (string)$lr['no_absen'], $sText);
        $x->cell($sh, $r, 3, (string)$lr['os'], $sText);
        for ($c = 4; $c <= 6; $c++) $x->cell($sh, $r, $c, '', $sText);
        $r++;
        $ktp = trim((string)($lr['no_ktp'] ?? ''));
        $x->cell($sh, $r, 1, 'KTP: ' . ($ktp !== '' ? $ktp : '-'), $sKtp);
        for ($c = 2; $c <= 6; $c++) $x->cell($sh, $r, $c, '', $sKtp);
        $r++;
        foreach ($lr['bor_detail'] as $bd) {
            $empTotal += (int)$bd['upah'];
            $x->cell($sh, $r, 1, '', $sText);
            $x->cell($sh, $r, 2, '', $sText);
            $x->cell($sh, $r, 3, '', $sText);
            $x->cell($sh, $r, 4, (string)($bd['nama'] !== '' ? $bd['nama'] : '-'), $sText);
            $x->cell($sh, $r, 5, $qtyFmt($bd['qty']) . (string)$bd['satuan'], $sQtyTxt);
            $x->cell($sh, $r, 6, (int)$bd['upah'], $sMoney);
            $r++;
        }
        $borGrand += $empTotal;
        $x->cell($sh, $r, 1, 'Total ' . $lr['nama_karyawan'], $sSubTot);
        $x->merge($sh, $r, 1, $r, 5);
        $x->cell($sh, $r, 6, $empTotal, $sSubVal);
        $r++;
        $r++;
    }
}
if (!$anyBor) {
    $x->cell($sh, $r, 1, 'Tidak ada data borongan pada filter ini.', $sText); $x->merge($sh, $r, 1, $r, 6); $r++;
} else {
    $x->cell($sh, $r, 1, 'TOTAL BORONGAN', $sTotLbl);
    $x->merge($sh, $r, 1, $r, 5);
    $x->cell($sh, $r, 6, $borGrand, $sTotVal);
}
$x->autofit($sh);

// Sheet per OS
foreach ($byOs as $os => $list) {
    $sh = $x->sheet(upah_sheet_name($os, $used));
    $r = 1;
    $x->cell($sh, $r, 1, 'RINGKASAN UPAH ' . strtoupper($speriode) . '  -  OS ' . $os, $sTitle); $x->merge($sh, $r, 1, $r, 6); $r++;
    $x->cell($sh, $r, 1, $label_periode . '  -  ' . $label_filter, $sSub); $x->merge($sh, $r, 1, $r, 6); $r++;
    $r++;
    $x->cell($sh, $r, 1, 'Nama', $sHead);
    $x->cell($sh, $r, 2, 'No. Absen', $sHead);
    $x->cell($sh, $r, 3, 'OS', $sHead);
    $x->cell($sh, $r, 4, 'Periode', $sHead);
    $x->cell($sh, $r, 5, 'Total Hari Kerja', $sHead);
    $x->cell($sh, $r, 6, 'Total Upah', $sHead);
    $r++;
    $sub = 0; $subHari = 0;
    foreach ($list as $lr) {
        $val = (int)$lr['total'];
        $sub += $val; $subHari += (int)$lr['hari'];
        $x->cell($sh, $r, 1, (string)$lr['nama_karyawan'], $sText);
        $x->cell($sh, $r, 2, (string)$lr['no_absen'], $sText);
        $x->cell($sh, $r, 3, (string)$lr['os'], $sText);
        $x->cell($sh, $r, 4, (string)$lr['periode'], $sText);
        $x->cell($sh, $r, 5, (int)$lr['hari'], $sTextR);
        $x->cell($sh, $r, 6, $val, $sMoney);
        $r++;
        $ktp = trim((string)($lr['no_ktp'] ?? ''));
        $x->cell($sh, $r, 1, 'KTP: ' . ($ktp !== '' ? $ktp : '-'), $sKtp);
        for ($c = 2; $c <= 6; $c++) $x->cell($sh, $r, $c, '', $sKtp);
        $r++;
    }
    $x->cell($sh, $r, 1, 'JUMLAH OS ' . $os . ' (' . count($list) . ' karyawan)', $sTotLbl);
    $x->cell($sh, $r, 2, '', $sTotLbl);
    $x->cell($sh, $r, 3, '', $sTotLbl);
    $x->cell($sh, $r, 4, '', $sTotLbl);
    $x->cell($sh, $r, 5, $subHari, $sTotCtr);
    $x->cell($sh, $r, 6, $sub, $sTotVal);
    $x->autofit($sh);
}

$fn = 'Ringkasan-Upah-' . preg_replace('/[^A-Za-z0-9]+/', '-', $speriode) . '-' . $sdari . '_' . $ssampai . '.xlsx';
$x->send($fn);
