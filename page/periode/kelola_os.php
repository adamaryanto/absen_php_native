<?php
// Halaman terpisah: "Kelola Realisasi" khusus OS terpilih (read-only).
// Dibuka dari tombol Detail di halaman Detail OS. Tampilan dibuat mirip
// halaman Kelola Realisasi, tapi isinya HANYA baris OS yang dipilih + tidak bisa
// ubah/simpan/hapus/tarik.

$sos = trim((string)($_GET['sos'] ?? ''));
if ($sos === '' || strlen($sos) > 50) $sos = 'Tanpa OS';
$sdari = trim((string)($_GET['sdari'] ?? ''));
$ssampai = trim((string)($_GET['ssampai'] ?? ''));
if ($sdari === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sdari)) $sdari = date('Y-m-01');
if ($ssampai === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ssampai)) $ssampai = date('Y-m-d');
$speriode = trim((string)($_GET['speriode'] ?? 'Harian'));
$sgol = trim((string)($_GET['sgol'] ?? 'semua'));
$q = trim((string)($_GET['q'] ?? ''));
$idrealisasi = (int)($_GET['id'] ?? 0);

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
    echo '<div class="container-fluid px-6 mt-6"><div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-xl p-4 text-sm font-bold">OS tidak valid.</div></div>';
    return;
}
if ($idrealisasi <= 0) {
    echo '<div class="container-fluid px-6 mt-6"><div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-xl p-4 text-sm font-bold">ID realisasi tidak valid.</div></div>';
    return;
}

$FORCE_SOS_KELOLA = $sos;
$_GET['id'] = $idrealisasi;
$_SERVER['REQUEST_METHOD'] = 'GET';

$back_qs = ['page' => 'periode', 'aksi' => 'detailos', 'sos' => $sos, 'sdari' => $sdari, 'ssampai' => $ssampai, 'speriode' => $speriode, 'sgol' => $sgol];
if ($q !== '') $back_qs['q'] = $q;
$back_url = '?' . http_build_query($back_qs);

ob_start();
include __DIR__ . '/../realisasi/kelola.php';
$html = ob_get_clean();

// Tombol "Kembali" di header diarahkan ke Detail OS (bukan ke List Realisasi)
$html = str_replace('href="?page=realisasi" class="inline-flex items-center bg-white', 'href="' . htmlspecialchars($back_url) . '" class="inline-flex items-center bg-white', $html);

echo $html;
