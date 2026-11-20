<?php
// Helper tarif lembur efektif (dipanggil dari lembur.php & halaman terkait).
// Tarif per karyawan (ms_karyawan.lembur_per_jam) override global (tb_lembur.lembur_per_jam).
if (!function_exists('lembur_global_per_jam')) {
    function lembur_global_per_jam($koneksi) {
        static $global = null;
        if ($global !== null) return $global;
        $r = @$koneksi->query("SELECT lembur_per_jam FROM tb_lembur LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        $global = (float)($row['lembur_per_jam'] ?? 0);
        return $global;
    }
}
if (!function_exists('lembur_custom_map')) {
    function lembur_custom_map($koneksi) {
        static $map = null;
        if ($map !== null) return $map;
        $map = ['pin' => [], 'id' => []];
        $r = @$koneksi->query("SELECT id_karyawan, TRIM(no_absen) AS pin, lembur_per_jam FROM ms_karyawan WHERE lembur_per_jam IS NOT NULL AND lembur_per_jam > 0");
        while ($r && ($row = $r->fetch_assoc())) {
            $v = (float)$row['lembur_per_jam'];
            $map['id'][(int)$row['id_karyawan']] = $v;
            $pin = trim((string)$row['pin']);
            if ($pin !== '') $map['pin'][$pin] = $v;
        }
        return $map;
    }
}
if (!function_exists('lembur_rate_per_jam')) {
    function lembur_rate_per_jam($koneksi, $id_karyawan, $pin = null) {
        $map = lembur_custom_map($koneksi);
        $pin = trim((string)($pin ?? ''));
        if ($pin !== '' && isset($map['pin'][$pin])) return $map['pin'][$pin];
        if ($id_karyawan && isset($map['id'][(int)$id_karyawan])) return $map['id'][(int)$id_karyawan];
        return lembur_global_per_jam($koneksi);
    }
}
// Tarif per menit dibulatkan KE ATAS ke 1 desimal (5000/60 = 83,4) — dipakai semua rumus
// lembur Rp (lewat lembur_jam desimal/menit).
if (!function_exists('lembur_rate_per_menit')) {
    function lembur_rate_per_menit($koneksi, $id_karyawan, $pin = null) {
        $rate = lembur_rate_per_jam($koneksi, $id_karyawan, $pin);
        return ceil($rate / 60 * 10) / 10;
    }
}
if (!function_exists('lembur_display_rp')) {
    // Lembur Rp tampilan: jam x tarif EFEKTIF MASTER terkini (bukan nilai tersimpan).
    // $row = baris tb_realisasi_detail (lembur_jam, id_karyawan, id_pengganti).
    function lembur_display_rp($koneksi, $row) {
        $jam = (float)($row['lembur_jam'] ?? 0);
        if ($jam <= 0) return 0;
        $eff = (int)($row['id_pengganti'] ?? 0) ?: (int)($row['id_karyawan'] ?? 0);
        return (int)round($jam * 60 * lembur_rate_per_menit($koneksi, $eff));
    }
}
if (!function_exists('snapshot_ensure_columns')) {
    // Pastikan kolom cadangan (snapshot nama/no. absen) ada di DB server.
    // Aman dipanggil berkali-kali; hanya ALTER kalau kolom benar-benar belum ada.
    // Dipakai halaman Tarik Data & export supaya tidak fatal di server yang
    // belum pernah menjalankan hapus karyawan versi snapshot.
    function snapshot_ensure_columns($koneksi) {
        static $done = false;
        if ($done) return;
        $done = true;
        $wanted = [
            ['tb_realisasi_detail', 'nama_karyawan_manual', 'VARCHAR(100) NULL'],
            ['tb_realisasi_detail', 'no_absen_manual',      'VARCHAR(32) NULL'],
            ['tb_rkk_detail',       'nama_karyawan_manual', 'VARCHAR(100) NULL'],
            ['tb_rkk_detail',       'no_absen_manual',      'VARCHAR(32) NULL'],
            ['tb_sync_log',         'nama_karyawan',        'VARCHAR(100) NULL'],
        ];
        foreach ($wanted as $w) {
            try {
                $ck = $koneksi->query("SHOW COLUMNS FROM {$w[0]} LIKE '{$w[1]}'");
                $ada = ($ck && $ck->num_rows > 0);
                if ($ck) $ck->free();
                if (!$ada) {
                    $koneksi->query("ALTER TABLE {$w[0]} ADD COLUMN {$w[1]} {$w[2]}");
                }
            } catch (Throwable $e) {
                // biarkan: kalau ALTER ditolak, halaman tetap jalan dengan kolom yang ada.
            }
        }
    }
}
if (!function_exists('lembur_recalc_all')) {
    // Hitung ulang kolom lembur (Rp) untuk semua baris dengan lembur_jam > 0,
    // memakai tarif efektif per karyawan (fallback global). Dipanggil saat tarif di master disimpan.
    // Return jumlah baris yang nilainya berubah.
    function lembur_recalc_all($koneksi) {
        $q = $koneksi->query("UPDATE tb_realisasi_detail RD
            LEFT JOIN ms_karyawan K ON K.id_karyawan = RD.id_karyawan
            SET RD.lembur = ROUND(RD.lembur_jam * 60 *
                (CEIL(COALESCE(NULLIF(K.lembur_per_jam, 0), (SELECT lembur_per_jam FROM tb_lembur LIMIT 1)) / 60 * 10) / 10))
            WHERE RD.lembur_jam > 0");
        return $q ? (int)$koneksi->affected_rows : -1;
    }
}
