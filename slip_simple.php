<?php
require_once __DIR__ . '/lembur_helper.php';
include "koneksi.php";
$id_karyawan = $_GET['id'] ?? '';
// 2026-11-09: default langsung 1 bulan penuh (dulu hari ini saja) — sama kayak preview.
$ttgl1 = $_GET['ttgl1'] ?? date('Y-m-01');
$ttgl2 = $_GET['ttgl2'] ?? date('Y-m-t');
if(!isset($_GET['ttgl1']) || !isset($_GET['ttgl2'])){
    $qRange=$koneksi->query("SELECT MAX(tgl_realisasi_detail) as max_tgl FROM tb_realisasi_detail WHERE id_karyawan='" . (int)$id_karyawan . "'");
    $dRange=$qRange?$qRange->fetch_assoc():null;
    if(!empty($dRange['max_tgl'])){ $ttgl1=date('Y-m-01', strtotime($dRange['max_tgl'])); $ttgl2=date('Y-m-t', strtotime($dRange['max_tgl'])); }
}
$bulanIndo=['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$bulan_angka=date("m", strtotime($ttgl1));
$bulan=$bulanIndo[$bulan_angka];
$q_kar=$koneksi->query("SELECT k.nama_karyawan, d.nama_departmen, os.OS_DHK FROM ms_karyawan k LEFT JOIN ms_departmen d ON k.id_departmen=d.id_departmen LEFT JOIN ms_os_dhk os ON k.id_os_dhk=os.id_os_dhk WHERE k.id_karyawan='$id_karyawan' LIMIT 1");
$d_kar=$q_kar->fetch_assoc();
$namaKaryawan=$d_kar['nama_karyawan']??"Karyawan_$id_karyawan";
// 2026-11-13: kalau master sudah dihapus, ambil nama dari snapshot history Realisasi.
if ($namaKaryawan === "Karyawan_$id_karyawan" || $namaKaryawan === '') {
    $q_nm=$koneksi->query("SELECT nama_karyawan_manual FROM tb_realisasi_detail WHERE id_karyawan='$id_karyawan' AND nama_karyawan_manual IS NOT NULL AND nama_karyawan_manual <> '' ORDER BY id_realisasi_detail DESC LIMIT 1");
    $d_nm=$q_nm?$q_nm->fetch_assoc():null;
    if ($d_nm && $d_nm['nama_karyawan_manual'] !== '') $namaKaryawan=$d_nm['nama_karyawan_manual'];
}
$nama_file=str_replace(' ', '_', $namaKaryawan);
$osdhk=$d_kar['OS_DHK']??'DHK';
$tgl_file=date('d-m-Y',strtotime($ttgl1));
// Gate OS (file download langsung, di luar router index.php): Excel slip
// hanya boleh untuk karyawan OS yang login. Harus sebelum header() Excel.
if (session_status() === PHP_SESSION_NONE) session_start();
include "os_helper.php";
if (function_exists('os_is_os') && os_is_os()) {
    $os_cek = $koneksi->query("SELECT id_os_dhk FROM ms_karyawan WHERE id_karyawan = '" . (int)$id_karyawan . "' LIMIT 1");
    $os_row = $os_cek ? $os_cek->fetch_assoc() : null;
    if (!$os_row || (int)$os_row['id_os_dhk'] !== (int)os_id()) {
        die('Akses ditolak: slip ini bukan milik OS Anda.');
    }
}
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Slip_".$nama_file."_".$tgl_file.".xls");
header("Pragma: no-cache");
header("Expires: 0");
echo "<meta charset='UTF-8'>";
function rupiah($a){ return "Rp " . number_format($a, 0, ',', '.'); }
$q_denda=$koneksi->query("SELECT * FROM tb_denda LIMIT 1"); $d_denda=$q_denda->fetch_assoc();
$globalDendaMasuk=$d_denda['denda_masuk']??0;
$globalDendaIstKeluar=$d_denda['denda_istirahat_keluar']??0;
$globalDendaIstMasuk=$d_denda['denda_istirahat_masuk']??0;
$globalDendaPulang=$d_denda['denda_pulang']??0;
$globalDendaTidakLengkap=$d_denda['denda_tidak_lengkap']??0;
// 2026-11-09: Cetak Slip (Simple) sekarang baca REALISASI (tb_realisasi_detail),
// sama seperti Slip Gaji (slip.php) — bukan lagi tap mentah tb_record + upah master.
$sql_real="SELECT r.*, RR.tgl_realisasi AS realisasi_tgl, j.jam_masuk, j.jam_keluar, j.istirahat_masuk, j.istirahat_keluar, j.keterangan as shift_ket, rd.status_rkk FROM tb_realisasi_detail r LEFT JOIN tb_realisasi RR ON RR.id_realisasi = r.id_realisasi LEFT JOIN tb_jadwal j ON r.id_jadwal = j.id_jadwal LEFT JOIN tb_rkk_detail rd ON r.id_rkk_detail = rd.id_rkk_detail WHERE (r.id_karyawan = '" . (int)$id_karyawan . "' OR (r.id_pengganti IS NOT NULL AND r.id_pengganti = '" . (int)$id_karyawan . "' AND r.id_pengganti <> 0)) AND r.tgl_realisasi_detail BETWEEN '$ttgl1' AND '$ttgl2' ORDER BY r.tgl_realisasi_detail ASC";
$q_real=$koneksi->query($sql_real);
$rows_real=[];
while($q_real && ($rr=$q_real->fetch_assoc())) $rows_real[]=$rr;
// 2026-11-09: dedupe 1 baris per orang per tanggal (kasus Realisasi 68:
// 255 baris 04/11 nyasar di Realisasi 03/11). Prioritas: baris dari Realisasi
// yang tanggalnya sama dengan tanggal detail (baris "benar", sama kayak Excel);
// kalau tidak ada, ambil id_realisasi_detail terkecil.
$dedupe = [];
foreach ($rows_real as $rr) {
    $receiver = ((int)($rr['id_pengganti'] ?? 0) === (int)$id_karyawan && (int)$id_karyawan > 0) ? (int)$id_karyawan : (int)($rr['id_karyawan'] ?? 0);
    $dk = $receiver . '|' . $rr['tgl_realisasi_detail'];
    $dm = (!empty($rr['realisasi_tgl']) && $rr['realisasi_tgl'] === $rr['tgl_realisasi_detail']) ? 1 : 0;
    if (!isset($dedupe[$dk]) || $dm > $dedupe[$dk]['m'] || ($dm === $dedupe[$dk]['m'] && (int)$rr['id_realisasi_detail'] < (int)$dedupe[$dk]['row']['id_realisasi_detail'])) {
        $dedupe[$dk] = ['row' => $rr, 'm' => $dm];
    }
}
$rows_real = [];
foreach ($dedupe as $dv) $rows_real[] = $dv['row'];
usort($rows_real, function($a, $b){ return strcmp($a['tgl_realisasi_detail'], $b['tgl_realisasi_detail']); });
// 2026-11-13 (borongan): peta total borongan per id_realisasi_detail —
// upah ikut total borongan, bukan gaji pokok (sama seperti Kelola).
$bor_map_slip = [];
$ckBorSlip = $koneksi->query("SHOW TABLES LIKE 'tb_realisasi_borongan'");
if ($ckBorSlip && $ckBorSlip->num_rows > 0) {
    $qBorSlip = $koneksi->query("SELECT b.id_realisasi_detail, SUM(ROUND(b.qty * b.tarif)) AS total FROM tb_realisasi_borongan b JOIN tb_realisasi_detail d ON b.id_realisasi_detail = d.id_realisasi_detail WHERE (d.id_karyawan = '$id_karyawan' OR d.id_pengganti = '$id_karyawan') AND d.tgl_realisasi_detail BETWEEN '$ttgl1' AND '$ttgl2' GROUP BY b.id_realisasi_detail");
    while ($qBorSlip && ($br = $qBorSlip->fetch_assoc())) $bor_map_slip[(int)$br['id_realisasi_detail']] = (int)$br['total'];
}
echo "<table border='1'>";
echo '<tr><td rowspan="2" style="width:80px; font-weight:bold; text-align:center; vertical-align:middle;">Bulan '.$bulan.'</td><td colspan="8" style="font-weight:bold; text-align:center; font-size:14pt;">SLIP UPAH</td><td colspan="3" rowspan="2" style="font-weight:bold; text-align:center; vertical-align:middle;">OUTSOURCING : '.$osdhk.'</td></tr>';
echo '<tr><td colspan="8" style="font-weight:bold; text-align:left;">NAMA : '.$namaKaryawan.'</td></tr>';
echo "<tr><th style='width:90px;'>Tanggal</th><th>Upah</th><th>Jam Masuk</th><th>Jam Pulang</th><th>Pot Telat</th><th>Pot Istirahat</th><th>Pot Pulang</th><th>Pot Tidak Absen</th><th>Pot Lainnya</th><th>Lembur</th><th>Total</th><th>Karyawan Setuju</th><th>OS</th><th>HRD ".$osdhk."</th></tr>";
$totalAll=0;
if(empty($rows_real)){
    echo "<tr><td colspan='14' style='text-align:center'>Tidak ada data Realisasi di periode $ttgl1 s/d $ttgl2</td></tr>";
} else {
    foreach($rows_real as $row){
        // 2026-11-13: lembur tampil = jam × tarif EFEKTIF MASTER terkini.
        $row['lembur'] = lembur_display_rp($koneksi, $row);
        // Baris digantikan: tampil hanya di slip PENGGANTI (sebagai "Hadir").
        $id_pengganti_slip = (int)($row['id_pengganti'] ?? 0);
        $this_is_pg_slip = ($id_pengganti_slip > 0 && $id_pengganti_slip === (int)$id_karyawan);
        if ($id_pengganti_slip > 0 && !$this_is_pg_slip) continue;
        $shift_ket = $row['shift_ket'] ?? '';
        $is_flexible = (strpos(strtoupper($shift_ket), 'FLEXI') !== false || strpos(strtoupper($shift_ket), 'FLEKSI') !== false);
        if ($id_pengganti_slip > 0) $is_flexible = true;
        $isLate = (!$is_flexible && !empty($row['ra_masuk']) && $row['ra_masuk'] != '00:00:00' && !empty($row['jam_masuk']) && $row['jam_masuk'] != '00:00:00' && strtotime($row['ra_masuk']) > strtotime($row['jam_masuk']));
        $isLateBreak = (!$is_flexible && !empty($row['ra_istirahat_masuk']) && $row['ra_istirahat_masuk'] != '00:00:00' && !empty($row['istirahat_masuk']) && $row['istirahat_masuk'] != '00:00:00' && strtotime($row['ra_istirahat_masuk']) > strtotime($row['istirahat_masuk']));
        $isEarlyOut = (!$is_flexible && !empty($row['ra_keluar']) && $row['ra_keluar'] != '00:00:00' && !empty($row['jam_keluar']) && $row['jam_keluar'] != '00:00:00' && strtotime($row['ra_keluar']) < strtotime($row['jam_keluar']));
        $hasIncompleteMain = (
            (!empty($row['ra_masuk']) && $row['ra_masuk'] != '00:00:00' && (empty($row['ra_keluar']) || $row['ra_keluar'] == '00:00:00')) ||
            ((empty($row['ra_masuk']) || $row['ra_masuk'] == '00:00:00') && !empty($row['ra_keluar']) && $row['ra_keluar'] != '00:00:00')
        );
        // 2026-11-10: Denda tidak lengkap TIDAK lagi menghitung istirahat (samakan dengan preview web).
        $hasIncompleteBreak = false;
        $isEarlyBreak = (!$is_flexible && !empty($row['ra_istirahat_keluar']) && $row['ra_istirahat_keluar'] != '00:00:00' && !empty($row['istirahat_keluar']) && $row['istirahat_keluar'] != '00:00:00' && strtotime($row['ra_istirahat_keluar']) < strtotime($row['istirahat_keluar']));
        if ($row['r_upah'] == 0 || $row['status_rkk'] == 'Digantikan') {
            $potTelatValue = 0; $potIstirahatValue = 0; $potPulangValue = 0; $potTidakLengkapValue = 0;
        } else {
            $potTelatValue = $isLate ? $globalDendaMasuk : 0;
            $potIstirahatValue = ($isEarlyBreak ? $globalDendaIstKeluar : 0) + ($isLateBreak ? $globalDendaIstMasuk : 0);
            $potPulangValue = $isEarlyOut ? $globalDendaPulang : 0;
            $potTidakLengkapValue = ($hasIncompleteMain || $hasIncompleteBreak) ? $globalDendaTidakLengkap : 0;
        }
        // 2026-11-13 (borongan): upah ikut total borongan, bukan gaji pokok.
        $bor_total_row = $bor_map_slip[(int)($row['id_realisasi_detail'] ?? 0)] ?? 0;
        if ($bor_total_row > 0) {
            $row['r_upah'] = $bor_total_row;
            $row['lembur'] = 0;
            // 2026-11-13 (revisi): denda telat & pulang awal TETAP kena buat borongan (Reza).
            $potIstirahatValue = 0;
            $potTidakLengkapValue = 0;
            $row['r_potongan_lainnya'] = 0;
        }
        $totalRow = ($row['r_upah'] + $row['lembur']) - ($potTelatValue + $potIstirahatValue + $potPulangValue + $potTidakLengkapValue + $row['r_potongan_lainnya']);
        $totalAll += $totalRow;
        $styleTotal = $totalRow < 0 ? 'color:red;' : '';
        $jamMasuk = (!empty($row['ra_masuk']) && $row['ra_masuk'] != '00:00:00') ? substr($row['ra_masuk'], 0, 5) : '-';
        $jamPulang = (!empty($row['ra_keluar']) && $row['ra_keluar'] != '00:00:00') ? substr($row['ra_keluar'], 0, 5) : '-';
        echo "<tr><td style='mso-number-format:\"\\@\"; width:90px; white-space:nowrap;'>".date('d/m/Y',strtotime($row['tgl_realisasi_detail']))."</td><td>".rupiah($row['r_upah'])."</td><td>".$jamMasuk."</td><td>".$jamPulang."</td><td>".rupiah($potTelatValue)."</td><td>".rupiah($potIstirahatValue)."</td><td>".rupiah($potPulangValue)."</td><td>".rupiah($potTidakLengkapValue)."</td><td>".rupiah($row['r_potongan_lainnya'])."</td><td>".rupiah($row['lembur'])."</td><td style='font-weight:bold; $styleTotal'>".rupiah($totalRow)."</td><td></td><td></td><td></td></tr>";
    }
    echo "<tr style='font-weight:bold; background:#f8f9fa;'><td colspan='10' style='text-align:right'>SUBTOTAL GAJI</td><td style='text-align:right; background:#e0f2fe; color:#1e40af;'>".rupiah($totalAll)."</td><td colspan='3'></td></tr>";
}
// ===== Insentif bulanan dinamis (per orang, sama seperti slip Realisasi) =====
$ins_bulanan = 0; $ins_rencana = 0; $ins_masuk = 0; $ins_diterima = 0; $ins_bulan_label = '';
$ckIns = $koneksi->query("SHOW COLUMNS FROM ms_karyawan LIKE 'insentif_bulanan'");
if ($ckIns && $ckIns->num_rows > 0) {
    $qIns = $koneksi->query("SELECT insentif_bulanan FROM ms_karyawan WHERE id_karyawan = '" . (int)$id_karyawan . "' LIMIT 1");
    $dIns = $qIns ? $qIns->fetch_assoc() : null;
    $ins_bulanan = (int)($dIns['insentif_bulanan'] ?? 0);
}
if ($ins_bulanan > 0 && !empty($ttgl1)) {
    $ins_bln = date('Y-m', strtotime($ttgl1));
    $ins_bulan_label = $bulanIndo[date('m', strtotime($ttgl1))] . ' ' . date('Y', strtotime($ttgl1));
    $qR = $koneksi->query("SELECT COUNT(DISTINCT R.tgl_rkk) AS j FROM tb_rkk R JOIN tb_rkk_detail RD ON RD.id_rkk = R.id_rkk WHERE RD.id_karyawan = '" . (int)$id_karyawan . "' AND DATE_FORMAT(R.tgl_rkk, '%Y-%m') = '$ins_bln'");
    $ins_rencana = (int)(($qR ? $qR->fetch_assoc() : null)['j'] ?? 0);
    $qM = $koneksi->query("SELECT COUNT(*) AS j FROM tb_realisasi_detail WHERE (id_karyawan = '" . (int)$id_karyawan . "' OR id_pengganti = '" . (int)$id_karyawan . "') AND DATE_FORMAT(tgl_realisasi_detail, '%Y-%m') = '$ins_bln' AND r_status = 'Hadir'");
    $ins_masuk = (int)(($qM ? $qM->fetch_assoc() : null)['j'] ?? 0);
    if ($ins_rencana > 0) {
        $ins_diterima = (int)round($ins_bulanan * min($ins_masuk, $ins_rencana) / $ins_rencana);
    }
}
if ($ins_bulanan > 0) {
    echo "
<tr style='font-weight:bold; background:#fffbeb;'>
    <td colspan='10' style='text-align:right; height:30px; vertical-align:middle;'>INSENTIF BULANAN &mdash; " . $ins_bulan_label . "</td>
    <td style='text-align:right; vertical-align:middle;'>" . rupiah($ins_bulanan) . "</td>
    <td colspan='3'></td>
</tr>
<tr>
    <td colspan='10' style='text-align:right; vertical-align:middle;'>HARI KERJA RENCANA (SEBULAN)</td>
    <td style='text-align:right; vertical-align:middle;'>" . $ins_rencana . " hari</td>
    <td colspan='3'></td>
</tr>
<tr>
    <td colspan='10' style='text-align:right; vertical-align:middle;'>HARI MASUK (REALISASI)</td>
    <td style='text-align:right; vertical-align:middle;'>" . $ins_masuk . " hari</td>
    <td colspan='3'></td>
</tr>
<tr style='font-weight:bold; background:#ecfdf5;'>
    <td colspan='10' style='text-align:right; height:30px; vertical-align:middle;'>INSENTIF DITERIMA</td>
    <td style='text-align:right; vertical-align:middle; color:#065f46;'>" . rupiah($ins_diterima) . "</td>
    <td colspan='3'></td>
</tr>";
}

echo "</table>";
?>