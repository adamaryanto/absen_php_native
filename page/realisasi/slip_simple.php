<?php
require_once __DIR__ . '/../../lembur_helper.php';
$id = $_GET['id'] ?? '';
// Gate OS: slip ini hanya boleh dibuka untuk karyawan OS yang login.
// (Semua query di halaman ini sudah tersekat oleh $id, jadi cek di sini cukup.)
if (function_exists('os_is_os') && os_is_os()) {
    $os_cek = $koneksi->query("SELECT id_os_dhk FROM ms_karyawan WHERE id_karyawan = '" . (int)$id . "' LIMIT 1");
    $os_row = $os_cek ? $os_cek->fetch_assoc() : null;
    if (!$os_row || (int)$os_row['id_os_dhk'] !== (int)os_id()) {
        echo "<script>alert('Akses ditolak: slip ini bukan milik OS Anda.'); window.location.href='?page=realisasi&aksi=slipk';</script>"; exit;
    }
}
// 2026-11-09: default langsung 1 bulan penuh (dulu 7 hari) — buka slip langsung
// keluar sebulan tanpa klik Cari dulu.
$ttgl1 = $_GET['ttgl1'] ?? date("Y-m-01");
$ttgl2 = $_GET['ttgl2'] ?? date("Y-m-t");
$q_karyawan_info = $koneksi->query("SELECT A.nama_karyawan, B.nama_departmen, O.OS_DHK AS label_os FROM ms_karyawan A LEFT JOIN ms_departmen B ON A.id_departmen = B.id_departmen LEFT JOIN ms_os_dhk O ON A.id_os_dhk = O.id_os_dhk WHERE A.id_karyawan = '$id'");
$d_karyawan_info = $q_karyawan_info->fetch_assoc();
$namaKaryawan = $d_karyawan_info['nama_karyawan'] ?? 'Karyawan Tidak Ditemukan';
// 2026-11-13: kalau master sudah dihapus, ambil nama dari snapshot history Realisasi
// (nama_karyawan_manual) supaya slip tidak tampil 'Karyawan Tidak Ditemukan'.
if ($namaKaryawan === 'Karyawan Tidak Ditemukan' || $namaKaryawan === '') {
    $q_nm = $koneksi->query("SELECT nama_karyawan_manual FROM tb_realisasi_detail WHERE id_karyawan = '$id' AND nama_karyawan_manual IS NOT NULL AND nama_karyawan_manual <> '' ORDER BY id_realisasi_detail DESC LIMIT 1");
    $d_nm = $q_nm ? $q_nm->fetch_assoc() : null;
    if ($d_nm && $d_nm['nama_karyawan_manual'] !== '') $namaKaryawan = $d_nm['nama_karyawan_manual'];
}
$deptKaryawan = $d_karyawan_info['nama_departmen'] ?? '-';
// 2026-10-31: keterangan OS di header preview (badge OS di samping badge bagian).
$osKaryawan = !empty($d_karyawan_info['label_os']) ? $d_karyawan_info['label_os'] : '-';
if(!isset($_GET['ttgl1']) || !isset($_GET['ttgl2'])){
    $qRange=$koneksi->query("SELECT MAX(tgl_realisasi_detail) as max_tgl FROM tb_realisasi_detail WHERE id_karyawan='" . (int)$id . "'");
    $dRange=$qRange?$qRange->fetch_assoc():null;
    if(!empty($dRange['max_tgl'])){ $ttgl1=date('Y-m-01', strtotime($dRange['max_tgl'])); $ttgl2=date('Y-m-t', strtotime($dRange['max_tgl'])); }
}
function rupiah($a){ return "Rp " . number_format($a, 0, ',', '.'); }
?>
<div class="container-fluid px-3 md:px-6 mt-6 mb-10">
    <div class="bg-white shadow-xl rounded-2xl border border-gray-100 overflow-hidden">
        <div class="bg-gradient-to-r from-blue-700 via-blue-600 to-indigo-700 px-6 md:px-8 py-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 bg-white/20 backdrop-blur-md rounded-xl flex items-center justify-center text-white border border-white/30">
                        <i class="fas fa-file-invoice-dollar text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl md:text-2xl font-bold text-white m-0">Preview Slip Gaji</h3>
                        <p class="text-blue-100 text-sm font-medium opacity-90"><?= $namaKaryawan ?> • <span class="bg-white/20 px-2 py-0.5 rounded text-[11px] uppercase"><?= $deptKaryawan ?></span> • <span class="bg-white/20 px-2 py-0.5 rounded text-[11px] uppercase">OS: <?= $osKaryawan ?></span></p>
                    </div>
                </div>
                <a href="index.php?page=realisasi&aksi=slipk" class="w-40 h-10 bg-white/10 hover:bg-white/20 backdrop-blur-md rounded-xl flex items-center justify-center text-white border border-white/20">
                    <i class="fas fa-arrow-left mr-2"></i>Kembali
                </a>
            </div>
        </div>
        <div class="p-4 md:p-8">
            <form method="GET" class="bg-blue-50/50 border border-blue-100 p-4 md:p-5 rounded-2xl mb-8">
                <input type="hidden" name="page" value="realisasi">
                <input type="hidden" name="aksi" value="slip_simple">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="flex flex-col md:flex-row items-center gap-4">
                    <div class="w-full md:flex-1 flex items-center bg-white px-4 h-[40px] rounded-xl border border-blue-100 shadow-sm">
                        <i class="fas fa-user-circle text-blue-500 mr-3"></i>
                        <span class="text-sm font-bold text-gray-700 truncate"><?= $namaKaryawan ?></span>
                    </div>
                    <div class="w-full md:w-40">
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 text-xs"><i class="fas fa-calendar-alt"></i></span>
                            <input type="date" name="ttgl1" required value="<?= $ttgl1 ?>" class="w-full h-[40px] pl-9 pr-3 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        </div>
                    </div>
                    <div class="w-full md:w-40">
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 text-xs"><i class="fas fa-calendar-alt"></i></span>
                            <input type="date" name="ttgl2" required value="<?= $ttgl2 ?>" class="w-full h-[40px] pl-9 pr-3 border border-gray-200 rounded-xl outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        </div>
                    </div>
                    <button type="submit" name="cari" value="1" class="h-[40px] px-6 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl shadow-md w-full md:w-auto flex items-center justify-center">
                        <i class="fas fa-search mr-2"></i>Cari
                    </button>
                </div>
            </form>

            <?php
            // 2026-11-09: Cetak Slip (Simple) sekarang baca REALISASI (tb_realisasi_detail),
            // sama seperti Slip Gaji (slip.php) — bukan lagi tap mentah tb_record + upah master.
            // Jadi angkanya selalu sama dengan Kelola/Realisasi: upah Rencana, Ganti Karyawan
            // (Digantikan = 0), edit upah di Detail, dan TDR (= 0) ikut kebawa.
            // Tanggal yang belum ada Realisasinya tidak muncul di slip.
            $q_denda=$koneksi->query("SELECT * FROM tb_denda LIMIT 1"); $d_denda=$q_denda?$q_denda->fetch_assoc():[];
            $globalDendaMasuk=$d_denda['denda_masuk']??0; $globalDendaIstirahatKeluar=$d_denda['denda_istirahat_keluar']??0; $globalDendaIstirahatMasuk=$d_denda['denda_istirahat_masuk']??0; $globalDendaPulang=$d_denda['denda_pulang']??0; $globalDendaTidakLengkap=$d_denda['denda_tidak_lengkap']??0;
            $sql_real="SELECT r.*, RR.tgl_realisasi AS realisasi_tgl, j.jam_masuk, j.jam_keluar, j.istirahat_masuk, j.istirahat_keluar, j.keterangan as shift_ket, rd.status_rkk FROM tb_realisasi_detail r LEFT JOIN tb_realisasi RR ON RR.id_realisasi = r.id_realisasi LEFT JOIN tb_jadwal j ON r.id_jadwal = j.id_jadwal LEFT JOIN tb_rkk_detail rd ON r.id_rkk_detail = rd.id_rkk_detail WHERE (r.id_karyawan = '" . (int)$id . "' OR (r.id_pengganti IS NOT NULL AND r.id_pengganti = '" . (int)$id . "' AND r.id_pengganti <> 0)) AND r.tgl_realisasi_detail BETWEEN '$ttgl1' AND '$ttgl2' ORDER BY r.tgl_realisasi_detail ASC";
            $q_real=$koneksi->query($sql_real);
            $rows_real=[];
            while($q_real && ($rr=$q_real->fetch_assoc())) $rows_real[]=$rr;
            // 2026-11-09: dedupe 1 baris per orang per tanggal (kasus Realisasi 68:
            // 255 baris 04/11 nyasar di Realisasi 03/11). Prioritas: baris dari Realisasi
            // yang tanggalnya sama dengan tanggal detail (baris "benar", sama kayak Excel);
            // kalau tidak ada, ambil id_realisasi_detail terkecil.
            $dedupe = [];
            foreach ($rows_real as $rr) {
                $receiver = ((int)($rr['id_pengganti'] ?? 0) === (int)$id && (int)$id > 0) ? (int)$id : (int)($rr['id_karyawan'] ?? 0);
                $dk = $receiver . '|' . (!empty($rr['realisasi_tgl']) ? $rr['realisasi_tgl'] : $rr['tgl_realisasi_detail']);
                $dm = (!empty($rr['realisasi_tgl']) ? $rr['realisasi_tgl'] : '') === $rr['tgl_realisasi_detail'] ? 1 : 0;
                if (!isset($dedupe[$dk]) || $dm > $dedupe[$dk]['m'] || ($dm === $dedupe[$dk]['m'] && (int)$rr['id_realisasi_detail'] < (int)$dedupe[$dk]['row']['id_realisasi_detail'])) {
                    $dedupe[$dk] = ['row' => $rr, 'm' => $dm];
                }
            }
            $rows_real = [];
            foreach ($dedupe as $dv) $rows_real[] = $dv['row'];
            // 2026-11-13 (borongan): peta total borongan per id_realisasi_detail —
            // upah ikut total borongan, bukan gaji pokok (sama seperti Kelola).
            $bor_map_slip = [];
            $ckBorSlip = $koneksi->query("SHOW TABLES LIKE 'tb_realisasi_borongan'");
            if ($ckBorSlip && $ckBorSlip->num_rows > 0) {
                $qBorSlip = $koneksi->query("SELECT b.id_realisasi_detail, SUM(ROUND(b.qty * b.tarif)) AS total FROM tb_realisasi_borongan b JOIN tb_realisasi_detail d ON b.id_realisasi_detail = d.id_realisasi_detail WHERE (d.id_karyawan = '$id' OR d.id_pengganti = '$id') AND d.tgl_realisasi_detail BETWEEN '$ttgl1' AND '$ttgl2' GROUP BY b.id_realisasi_detail");
                while ($qBorSlip && ($br = $qBorSlip->fetch_assoc())) $bor_map_slip[(int)$br['id_realisasi_detail']] = (int)$br['total'];
            }
            usort($rows_real, function($a, $b){ return strcmp($a['tgl_realisasi_detail'], $b['tgl_realisasi_detail']); });
            ?>
<?php
// ===== Insentif bulanan dinamis (per orang, sama seperti slip Realisasi) =====
// Pembagi = hari kerja Rencana sebulan (dari RKK bulan ttgl1).
// Potong per hari Realisasi kosong (tidak masuk) di bulan itu.
$ins_bulanan = 0; $ins_rencana = 0; $ins_masuk = 0; $ins_diterima = 0; $ins_bulan_label = '';
$ckIns = $koneksi->query("SHOW COLUMNS FROM ms_karyawan LIKE 'insentif_bulanan'");
if ($ckIns && $ckIns->num_rows > 0) {
    $qIns = $koneksi->query("SELECT insentif_bulanan FROM ms_karyawan WHERE id_karyawan = '" . (int)$id . "' LIMIT 1");
    $dIns = $qIns ? $qIns->fetch_assoc() : null;
    $ins_bulanan = (int)($dIns['insentif_bulanan'] ?? 0);
}
if ($ins_bulanan > 0 && !empty($ttgl1)) {
    $ins_bln = date('Y-m', strtotime($ttgl1));
    $bulanIndo_ins = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $ins_bulan_label = $bulanIndo_ins[date('m', strtotime($ttgl1))] . ' ' . date('Y', strtotime($ttgl1));
    $qR = $koneksi->query("SELECT COUNT(DISTINCT R.tgl_rkk) AS j FROM tb_rkk R JOIN tb_rkk_detail RD ON RD.id_rkk = R.id_rkk WHERE RD.id_karyawan = '" . (int)$id . "' AND DATE_FORMAT(R.tgl_rkk, '%Y-%m') = '$ins_bln'");
    $ins_rencana = (int)(($qR ? $qR->fetch_assoc() : null)['j'] ?? 0);
    $qM = $koneksi->query("SELECT COUNT(*) AS j FROM tb_realisasi_detail WHERE (id_karyawan = '" . (int)$id . "' OR id_pengganti = '" . (int)$id . "') AND DATE_FORMAT(tgl_realisasi_detail, '%Y-%m') = '$ins_bln' AND r_status = 'Hadir'");
    $ins_masuk = (int)(($qM ? $qM->fetch_assoc() : null)['j'] ?? 0);
    if ($ins_rencana > 0) {
        $ins_diterima = (int)round($ins_bulanan * min($ins_masuk, $ins_rencana) / $ins_rencana);
    }
}
?>
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6 pt-4 border-t border-gray-100">
                <div>
                    <h4 class="text-lg font-bold text-gray-800 m-0">Hasil Pencarian</h4>
                    <p class="text-gray-500 text-xs mt-1">Menampilkan data slip gaji periode <?= date('d/m/Y',strtotime($ttgl1)) ?> - <?= date('d/m/Y',strtotime($ttgl2)) ?></p>
                </div>
                <?php if(!empty($rows_real)): ?>
                <a target="_blank" href="slip_simple.php?id=<?= $id ?>&ttgl1=<?= $ttgl1 ?>&ttgl2=<?= $ttgl2 ?>" class="flex items-center justify-center px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-md">
                    <i class="fas fa-file-excel mr-2"></i> Download Excel
                </a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="w-full text-left border-collapse table-modern" id="slipSimpleTable">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="py-3 px-3 text-[11px] font-bold uppercase">Tanggal</th>
                            <th class="py-3 px-3 text-[11px] font-bold uppercase">Upah Pokok</th>
                            <th class="py-3 px-3 text-[11px] font-bold uppercase">Masuk/Pulang</th>
                            <th class="py-3 px-3 text-[11px] font-bold uppercase">Pot. Telat</th>
                            <th class="py-3 px-3 text-[11px] font-bold uppercase">Pot. Istirahat</th>
                            <th class="py-3 px-3 text-[11px] font-bold uppercase">Pot. Pulang</th>
                            <th class="py-3 px-3 text-[11px] font-bold uppercase">Pot. Tidak Absen</th>
                            <th class="py-3 px-3 text-[11px] font-bold uppercase">Pot. Lain</th>
                            <th class="py-3 px-3 text-[11px] font-bold uppercase">Lembur</th>
                            <th class="py-3 px-3 text-[11px] font-bold uppercase text-right">Total Net</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php if(empty($rows_real)): ?>
                        <tr><td class="py-3 px-3 text-sm">-</td><td class="py-3 px-3 text-sm">-</td><td class="py-3 px-3 text-sm">-</td><td class="py-3 px-3 text-sm">-</td><td class="py-3 px-3 text-sm">-</td><td class="py-3 px-3 text-sm">-</td><td class="py-3 px-3 text-sm">-</td><td class="py-3 px-3 text-sm">-</td><td class="py-3 px-3 text-sm">-</td><td class="py-3 px-3 text-sm text-right">-</td></tr>
                        <tr><td colspan="10" class="py-4 text-center text-gray-400 text-sm">Tidak ada data Realisasi di periode ini — coba ganti tanggal</td></tr>
                        <?php else: foreach($rows_real as $row):
                            // 2026-11-13: lembur tampil = jam × tarif EFEKTIF MASTER terkini.
                            $row['lembur'] = lembur_display_rp($koneksi, $row);
                            $id_pengganti_slip = (int)($row['id_pengganti'] ?? 0);
                            $this_is_pg_slip = ($id_pengganti_slip > 0 && $id_pengganti_slip === (int)$id);
                            // Baris digantikan: tampil hanya di slip PENGGANTI (sebagai "Hadir");
                            // di slip karyawan asli baris ini di-skip.
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
                            // 2026-11-10: Denda tidak lengkap TIDAK lagi menghitung istirahat.
                            $hasIncompleteBreak = false;
                            $isEarlyBreak = (!$is_flexible && !empty($row['ra_istirahat_keluar']) && $row['ra_istirahat_keluar'] != '00:00:00' && !empty($row['istirahat_keluar']) && $row['istirahat_keluar'] != '00:00:00' && strtotime($row['ra_istirahat_keluar']) < strtotime($row['istirahat_keluar']));
                            if ($row['r_upah'] == 0 || $row['status_rkk'] == 'Digantikan') {
                                $potTelatValue = 0; $potIstirahatValue = 0; $potPulangValue = 0; $potTidakLengkapValue = 0;
                            } else {
                                $potTelatValue = $isLate ? $globalDendaMasuk : 0;
                                $potIstirahatValue = ($isEarlyBreak ? $globalDendaIstirahatKeluar : 0) + ($isLateBreak ? $globalDendaIstirahatMasuk : 0);
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
                            $total = ($row['r_upah'] + $row['lembur']) - ($potTelatValue + $potIstirahatValue + $potPulangValue + $potTidakLengkapValue + $row['r_potongan_lainnya']);
                            $jamMasuk = (!empty($row['ra_masuk']) && $row['ra_masuk'] != '00:00:00') ? substr($row['ra_masuk'], 0, 5) : '-';
                            $jamPulang = (!empty($row['ra_keluar']) && $row['ra_keluar'] != '00:00:00') ? substr($row['ra_keluar'], 0, 5) : '-';
                        ?>
                        <tr>
                            <td class="py-3 px-3 text-sm font-bold"><?= date('d/m/Y',strtotime($row['realisasi_tgl'] ?? $row['tgl_realisasi_detail'])) ?></td>
                            <td class="py-3 px-3 text-sm"><?= rupiah($row['r_upah']) ?></td>
                            <td class="py-3 px-3 text-sm"><span class="bg-gray-100 px-2 py-1 rounded text-xs font-bold"><?= $jamMasuk.' - '.$jamPulang ?></span></td>
                            <td class="py-3 px-3 text-sm font-bold text-rose-600"><?= rupiah($potTelatValue) ?></td>
                            <td class="py-3 px-3 text-sm font-bold text-rose-600"><?= rupiah($potIstirahatValue) ?></td>
                            <td class="py-3 px-3 text-sm font-bold text-rose-600"><?= rupiah($potPulangValue) ?></td>
                            <td class="py-3 px-3 text-sm font-bold text-rose-600"><?= rupiah($potTidakLengkapValue) ?></td>
                            <td class="py-3 px-3 text-sm font-bold text-orange-600"><?= rupiah($row['r_potongan_lainnya']) ?></td>
                            <td class="py-3 px-3 text-sm font-bold text-emerald-600"><?= rupiah($row['lembur']) ?></td>
                            <td class="py-3 px-3 text-sm font-bold text-blue-700 text-right"><?= rupiah($total) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
                <?php if ($ins_bulanan > 0): ?>
                <div class="mt-6 bg-amber-50/70 border border-amber-200 rounded-2xl p-5">
                    <h4 class="text-base font-bold text-gray-800 m-0 mb-3">Insentif Bulanan &mdash; <?= $ins_bulan_label ?></h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                        <div class="flex justify-between"><span class="text-gray-600">Insentif Bulanan</span><b><?= rupiah($ins_bulanan) ?></b></div>
                        <div class="flex justify-between"><span class="text-gray-600">Hari Kerja Rencana (sebulan)</span><b><?= $ins_rencana ?> hari</b></div>
                        <div class="flex justify-between"><span class="text-gray-600">Hari Masuk (Realisasi)</span><b><?= $ins_masuk ?> hari</b></div>
                        <?php if ($ins_rencana > 0): ?>
                        <div class="flex justify-between"><span class="text-gray-600">Potongan (<?= max(0, $ins_rencana - $ins_masuk) ?> hari tidak masuk)</span><b class="text-rose-600"><?= rupiah($ins_bulanan - $ins_diterima) ?></b></div>
                        <?php endif; ?>
                    </div>
                    <div class="flex justify-between items-center border-t border-amber-200 mt-3 pt-3">
                        <span class="font-bold text-gray-800">Insentif Diterima</span>
                        <span class="text-lg font-extrabold text-emerald-700"><?= rupiah($ins_diterima) ?></span>
                    </div>
                </div>
                <?php endif; ?>
        </div>
    </div>
</div>
<style>
#slipSimpleTable_wrapper .row:last-child{display:flex!important;justify-content:space-between!important;align-items:center!important;flex-wrap:wrap!important;gap:15px!important;margin-top:15px!important;padding:0 5px!important}
#slipSimpleTable_wrapper .dataTables_paginate{display:flex!important;gap:4px!important;align-items:center!important}
#slipSimpleTable_wrapper .dataTables_paginate .paginate_button{border:1px solid #e2e8f0!important;background:white!important;border-radius:6px!important;padding:5px 12px!important;color:#475569!important}
#slipSimpleTable_wrapper .dataTables_paginate .paginate_button.current{background:#2563eb!important;color:white!important;border-color:#2563eb!important}
#slipSimpleTable_wrapper .dataTables_info{color:#64748b!important;font-size:13px!important}
</style>
<script>
$(document).ready(function(){ if($('#slipSimpleTable').length) $('#slipSimpleTable').DataTable({pageLength:25,language:{search:"Cari:",lengthMenu:"Tampilkan _MENU_ data",info:"Menampilkan _START_ s/d _END_ dari _TOTAL_ data",paginate:{previous:"Prev",next:"Next"}}}); });
</script>
