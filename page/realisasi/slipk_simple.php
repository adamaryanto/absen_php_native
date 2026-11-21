<?php
// OS hanya melihat karyawannya sendiri (fail-closed: OS tanpa id tampil kosong).
$slipk_os = (function_exists('os_is_os') && os_is_os()) ? " AND A.id_os_dhk = " . (int)os_id() : "";
// 2026-10-31: Filter OS untuk role internal (dropdown reload halaman).
// Login OS tetap dipaksa OS sendiri (?os= diabaikan).
$slipk_filter = '';
$slipk_filter_val = 'all';
if (!(function_exists('os_is_os') && os_is_os())) {
    $slipk_filter_val = trim((string)($_GET['os'] ?? 'all'));
    if ($slipk_filter_val === 'tanpa') {
        $slipk_filter = " AND (A.id_os_dhk IS NULL OR A.id_os_dhk = 0)";
    } elseif (ctype_digit($slipk_filter_val)) {
        $slipk_filter = " AND A.id_os_dhk = " . (int)$slipk_filter_val;
    } else {
        $slipk_filter_val = 'all';
    }
}
// 2026-11-13: karyawan yang sudah dihapus dari master tapi masih punya history
// 2026-11-16: baris snapshot karyawan yang sudah dihapus dari master TIDAK ditampilkan
// di daftar Cetak Slip (hanya karyawan Aktif di master yang tampil).
$slipk_hist_union = '';
// 2026-11-21: sembunyikan master duplikat (nama sama) yang tidak punya data
// Realisasi/Rencana sama sekali (lihat slipk.php). Slip kosong tidak perlu
// muncul dua kali di daftar.
$slipk_dedupe = "
      AND (
        EXISTS (SELECT 1 FROM tb_realisasi_detail R WHERE R.id_karyawan = A.id_karyawan)
        OR EXISTS (SELECT 1 FROM tb_rkk_detail D WHERE D.id_karyawan = A.id_karyawan)
        OR (
          NOT EXISTS (
            SELECT 1 FROM ms_karyawan K2
            WHERE K2.status_karyawan = 'Aktif' AND K2.id_karyawan <> A.id_karyawan
              AND UPPER(TRIM(K2.nama_karyawan)) = UPPER(TRIM(A.nama_karyawan))
              AND (EXISTS (SELECT 1 FROM tb_realisasi_detail R2 WHERE R2.id_karyawan = K2.id_karyawan)
                OR EXISTS (SELECT 1 FROM tb_rkk_detail D2 WHERE D2.id_karyawan = K2.id_karyawan))
          )
          AND A.id_karyawan = (SELECT MIN(K3.id_karyawan) FROM ms_karyawan K3
                               WHERE K3.status_karyawan = 'Aktif'
                                 AND UPPER(TRIM(K3.nama_karyawan)) = UPPER(TRIM(A.nama_karyawan)))
        )
      )";
$q_list = $koneksi->query("SELECT * FROM (
                           SELECT A.id_karyawan, A.no_absen, A.nama_karyawan, B.nama_departmen, O.OS_DHK AS label_os
                           FROM ms_karyawan A
                           LEFT JOIN ms_departmen B ON A.id_departmen = B.id_departmen
                           LEFT JOIN ms_os_dhk O ON A.id_os_dhk = O.id_os_dhk
                           WHERE A.status_karyawan = 'Aktif' $slipk_os$slipk_filter$slipk_dedupe
                           $slipk_hist_union
                           ) X
                           ORDER BY X.nama_karyawan ASC");
?>
<div class="container-fluid px-3 md:px-6 mt-6 mb-10">
    <div class="bg-white shadow-xl rounded-2xl border border-gray-100 overflow-hidden">
        <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-6">
            <div class="flex items-center space-x-4">
                <div class="w-12 h-12 bg-white/20 backdrop-blur-md rounded-xl flex items-center justify-center text-white border border-white/30">
                    <i class="fas fa-file-invoice-dollar text-2xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-white m-0">Cetak Slip - Simple</h3>
                    <p class="text-emerald-100 text-sm opacity-90">Langsung dari Tarik Data (tb_record) tanpa Rencana/Realisasi</p>
                </div>
            </div>
        </div>
        <div class="p-6">
            <?php if (!(function_exists('os_is_os') && os_is_os())): ?>
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-gray-700"><i class="fas fa-filter mr-1"></i> Filter OS:</span>
                <select onchange="window.location.href='?page=realisasi&aksi=slipk&os='+this.value" class="px-2 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    <option value="all"<?= $slipk_filter_val === 'all' ? ' selected' : '' ?>>Semua Karyawan</option>
                    <option value="tanpa"<?= $slipk_filter_val === 'tanpa' ? ' selected' : '' ?>>Tanpa OS</option>
                    <?php $q_os_sl = $koneksi->query("SELECT id_os_dhk, OS_DHK FROM ms_os_dhk WHERE OS_DHK <> 'testing123' ORDER BY OS_DHK ASC"); while ($q_os_sl && ($r_os_sl = $q_os_sl->fetch_assoc())): ?><option value="<?= (int)$r_os_sl['id_os_dhk'] ?>"<?= ($slipk_filter_val !== 'all' && $slipk_filter_val !== 'tanpa' && (int)$slipk_filter_val === (int)$r_os_sl['id_os_dhk']) ? ' selected' : '' ?>><?= htmlspecialchars($r_os_sl['OS_DHK']) ?></option><?php endwhile; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="w-full text-left border-separate border-spacing-0" id="tableKaryawanSimple">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="py-4 px-4 text-[11px] font-bold text-gray-500 uppercase tracking-widest border-b">No. Absen</th>
                            <th class="py-4 px-4 text-[11px] font-bold text-gray-500 uppercase tracking-widest border-b">Nama Karyawan</th>
                            <th class="py-4 px-4 text-[11px] font-bold text-gray-500 uppercase tracking-widest border-b">OS</th>
                            <th class="py-4 px-4 text-[11px] font-bold text-gray-500 uppercase tracking-widest border-b">Bagian</th>
                            <th class="py-4 px-4 text-[11px] font-bold text-gray-500 uppercase tracking-widest border-b text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php if (!$q_list): ?>
                        <tr><td colspan="5" class="py-6 px-4 text-center text-rose-600 text-sm font-semibold">Data slip gagal dimuat: <?= htmlspecialchars($koneksi->error ?? 'query error') ?></td></tr>
                        <?php else: ?>
                        <?php while ($row = $q_list->fetch_assoc()): ?>
                            <tr class="hover:bg-emerald-50/50">
                                <td class="py-4 px-4 text-sm font-semibold text-gray-700"><?= htmlspecialchars((string)($row['no_absen'] ?? '')) ?></td>
                                <td class="py-4 px-4 text-sm font-bold text-gray-900"><?= htmlspecialchars((string)($row['nama_karyawan'] ?? '')) ?></td>
                                <td class="py-4 px-4"><span class="bg-indigo-100 text-indigo-700 text-[10px] px-2 py-1 rounded-md font-bold uppercase"><?= !empty($row['label_os']) ? htmlspecialchars((string)$row['label_os']) : '-' ?></span></td>
                                <td class="py-4 px-4"><span class="bg-emerald-100 text-emerald-700 text-[10px] px-2 py-1 rounded-md font-bold uppercase"><?= !empty($row['nama_departmen']) ? htmlspecialchars((string)$row['nama_departmen']) : '-' ?></span></td>
                                <td class="py-4 px-4 text-center">
                                    <a href="index.php?page=realisasi&aksi=slip_simple&id=<?= $row['id_karyawan'] ?>" class="inline-flex items-center px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-bold rounded-xl shadow-md">
                                        <i class="fas fa-eye mr-2"></i> Preview Slip
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<style>
#tableKaryawanSimple_wrapper .row:last-child{display:flex!important;justify-content:space-between!important;align-items:center!important;flex-wrap:wrap!important;gap:15px!important;margin-top:15px!important;padding:0 5px!important}
#tableKaryawanSimple_wrapper .dataTables_paginate{display:flex!important;gap:4px!important;align-items:center!important}
#tableKaryawanSimple_wrapper .dataTables_paginate .paginate_button{border:1px solid #e2e8f0!important;background:white!important;border-radius:6px!important;padding:5px 12px!important;color:#475569!important}
#tableKaryawanSimple_wrapper .dataTables_paginate .paginate_button.current{background:#10b981!important;color:white!important;border-color:#10b981!important}
#tableKaryawanSimple_wrapper .dataTables_info{color:#64748b!important;font-size:13px!important}
</style>
<script>
$(document).ready(function(){
    $('#tableKaryawanSimple').DataTable({pageLength:25,language:{search:"Cari:",searchPlaceholder:"Cari data...",lengthMenu:"Tampilkan _MENU_ data",info:"Menampilkan _START_ s/d _END_ dari _TOTAL_ data",paginate:{previous:"Prev",next:"Next"}}});
});
</script>
