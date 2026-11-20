<?php
// Halaman Detail OS (READ-ONLY): realisasi per tanggal untuk OS yang dipilih
// dari halaman Periode. Hanya bisa dibuka dari tombol "Detail" di header OS.
// Tanpa ubah/hapus/approve. Filter: OS + rentang tanggal.

$sos = trim((string)($_GET['sos'] ?? ''));
if ($sos === '' || strlen($sos) > 50) $sos = 'Tanpa OS';
$sdari = trim((string)($_GET['sdari'] ?? ''));
$ssampai = trim((string)($_GET['ssampai'] ?? ''));
if ($sdari === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sdari)) $sdari = date('Y-m-01');
if ($ssampai === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ssampai)) $ssampai = date('Y-m-d');
if ($sdari > $ssampai) { $tmp = $sdari; $sdari = $ssampai; $ssampai = $tmp; }
$q = trim((string)($_GET['q'] ?? ''));
$speriode = trim((string)($_GET['speriode'] ?? 'Harian'));
$sgol = trim((string)($_GET['sgol'] ?? 'semua'));

// Kumpulan nama OS valid (untuk filter)
$osNames = [];
$q_osn = $koneksi->query("SELECT OS_DHK FROM ms_os_dhk ORDER BY OS_DHK ASC");
if ($q_osn) { while ($r_osn = $q_osn->fetch_assoc()) $osNames[] = (string)$r_osn['OS_DHK']; }
if (!in_array('Tanpa OS', $osNames, true)) $osNames[] = 'Tanpa OS';

// Role OS: dipaksa OS milik akunnya (fail closed kalau tidak dipasang)
if (function_exists('os_is_os') && os_is_os()) {
    $osSaya = '';
    if (function_exists('os_id') && os_id() > 0) {
        $q_me = $koneksi->query("SELECT OS_DHK FROM ms_os_dhk WHERE id_os_dhk = " . (int)os_id() . " LIMIT 1");
        if ($q_me && ($r_me = $q_me->fetch_assoc())) $osSaya = (string)$r_me['OS_DHK'];
    }
    $sos = $osSaya !== '' ? $osSaya : 'Tanpa OS';
}
if (!in_array($sos, $osNames, true)) {
    echo '<div class="container-fluid px-6 mt-6"><div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-xl p-4 text-sm font-bold">OS tidak valid.</div></div>';
    return;
}

// OS efektif sebuah baris detail: master diutamakan, baris manual pakai id_os_dhk detail.
// 'Tanpa OS' = efektif 0 (tidak punya OS).
$osEff = 0;
if ($sos !== 'Tanpa OS') {
    $q_id = $koneksi->query("SELECT id_os_dhk FROM ms_os_dhk WHERE OS_DHK = '" . $koneksi->real_escape_string($sos) . "' LIMIT 1");
    if ($q_id && ($r_id = $q_id->fetch_assoc())) $osEff = (int)$r_id['id_os_dhk'];
}
$sdE = $koneksi->real_escape_string($sdari);
$seE = $koneksi->real_escape_string($ssampai);
$osCond = "COALESCE(NULLIF(BK.id_os_dhk, 0), RD.id_os_dhk) = $osEff";
$osCond2 = "COALESCE(NULLIF(BK2.id_os_dhk, 0), RD2.id_os_dhk) = $osEff";
$person_key_jml = "IF(RD.id_karyawan = 0, CONCAT('M:', IFNULL(RD.nama_karyawan_manual, '')), CONCAT('K:', RD.id_karyawan))";

$tampil = null;
try {
    $tampil = $koneksi->query("SELECT A.id_realisasi, A.tgl_realisasi, A.keterangan, A.status_realisasi,
            S.ttl, S.potlainnya, S.p_telat, S.p_istirahat, S.p_pulang, S.p_log,
            (SELECT COUNT(DISTINCT $person_key_jml)
             FROM tb_realisasi_detail RD
             LEFT JOIN ms_karyawan BKJ ON BKJ.id_karyawan = RD.id_karyawan
             LEFT JOIN tb_rkk_detail RKD ON RD.id_rkk_detail = RKD.id_rkk_detail
             WHERE RD.id_realisasi = A.id_realisasi AND (RKD.id_rkk_detail IS NULL OR RKD.status_rkk != 'Digantikan')
               AND COALESCE(NULLIF(BKJ.id_os_dhk, 0), RD.id_os_dhk) = $osEff
            ) AS jml
        FROM tb_realisasi A
        LEFT JOIN (
            SELECT RD.id_realisasi AS sid,
                SUM((IFNULL(RD.r_upah, 0) + IFNULL(RD.lembur, 0)) -
                    (IFNULL(RD.r_potongan_telat, 0) +
                     IFNULL(RD.r_potongan_istirahat_awal, 0) +
                     IFNULL(RD.r_potongan_istirahat_telat, 0) +
                     IFNULL(RD.r_potongan_pulang, 0) +
                     IFNULL(RD.r_potongan_tidak_lengkap, 0) +
                     IFNULL(RD.r_potongan_lainnya, 0))) AS ttl,
                SUM(IFNULL(RD.r_potongan_lainnya, 0)) AS potlainnya,
                SUM(IFNULL(RD.r_potongan_telat, 0)) AS p_telat,
                SUM(IFNULL(RD.r_potongan_istirahat_awal, 0) + IFNULL(RD.r_potongan_istirahat_telat, 0)) AS p_istirahat,
                SUM(IFNULL(RD.r_potongan_pulang, 0)) AS p_pulang,
                SUM(IFNULL(RD.r_potongan_tidak_lengkap, 0)) AS p_log
            FROM tb_realisasi_detail RD
            LEFT JOIN ms_karyawan BK ON BK.id_karyawan = RD.id_karyawan
            LEFT JOIN tb_rkk_detail RKD ON RD.id_rkk_detail = RKD.id_rkk_detail
            WHERE (RKD.id_rkk_detail IS NULL OR RKD.status_rkk != 'Digantikan')
              AND RD.tgl_realisasi_detail BETWEEN '$sdE' AND '$seE'
              AND COALESCE(NULLIF(BK.id_os_dhk, 0), RD.id_os_dhk) = $osEff
            GROUP BY RD.id_realisasi
        ) S ON S.sid = A.id_realisasi
        WHERE A.tgl_realisasi BETWEEN '$sdE' AND '$seE'
          AND EXISTS (
              SELECT 1 FROM tb_realisasi_detail RD2
              LEFT JOIN ms_karyawan BK2 ON BK2.id_karyawan = RD2.id_karyawan
              WHERE RD2.id_realisasi = A.id_realisasi AND $osCond2
          )
        ORDER BY A.tgl_realisasi ASC, A.id_realisasi ASC");
} catch (Throwable $e) { $tampil = null; }

$rows = [];
$tot = ['jml' => 0, 'ttl' => 0, 'p_telat' => 0, 'p_istirahat' => 0, 'p_pulang' => 0, 'p_log' => 0, 'potlainnya' => 0];
$totOrang = [];
if ($tampil) {
    while ($d = $tampil->fetch_assoc()) {
        $rows[] = $d;
        $tot['ttl'] += (int)round((float)($d['ttl'] ?? 0));
        $tot['p_telat'] += (int)round((float)($d['p_telat'] ?? 0));
        $tot['p_istirahat'] += (int)round((float)($d['p_istirahat'] ?? 0));
        $tot['p_pulang'] += (int)round((float)($d['p_pulang'] ?? 0));
        $tot['p_log'] += (int)round((float)($d['p_log'] ?? 0));
        $tot['potlainnya'] += (int)round((float)($d['potlainnya'] ?? 0));
    }
    $tot['jml'] = count($rows);
    $qOr = $koneksi->query("SELECT COUNT(DISTINCT $person_key_jml) AS n FROM tb_realisasi_detail RD LEFT JOIN ms_karyawan BK ON BK.id_karyawan = RD.id_karyawan LEFT JOIN tb_rkk_detail RKD ON RD.id_rkk_detail = RKD.id_rkk_detail WHERE (RKD.id_rkk_detail IS NULL OR RKD.status_rkk != 'Digantikan') AND RD.tgl_realisasi_detail BETWEEN '$sdE' AND '$seE' AND $osCond");
    if ($qOr && ($rOr = $qOr->fetch_assoc())) $totOrang = (int)$rOr['n'];
}

$rp = function ($n) { return 'Rp ' . number_format((float)$n, 0, ',', '.'); };
$back_qs = ['page' => 'periode', 'speriode' => $speriode, 'sdari' => $sdari, 'ssampai' => $ssampai, 'sgol' => $sgol];
if ($osEff > 0) $back_qs['sos'] = (string)$osEff;
if ($q !== '') $back_qs['q'] = $q;
?>
<div class="container-fluid px-3 md:px-6 mt-6 mb-10">
  <div class="bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden">
    <div class="bg-gradient-to-r from-indigo-600 to-blue-600 px-6 py-5 flex flex-col md:flex-row md:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-extrabold text-white m-0"><i class="fas fa-list-ul mr-2"></i>Realisasi  -  OS <?= htmlspecialchars($sos) ?></h1>
        <p class="text-indigo-100 text-sm mt-1">Tanggal <?= date('d/m/Y', strtotime($sdari)) ?> s/d <?= date('d/m/Y', strtotime($ssampai)) ?> &middot; <b>Mode lihat</b> (tanpa ubah/hapus)</p>
      </div>
      <div class="flex items-center gap-2">
        <a href="page/periode/export_detailos_excel.php?sos=<?= urlencode($sos) ?>&amp;sdari=<?= urlencode($sdari) ?>&amp;ssampai=<?= urlencode($ssampai) ?>" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-4 py-2 rounded-lg"><i class="fas fa-file-excel mr-1"></i> Download Excel</a>
        <a href="?<?= htmlspecialchars(http_build_query($back_qs)) ?>" class="inline-flex items-center bg-white text-indigo-700 hover:bg-indigo-50 text-sm font-bold px-4 py-2 rounded-lg"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Periode</a>
      </div>
    </div>

    <div class="p-5">
      <div class="table-responsive">
        <table class="w-full text-left border-collapse">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="py-2 px-2 text-[13px] font-bold text-gray-700 uppercase align-middle text-center w-8">No</th>
              <th class="py-2 px-2 text-[13px] font-bold text-gray-700 uppercase align-middle">Tanggal</th>
              <th class="py-2 px-2 text-[13px] font-bold text-gray-700 uppercase align-middle">Keterangan</th>
              <th class="py-2 px-2 text-[13px] font-bold text-gray-700 uppercase align-middle text-center">Jumlah Karyawan</th>
              <th class="py-2 px-2 text-[13px] font-bold text-gray-700 uppercase align-middle text-right">Total Upah</th>
              <th class="py-2 px-2 text-[13px] font-bold text-gray-700 uppercase align-middle text-right" title="Potongan Telat">Telat</th>
              <th class="py-2 px-2 text-[13px] font-bold text-gray-700 uppercase align-middle text-right" title="Denda Pulang Awal">Denda Pulang</th>
              <th class="py-2 px-2 text-[13px] font-bold text-gray-700 uppercase align-middle text-right" title="Denda Absensi Tidak Lengkap">Denda Tidak Absen</th>
              <th class="py-2 px-2 text-[13px] font-bold text-gray-700 uppercase align-middle text-right" title="Potongan Lainnya">Lainnya</th>
              <th class="py-2 px-2 text-[13px] font-bold text-gray-700 uppercase align-middle text-center w-32">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php if (!count($rows)): ?>
              <tr><td colspan="10" class="py-12 text-center text-gray-400 italic">Tidak ada realisasi OS <?= htmlspecialchars($sos) ?> pada tanggal <?= date('d/m/Y', strtotime($sdari)) ?> s/d <?= date('d/m/Y', strtotime($ssampai)) ?>.</td></tr>
            <?php endif; ?>
            <?php $no = 1; foreach ($rows as $data):
                $row_class = 'hover:bg-gray-50 transition-colors';
            ?>
            <tr class="<?= $row_class ?>">
              <td class="py-2 md:py-2.5 px-2 text-center text-sm md:text-[15px] text-gray-700 font-medium align-middle"><?= $no ?></td>
              <td class="py-2 md:py-2.5 px-2 text-[15px] font-medium text-gray-900 align-middle whitespace-nowrap"><?= date('d/m/Y', strtotime($data['tgl_realisasi'])) ?></td>
              <td class="py-2 md:py-2.5 px-2 align-middle">
                <div class="text-[14px] text-gray-700 md:max-w-[160px] md:truncate" title="<?= htmlspecialchars((string)$data['keterangan']) ?>"><?= htmlspecialchars((string)$data['keterangan']) ?></div>
              </td>
              <td class="py-2 md:py-2.5 px-2 md:text-center text-[15px] text-gray-700 align-middle"><?= (int)$data['jml'] ?> <span class="md:hidden">Orang</span></td>
              <td class="py-2 md:py-2.5 px-2 md:text-right text-[15px] font-bold text-gray-900 align-middle whitespace-nowrap"><?= $rp($data['ttl'] ?? 0) ?></td>
              <td class="py-2 md:py-2.5 px-2 md:text-right text-[14px] md:text-[15px] font-medium text-rose-600 align-middle whitespace-nowrap"><?= $rp($data['p_telat'] ?? 0) ?></td>
              <td class="py-2 md:py-2.5 px-2 md:text-right text-[14px] md:text-[15px] font-medium text-rose-600 align-middle whitespace-nowrap"><?= $rp($data['p_pulang'] ?? 0) ?></td>
              <td class="py-2 md:py-2.5 px-2 md:text-right text-[14px] md:text-[15px] font-medium text-rose-600 align-middle whitespace-nowrap"><?= $rp($data['p_log'] ?? 0) ?></td>
              <td class="py-2 md:py-2.5 px-2 md:text-right text-[14px] md:text-[15px] font-medium text-rose-600 align-middle whitespace-nowrap"><?= $rp($data['potlainnya'] ?? 0) ?></td>
              <td class="py-2 md:py-2.5 px-2 align-middle md:text-center">
                <a href="?page=periode&amp;aksi=kelolaos&amp;id=<?= (int)$data['id_realisasi'] ?>&amp;sos=<?= urlencode($sos) ?>&amp;sdari=<?= urlencode($sdari) ?>&amp;ssampai=<?= urlencode($ssampai) ?>&amp;speriode=<?= urlencode($speriode) ?>&amp;sgol=<?= urlencode($sgol) ?><?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white text-[13px] font-bold px-4 py-1.5 rounded-lg" title="Detail karyawan yang masuk di tanggal ini (hanya OS <?= htmlspecialchars($sos) ?>)">
                  <i class="fas fa-eye mr-1"></i> Detail
                </a>
              </td>
            </tr>
            <?php $no++; endforeach; ?>
          </tbody>
          <?php if (count($rows)): ?>
          <tfoot>
            <tr class="bg-slate-100 font-bold">
              <td class="px-2 py-2.5" colspan="3">TOTAL (<?= $tot['jml'] ?> tanggal)</td>
              <td class="px-2 py-2.5 md:text-center text-[15px]"><?= (int)$totOrang ?> karyawan</td>
              <td class="px-2 py-2.5 md:text-right whitespace-nowrap"><?= $rp($tot['ttl']) ?></td>
              <td class="px-2 py-2.5 md:text-right text-rose-600"><?= $rp($tot['p_telat']) ?></td>
              <td class="px-2 py-2.5 md:text-right text-rose-600"><?= $rp($tot['p_pulang']) ?></td>
              <td class="px-2 py-2.5 md:text-right text-rose-600"><?= $rp($tot['p_log']) ?></td>
              <td class="px-2 py-2.5 md:text-right text-rose-600"><?= $rp($tot['potlainnya']) ?></td>
              <td></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
      <p class="mt-4 text-xs text-slate-500">Total upah dihitung dari baris realisasi OS <?= htmlspecialchars($sos) ?> (Digantikan diabaikan). Halaman ini hanya menampilkan ringkasan; tombol Detail membuka detail karyawan yang masuk pada tanggal tersebut (Kelola Realisasi).</p>
    </div>
  </div>
</div>
