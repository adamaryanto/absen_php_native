<?php
// Helper role OS (2026-10-30): login per-OS, lihat saja, data OS-nya sendiri.
// OS = pihak luar pabrik: boleh buka Realisasi, Tarik Data, Karyawan â€” tapi
// TIDAK boleh menambah, mengubah, menghapus, approve, tarik/pull, atau
// menyimpan apa pun. Semua gate di sini fail-closed: kalau id_os_dhk tidak
// ada (migrasi belum jalan / akun belum dipasangi OS), filter tidak
// menampilkan apa-apa, bukan membocorkan data OS lain.
// Dipakai index.php (router + sidebar) dan halaman yang diizinkan untuk OS.
// Aman dipanggil berkali-kali dan aman bila session belum jalan.
// Nilai -1 = akun OS yang belum dipasangi OS: tidak cocok dengan karyawan mana
// pun, jadi semua filter OS menampilkan kosong (fail closed), bukan data campur.
if (!function_exists('os_is_os')) {
    function os_is_os() {
        $r = strtolower((string)($_SESSION['role'] ?? ''));
        if ($r === 'os') return true;
        // Role internal jelas bukan OS.
        if (in_array($r, ['kepala pabrik', 'admin hr', 'admin master', 'owner', 'admin boneless & borongan', ''], true)) return false;
        // Role lain (DHK, MJS, WJS, RKA, MHS, RDS, CP, ...) = role per-OS:
        // dihitung OS asal sesi punya id_os_dhk (termasuk -1 = belum dipasangi OS,
        // yang filternya tampil kosong, bukan campur).
        return (int)($_SESSION['id_os_dhk'] ?? 0) !== 0;
    }
}
if (!function_exists('os_id')) {
    function os_id() {
        return (int)($_SESSION['id_os_dhk'] ?? 0);
    }
}
if (!function_exists('os_nama')) {
    function os_nama() {
        return (string)($_SESSION['os_nama'] ?? '');
    }
}
// SQL: id OS efektif sebuah baris detail.
// $m = alias ms_karyawan (master), $d = alias tb_realisasi_detail.
// Master diutamakan; baris manual (id_karyawan=0, master NULL) pakai id_os_dhk detail.
if (!function_exists('os_effective_sql')) {
    function os_effective_sql($m, $d) {
        return "COALESCE(NULLIF($m.id_os_dhk, 0), $d.id_os_dhk)";
    }
}
// Blokir OS dengan alert + redirect ke Realisasi, lalu exit.
// Dipakai di awal handler POST / halaman yang tidak boleh dibuka OS.
if (!function_exists('os_block')) {
    function os_block($msg = 'Akses ditolak untuk role OS (lihat saja).') {
        if (!os_is_os()) return;
        echo '<!DOCTYPE html><html><head><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script></head><body><script>Swal.fire({ icon: "error", title: "Akses Ditolak", text: "' . addslashes($msg) . '", confirmButtonColor: "#2563eb" }).then(() => { window.location.href = "?page=realisasi"; });</script></body></html>';
        exit;
    }
}
// Blokir OS untuk endpoint JSON (borongan_ajax, tarik_ajax, ajax_hapus).
if (!function_exists('os_block_json')) {
    function os_block_json($msg = 'Role OS hanya boleh melihat, tidak boleh mengubah data.') {
        if (!os_is_os()) return;
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'success' => false, 'msg' => $msg]);
        exit;
    }
}
