<?php
// Halaman FINANCE > Rekap Pengeluaran per tanggal:
// Biaya OS + Borongan (semua jenis), Biaya Boneless, Biaya permobil.
require_once __DIR__ . '/_rekap_lib.php';

$tgl = rekap_tgl();
$tglE = $koneksi->real_escape_string($tgl);
$osRows = rekap_os($koneksi, $tgl);
$borRows = rekap_borongan($koneksi, $tgl);
$blRows = rekap_boneless($koneksi, $tgl);
$mobRows = rekap_mobil($koneksi, $tgl);
$tot = rekap_totals($osRows, $borRows, $blRows, $mobRows);

$rp = function ($n) { return 'Rp ' . number_format((float)$n, 0, ',', '.'); };
$qs = ['page' => 'rekap_pengeluaran', 'tgl' => $tgl];
$tglDisp = date('d/m/Y', strtotime($tgl));
?>
<div class="container-fluid px-3 md:px-6 mt-6 mb-10">
  <div class="bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden">

    <div class="px-6 py-5 flex flex-col md:flex-row md:items-center md:justify-between gap-3" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);">
      <div>
        <h1 class="text-xl font-extrabold text-white m-0"><i class="fas fa-file-invoice-dollar mr-2"></i>Rekap Pengeluaran</h1>
        <p class="text-blue-100 text-sm mt-1">Ringkasan biaya tanggal <b><?= htmlspecialchars($tglDisp) ?></b> &middot; Biaya OS + Borongan &middot; Biaya Boneless &middot; Biaya permobil</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <form method="GET" id="rekapTglForm" class="flex items-center gap-2">
          <input type="hidden" name="page" value="rekap_pengeluaran">
          <input type="date" name="tgl" id="rekapTglInput" value="<?= htmlspecialchars($tgl) ?>" class="border border-white/30 bg-white/10 text-white rounded-lg px-3 py-2 text-sm [color-scheme:dark]">
        </form>
        <a href="page/finance/export_rekap_excel.php?jenis=semua&amp;tgl=<?= urlencode($tgl) ?>" class="inline-flex items-center bg-emerald-500 hover:bg-emerald-400 text-white text-sm font-bold px-4 py-2 rounded-lg transition-colors"><i class="fas fa-file-excel mr-1.5"></i> Download Keseluruhan</a>
      </div>
    </div>

    <div class="p-4 md:p-6">

      <!-- Biaya OS -->
      <div class="rounded-2xl border border-slate-200 overflow-hidden mb-5 shadow-sm">
        <div class="bg-slate-100 px-4 py-3 flex flex-wrap items-center justify-between gap-2 border-b border-slate-200">
          <div class="flex items-center gap-2"><span class="inline-flex w-8 h-8 items-center justify-center rounded-lg bg-indigo-600 text-white"><i class="fas fa-building"></i></span><span class="font-extrabold text-slate-700">Biaya OS + Borongan <span class="text-slate-400 font-semibold text-sm">(gaji semua jenis)</span></span></div>
          <a href="page/finance/export_rekap_excel.php?jenis=os&amp;tgl=<?= urlencode($tgl) ?>" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-bold px-3 py-1.5 rounded-lg transition-colors"><i class="fas fa-file-excel mr-1"></i> Download Excel</a>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="text-left px-4 py-2.5">OS</th>
                <th class="text-center px-4 py-2.5">Jumlah Karyawan</th>
                <th class="text-right px-4 py-2.5">Total Upah</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php if (!count($osRows)): ?><tr><td colspan="3" class="text-center text-slate-400 italic py-6">Tidak ada data OS pada tanggal ini.</td></tr><?php endif; ?>
              <?php foreach ($osRows as $r): ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-2 font-semibold text-slate-800"><?= htmlspecialchars($r['os']) ?></td>
                <td class="px-4 py-2 text-center text-slate-700"><?= (int)$r['n'] ?></td>
                <td class="px-4 py-2 text-right font-bold text-emerald-700 whitespace-nowrap"><?= $rp($r['total']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php if (count($osRows)): ?>
            <tfoot>
              <tr class="bg-slate-100 font-bold">
                <td class="px-4 py-2.5">TOTAL OS</td>
                <td class="px-4 py-2.5 text-center"><?= (int)$tot['os_n'] ?></td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap"><?= $rp($tot['os']) ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>

      <!-- Biaya Borongan -->
      <div class="rounded-2xl border border-slate-200 overflow-hidden mb-5 shadow-sm">
        <div class="bg-slate-100 px-4 py-3 flex flex-wrap items-center justify-between gap-2 border-b border-slate-200">
          <div class="flex items-center gap-2"><span class="inline-flex w-8 h-8 items-center justify-center rounded-lg bg-violet-600 text-white"><i class="fas fa-boxes"></i></span><span class="font-extrabold text-slate-700">Biaya Borongan <span class="text-slate-400 font-semibold text-sm">(upah borongan karyawan)</span></span></div>
          <a href="page/finance/export_rekap_excel.php?jenis=borongan&amp;tgl=<?= urlencode($tgl) ?>" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-bold px-3 py-1.5 rounded-lg transition-colors"><i class="fas fa-file-excel mr-1"></i> Download Excel</a>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="text-left px-4 py-2.5 w-10">No</th>
                <th class="text-left px-4 py-2.5">Nama</th>
                <th class="text-center px-4 py-2.5">OS</th>
                <th class="text-right px-4 py-2.5">Total Borongan</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php if (!count($borRows)): ?><tr><td colspan="4" class="text-center text-slate-400 italic py-6">Tidak ada data borongan pada tanggal ini.</td></tr><?php endif; ?>
              <?php $noB = 1; foreach ($borRows as $r): ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-2 text-slate-600"><?= $noB++ ?></td>
                <td class="px-4 py-2 font-semibold text-slate-800"><?= htmlspecialchars($r['nama']) ?></td>
                <td class="px-4 py-2 text-center text-slate-600"><?= htmlspecialchars($r['os']) ?></td>
                <td class="px-4 py-2 text-right font-bold text-violet-700 whitespace-nowrap"><?= $rp($r['total']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php if (count($borRows)): ?>
            <tfoot>
              <tr class="bg-slate-100 font-bold">
                <td class="px-4 py-2.5" colspan="3">TOTAL BORONGAN (<?= count($borRows) ?> karyawan)</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap"><?= $rp($tot['bor']) ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>

      <!-- Biaya Boneless -->
      <div class="rounded-2xl border border-slate-200 overflow-hidden mb-5 shadow-sm">
        <div class="bg-slate-100 px-4 py-3 flex flex-wrap items-center justify-between gap-2 border-b border-slate-200">
          <div class="flex items-center gap-2"><span class="inline-flex w-8 h-8 items-center justify-center rounded-lg bg-amber-500 text-white"><i class="fas fa-truck-loading"></i></span><span class="font-extrabold text-slate-700">Biaya Boneles <span class="text-slate-400 font-semibold text-sm">(item + truck)</span></span></div>
          <a href="page/finance/export_rekap_excel.php?jenis=boneless&amp;tgl=<?= urlencode($tgl) ?>" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-bold px-3 py-1.5 rounded-lg transition-colors"><i class="fas fa-file-excel mr-1"></i> Download Excel</a>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="text-left px-4 py-2.5 w-10">No</th>
                <th class="text-left px-4 py-2.5">Keterangan</th>
                <th class="text-center px-4 py-2.5">Jumlah Mobil</th>
                <th class="text-right px-4 py-2.5">Biaya Mobil</th>
                <th class="text-right px-4 py-2.5">Biaya Item</th>
                <th class="text-right px-4 py-2.5">Total</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php if (!count($blRows)): ?><tr><td colspan="6" class="text-center text-slate-400 italic py-6">Tidak ada data boneless pada tanggal ini.</td></tr><?php endif; ?>
              <?php $noL = 1; foreach ($blRows as $r): ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-2 text-slate-600"><?= $noL++ ?></td>
                <td class="px-4 py-2 text-slate-700"><?= htmlspecialchars($r['ket'] !== '' ? $r['ket'] : '-') ?></td>
                <td class="px-4 py-2 text-center text-slate-700"><?= (int)$r['mobil'] ?></td>
                <td class="px-4 py-2 text-right text-slate-700 whitespace-nowrap"><?= $rp($r['biaya_mobil']) ?></td>
                <td class="px-4 py-2 text-right text-slate-700 whitespace-nowrap"><?= $rp($r['biaya_item']) ?></td>
                <td class="px-4 py-2 text-right font-bold text-amber-700 whitespace-nowrap"><?= $rp($r['total']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php if (count($blRows)): ?>
            <tfoot>
              <tr class="bg-slate-100 font-bold">
                <td class="px-4 py-2.5" colspan="3">TOTAL BONELESS (<?= count($blRows) ?> entri)</td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap"><?= $rp($tot['bl_mobil']) ?></td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap"><?= $rp($tot['bl_item']) ?></td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap"><?= $rp($tot['bl']) ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>

      <!-- Biaya permobil -->
      <div class="rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
        <div class="bg-slate-100 px-4 py-3 flex flex-wrap items-center justify-between gap-2 border-b border-slate-200">
          <div class="flex items-center gap-2"><span class="inline-flex w-8 h-8 items-center justify-center rounded-lg bg-emerald-600 text-white"><i class="fas fa-truck"></i></span><span class="font-extrabold text-slate-700">Biaya permobil <span class="text-slate-400 font-semibold text-sm">(dipakai per truck)</span></span></div>
          <a href="page/finance/export_rekap_excel.php?jenis=mobil&amp;tgl=<?= urlencode($tgl) ?>" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-bold px-3 py-1.5 rounded-lg transition-colors"><i class="fas fa-file-excel mr-1"></i> Download Excel</a>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
              <tr>
                <th class="text-left px-4 py-2.5 w-10">No</th>
                <th class="text-left px-4 py-2.5">Mobil</th>
                <th class="text-center px-4 py-2.5">Frekuensi</th>
                <th class="text-right px-4 py-2.5">Total Biaya</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php if (!count($mobRows)): ?><tr><td colspan="4" class="text-center text-slate-400 italic py-6">Tidak ada truck pada tanggal ini.</td></tr><?php endif; ?>
              <?php $noM = 1; foreach ($mobRows as $r): ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-2 text-slate-600"><?= $noM++ ?></td>
                <td class="px-4 py-2 font-semibold text-slate-800"><?= htmlspecialchars($r['nama']) ?></td>
                <td class="px-4 py-2 text-center text-slate-700"><?= (int)$r['freq'] ?></td>
                <td class="px-4 py-2 text-right font-bold text-emerald-700 whitespace-nowrap"><?= $rp($r['total']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php if (count($mobRows)): ?>
            <tfoot>
              <tr class="bg-slate-100 font-bold">
                <td class="px-4 py-2.5" colspan="2">TOTAL PERMOBIL</td>
                <td class="px-4 py-2.5 text-center"><?= (int)$tot['mob_freq'] ?></td>
                <td class="px-4 py-2.5 text-right whitespace-nowrap"><?= $rp($tot['mob']) ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>

      <?php $grandTotal = $tot['os'] + $tot['bl'] + $tot['mob']; // 2026-11-17: borongan SUDAH termasuk di Biaya OS, jangan ditambah lagi (dulu double-count). ?>
      <div class="rounded-2xl border-2 border-emerald-600 bg-emerald-50 px-6 py-4 flex flex-wrap items-center justify-between gap-3">
        <span class="text-base font-extrabold text-emerald-900 flex items-center gap-2"><i class="fas fa-coins text-xl"></i> TOTAL KESELURUHAN</span>
        <span class="text-2xl font-black text-emerald-700 whitespace-nowrap"><?= $rp($grandTotal) ?></span>
      </div>

      <p class="mt-4 text-xs text-slate-500">Biaya OS + Borongan = gaji semua jenis termasuk upah borongan (qty x tarif, Digantikan diabaikan). Rincian borongan per karyawan ada di bawah (sudah termasuk di Total OS). Biaya Boneless = item + truck dari Data Boneless. Biaya permobil diambil dari truck yang dicentang pada Boneless. Total keseluruhan = OS + Boneless + Permobil.</p>
    </div>
  </div>
</div>

<script>
$(function () {
  var t = document.getElementById('rekapTglInput');
  if (t) t.addEventListener('change', function () { document.getElementById('rekapTglForm').submit(); });
});
</script>
