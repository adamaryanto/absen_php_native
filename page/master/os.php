<?php
// Kelola OS (2026-10-30, revisi halaman pisah + gaya Bagian): Master OS dengan
// 3 halaman sendiri dalam satu file ini (jadi cuma satu file yang dicopy):
//   - Kelola (daftar) : ?page=os (tabel DataTables ala halaman Bagian)
//   - Tambah          : ?page=os&aksi=tambah (kartu ala halaman Tambah Bagian)
//   - Ubah            : ?page=os&aksi=ubah&id=... (kartu ala halaman Ubah Bagian)
// Sesimple ini: buat OS di sini, langsung muncul di dropdown dan langsung bisa
// dipakai sebagai Role login, tanpa langkah tambahan apapun (kolom role di
// ms_login sudah diubah jadi teks biasa).
// Gate: role OS tidak boleh buka halaman ini (router index.php juga sudah
// memblokir page=os untuk OS; gate di sini pertahanan kedua bila file ini
// dipanggil dari jalur lain). Halaman ini untuk Admin HR / Admin Master /
// Kepala Pabrik / Owner lewat menu MASTER (Owner tidak melihat menu MASTER,
// tapi tetap bisa buka lewat ?page=os langsung).
if (function_exists('os_is_os') && os_is_os()) {
    echo "<script>alert('Akses ditolak untuk role OS.'); window.location.href='?page=realisasi';</script>"; exit;
}
if (session_status() === PHP_SESSION_NONE) session_start();

$os_msg = '';
$os_err = '';
// Pesan sekali tampil setelah redirect (habis tambah/ubah/hapus sukses/gagal
// lewat hapus) — diambil dari session lalu langsung dihapus.
if (!empty($_SESSION['os_flash_msg'])) { $os_msg = (string)$_SESSION['os_flash_msg']; unset($_SESSION['os_flash_msg']); }
if (!empty($_SESSION['os_flash_err'])) { $os_err = (string)$_SESSION['os_flash_err']; unset($_SESSION['os_flash_err']); }

$aksi = (string)($_GET['aksi'] ?? '');
$view = ($aksi === 'tambah' || $aksi === 'ubah') ? $aksi : 'kelola';

// --- Aksi POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['os_act'] ?? '';
    if ($act === 'tambah') {
        $nama = strtoupper(trim((string)($_POST['os_nama'] ?? '')));
        if ($nama === '') {
            $os_err = 'Nama OS tidak boleh kosong.';
        } elseif (!preg_match('/^[A-Z0-9 _-]+$/', $nama)) {
            $os_err = 'Nama OS hanya boleh huruf, angka, spasi, _ dan -.';
        } else {
            $esc = $koneksi->real_escape_string($nama);
            $cek = $koneksi->query("SELECT id_os_dhk FROM ms_os_dhk WHERE UPPER(OS_DHK) = '$esc' LIMIT 1");
            if ($cek && $cek->num_rows > 0) {
                $os_err = "OS $nama sudah ada.";
            } elseif ($koneksi->query("INSERT INTO ms_os_dhk (OS_DHK) VALUES ('$esc')")) {
                $_SESSION['os_flash_msg'] = "OS $nama berhasil ditambahkan dan langsung muncul di dropdown Role menu User. Akun dengan Role $nama sudah bisa dibuat.";
                echo "<script>window.location.href='?page=os';</script>"; exit;
            } else {
                $os_err = 'Gagal menambah OS: ' . $koneksi->error;
            }
        }
        $view = 'tambah'; // gagal: tampilkan lagi halaman tambah dengan errornya
    } elseif ($act === 'ubah') {
        $id = (int)($_POST['os_id'] ?? 0);
        $nama_baru = strtoupper(trim((string)($_POST['os_nama'] ?? '')));
        $row = $koneksi->query("SELECT OS_DHK FROM ms_os_dhk WHERE id_os_dhk = '$id' LIMIT 1");
        $old = $row ? $row->fetch_assoc() : null;
        if (!$old) {
            $os_err = 'OS tidak ditemukan.';
        } elseif (strtoupper((string)$old['OS_DHK']) === 'OS') {
            $os_err = "Baris 'OS' tidak boleh diubah namanya.";
        } elseif ($nama_baru === '') {
            $os_err = 'Nama OS baru tidak boleh kosong.';
        } elseif (!preg_match('/^[A-Z0-9 _-]+$/', $nama_baru)) {
            $os_err = 'Nama OS hanya boleh huruf, angka, spasi, _ dan -.';
        } else {
            $esc_baru = $koneksi->real_escape_string($nama_baru);
            $cek = $koneksi->query("SELECT id_os_dhk FROM ms_os_dhk WHERE UPPER(OS_DHK) = '$esc_baru' AND id_os_dhk <> '$id' LIMIT 1");
            if ($cek && $cek->num_rows > 0) {
                $os_err = "Nama $nama_baru sudah dipakai OS lain.";
            } else {
                $old_esc = $koneksi->real_escape_string((string)$old['OS_DHK']);
                if ($koneksi->query("UPDATE ms_os_dhk SET OS_DHK = '$esc_baru' WHERE id_os_dhk = '$id' LIMIT 1")) {
                    $koneksi->query("UPDATE ms_login SET role = '$esc_baru' WHERE UPPER(role) = UPPER('$old_esc')");
                    $_SESSION['os_flash_msg'] = "OS {$old['OS_DHK']} diubah menjadi $nama_baru. Akun login dengan role lama ikut diubah ke $nama_baru, jadi login mereka tetap jalan.";
                    echo "<script>window.location.href='?page=os';</script>"; exit;
                } else {
                    $os_err = 'Gagal mengubah OS: ' . $koneksi->error;
                }
            }
        }
        $view = 'ubah'; // gagal: tampilkan lagi halaman ubah dengan errornya
    } elseif ($act === 'hapus') {
        $id = (int)($_POST['os_id'] ?? 0);
        $row = $koneksi->query("SELECT OS_DHK FROM ms_os_dhk WHERE id_os_dhk = '$id' LIMIT 1");
        $old = $row ? $row->fetch_assoc() : null;
        if (!$old) {
            $_SESSION['os_flash_err'] = 'OS tidak ditemukan.';
        } elseif (strtoupper((string)$old['OS_DHK']) === 'OS') {
            $_SESSION['os_flash_err'] = "Baris 'OS' tidak boleh dihapus.";
        } else {
            $nm_esc = $koneksi->real_escape_string((string)$old['OS_DHK']);
            $ck = $koneksi->query("SELECT COUNT(*) AS j FROM ms_karyawan WHERE id_os_dhk = '$id'");
            $jkar = $ck ? (int)$ck->fetch_assoc()['j'] : 0;
            $cl = $koneksi->query("SELECT COUNT(*) AS j FROM ms_login WHERE id_os_dhk = '$id' OR UPPER(role) = UPPER('$nm_esc')");
            $jakun = $cl ? (int)$cl->fetch_assoc()['j'] : 0;
            if ($jakun > 0) {
                $_SESSION['os_flash_err'] = "OS {$old['OS_DHK']} tidak bisa dihapus: masih ada $jakun akun login. Ubah dulu role akunnya lewat menu User.";
            } else {
                // Karyawan OS ini TIDAK dihapus — OS-nya jadi kosong (id_os_dhk = 0).
                // Data Realisasi lama tidak diubah.
                $koneksi->query("UPDATE ms_karyawan SET id_os_dhk = 0 WHERE id_os_dhk = '$id'");
                if ($koneksi->query("DELETE FROM ms_os_dhk WHERE id_os_dhk = '$id' LIMIT 1")) {
                    $_SESSION['os_flash_msg'] = "OS {$old['OS_DHK']} berhasil dihapus." . ($jkar > 0 ? " $jkar karyawan jadi tanpa OS (kosong)." : "");
                } else {
                    $_SESSION['os_flash_err'] = 'Gagal menghapus OS: ' . $koneksi->error;
                }
            }
        }
        echo "<script>window.location.href='?page=os';</script>"; exit;
    }
}

// --- Data untuk halaman ubah ---
$ubah_row = null;
$ubah_id = 0;
if ($view === 'ubah') {
    $ubah_id = (int)($_POST['os_id'] ?? $_GET['id'] ?? 0);
    if ($ubah_id > 0) {
        $er = $koneksi->query("SELECT id_os_dhk, OS_DHK FROM ms_os_dhk WHERE id_os_dhk = '$ubah_id' LIMIT 1");
        $ubah_row = $er ? $er->fetch_assoc() : null;
    }
}
?>

<?php if ($view === 'tambah'): ?>
<!-- Halaman Tambah OS (kartu ala Tambah Bagian) -->
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-10 mb-10">
    <div class="bg-white shadow-xl border border-gray-200 rounded-2xl overflow-hidden">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 sm:px-8 py-4 sm:py-6">
            <h3 class="text-2xl font-extrabold text-white m-0 tracking-tight flex items-center">
                <i class="fas fa-plus-circle mr-3"></i>
                Tambah Data OS
            </h3>
            <p class="text-slate-300 text-sm mt-1">Tambahkan OS baru ke dalam sistem</p>
        </div>
        <form method="POST">
            <input type="hidden" name="os_act" value="tambah">
            <div class="p-6 sm:p-8">
                <?php if ($os_err !== ''): ?>
                    <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:16px;"><?php echo htmlspecialchars($os_err); ?></div>
                <?php endif; ?>
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        Nama OS <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-users text-gray-400"></i>
                        </div>
                        <input placeholder="Masukkan nama OS..." autocomplete="off" type="text" name="os_nama" value="<?php echo htmlspecialchars((string)($_POST['os_nama'] ?? '')); ?>" required autofocus
                               class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl leading-5 bg-gray-50 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150 ease-in-out font-medium uppercase"/>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Nama otomatis disimpan HURUF BESAR.</p>
                </div>
                <div class="flex flex-col sm:flex-row items-center justify-between pt-6 border-t border-gray-100 mt-4 gap-4">
                    <div class="text-xs text-gray-500 italic order-2 sm:order-1">
                        <span class="text-rose-500">*</span> Wajib diisi
                    </div>
                    <div class="flex gap-3 w-full sm:w-auto order-1 sm:order-2">
                        <a href="?page=os" class="flex-1 sm:flex-none inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 shadow-sm text-sm font-bold rounded-xl text-gray-700 bg-white hover:bg-gray-50 transition duration-150 ease-in-out">
                            Batal
                        </a>
                        <button type="submit" class="flex-1 sm:flex-none inline-flex items-center justify-center px-6 py-2.5 border border-transparent text-sm font-bold rounded-xl shadow-md text-white bg-blue-600 hover:bg-blue-700 transition duration-150 ease-in-out transform hover:-translate-y-0.5">
                            <i class="fas fa-save mr-2"></i> Simpan
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php elseif ($view === 'ubah'): ?>
<!-- Halaman Ubah OS (kartu ala Ubah Bagian) -->
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-10 mb-10">
    <div class="bg-white shadow-xl border border-gray-200 rounded-2xl overflow-hidden">
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 sm:px-8 py-4 sm:py-6">
            <h3 class="text-2xl font-extrabold text-white m-0 tracking-tight flex items-center">
                <i class="fas fa-edit mr-3"></i>
                Ubah Data OS
            </h3>
            <p class="text-slate-300 text-sm mt-1">Ubah nama OS di dalam sistem</p>
        </div>
        <?php if (!$ubah_row): ?>
            <div class="p-6 sm:p-8">
                <p style="font-size:14px;color:#991b1b;">OS tidak ditemukan.</p>
                <a href="?page=os" class="inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 shadow-sm text-sm font-bold rounded-xl text-gray-700 bg-white hover:bg-gray-50 transition duration-150 ease-in-out">Kembali ke Kelola</a>
            </div>
        <?php elseif (strtoupper((string)$ubah_row['OS_DHK']) === 'OS'): ?>
            <div class="p-6 sm:p-8">
                <p style="font-size:14px;color:#991b1b;">Baris 'OS' tidak boleh diubah namanya.</p>
                <a href="?page=os" class="inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 shadow-sm text-sm font-bold rounded-xl text-gray-700 bg-white hover:bg-gray-50 transition duration-150 ease-in-out">Kembali ke Kelola</a>
            </div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="os_act" value="ubah">
            <input type="hidden" name="os_id" value="<?php echo (int)$ubah_row['id_os_dhk']; ?>">
            <div class="p-6 sm:p-8">
                <?php if ($os_err !== ''): ?>
                    <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:16px;"><?php echo htmlspecialchars($os_err); ?></div>
                <?php endif; ?>
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        Nama OS <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-users text-gray-400"></i>
                        </div>
                        <input placeholder="Masukkan nama OS..." autocomplete="off" type="text" name="os_nama" value="<?php echo htmlspecialchars((string)($_POST['os_nama'] ?? $ubah_row['OS_DHK'])); ?>" required autofocus
                               class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl leading-5 bg-gray-50 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-150 ease-in-out font-medium uppercase"/>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Nama otomatis disimpan HURUF BESAR. Mengubah nama OS otomatis mengubah role akun login yang lama ke nama baru, jadi login mereka tetap jalan.</p>
                </div>
                <div class="flex flex-col sm:flex-row items-center justify-between pt-6 border-t border-gray-100 mt-4 gap-4">
                    <div class="text-xs text-gray-500 italic order-2 sm:order-1">
                        <span class="text-rose-500">*</span> Wajib diisi
                    </div>
                    <div class="flex gap-3 w-full sm:w-auto order-1 sm:order-2">
                        <a href="?page=os" class="flex-1 sm:flex-none inline-flex items-center justify-center px-5 py-2.5 border border-gray-300 shadow-sm text-sm font-bold rounded-xl text-gray-700 bg-white hover:bg-gray-50 transition duration-150 ease-in-out">
                            Batal
                        </a>
                        <button type="submit" class="flex-1 sm:flex-none inline-flex items-center justify-center px-6 py-2.5 border border-transparent text-sm font-bold rounded-xl shadow-md text-white bg-blue-600 hover:bg-blue-700 transition duration-150 ease-in-out transform hover:-translate-y-0.5">
                            <i class="fas fa-save mr-2"></i> Simpan Perubahan
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- Halaman Kelola OS (tabel DataTables ala halaman Bagian) -->
<div class="container-fluid px-2 mt-4 mb-4">
    <div class="card border-0 shadow-sm rounded-xl overflow-hidden bg-white">
        <div class="border-b border-gray-100 py-4 px-5 flex justify-between items-center bg-white">
            <div>
                <h3 class="text-xl font-bold text-indigo-600 m-0">
                    <i class="fas fa-users mr-2"></i>Data OS (Outsourcing)
                </h3>
                <p class="text-[12px] text-gray-400 mt-0.5 mb-0">Kelola daftar OS. OS yang ditambahkan di sini langsung muncul di dropdown Role menu User dan di dropdown OS form Karyawan.</p>
            </div>
            <div>
                <a href="?page=os&aksi=tambah" class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white text-[15px] font-medium py-2 px-4 rounded shadow-sm transition-colors">
                    <i class="fas fa-plus mr-1.5"></i> Tambah OS
                </a>
            </div>
        </div>

        <div class="p-4">
            <?php if ($os_msg !== ''): ?>
                <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px 14px;border-radius:8px;margin-bottom:12px;"><?php echo $os_msg; ?></div>
            <?php endif; ?>
            <?php if ($os_err !== ''): ?>
                <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:8px;margin-bottom:12px;"><?php echo htmlspecialchars($os_err); ?></div>
            <?php endif; ?>

            <style>
                .dataTables_wrapper { display: block !important; }
                .dataTables_wrapper::before, .dataTables_wrapper::after { display: none !important; }
                #dataTables-example_wrapper .row:first-child { display: flex !important; justify-content: space-between !important; align-items: center !important; margin-bottom: 20px !important; width: 100% !important; }
                .dataTables_length { display: flex !important; align-items: center !important; }
                .dataTables_length label { display: flex !important; align-items: center !important; gap: 8px !important; margin: 0 !important; }
                .dataTables_length select { padding: 5px 10px !important; border: 1px solid #e0e6ed !important; border-radius: 8px !important; }
                .dataTables_filter { text-align: right !important; display: flex !important; justify-content: flex-end !important; }
                .dataTables_filter label { display: flex !important; align-items: center !important; gap: 8px !important; margin: 0 !important; }
                .dataTables_filter input { padding: 6px 12px !important; border: 1px solid #e0e6ed !important; border-radius: 8px !important; width: 200px !important; }
                .dataTables_wrapper .dataTables_paginate { display: flex !important; justify-content: flex-end !important; align-items: center !important; gap: 4px !important; padding-top: 15px !important; }
                .dataTables_paginate .paginate_button { border: 1px solid #e2e8f0 !important; background: white !important; border-radius: 6px !important; padding: 5px 12px !important; color: #475569 !important; font-weight: 500 !important; cursor: pointer !important; transition: all 0.2s !important; }
                .dataTables_paginate .paginate_button:hover { background: #f8fafc !important; color: #2563eb !important; border-color: #cbd5e1 !important; }
                .dataTables_paginate .paginate_button.current { background: #2563eb !important; border-color: #2563eb !important; color: white !important; }
                .dataTables_paginate .paginate_button.disabled { background: #f1f5f9 !important; color: #94a3b8 !important; cursor: not-allowed !important; }
                .dataTables_wrapper .dataTables_info { padding-top: 20px !important; color: #64748b !important; font-size: 13px !important; }
            </style>
            <div class="table-responsive px-3 py-3">
                <table class="w-full text-left border-collapse" id="dataTables-example">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="py-2 px-2 text-[13px] font-bold text-gray-600 uppercase text-center" width="5%">No</th>
                            <th class="py-2 px-2 text-[13px] font-bold text-gray-600 uppercase">Nama OS</th>
                            <th class="py-2 px-2 text-[13px] font-bold text-gray-600 uppercase text-center">Karyawan</th>
                            <th class="py-2 px-2 text-[13px] font-bold text-gray-600 uppercase text-center">Akun Login</th>
                            <th class="py-2 px-2 text-[13px] font-bold text-gray-600 uppercase text-center" width="18%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php
                        $list = $koneksi->query("SELECT id_os_dhk, OS_DHK FROM ms_os_dhk ORDER BY OS_DHK ASC");
                        $no = 1; while ($list && ($d = $list->fetch_assoc())):
                            $oid = (int)$d['id_os_dhk'];
                            $onm = (string)$d['OS_DHK'];
                            $onm_esc = $koneksi->real_escape_string($onm);
                            $ck1 = $koneksi->query("SELECT COUNT(*) AS j FROM ms_karyawan WHERE id_os_dhk = '$oid'");
                            $jkar = $ck1 ? (int)$ck1->fetch_assoc()['j'] : 0;
                            $ck2 = $koneksi->query("SELECT COUNT(*) AS j FROM ms_login WHERE id_os_dhk = '$oid' OR UPPER(role) = UPPER('$onm_esc')");
                            $jakun = $ck2 ? (int)$ck2->fetch_assoc()['j'] : 0;
                            $is_generik = (strtoupper($onm) === 'OS');
                        ?>
                            <tr class="hover:bg-blue-50/50">
                                <td class="py-2 px-2 text-center text-sm text-gray-600" data-label="No"><?php echo $no++; ?></td>
                                <td class="py-2 px-2 text-sm text-gray-700 font-semibold" data-label="Nama OS"><?php echo htmlspecialchars($onm); ?></td>
                                <td class="py-2 px-2 text-center text-sm" data-label="Karyawan"><?php echo $jkar; ?></td>
                                <td class="py-2 px-2 text-center text-sm" data-label="Akun Login"><?php echo $jakun; ?></td>
                                <td class="py-2 px-2 text-center" data-label="Aksi">
                                    <?php if (!$is_generik): ?>
                                        <a href="?page=os&aksi=ubah&id=<?php echo $oid; ?>" style="padding:6px 12px;background:#fef3c7;color:#92400e;border-radius:8px;text-decoration:none;font-size:13px;">Ubah</a>
                                        <form method="POST" onsubmit="return confirm('Hapus OS ini? Karyawan OS ini akan jadi tanpa OS (kosong).');" style="display:inline;">
                                            <input type="hidden" name="os_act" value="hapus">
                                            <input type="hidden" name="os_id" value="<?php echo $oid; ?>">
                                            <button type="submit" style="padding:6px 12px;background:#fee2e2;color:#991b1b;border:none;border-radius:8px;cursor:pointer;font-size:13px;">Hapus</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <script>
                $(document).ready(function() {
                    $('#dataTables-example').DataTable({
                        pageLength: 25,
                        autoWidth: false,
                        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                        language: {
                            search: "Cari:",
                            searchPlaceholder: "Cari OS...",
                            lengthMenu: "Tampilkan _MENU_ data",
                            info: "Menampilkan _START_ s/d _END_ dari _TOTAL_ data",
                            paginate: { previous: "Prev", next: "Next" }
                        }
                    });
                    $('.dataTables_filter').css('float', 'right').addClass('mb-3');
                    $('.dataTables_length').css('float', 'left').addClass('mb-3');
                });
            </script>
            <p style="font-size:12px;color:#6b7280;margin-top:12px;">Catatan: Mengubah nama OS otomatis mengubah role akun login yang lama ke nama baru, jadi login mereka tetap jalan.</p>
        </div>
    </div>
</div>
<?php endif; ?>
