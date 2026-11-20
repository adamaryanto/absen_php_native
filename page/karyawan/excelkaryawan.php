<?php
// Export Excel Data Karyawan — filter OS (Semua / per-OS / Tanpa OS).
// 2026-10-31: tombol Download Excel di halaman Karyawan.
// - Login wajib (data HR sensitif: KTP/BPJS/upah) — dicek sebelum header() Excel.
// - Login OS dipaksa OS sendiri, apa pun ?os= yang dikirim (fail-closed).
// - Kolom Sidik Jari dari data backup template terakhir (tb_fingerprint), BUKAN
//   cek live ke mesin (cek live per karyawan untuk ~400 orang = ribuan SOAP,
//   download bakal timeout + bikin mesin sibuk; alasan yang sama auto-cek di
//   list dihapus 2026-10-24). Kolom Masuk Mesin dicek live borongan
//   (get_device_users_map = 1x GetAllUserInfo per mesin, cache maks 10 menit).
// 2026-10-31 (revisi): kolom diringkas jadi 7 (No, PIN, Nama, OS, Bagian, Sidik,
//   Mesin); semua sel rata tengah; baris info jadi OS + Tgl saja (tanpa jam unduh).
// 2026-10-31 (revisi 2): kolom Bagian dihapus (tidak perlu); tinggal 6 kolom.
error_reporting(0);
ini_set('display_errors', '0');
while (ob_get_level() > 0) { @ob_end_clean(); }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['iduser']) || empty($_SESSION['nama'])) {
    die('Akses ditolak: silakan login dulu.');
}
include "../../koneksi.php";
include_once __DIR__ . '/../../os_helper.php';
if (isset($koneksi) && $koneksi) { @$koneksi->set_charset('utf8mb4'); }
set_time_limit(300);

// ---------- Filter OS ----------
$os_param = trim((string)($_GET['os'] ?? 'all'));
$os_where = '';
$os_label = 'Semua Karyawan';
if (os_is_os()) {
    $os_id = os_id();
    $os_where = " AND k.id_os_dhk = " . $os_id;
    $q_lbl = $koneksi->query("SELECT OS_DHK FROM ms_os_dhk WHERE id_os_dhk = '" . $os_id . "' LIMIT 1");
    $r_lbl = $q_lbl ? $q_lbl->fetch_assoc() : null;
    $os_label = ($r_lbl && !empty($r_lbl['OS_DHK'])) ? $r_lbl['OS_DHK'] : 'OS Saya';
} elseif ($os_param === 'tanpa') {
    $os_where = " AND (k.id_os_dhk IS NULL OR k.id_os_dhk = 0)";
    $os_label = 'Tanpa OS';
} elseif (ctype_digit($os_param)) {
    $os_id = (int)$os_param;
    $os_where = " AND k.id_os_dhk = " . $os_id;
    $q_lbl = $koneksi->query("SELECT OS_DHK FROM ms_os_dhk WHERE id_os_dhk = '" . $os_id . "' LIMIT 1");
    $r_lbl = $q_lbl ? $q_lbl->fetch_assoc() : null;
    $os_label = ($r_lbl && !empty($r_lbl['OS_DHK'])) ? $r_lbl['OS_DHK'] : ('OS #' . $os_id);
}

// ---------- Data karyawan ----------
$q = $koneksi->query("SELECT k.*, o.OS_DHK AS label_os, g.golongan AS label_gol,
    d.nama_departmen AS label_bagian, j.jabatan AS label_posisi,
    sd.nama_sub_department AS label_sub
    FROM ms_karyawan k
    LEFT JOIN ms_os_dhk o ON k.id_os_dhk = o.id_os_dhk
    LEFT JOIN ms_golongan g ON k.id_golongan = g.id_golongan
    LEFT JOIN ms_departmen d ON k.id_departmen = d.id_departmen
    LEFT JOIN ms_jabatan j ON k.id_jabatan = j.id_jabatan
    LEFT JOIN ms_sub_department sd ON k.id_sub_department = sd.id_sub_department
    WHERE k.nama_karyawan NOT LIKE 'User PIN%'" . $os_where . "
    ORDER BY k.nama_karyawan ASC");
$rows = [];
while ($q && ($r = $q->fetch_assoc())) $rows[] = $r;

// ---------- Sidik Jari dari backup terakhir (tb_fingerprint) ----------
$sidik = []; // pin (string) => jumlah jari distinct yang valid
$ck_fp = $koneksi->query("SHOW TABLES LIKE 'tb_fingerprint'");
if ($ck_fp && $ck_fp->num_rows > 0) {
    $q_fp = $koneksi->query("SELECT TRIM(pin) AS pin, COUNT(DISTINCT finger_id) AS jml FROM tb_fingerprint WHERE valid = 1 GROUP BY TRIM(pin)");
    while ($q_fp && ($r_fp = $q_fp->fetch_assoc())) $sidik[trim((string)$r_fp['pin'])] = (int)$r_fp['jml'];
}

// ---------- Masuk Mesin, cek live borongan (1 panggilan per mesin) ----------
$mesin_nama = []; // id_mesin => nama
$mesin_pin = [];  // id_mesin => [pin => true]
include_once __DIR__ . '/../../device_sync_helper.php';
if (function_exists('get_all_devices')) {
    foreach (get_all_devices($koneksi) as $dv) {
        $mesin_nama[(int)($dv['id_mesin'] ?? 0)] = $dv['nama_mesin'] ?? ('Mesin ' . (int)($dv['id_mesin'] ?? 0));
    }
}
if (function_exists('get_device_users_map')) {
    $map = get_device_users_map($koneksi);
    if (is_array($map)) {
        foreach ($map as $did => $pins) {
            if (!is_array($pins)) continue;
            foreach ($pins as $pin => $nm) $mesin_pin[(int)$did][trim((string)$pin)] = true;
        }
    }
}
$total_mesin = count($mesin_nama);

function ex_tgl($v) {
    if (empty($v) || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '-';
    $t = strtotime($v);
    return $t ? date('d/m/Y', $t) : '-';
}
function ex_esc($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$fname = 'Data_Karyawan_' . preg_replace('/[^A-Za-z0-9]+/', '_', $os_label) . '_' . date('Y-m-d') . '.xls';
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=" . $fname);
header("Pragma: no-cache");
header("Expires: 0");

$cols = ['No', 'Absen', 'Nama Karyawan', 'OS', 'Sidik Jari', 'Masuk Mesin'];
$ncol = count($cols);
?>
<html><head><meta charset="utf-8"></head><body>
<table border="1">
<tr><td colspan="<?= $ncol ?>" style="font-weight:bold;font-size:14pt;text-align:center;">DATA KARYAWAN — <?= ex_esc(strtoupper($os_label)) ?></td></tr>
<tr><td colspan="<?= $ncol ?>" style="text-align:center;">OS: <?= ex_esc($os_label) ?> | Tgl: <?= date('d/m/Y') ?> | Jumlah: <?= count($rows) ?> karyawan</td></tr>
<tr><td colspan="<?= $ncol ?>" style="text-align:center;">Catatan: Status Sidik Jari dari data backup template terakhir (tb_fingerprint), bukan cek live ke mesin. Masuk Mesin dicek live dari mesin saat download (cache maks 10 menit; mesin offline memakai data terakhir yang tersimpan).</td></tr>
<tr>
<?php foreach ($cols as $c): ?><th style="text-align:center;"><?= ex_esc($c) ?></th><?php endforeach; ?>
</tr>
<?php
$no = 1;
foreach ($rows as $row):
    $pin_raw = $row['no_absen'] ?? '';
    $pin = trim((string)$pin_raw);
    $has_pin = ($pin !== '' && (int)$pin > 0);
    if ($has_pin) {
        $jml_jari = $sidik[$pin] ?? 0;
        $sidik_txt = $jml_jari > 0 ? ('Ada (' . $jml_jari . ' jari)') : 'Tidak ada di backup';
        if ($total_mesin > 0) {
            $found = [];
            foreach ($mesin_nama as $did => $nm) {
                if (!empty($mesin_pin[$did][$pin])) $found[] = $nm;
            }
            $mesin_txt = count($found) . '/' . $total_mesin . (empty($found) ? '' : ' (' . implode(', ', $found) . ')');
        } else {
            $mesin_txt = '-';
        }
    } else {
        $sidik_txt = '-';
        $mesin_txt = '-';
    }
?>
<tr>
<td style="text-align:center;"><?= $no++ ?></td>
<td style="text-align:center;"><?= ex_esc($has_pin ? $pin : '-') ?></td>
<td style="text-align:center;"><?= ex_esc($row['nama_karyawan'] ?? '') ?></td>
<td style="text-align:center;"><?= ex_esc($row['label_os'] ?? '-') ?></td>
<td style="text-align:center;"><?= ex_esc($sidik_txt) ?></td>
<td style="text-align:center;"><?= ex_esc($mesin_txt) ?></td>
</tr>
<?php endforeach; ?>
</table>
</body></html>
