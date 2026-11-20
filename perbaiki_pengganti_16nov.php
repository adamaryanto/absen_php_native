<?php
// FIX (read-only preview tanpa &confirm=1): 11 baris Tambahan pengganti Ganti 16/11 masih id_realisasi=0 (TDR),
// sehingga Rekap menghitungnya tapi Excel Realisasi 81 tidak. Script ini memindahkan mereka ke Realisasi 81.
// Taruh di root payroll, buka: perbaiki_pengganti_16nov.php
// Jalankan fix: perbaiki_pengganti_16nov.php?confirm=1
// HAPUS file ini setelah dipakai.
require 'koneksi.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$ids = [1096,1107,1112,793,1099,1102,1100,1101,825,1103,1116]; // 11 pengganti yg punya baris TDR tgl 16/11 (1102=Bima Fani Herlambang; 923/IPAN SUNANDAR tidak punya baris 16/11)
// Catatan: 5 pengganti lain (EDI IRAWAN 686, FAHMI 1091, IPAN SUNANDAR 923, MUHAMAD SANDI 1092, SADELI 993)
// TIDAK punya baris TDR tgl 16/11 sama sekali — mereka tidak disentuh script ini.
$in = implode(',', $ids);
$q = $koneksi->query("SELECT d.id_realisasi_detail, k.nama_karyawan, d.id_karyawan, d.r_upah, d.tgl_realisasi_detail, d.id_realisasi, d.id_grup
  FROM tb_realisasi_detail d JOIN ms_karyawan k ON k.id_karyawan=d.id_karyawan
  WHERE d.id_realisasi=0 AND d.tgl_realisasi_detail='2026-11-16' AND d.id_karyawan IN ($in) AND d.id_grup=44
  ORDER BY k.nama_karyawan");
echo "<pre>";
echo "Baris Tambahan pengganti (id_realisasi=0, tgl 16/11, grup 44):\n";
$rows = []; $tot = 0;
while ($q && ($r = $q->fetch_assoc())) { $rows[] = $r; $tot += (float)$r['r_upah']; echo "- " . h($r['nama_karyawan']) . " (id_detail=" . (int)$r['id_realisasi_detail'] . ", id_karyawan=" . (int)$r['id_karyawan'] . ") r_upah=" . number_format((float)$r['r_upah'],0,',','.') . "\n"; }
echo "Jumlah: " . count($rows) . " orang, total r_upah Rp " . number_format($tot,0,',','.') . " (harus 11 orang / 1.120.000)\n\n";
if (!$confirm) { echo "PREVIEW ONLY. Tambah ?confirm=1 untuk memindahkan ke Realisasi 81 (id_realisasi 81 = 16/11).\n"; echo "</pre>"; exit; }
if (count($rows) !== 11) { echo "BATAL: jumlah baris bukan 11 (" . count($rows) . "). Jangan lanjutkan, cek dulu.\n"; echo "</pre>"; exit; }
$detailIds = implode(',', array_map(function($r){ return (int)$r['id_realisasi_detail']; }, $rows));
$ok = $koneksi->query("UPDATE tb_realisasi_detail SET id_realisasi=81 WHERE id_realisasi_detail IN ($detailIds) AND id_realisasi=0 AND tgl_realisasi_detail='2026-11-16'");
if ($ok) { echo "OK: " . $koneksi->affected_rows . " baris dipindah ke Realisasi 81.\n"; echo "Cek: Excel Realisasi 16/11 sekarang harus 293 orang / GRAND TOTAL 23.809.348 = Rekap.\n"; }
else { echo "GAGAL: " . h($koneksi->error) . "\n"; }
echo "</pre>";
