<?php
// perbaiki_pembatalan_pengganti.php — PERBAIKI SEKALI JALAN (one-time cleanup) untuk case 2.
// Dipakai untuk baris yang SUDAH terlanjur pembatalan pengganti SEBELUM detail_PEMBATALAN_PENGGANTI.php
// dipasang: jam finger pengganti (ra_masuk/ra_keluar) masih nyangkut di baris karyawan
// asli, sehingga tampil juga di slip-nya. File fix-nya hanya mencegah kasus BARU,
// yang sudah nyangkut harus diperbaiki datanya (Tarik Data tidak membantu, karena
// sync nge-skip baris manual yang karyawan aslinya tidak ada tap).
//
// Taruh di ROOT payroll (sejajar koneksi.php), buka:
//   perbaiki_pembatalan_pengganti.php?nama=NAMA&tglm=2026-11-07
//   atau: perbaiki_pembatalan_pengganti.php?id=12345          (id_realisasi_detail langsung)
// Tanpa &confirm=1 hanya MENAMPILKAN baris yang ketemu (tidak mengubah apa-apa).
// Tambah &confirm=1 untuk benar-benar memperbaiki. Hapus file ini setelah dipakai.
include "koneksi.php";
function qq($koneksi, $sql) {
    try { return $koneksi->query($sql); }
    catch (Throwable $e) { echo "QUERY ERROR: " . $e->getMessage() . "\n"; return false; }
}
function esc($koneksi, $v) { return $koneksi->real_escape_string($v); }
echo "<pre>";
$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$nama = trim($_GET['nama'] ?? '');
// 2026-11-12: BUG — petunjuk pakai "tglm" tapi kode baca $_GET['tgl'], jadi
// parameter tanggal tidak pernah kebaca dan selalu jatuh ke halaman petunjuk.
// Terima "tglm" (sesuai petunjuk) dan "tgl" sebagai cadangan.
$tgl = $_GET['tglm'] ?? $_GET['tgl'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $tgl = '';

if ($id <= 0 && ($nama === '' || $tgl === '')) {
    echo "Pakai: perbaiki_pembatalan_pengganti.php?nama=NAMA&tglm=YYYY-MM-DD\n";
    echo "   atau: perbaiki_pembatalan_pengganti.php?id=ID_REALISASI_DETAIL\n";
    echo "Tambah &confirm=1 untuk benar-benar memperbaiki.\n</pre>";
    exit;
}

// Cari barisnya
if ($id > 0) {
    $q = qq($koneksi, "SELECT RD.*, COALESCE(K.nama_karyawan, RD.nama_karyawan_manual) AS nm, R.tgl_realisasi
        FROM tb_realisasi_detail RD LEFT JOIN ms_karyawan K ON K.id_karyawan = RD.id_karyawan
        LEFT JOIN tb_realisasi R ON R.id_realisasi = RD.id_realisasi
        WHERE RD.id_realisasi_detail = $id LIMIT 1");
} else {
    $nm_esc = esc($koneksi, $nama);
    $q = qq($koneksi, "SELECT RD.*, COALESCE(K.nama_karyawan, RD.nama_karyawan_manual) AS nm, R.tgl_realisasi
        FROM tb_realisasi_detail RD LEFT JOIN ms_karyawan K ON K.id_karyawan = RD.id_karyawan
        LEFT JOIN tb_realisasi R ON R.id_realisasi = RD.id_realisasi
        WHERE R.tgl_realisasi = '$tgl' AND (K.nama_karyawan LIKE '%$nm_esc%' OR RD.nama_karyawan_manual LIKE '%$nm_esc%')");
}
if (!$q || $q->num_rows == 0) { echo "Baris tidak ketemu. Cek lagi nama/tanggal/id-nya.\n</pre>"; exit; }
$rows = [];
while ($r = $q->fetch_assoc()) $rows[] = $r;
echo "Ketemu " . count($rows) . " baris:\n\n";
foreach ($rows as $r) {
    $stuck = ((int)($r['id_pengganti'] ?? 0) === 0)
        && ((!empty($r['ra_masuk']) && $r['ra_masuk'] !== '00:00:00') || (!empty($r['ra_keluar']) && $r['ra_keluar'] !== '00:00:00'));
    echo "id_detail=" . $r['id_realisasi_detail'] . " | tgl=" . $r['tgl_realisasi'] . " | nama=" . $r['nm']
        . " | id_pengganti=" . var_export($r['id_pengganti'], true)
        . " | masuk=" . $r['ra_masuk'] . " pulang=" . $r['ra_keluar']
        . " | upah=" . $r['r_upah'] . " status=" . $r['r_status'] . " manual=" . $r['status_realisasi_detail']
        . " | " . ($stuck ? "NYANGKUT (bekas pengganti)" : "tidak nyangkut / bukan bekas pembatalan pengganti") . "\n";
}
echo "\n";
if (!$confirm) {
    echo "MODE LIHAT SAJA (belum ada yang diubah).\n";
    echo "Kalau baris di atas benar, buka lagi URL yang sama + &confirm=1 untuk memperbaiki.\n</pre>";
    exit;
}
// Perbaiki: sama seperti reset pembatalan pengganti di detail.php (revisi 2026-11-12).
foreach ($rows as $r) {
    $rid = (int)$r['id_realisasi_detail'];
    $is_stuck = ((int)($r['id_pengganti'] ?? 0) === 0)
        && ((!empty($r['ra_masuk']) && $r['ra_masuk'] !== '00:00:00') || (!empty($r['ra_keluar']) && $r['ra_keluar'] !== '00:00:00'));
    if (!$is_stuck) { echo "id_detail=$rid: dilewati (tidak nyangkut).\n"; continue; }
    // Shift asli: id_jadwal RKK dulu, kalau kosong pakai shift master karyawan asli.
    $jm = $jk = $im = $ik = '00:00:00';
    $id_jd = 0;
    $qj = qq($koneksi, "SELECT id_jadwal FROM tb_rkk_detail WHERE id_rkk_detail = '" . (int)($r['id_rkk_detail'] ?? 0) . "' LIMIT 1");
    if ($qj && ($rj = $qj->fetch_assoc())) $id_jd = (int)$rj['id_jadwal'];
    if ($id_jd <= 0) {
        $qm = qq($koneksi, "SELECT id_jadwal FROM ms_karyawan WHERE id_karyawan = '" . (int)$r['id_karyawan'] . "' LIMIT 1");
        if ($qm && ($rm = $qm->fetch_assoc())) $id_jd = (int)$rm['id_jadwal'];
    }
    if ($id_jd > 0) {
        $qw = qq($koneksi, "SELECT jam_masuk, jam_keluar, istirahat_masuk, istirahat_keluar FROM tb_jadwal WHERE id_jadwal = '$id_jd' LIMIT 1");
        if ($qw && ($rw = $qw->fetch_assoc())) {
            $jm = !empty($rw['jam_masuk']) ? $rw['jam_masuk'] : '00:00:00';
            $jk = !empty($rw['jam_keluar']) ? $rw['jam_keluar'] : '00:00:00';
            $im = !empty($rw['istirahat_masuk']) ? $rw['istirahat_masuk'] : '00:00:00';
            $ik = !empty($rw['istirahat_keluar']) ? $rw['istirahat_keluar'] : '00:00:00';
        }
    }
    $ok = qq($koneksi, "UPDATE tb_realisasi_detail SET ra_masuk = '00:00:00', ra_keluar = '00:00:00', ra_istirahat_masuk = '00:00:00', ra_istirahat_keluar = '00:00:00', r_jam_masuk = '$jm', r_jam_keluar = '$jk', r_istirahat_masuk = '$im', r_istirahat_keluar = '$ik', lembur_jam = '0', lembur = '0', r_potongan_telat = '0', r_potongan_istirahat_awal = '0', r_potongan_istirahat_telat = '0', r_potongan_pulang = '0', r_potongan_tidak_lengkap = '0', r_upah = 0, r_status = 'Tidak Hadir', status_realisasi_detail = 2 WHERE id_realisasi_detail = $rid");
    echo "id_detail=$rid (" . $r['nm'] . "): " . ($ok ? "DIPERBAIKI." : "GAGAL.") . "\n";
}
echo "\nSelesai. Habis ini jalanin Tarik Data tanggal itu biar keisi ulang dari tap karyawan asli kalau ada.\n";
echo "Hapus file perbaiki_pembatalan_pengganti.php ini setelah dipakai.\n</pre>";
