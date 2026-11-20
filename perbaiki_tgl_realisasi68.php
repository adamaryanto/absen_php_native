<?php
// PERBAIKAN: 241 baris Realisasi 68 (tgl 3 Nov) yang tgl_realisasi_detail-nya
// salah tulis 2026-11-04. Akibatnya slip tidak menampilkan tgl 3 Nov.
// Taruh di root payroll, buka: perbaiki_tgl_realisasi68.php  (preview saja)
// Tambah &confirm=1 untuk menerapkan. Hapus file setelah dipakai.
// HANYA mengubah tgl_realisasi_detail; tidak menyentuh upah/tap/potongan.
require 'koneksi.php';
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
echo "<pre>";
echo "REALISASI 68 = tgl 2026-11-03 (id_rkk 103)\n";
echo "Target: baris id_realisasi=68 AND tgl_realisasi_detail='2026-11-04'\n\n";

// --- Klasifikasi per baris (berdasarkan tap di tb_record) ---
$q = $koneksi->query("
SELECT d.id_realisasi_detail, d.id_karyawan,
  COALESCE(NULLIF(TRIM(k.nama_karyawan),''), d.nama_karyawan_manual, CONCAT('ID ', d.id_karyawan)) AS nama,
  d.r_status, d.r_upah, d.ra_masuk, d.ra_keluar,
  EXISTS(SELECT 1 FROM tb_record r WHERE TRIM(r.userid)=TRIM(k.no_absen) AND r.tgl='2026-11-03' AND r.detail_waktu=CONCAT('2026-11-03 ', d.ra_masuk)) AS masuk_nov3,
  EXISTS(SELECT 1 FROM tb_record r WHERE TRIM(r.userid)=TRIM(k.no_absen) AND r.tgl='2026-11-04' AND r.detail_waktu=CONCAT('2026-11-04 ', d.ra_masuk)) AS masuk_nov4,
  EXISTS(SELECT 1 FROM tb_record r WHERE TRIM(r.userid)=TRIM(k.no_absen) AND r.tgl='2026-11-03' AND r.detail_waktu=CONCAT('2026-11-03 ', d.ra_keluar)) AS pulang_nov3,
  EXISTS(SELECT 1 FROM tb_record r WHERE TRIM(r.userid)=TRIM(k.no_absen) AND r.tgl='2026-11-04' AND r.detail_waktu=CONCAT('2026-11-04 ', d.ra_keluar)) AS pulang_nov4
FROM tb_realisasi_detail d LEFT JOIN ms_karyawan k ON k.id_karyawan=d.id_karyawan
WHERE d.id_realisasi=68 AND d.tgl_realisasi_detail='2026-11-04'
ORDER BY d.id_realisasi_detail");
$fix = []; $skip = [];
if ($q) while ($r = $q->fetch_assoc()) {
    $masuk = trim((string)$r['ra_masuk']); $keluar = trim((string)$r['ra_keluar']);
    $hasTap = ($masuk !== '' && $masuk !== '00:00:00') || ($keluar !== '' && $keluar !== '00:00:00');
    $masukNov3 = (int)$r['masuk_nov3']; $masukNov4 = (int)$r['masuk_nov4'];
    $pulangNov3 = (int)$r['pulang_nov3']; $pulangNov4 = (int)$r['pulang_nov4'];
    // Tanggal kerja = tanggal MASUK (shift mulai). Shift malam: masuk 3 Nov,
    // pulang lewat tengah malam (4 Nov) tetap kerjaan 3 Nov.
    if ($masukNov3) { $fix[] = $r; }
    elseif ($pulangNov3 && !$pulangNov4) { $fix[] = $r; } // pulang tap 3 Nov (masuk kosong/tidak cocok)
    elseif (!$hasTap && $r['r_status'] === 'Tidak Hadir') { $fix[] = $r; } // absen, upah 0
    else { $skip[] = $r; } // pulang 4 Nov saja, atau tap tidak cocok keduanya
}
echo "Akan diubah ke 2026-11-03: " . count($fix) . " baris\n";
echo "Dilewati (perlu cek manual): " . count($skip) . " baris\n\n";
echo "== DILEWATI ==\n";
foreach ($skip as $r) {
    echo "id=" . (int)$r['id_realisasi_detail'] . " | " . h($r['nama']) . " | " . h($r['r_status'])
        . " | masuk " . h($r['ra_masuk']) . " pulang " . h($r['ra_keluar'])
        . " | nov3=" . (int)$r['masuk_nov3'] . "/" . (int)$r['pulang_nov3']
        . " nov4=" . (int)$r['masuk_nov4'] . "/" . (int)$r['pulang_nov4'] . "\n";
}
if (!$confirm) {
    echo "\n== PREVIEW SAJA (belum diubah) ==\n";
    echo "Contoh 10 baris yang akan diubah:\n";
    foreach (array_slice($fix, 0, 10) as $r) {
        echo "id=" . (int)$r['id_realisasi_detail'] . " | " . h($r['nama']) . " | " . h($r['r_status'])
            . " | masuk " . h($r['ra_masuk']) . " pulang " . h($r['ra_keluar']) . " | upah " . (int)$r['r_upah'] . "\n";
    }
    echo "\nUntuk menerapkan, buka: perbaiki_tgl_realisasi68.php?confirm=1\n";
    echo "</pre>";
    exit;
}
// --- Terapkan ---
$ids = array_map(function($r){ return (int)$r['id_realisasi_detail']; }, $fix);
if ($ids) {
    $in = implode(',', $ids);
    $ok = $koneksi->query("UPDATE tb_realisasi_detail SET tgl_realisasi_detail='2026-11-03' WHERE id_realisasi=68 AND tgl_realisasi_detail='2026-11-04' AND id_realisasi_detail IN ($in)");
    echo $ok ? "BERHASIL: " . $koneksi->affected_rows . " baris diubah ke 2026-11-03.\n" : "GAGAL: " . h($koneksi->error) . "\n";
} else {
    echo "Tidak ada baris yang diubah.\n";
}
echo "Sisa baris Realisasi 68 bertanggal 2026-11-04 (yang dilewati):\n";
$q2 = $koneksi->query("SELECT COUNT(*) AS n FROM tb_realisasi_detail WHERE id_realisasi=68 AND tgl_realisasi_detail='2026-11-04'");
if ($q2 && ($r2 = $q2->fetch_assoc())) echo (int)$r2['n'] . " baris.\n";
echo "Hapus file ini setelah selesai.\n</pre>";
