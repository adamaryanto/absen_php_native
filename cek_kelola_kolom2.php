<?php
// PROBE v2 (baca-saja): mapping kolom Jam Masuk / Istirahat Masuk / Jam Pulang
// di kelola.php LIVE. v1 gagal tampil karena baris <td> mentah dimakan browser
// sebagai tag HTML — v2 ini di-escape dulu.
// Taruh di root, buka: cek_kelola_kolom2.php
ini_set('display_errors', 1); error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');
echo "<pre>";
$f = __DIR__ . '/page/realisasi/kelola.php';
if (!file_exists($f)) { echo "File tidak ketemu: $f\n"; exit; }
echo "File: page/realisasi/kelola.php | ukuran: " . filesize($f) . " byte | diubah: " . date('Y-m-d H:i:s', filemtime($f)) . "\n\n";
$lines = file($f);
foreach ($lines as $i => $ln) {
    if (strpos($ln, 'data-label="Jam Masuk"') !== false || strpos($ln, 'data-label="Istirahat Masuk"') !== false || strpos($ln, 'data-label="Jam Pulang"') !== false) {
        $n = $i + 1;
        $chunk = $ln . ($lines[$i+1] ?? '') . ($lines[$i+2] ?? '') . ($lines[$i+3] ?? '');
        preg_match_all('/\$data\[\'([a-z_0-9]+)\'\]/', $chunk, $m);
        $fields = array_unique($m[1]);
        echo "Baris $n | field: " . (count($fields) ? implode(', ', $fields) : '(tidak ketemu)') . "\n";
        echo "   isi: " . htmlspecialchars(trim($ln)) . "\n\n";
    }
}
echo "</pre>";
