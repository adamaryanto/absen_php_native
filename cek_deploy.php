<?php
// DIAGNOSTIK DEPLOY — upload ke folder payroll di server, buka:
//   https://dhk.co.id/cek_deploy.php?k=payroll2026
// HAPUS file ini setelah selesai. Tidak mengubah data (read-only).
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['k'] ?? '') !== 'payroll2026') {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

function sha16($p) {
    return (is_file($p) && is_readable($p)) ? substr(hash_file('sha256', $p), 0, 16) : '-';
}

echo "=== DIAGNOSTIK DEPLOY PAYROLL ===\n";
echo "waktu          : " . date('Y-m-d H:i:s') . "\n";
echo "php version    : " . PHP_VERSION . "\n";
echo "sapi           : " . PHP_SAPI . "\n";
echo "script dir     : " . __DIR__ . "\n";
echo "document root  : " . ($_SERVER['DOCUMENT_ROOT'] ?? '-') . "\n";
echo "display_errors : " . ini_get('display_errors') . "\n";
echo "error_log      : " . ini_get('error_log') . "\n";
echo "openssl/mysqli : " . (extension_loaded('mysqli') ? 'mysqli OK' : 'mysqli TIDAK ADA') . "\n";
echo "\n";

echo "--- FILE (harus ada, ukuran, sha256 16) ---\n";
$files = [
    'lembur_helper.php',
    'os_helper.php',
    'koneksi.php',
    'index.php',
    'page/realisasi/slipk.php',
    'page/realisasi/slipk_simple.php',
    'page/realisasi/slip_simple.php',
    'page/realisasi/slip.php',
    'page/realisasi/sync_lib.php',
    'page/realisasi/kelola.php',
    'page/realisasi/detail.php',
    'page/lembur/lembur.php',
    'page/generate/tarik-data.php',
    'slip.php',
    'slip_simple.php',
    'excel_tarik.php',
    'pdf_tarik.php',
];
foreach ($files as $f) {
    $p = __DIR__ . '/' . $f;
    printf("%-38s %-6s %8s  %s\n", $f, is_file($p) ? 'ADA' : 'HILANG', is_file($p) ? filesize($p) : '-', sha16($p));
}

echo "\n--- TEST LOAD lembur_helper.php ---\n";
$helperErr = '';
if (is_file(__DIR__ . '/lembur_helper.php')) {
    try {
        require_once __DIR__ . '/lembur_helper.php';
    } catch (Throwable $e) {
        $helperErr = $e->getMessage();
    }
    $fns = ['lembur_global_per_jam','lembur_custom_map','lembur_rate_per_jam','lembur_rate_per_menit','lembur_display_rp','lembur_recalc_all'];
    foreach ($fns as $fn) echo sprintf("function %-24s %s\n", $fn, function_exists($fn) ? 'OK' : 'TIDAK ADA');
    if ($helperErr !== '') echo "ERROR: $helperErr\n";
} else {
    echo "lembur_helper.php TIDAK ADA di root -> semua halaman lembur/tarik/slip akan BLANK (fatal require)\n";
}

echo "\n--- DATABASE (via koneksi.php) ---\n";
if (!is_file(__DIR__ . '/koneksi.php')) {
    echo "koneksi.php tidak ada\n";
} else {
    ob_start();
    $koneksi = null;
    try { include __DIR__ . '/koneksi.php'; } catch (Throwable $e) { echo "include koneksi.php ERROR: " . $e->getMessage() . "\n"; }
    $noise = ob_get_clean();
    if ($noise !== '') echo "output koneksi.php: " . trim($noise) . "\n";
    if (!isset($koneksi) || !$koneksi) {
        echo "KONEKSI DB GAGAL: " . (function_exists('mysqli_connect_error') ? mysqli_connect_error() : 'unknown') . "\n";
    } else {
        $db = @$koneksi->query("SELECT DATABASE() AS db");
        echo "database aktif : " . (($db && ($r = $db->fetch_assoc())) ? $r['db'] : '?') . "\n";
        echo "server info    : " . $koneksi->server_info . "\n";

        echo "\n-- kolom snapshot (harus ADA semua) --\n";
        $cols = [
            ['tb_realisasi_detail', 'nama_karyawan_manual'],
            ['tb_realisasi_detail', 'no_absen_manual'],
            ['tb_rkk_detail',       'nama_karyawan_manual'],
            ['tb_rkk_detail',       'no_absen_manual'],
            ['tb_sync_log',         'nama_karyawan'],
        ];
        foreach ($cols as $c) {
            $q = @$koneksi->query("SHOW COLUMNS FROM {$c[0]} LIKE '{$c[1]}'");
            echo sprintf("%-22s.%-22s %s\n", $c[0], $c[1], ($q && $q->num_rows) ? 'ADA' : 'HILANG');
        }

        echo "\n-- data dasar --\n";
        $q = @$koneksi->query("SELECT COUNT(*) c FROM ms_karyawan");
        echo "ms_karyawan total      : " . ($q ? $q->fetch_assoc()['c'] : 'ERROR: ' . $koneksi->error) . "\n";
        $q = @$koneksi->query("SELECT COUNT(*) c FROM ms_karyawan WHERE status_karyawan='Aktif'");
        echo "ms_karyawan aktif      : " . ($q ? $q->fetch_assoc()['c'] : 'ERROR: ' . $koneksi->error) . "\n";
        $q = @$koneksi->query("SELECT COUNT(*) c FROM tb_realisasi_detail");
        echo "tb_realisasi_detail    : " . ($q ? $q->fetch_assoc()['c'] : 'ERROR: ' . $koneksi->error) . "\n";

        echo "\n-- tarif lembur master --\n";
        $q = @$koneksi->query("SELECT COUNT(*) c FROM tb_lembur");
        echo "tb_lembur jumlah baris : " . ($q ? $q->fetch_assoc()['c'] : 'ERROR: ' . $koneksi->error) . "\n";
        $q = @$koneksi->query("SELECT * FROM tb_lembur LIMIT 1");
        if ($q && ($r = $q->fetch_assoc())) {
            foreach ($r as $k => $v) echo "  $k = $v\n";
        } else {
            echo "  tb_lembur KOSONG (lembur global = 0 -> semua tampil Rp 0)\n";
        }
        $q = @$koneksi->query("SHOW COLUMNS FROM ms_karyawan LIKE 'lembur_per_jam'");
        echo "ms_karyawan.lembur_per_jam : " . (($q && $q->num_rows) ? 'ADA' : 'HILANG') . "\n";
        $q = @$koneksi->query("SELECT COUNT(*) c FROM ms_karyawan WHERE lembur_per_jam IS NOT NULL AND lembur_per_jam > 0");
        echo "karyawan tarif khusus  : " . ($q ? $q->fetch_assoc()['c'] : 'ERROR: ' . $koneksi->error) . "\n";

        echo "\n-- query PERSIS slipk.php --\n";
        $sql = "SELECT * FROM (
                  SELECT A.id_karyawan, A.no_absen, A.nama_karyawan, B.nama_departmen, O.OS_DHK AS label_os
                  FROM ms_karyawan A
                  LEFT JOIN ms_departmen B ON A.id_departmen = B.id_departmen
                  LEFT JOIN ms_os_dhk O ON A.id_os_dhk = O.id_os_dhk
                  WHERE A.status_karyawan = 'Aktif'
                  UNION
                  SELECT RD.id_karyawan, MAX(NULLIF(TRIM(RD.no_absen_manual), '')) AS no_absen,
                         MAX(COALESCE(NULLIF(TRIM(RD.nama_karyawan_manual), ''), CONCAT('ID ', RD.id_karyawan))) AS nama_karyawan,
                         NULL AS nama_departmen, NULL AS label_os
                  FROM tb_realisasi_detail RD
                  WHERE RD.id_karyawan <> 0
                    AND NOT EXISTS (SELECT 1 FROM ms_karyawan K WHERE K.id_karyawan = RD.id_karyawan)
                  GROUP BY RD.id_karyawan
                ) X ORDER BY X.nama_karyawan ASC";
        $q = @$koneksi->query($sql);
        if (!$q) {
            echo "slipk QUERY ERROR: " . $koneksi->error . "\n";
        } else {
            echo "slipk QUERY OK, baris: " . $q->num_rows . "\n";
            $n = 0;
            while ($n < 5 && ($r = $q->fetch_assoc())) { echo "  - " . $r['no_absen'] . " | " . $r['nama_karyawan'] . "\n"; $n++; }
        }

        echo "\n-- query PERSIS tarik-data.php (roster + snapshot) --\n";
        $sql2 = "SELECT A.id_karyawan, A.nama_karyawan,
                        COALESCE(NULLIF(TRIM(A.no_absen),''), NULLIF(TRIM(RD.no_absen_manual),'')) AS no_absen
                 FROM ms_karyawan A
                 LEFT JOIN tb_realisasi_detail RD ON RD.id_karyawan = A.id_karyawan
                 WHERE A.status_karyawan = 'Aktif'
                 LIMIT 5";
        $q = @$koneksi->query($sql2);
        if (!$q) {
            echo "tarik QUERY ERROR: " . $koneksi->error . "\n";
        } else {
            echo "tarik QUERY OK, contoh baris: " . $q->num_rows . "\n";
            while ($r = $q->fetch_assoc()) echo "  - " . $r['id_karyawan'] . " | " . $r['no_absen'] . " | " . $r['nama_karyawan'] . "\n";
        }
    }
}

echo "\n--- ISI LOG ERROR TERAKHIR (penyebab halaman blank) ---\n";
$logPaths = array_filter([
    ini_get('error_log'),
    __DIR__ . '/php_error_log',
    __DIR__ . '/error_log',
    'C:/xampp/php/logs/php_error_log',
]);
$logShown = false;
foreach ($logPaths as $lp) {
    if (!$lp || !is_file($lp) || !is_readable($lp)) continue;
    echo "file : $lp (" . filesize($lp) . " bytes)\n";
    $fh = @fopen($lp, 'rb');
    if (!$fh) { echo "  (tidak bisa dibuka)\n"; continue; }
    $size = filesize($lp);
    $read = 16384;
    fseek($fh, max(0, $size - $read));
    $tail = fread($fh, $read);
    fclose($fh);
    $lines = preg_split('/\r\n|\r|\n/', $tail);
    $lines = array_slice($lines, -60);
    echo "----- tail 60 baris -----\n";
    foreach ($lines as $ln) if (trim($ln) !== '') echo $ln . "\n";
    echo "-----\n";
    $logShown = true;
    break;
}
if (!$logShown) echo "Log tidak terbaca lewat web (izin file). Minta admin buka manual file log di atas.\n";

echo "\n=== SELESAI. Kirim seluruh teks ini ke pengembang. ===\n";
