<?php
// Helper rekap pengeluaran — dipakai halaman & exporter.
// 4 kategori: Biaya OS (semua jenis upah), Biaya Borongan, Biaya Boneless, Biaya permobil.

function rekap_tgl() {
    $t = trim((string)($_GET['tgl'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) $t = date('Y-m-d');
    return $t;
}

// Biaya OS: total gaji per OS (semua jenis: normal + borongan), skip Digantikan
function rekap_os($koneksi, $tgl) {
    $e = $koneksi->real_escape_string($tgl);
    $norm = "GREATEST(IFNULL(d.r_upah,0)+IFNULL(d.lembur,0)
        - IF(IFNULL(d.r_upah,0) = 0, 0,
            (IFNULL(d.r_potongan_telat,0)+IFNULL(d.r_potongan_istirahat_awal,0)+IFNULL(d.r_potongan_istirahat_telat,0)
             +IFNULL(d.r_potongan_pulang,0)+IFNULL(d.r_potongan_tidak_lengkap,0)+IFNULL(d.r_potongan_lainnya,0))), 0)";
    $bor = "GREATEST(IFNULL(bt.total_bor,0)-IFNULL(d.r_potongan_telat,0)-IFNULL(d.r_potongan_pulang,0), 0)";
    $rows = [];
    // 2026-11-17 BALANCE3: tanggal acuan = tb_realisasi.tgl_realisasi (tanggal Realisasi),
    // BUKAN tb_realisasi_detail.tgl_realisasi_detail (salinan yg bisa salah — 241 baris
    // Realisasi 68 tertulis 04/11 padahal Realisasi-nya 03/11). Jadi Rekap per tanggal
    // memakai baris yg SAMA dengan Realisasi tanggal itu -> jumlah orang & total balanced
    // dengan halaman Realisasi. Baris id_realisasi=0 (TDR lepas) tetap ikut lewat
    // tgl_realisasi_detail-nya sendiri. total_bor tetap dijumlah per-orang (BALANCE2).
    $q = $koneksi->query("SELECT COALESCE(NULLIF(TRIM(o.OS_DHK),''), 'Tanpa OS') AS os,
            COUNT(DISTINCT IF(d.id_karyawan = 0, CONCAT('M:', IFNULL(d.nama_karyawan_manual, '')), CONCAT('K:', d.id_karyawan))) AS n,
            SUM(IF(IFNULL(bt.total_bor,0) > 0, $bor, $norm)) AS total
        FROM tb_realisasi_detail d
        LEFT JOIN tb_realisasi r ON r.id_realisasi = d.id_realisasi
        LEFT JOIN ms_karyawan k ON k.id_karyawan = d.id_karyawan
        LEFT JOIN tb_rkk_detail rd ON rd.id_rkk_detail = d.id_rkk_detail
        LEFT JOIN ms_os_dhk o ON o.id_os_dhk = COALESCE(NULLIF(k.id_os_dhk,0), d.id_os_dhk)
        LEFT JOIN (SELECT IF(d2.id_karyawan = 0, CONCAT('M:', IFNULL(d2.nama_karyawan_manual, '')), CONCAT('K:', d2.id_karyawan)) AS person_key,
                       SUM(ROUND(b.qty * b.tarif)) AS total_bor
                FROM tb_realisasi_borongan b
                JOIN tb_realisasi_detail d2 ON d2.id_realisasi_detail = b.id_realisasi_detail
                LEFT JOIN tb_realisasi r2 ON r2.id_realisasi = d2.id_realisasi
                WHERE (r2.tgl_realisasi = '$e' OR (d2.id_realisasi = 0 AND d2.tgl_realisasi_detail = '$e'))
                GROUP BY person_key) bt
            ON bt.person_key = IF(d.id_karyawan = 0, CONCAT('M:', IFNULL(d.nama_karyawan_manual, '')), CONCAT('K:', d.id_karyawan))
        WHERE (r.tgl_realisasi = '$e' OR (d.id_realisasi = 0 AND d.tgl_realisasi_detail = '$e'))
          AND (rd.id_rkk_detail IS NULL OR rd.status_rkk <> 'Digantikan')
          AND d.id_realisasi_detail IN (
              SELECT MIN(d3.id_realisasi_detail) FROM tb_realisasi_detail d3
              LEFT JOIN tb_realisasi r3 ON r3.id_realisasi = d3.id_realisasi
              WHERE (r3.tgl_realisasi = '$e' OR (d3.id_realisasi = 0 AND d3.tgl_realisasi_detail = '$e'))
              GROUP BY IF(d3.id_karyawan = 0, CONCAT('M:', IFNULL(d3.nama_karyawan_manual, '')), CONCAT('K:', d3.id_karyawan))
          )
        GROUP BY os
        ORDER BY os ASC");
    while ($q && ($r = $q->fetch_assoc())) {
        $rows[] = ['os' => (string)$r['os'], 'n' => (int)$r['n'], 'total' => (int)round((float)$r['total'])];
    }
    return $rows;
}

// Biaya Borongan: upah borongan per karyawan pada tanggal tertentu
function rekap_borongan($koneksi, $tgl) {
    $e = $koneksi->real_escape_string($tgl);
    $rows = [];
    $q = $koneksi->query("SELECT d.id_karyawan,
            COALESCE(NULLIF(TRIM(k2.nama_karyawan),''), NULLIF(TRIM(d.nama_karyawan_manual),''), 'Tanpa Nama') AS nama,
            COALESCE(NULLIF(TRIM(o.OS_DHK),''), 'Tanpa OS') AS os,
            SUM(ROUND(b.qty * b.tarif)) AS total
        FROM tb_realisasi_detail d
        JOIN tb_realisasi_borongan b ON b.id_realisasi_detail = d.id_realisasi_detail
        LEFT JOIN tb_realisasi r ON r.id_realisasi = d.id_realisasi
        LEFT JOIN ms_karyawan k2 ON k2.id_karyawan = d.id_karyawan
        LEFT JOIN tb_rkk_detail rd ON rd.id_rkk_detail = d.id_rkk_detail
        LEFT JOIN ms_os_dhk o ON o.id_os_dhk = k2.id_os_dhk
        WHERE (r.tgl_realisasi = '$e' OR (d.id_realisasi = 0 AND d.tgl_realisasi_detail = '$e'))
          AND (rd.id_rkk_detail IS NULL OR rd.status_rkk <> 'Digantikan')
        GROUP BY d.id_karyawan, nama, os
        ORDER BY total DESC, nama ASC");
    while ($q && ($r = $q->fetch_assoc())) {
        $rows[] = ['nama' => (string)$r['nama'], 'os' => (string)$r['os'], 'total' => (int)round((float)$r['total'])];
    }
    return $rows;
}

// Biaya Boneless: item + truck per entri di tanggal itu
function rekap_boneless($koneksi, $tgl) {
    $e = $koneksi->real_escape_string($tgl);
    if (!function_exists('boneless_mobil_total')) {
        $f = __DIR__ . '/../boneless/mobil_lib.php';
        if (file_exists($f)) include_once $f;
    }
    $rows = [];
    $q = $koneksi->query("SELECT A.id_boneless, A.tgl, A.keterangan, A.jumlah_mobil, A.id_biayamobil,
            B.biaya_mobil, IFNULL(I.total_item, 0) AS total_item
        FROM tb_boneless A
        LEFT JOIN tb_biayamobil B ON B.id_biayamobil = A.id_biayamobil
        LEFT JOIN (SELECT id_boneless, SUM(`total`) AS total_item FROM tb_boneless_detail GROUP BY id_boneless) I
            ON I.id_boneless = A.id_boneless
        WHERE A.tgl = '$e'
        ORDER BY A.id_boneless ASC");
    while ($q && ($r = $q->fetch_assoc())) {
        $mobil = function_exists('boneless_mobil_total') ? (float)boneless_mobil_total($koneksi, (int)$r['id_boneless'], (int)$r['jumlah_mobil']) : ((int)$r['jumlah_mobil'] * (float)($r['biaya_mobil'] ?? 0));
        $rows[] = [
            'id' => (int)$r['id_boneless'],
            'ket' => trim((string)$r['keterangan']),
            'mobil' => (int)$r['jumlah_mobil'],
            'biaya_mobil' => (int)round($mobil),
            'biaya_item' => (int)round((float)$r['total_item']),
            'total' => (int)round($mobil + (float)$r['total_item']),
        ];
    }
    return $rows;
}

// Biaya permobil: per nama truck dari tb_boneless_mobil
function rekap_mobil($koneksi, $tgl) {
    $e = $koneksi->real_escape_string($tgl);
    $rows = [];
    $q = $koneksi->query("SELECT M.nama, COUNT(*) AS freq, SUM(M.harga) AS total
        FROM tb_boneless_mobil M
        JOIN tb_boneless B ON B.id_boneless = M.id_boneless
        WHERE B.tgl = '$e'
        GROUP BY M.nama
        ORDER BY M.nama ASC");
    while ($q && ($r = $q->fetch_assoc())) {
        $rows[] = ['nama' => (string)$r['nama'], 'freq' => (int)$r['freq'], 'total' => (int)round((float)$r['total'])];
    }
    // Truck tanpa link nama (jumlah_mobil > 0 tapi tidak ada di tb_boneless_mobil)
    $qf = $koneksi->query("SELECT SUM(A.jumlah_mobil) AS unit, SUM(A.jumlah_mobil * IFNULL(B.biaya_mobil, 0)) AS total
        FROM tb_boneless A
        LEFT JOIN tb_biayamobil B ON B.id_biayamobil = A.id_biayamobil
        WHERE A.tgl = '$e'
          AND A.jumlah_mobil > 0
          AND NOT EXISTS (SELECT 1 FROM tb_boneless_mobil M WHERE M.id_boneless = A.id_boneless)");
    if ($qf && ($rf = $qf->fetch_assoc()) && (int)($rf['unit'] ?? 0) > 0) {
        $rows[] = ['nama' => 'TRUK TANPA LINK', 'freq' => (int)$rf['unit'], 'total' => (int)round((float)$rf['total'])];
    }
    return $rows;
}

function rekap_totals($os, $bor, $bl, $mob) {
    $t = ['os' => 0, 'os_n' => 0, 'bor' => 0, 'bor_n' => 0, 'bl' => 0, 'bl_mobil' => 0, 'bl_item' => 0, 'mob' => 0, 'mob_freq' => 0];
    foreach ($os as $r) { $t['os'] += $r['total']; $t['os_n'] += $r['n']; }
    foreach ($bor as $r) { $t['bor'] += $r['total']; $t['bor_n']++; }
    foreach ($bl as $r) { $t['bl'] += $r['total']; $t['bl_mobil'] += $r['biaya_mobil']; $t['bl_item'] += $r['biaya_item']; }
    foreach ($mob as $r) { $t['mob'] += $r['total']; $t['mob_freq'] += $r['freq']; }
    return $t;
}
