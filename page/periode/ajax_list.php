<?php
// Endpoint live-search halaman Periode: kembalikan potongan area tabel per OS (tanpa reload halaman).
session_start();
include __DIR__ . '/../../koneksi.php';
header('Content-Type: application/json');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = 'periode';

ob_start();
include __DIR__ . '/periode.php';
$html = ob_get_clean();

$area = '';
if (preg_match('/<!--AREA_START-->(.*?)<!--AREA_END-->/s', $html, $m)) $area = $m[1];

echo json_encode(['area' => $area]);
