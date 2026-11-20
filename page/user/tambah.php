<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 mt-10 mb-10">
    <div class="bg-white shadow-xl border border-gray-200 rounded-2xl overflow-hidden">
        
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-8 py-6">
            <h3 class="text-2xl font-extrabold text-white m-0 tracking-tight flex items-center">
                <i class="fas fa-user-plus mr-3"></i>
                Tambah Data User
            </h3>
            <p class="text-slate-300 text-sm mt-1">Buat akun pengguna baru dengan hak akses spesifik</p>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="p-8">
                <!-- Username Input -->
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        Username <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-gray-400"></i>
                        </div>
                        <input placeholder="Masukkan username..." autocomplete="off" type="text" name="tnama" required 
                               class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl leading-5 bg-gray-50 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:border-slate-500 sm:text-sm transition duration-150 ease-in-out font-medium"/>
                    </div>
                </div>

                <!-- Password Input -->
                <div class="mb-6">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        Password <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input placeholder="Masukkan password..." autocomplete="off" type="password" name="tpassword" required 
                               class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-xl leading-5 bg-gray-50 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:border-slate-500 sm:text-sm transition duration-150 ease-in-out font-medium"/>
                    </div>
                </div>

                <!-- Role Selection -->
                <div class="mb-8">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        Role / Hak Akses <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user-tag text-gray-400"></i>
                        </div>
                        <select name="trole" class="block w-full pl-10 pr-10 py-3 text-base border-gray-300 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:border-slate-500 sm:text-sm rounded-xl appearance-none bg-gray-50 font-medium cursor-pointer" required>
                            <option value="" disabled selected>-- Pilih Role --</option>
                            <option value="Owner">Owner</option>
                            <option value="Admin HR">Admin HR</option>
                            <option value="Kepala Pabrik">Kepala Pabrik</option>
                            <option value="Admin Master">Admin Master</option>
                            <option value="Admin Boneless &amp; Borongan">Admin Boneless &amp; Borongan</option>
                            <?php
                            // 2026-10-30 (role per-OS): satu role per OS, diambil dari ms_os_dhk
                            // (bukan hardcode) — 'OS' generik dan 'testing123' tidak ditawarkan sebagai role baru.
                            $qosRole = $koneksi->query("SELECT OS_DHK FROM ms_os_dhk WHERE UPPER(OS_DHK) NOT IN ('OS', 'TESTING123') ORDER BY OS_DHK ASC");
                            while ($qosRole && ($rosRole = $qosRole->fetch_assoc())) {
                                $osOpt = trim((string)$rosRole['OS_DHK']);
                                if ($osOpt === '') continue;
                                echo '<option value="' . htmlspecialchars($osOpt) . '">' . htmlspecialchars($osOpt) . '</option>';
                            }
                            ?>
                            <option value="OS">OS</option>
                        </select>
                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-gray-500">
                            <i class="fas fa-chevron-down text-xs"></i>
                        </div>
                    </div>
                </div>

                <!-- OS (hanya bila Role = OS): akun ini hanya melihat data OS tersebut -->
                <div class="mb-8" id="os-wrap" style="display:none;">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        OS (untuk Role OS) <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <select name="tos" class="block w-full pl-10 pr-10 py-3 text-base border-gray-300 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:border-slate-500 sm:text-sm rounded-xl appearance-none bg-gray-50 font-medium cursor-pointer">
                            <option value="" disabled selected>-- Pilih OS --</option>
                            <?php $qos = $koneksi->query("SELECT id_os_dhk, OS_DHK FROM ms_os_dhk ORDER BY OS_DHK ASC"); while ($qos && ($ros = $qos->fetch_assoc())) { echo '<option value="' . (int)$ros['id_os_dhk'] . '">' . htmlspecialchars($ros['OS_DHK']) . '</option>'; } ?>
                        </select>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Akun OS hanya bisa buka Realisasi, Tarik Data, dan Karyawan — dan hanya data OS ini, tanpa bisa menambah/mengubah/menghapus.</p>
                </div>
                <script>
                (function() {
                    var roleSel = document.querySelector('select[name="trole"]');
                    var osWrap = document.getElementById('os-wrap');
                    var osSel = document.querySelector('select[name="tos"]');
                    function syncOs() {
                        var isOs = roleSel && roleSel.value === 'OS';
                        if (osWrap) osWrap.style.display = isOs ? 'block' : 'none';
                        if (osSel) osSel.required = !!isOs;
                    }
                    if (roleSel) roleSel.addEventListener('change', syncOs);
                    syncOs();
                })();
                </script>

                <div class="flex items-center justify-between pt-6 border-t border-gray-100">
                    <div class="text-xs text-gray-500 italic">
                        <span class="text-rose-500">*</span> Wajib diisi
                    </div>
                    <div class="flex gap-3">
                        <a href="?page=user" class="inline-flex items-center px-5 py-2.5 border border-gray-300 shadow-sm text-sm font-bold rounded-xl text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500 transition duration-150 ease-in-out">
                            Batal
                        </a>
                        <button type="submit" name="simpan" value="Simpan" class="inline-flex items-center px-6 py-2.5 border border-transparent text-sm font-bold rounded-xl shadow-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500 transition duration-150 ease-in-out transform hover:-translate-y-0.5">
                            <i class="fas fa-save mr-2"></i> Simpan
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php
if (isset($_POST['simpan'])) {
    $tnama     = $_POST['tnama'] ?? '';
    $trole     = $_POST['trole'] ?? '';
    $tpassword = $_POST['tpassword'] ?? '';
    // 2026-10-30 (role per-OS): Role internal id_os_dhk = NULL; role 'OS' generik
    // pakai OS dari dropdown bawah; role nama-OS (DHK/MJS/...) id-nya dicari dari
    // ms_os_dhk berdasarkan nama role (bukan dari dropdown bawah).
    $tos_id = (int)($_POST['tos'] ?? 0);
    $ckOsColT = $koneksi->query("SHOW COLUMNS FROM ms_login LIKE 'id_os_dhk'");
    $hasOsColT = ($ckOsColT && $ckOsColT->num_rows > 0);
    $isInternalT = in_array($trole, ['Owner', 'Admin HR', 'Kepala Pabrik', 'Admin Master', 'Admin Boneless & Borongan'], true);
    $osValT = "NULL";
    if ($trole === 'OS') {
        if ($tos_id <= 0) {
            echo "<script>alert('Pilih OS untuk akun Role OS.'); window.history.back();</script>"; exit;
        }
        if (!$hasOsColT) {
            echo "<script>alert('Jalankan dulu migrasi_login_os.sql, baru bisa buat akun OS.'); window.history.back();</script>"; exit;
        }
        $osValT = "'$tos_id'";
    } elseif (!$isInternalT && $trole !== '') {
        if (!$hasOsColT) {
            echo "<script>alert('Jalankan dulu migrasi_login_os.sql, baru bisa buat akun OS.'); window.history.back();</script>"; exit;
        }

        $trole_esc = $koneksi->real_escape_string(strtoupper($trole));
        $qosIdT = $koneksi->query("SELECT id_os_dhk FROM ms_os_dhk WHERE UPPER(OS_DHK) = '$trole_esc' LIMIT 1");
        $rosIdT = $qosIdT ? $qosIdT->fetch_assoc() : null;
        if (!$rosIdT) {
            echo "<script>alert('OS " . htmlspecialchars($trole) . " tidak ada di ms_os_dhk. Tambahkan dulu OS-nya.'); window.history.back();</script>"; exit;
        }
        $osValT = "'" . (int)$rosIdT['id_os_dhk'] . "'";
    }
    if ($hasOsColT) {
        $sql = $koneksi->query("INSERT INTO ms_login (id_perusahaan, username, password, role, id_os_dhk) VALUES ('1', '$tnama', '$tpassword', '$trole', $osValT)");
    } else {
        $sql = $koneksi->query("INSERT INTO ms_login (id_perusahaan, username, password, role) VALUES ('1', '$tnama', '$tpassword', '$trole')");
    }
    
    if ($sql) {
        echo "<!DOCTYPE html>
        <html>
        <head>
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        </head>
        <body>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil',
                    text: 'Data Berhasil Tersimpan',
                    confirmButtonColor: '#2563eb',
                    confirmButtonText: 'Selesai'
                }).then((result) => {
                    window.location.href='?page=user';
                });
            </script>
        </body>
        </html>";
        exit;
    } else {
        echo "<!DOCTYPE html>
        <html>
        <head>
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        </head>
        <body>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: 'Gagal menyimpan data!',
                    confirmButtonColor: '#2563eb',
                    confirmButtonText: 'Ok'
                });
            </script>
        </body>
        </html>";
    }
}
?>