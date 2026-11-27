<?php
// Library sync realisasi (dipakai kelola.php & tarik_ajax.php). Guarded agar aman double-include.
require_once __DIR__ . '/grouping_lib.php';
if (!function_exists('is_flexible_shift')) {
    // Deteksi shift fleksibel dua ejaan: Flexible (X) & Fleksibel/Fleksi (KS).
    // Seluruh kode yg tadinya cek 'FLEXI' saja gagal mengenali "Fleksibel".
    function is_flexible_shift($s) {
        $u = strtoupper(trim((string)($s ?? '')));
        return (strpos($u, 'FLEXI') !== false || strpos($u, 'FLEKSI') !== false);
    }
}
if (!function_exists('late_minutes')) {
    // Menit keterlambatan (negatif = lebih awal).
    // 2026-11-09: Tanpa putaran 24 jam. Tap masuk selalu di tanggal yg sama dgn
    // Realisasi (sync hanya ambil tap tgl itu), jadi selisih jam mentah sudah benar:
    // negatif = datang lebih awal, positif = telat. Putaran lama (batas 18 jam)
    // bikin datang >6 jam lebih awal kehitung telat — cth SYEILA 04:43 vs 13:00
    // = 943 mnt telat, padahal 8j17mnt lebih awal (bukan telat). EFAUDIN (04:00 ->
    // 20:07) tetap telat 967 mnt karena 20:07 memang sesudah 04:00 di hari yg sama.
    function late_minutes($actual, $expected) {
        if (empty($actual) || empty($expected) || $actual == '00:00:00' || $expected == '00:00:00' || $expected == 'Bebas') return 0;
        $diff = strtotime($actual) - strtotime($expected);
        // 2026-11-25 DINI_HARI: tap sebelum tengah malam (mis. 23:56 untuk
        // jadwal 00:00) adalah MASUK LEBIH AWAL, bukan telat 23 jam.
        if ($diff > 43200) $diff -= 86400;
        return (int)($diff / 60);
    }
    // Menit kepulangan-awal (positif = pulang lebih cepat), normalisasi sama.
    function early_minutes($actual, $expected) {
        if (empty($actual) || empty($expected) || $actual == '00:00:00' || $expected == '00:00:00' || $expected == 'Bebas') return 0;
        $diff = (strtotime($expected) - strtotime($actual) + 86400) % 86400;
        if ($diff > 43200) $diff -= 86400;
        return (int)($diff / 60);
    }
}
if (!function_exists('load_toleransi')) {
    // Ambil batas toleransi (menit) dari tb_toleransi; default ikut tarik-data (10/5/5/10).
    function load_toleransi($koneksi) {
        try {
            $q = $koneksi->query("SELECT * FROM tb_toleransi LIMIT 1");
            $r = $q ? $q->fetch_assoc() : [];
        } catch (Throwable $e) { $r = []; }
        if (!is_array($r)) $r = [];
        return [
            'masuk' => (int)($r['toleransi_masuk'] ?? 10),
            'pulang' => (int)($r['toleransi_pulang'] ?? 5),
            'ist_keluar' => (int)($r['toleransi_istirahat_keluar'] ?? 5),
            'ist_masuk' => (int)($r['toleransi_istirahat_masuk'] ?? 10),
        ];
    }
}
if (!function_exists('is_time_late')) {
    function is_time_late($actual, $expected) {
        if (empty($actual) || empty($expected) || $actual == '00:00:00' || $expected == '00:00:00' || $expected == 'Bebas') return false;
        $diff = strtotime($actual) - strtotime($expected);
        if ($diff > 43200) $diff -= 86400;
        elseif ($diff < -43200) $diff += 86400;
        return $diff > 0;
    }
}
if (!function_exists('is_time_early')) {
    function is_time_early($actual, $expected) {
        if (empty($actual) || empty($expected) || $actual == '00:00:00' || $expected == '00:00:00' || $expected == 'Bebas') return false;
        $diff = strtotime($actual) - strtotime($expected);
        if ($diff > 43200) $diff -= 86400;
        elseif ($diff < -43200) $diff += 86400;
        return $diff < 0;
    }
}

function syncRealisasiData($koneksi, $id_realisasi, $prelogs = null, $prelogs_ok = false, $tgl_override = null)
{
    // 2026-11-13: simpan nama+PIN ke baris history SAAT SYNC (bukan saat hapus),
    // supaya hapus master tidak menghilangkan nama di Tarik Data & slip.
    // Kolom no_absen_manual dibuat sekali saja per request.
    static $snap_col_ok = false;
    if (!$snap_col_ok) {
        $snap_col_ok = true;
        $ck = $koneksi->query("SHOW COLUMNS FROM tb_realisasi_detail LIKE 'no_absen_manual'");
        if ($ck && $ck->num_rows == 0) $koneksi->query("ALTER TABLE tb_realisasi_detail ADD COLUMN no_absen_manual VARCHAR(32) NULL AFTER nama_karyawan_manual");
        // 2026-11-21 UPAH_OTOMATIS: penanda upah manual (termasuk 0 disengaja).
        $cku = $koneksi->query("SHOW COLUMNS FROM tb_realisasi_detail LIKE 'upah_manual'");
        if ($cku && $cku->num_rows == 0) $koneksi->query("ALTER TABLE tb_realisasi_detail ADD COLUMN upah_manual TINYINT(1) NOT NULL DEFAULT 0");
    }
    // 1. Get the realization date and global denda
    $query_real = $koneksi->query("SELECT tgl_realisasi FROM tb_realisasi WHERE id_realisasi = '$id_realisasi'");
    $data_real = $query_real->fetch_assoc();
    if (!$data_real) return 0;
    $tgl_real = $data_real['tgl_realisasi'];
    // Override tanggal manual (datepicker): sinkron cocokkan tap tanggal pilihan, bukan tgl realisasi
    if (!empty($tgl_override) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$tgl_override)) $tgl_real = (string)$tgl_override;
    // 2026-11-16: tanggal yang dicap ke baris BARU selalu tgl Realisasi (bukan
    // override). Override cuma ngatur tap mana yang ditarik. Kalau baris Tambahan
    // kecap tanggal override, dia hilang dari slip tanggal Realisasi (kasus
    // Realisasi 68: 241 baris ketulis 4 Nov padahal Realisasi 3 Nov).
    $tgl_cap = $data_real['tgl_realisasi'];
    $tgl_next = date('Y-m-d', strtotime('+1 day', strtotime($tgl_real)));
    // 2026-11-25 DINI_HARI: tanggal H-1 dipakai untuk menangkap tap masuk
    // sebelum tengah malam pada shift yang mulai 00:00 (mis. "Shift 24 - 12").
    $tgl_prev = date('Y-m-d', strtotime('-1 day', strtotime($tgl_real)));

    // Preload SEMUA log 2 hari SEKALI (ganti N query per karyawan).
    // Urutan per pin dipertahankan (tgl ASC, detail_waktu ASC) persis query lama.
    $bulk_logs = is_array($prelogs) ? $prelogs : [];
    $bulk_ok = $prelogs_ok;
    if (!$bulk_ok) {
        $t0e = $koneksi->real_escape_string($tgl_real);
        $t1e = $koneksi->real_escape_string($tgl_next);
        $qb = $koneksi->query("SELECT userid, tgl, status, detail_waktu, source_device_id FROM tb_record WHERE tgl IN ('$t0e','$t1e') ORDER BY tgl ASC, detail_waktu ASC");
        if ($qb) {
            $bulk_ok = true;
            while ($br = $qb->fetch_assoc()) $bulk_logs[$br['userid']][] = $br;
        }
    }

    // 2026-11-25 DINI_HARI: preload tap H-1 sekali (bukan per baris). Tap malam
    // sebelum Realisasi bisa jadi MASUK shift yang mulai 00:00.
    $prev_logs = [];
    $tp_e = $koneksi->real_escape_string($tgl_prev);
    $qpv = $koneksi->query("SELECT userid, tgl, status, detail_waktu, source_device_id FROM tb_record WHERE tgl = '$tp_e' ORDER BY detail_waktu ASC");
    while ($qpv && ($rpv = $qpv->fetch_assoc())) $prev_logs[$rpv['userid']][] = $rpv;

    // 2026-11-25 TARIK_SAMA: grouping resmi PERSIS seperti halaman Tarik Data.
    // Key "PIN_tanggal"; tanggal grup = tanggal tap pertama (merge malam <=16 jam).
    // Realisasi hanya memakai grup yang tanggalnya SAMA dengan tanggal baris.
    $grouped_map = group_taps_by_pin_date($koneksi, $tgl_prev, $tgl_next, '');

    // 2026-11-18: PIN yang SAMA bisa dipakai ORANG BERBEDA di mesin berbeda.
    // Tap dari PIN milik karyawan lain (PIN = no_absen orang lain) JANGAN
    // diambil lewat mapping mesin. Contoh nyata: LOLA (no_absen 35) punya
    // slot PIN 40 di Mesin 2, padahal PIN 40 = no_absen EDI SUWANTORO.
    // Akibatnya tap Mesin 2 PIN 40 (bukan LOLA) nyasar ke baris LOLA padahal
    // halaman Tarik Data (cocok per no_absen) menampilkannya bukan sebagai LOLA.
    static $absen_owner = null;
    if ($absen_owner === null) {
        $absen_owner = [];
        $qao = $koneksi->query("SELECT id_karyawan, TRIM(no_absen) AS pin FROM ms_karyawan WHERE TRIM(COALESCE(no_absen,'')) <> ''");
        while ($qao && ($rao = $qao->fetch_assoc())) {
            $absen_owner[(string)$rao['pin']][] = (int)$rao['id_karyawan'];
        }
    }

    $query_denda = $koneksi->query("SELECT * FROM tb_denda LIMIT 1");
    $denda = $query_denda->fetch_assoc();
    $gd_masuk = $denda['denda_masuk'] ?? 0;
    $gd_istirahat_awal = $denda['denda_istirahat_keluar'] ?? 0;
    $gd_istirahat_telat = $denda['denda_istirahat_masuk'] ?? 0;
    $gd_pulang = $denda['denda_pulang'] ?? 0;
    $gd_tidak_lengkap = $denda['denda_tidak_lengkap'] ?? 0;
    // Toleransi (menit) — SAMA kayak halaman tarik data
    $tol = function_exists('load_toleransi') ? load_toleransi($koneksi) : ['masuk' => 10, 'pulang' => 5, 'ist_keluar' => 5, 'ist_masuk' => 10];

    // ID grup TIDAK DALAM RENCANA (baris tap tanpa master): jangan ditimpa sync
    $gid_tdr = 0;
    $qg0 = $koneksi->query("SELECT id_grup FROM ms_grup_borongan WHERE UPPER(TRIM(nama_grup)) = 'TIDAK DALAM RENCANA' LIMIT 1");
    if ($qg0 && ($gg0 = $qg0->fetch_assoc())) $gid_tdr = (int)$gg0['id_grup'];

    // 2026-11-10: ID grup KARYAWAN TAMBAHAN (untuk baris pengganti yang dibayar).
    // Kalau belum ada, buat (sama seperti page/realisasi/karyawan.php).
    $gid_tambahan = 0;
    $qgt = $koneksi->query("SELECT id_grup FROM ms_grup_borongan WHERE UPPER(TRIM(nama_grup)) = 'KARYAWAN TAMBAHAN' LIMIT 1");
    if ($qgt && ($ggt = $qgt->fetch_assoc())) $gid_tambahan = (int)$ggt['id_grup'];
    if ($gid_tambahan <= 0) {
        $koneksi->query("INSERT INTO ms_grup_borongan (nama_grup, keterangan, tgl_updt, is_borongan) VALUES ('KARYAWAN TAMBAHAN', 'Grup otomatis untuk karyawan yang ditambahkan manual di realisasi', NOW(), 0)");
        $gid_tambahan = (int)$koneksi->insert_id;
    }

    // 2026-11-10: Tarif lembur master (tb_lembur.lembur_per_jam, global).
    // Dipakai untuk menghitung ulang lembur baris yang punya lembur_jam > 0,
    // supaya perubahan tarif di master langsung ikut saat Tarik Data.
    $lembur_per_jam = 0;
    $qlb = $koneksi->query("SELECT lembur_per_jam FROM tb_lembur LIMIT 1");
    if ($qlb && ($rlb = $qlb->fetch_assoc())) $lembur_per_jam = (float)($rlb['lembur_per_jam'] ?? 0);
    // 2026-11-11: Tarif per menit dibulatkan KE ATAS ke 1 desimal (5000/60 = 83,4).
    // round() biasa menghasilkan 83,3 (63 x 83,3 = Rp 5.248, SALAH) — klien
    // menghitung 83,4, jadi pakai ceil. 63 menit x 83,4 = Rp 5.254.
    // Dipakai di semua hitung lembur Rp.
    $lembur_per_menit = ceil($lembur_per_jam / 60 * 10) / 10;

    // 2. Get all employees in this realization (using LEFT JOIN to include manual employees and RKK fallback)
    $query_details = $koneksi->query("SELECT RD.id_realisasi_detail, RD.id_karyawan, RD.id_pengganti, RD.id_grup AS grup_id, RD.r_upah as r_upah_lama, RD.upah_manual, RD.nama_karyawan_manual, RD.no_absen_manual, RD.lembur_jam, K.no_absen, KP.no_absen AS no_absen_pengganti, KP.nama_karyawan AS nama_pengganti, KP.upah AS upah_master_pengganti, K.nama_karyawan AS nama_karyawan, K.upah as upah_master, RKKD.upah as upah_rkk, RKKD.status_rkk as status_rkk, RD.id_jadwal as detail_jadwal, RKKD.id_jadwal as rkk_jadwal, RD.status_realisasi_detail, 
                                      COALESCE(J.keterangan, JR.keterangan) as shift_ket,
                                      COALESCE(J.jam_masuk, JR.jam_masuk) as s_masuk, 
                                      COALESCE(J.jam_keluar, JR.jam_keluar) as s_keluar, 
                                      COALESCE(J.istirahat_masuk, JR.istirahat_masuk) as s_ist_masuk, 
                                      COALESCE(J.istirahat_keluar, JR.istirahat_keluar) as s_ist_keluar,
                                      KP.id_jadwal as id_jadwal_pengganti,
                                      JP.jam_masuk as s_masuk_pg, JP.jam_keluar as s_keluar_pg,
                                      JP.istirahat_masuk as s_ist_masuk_pg, JP.istirahat_keluar as s_ist_keluar_pg,
                                      (UPPER(TRIM(COALESCE(GL.golongan, ''))) = 'BORONGAN'
                                       OR COALESCE(GA.is_borongan, 0) = 1
                                       OR COALESCE(GB.is_borongan, 0) = 1) as is_borongan_row
                                      FROM tb_realisasi_detail RD
                                      LEFT JOIN ms_karyawan K ON RD.id_karyawan = K.id_karyawan
                                      LEFT JOIN ms_karyawan KP ON RD.id_pengganti = KP.id_karyawan
                                      LEFT JOIN tb_rkk_detail RKKD ON RD.id_rkk_detail = RKKD.id_rkk_detail
                                      LEFT JOIN tb_jadwal J ON RD.id_jadwal = J.id_jadwal
                                      LEFT JOIN tb_jadwal JR ON RKKD.id_jadwal = JR.id_jadwal
                                      LEFT JOIN tb_jadwal JP ON KP.id_jadwal = JP.id_jadwal
                                      LEFT JOIN ms_golongan GL ON K.id_golongan = GL.id_golongan
                                      LEFT JOIN ms_grup_borongan GB ON RD.id_grup = GB.id_grup
                                      LEFT JOIN ms_grup_borongan_anggota GA ON GA.id_grup = RD.id_grup AND GA.id_karyawan = RD.id_karyawan
                                      WHERE RD.id_realisasi = '$id_realisasi'");

    // 2026-11-21 UPAH_OTOMATIS: peta master untuk baris yang tidak punya relasi
    // (id_karyawan = 0, mis. KARYAWAN TAMBAHAN). Dipakai untuk mencari upah
    // dan PIN tap lewat nama/PIN yang persis sama. Satu query per sync.
    $upah_peta = ['pin' => [], 'nama' => [], 'nama_pin' => []];
    $qm_peta = $koneksi->query("SELECT nama_karyawan, no_absen, upah FROM ms_karyawan");
    while ($qm_peta && ($rm_peta = $qm_peta->fetch_assoc())) {
        $pk_peta = trim((string)($rm_peta['no_absen'] ?? ''));
        $nk_peta = strtoupper(trim((string)($rm_peta['nama_karyawan'] ?? '')));
        $up_peta = (float)($rm_peta['upah'] ?? 0);
        if ($pk_peta !== '') {
            if ($up_peta > 0) $upah_peta['pin'][$pk_peta] = max($upah_peta['pin'][$pk_peta] ?? 0, $up_peta);
            // Simpan upah per PIN juga: kalau nama dobel (mis. master duplikat
            // dengan upah 0), pilih PIN milik record yang upahnya masuk akal.
            if ($nk_peta !== '') $upah_peta['nama_pin'][$nk_peta][$pk_peta] = max($upah_peta['nama_pin'][$nk_peta][$pk_peta] ?? 0, $up_peta);
        }
        if ($nk_peta !== '' && $up_peta > 0) $upah_peta['nama'][$nk_peta] = max($upah_peta['nama'][$nk_peta] ?? 0, $up_peta);
    }

    $count = 0;
    while ($row = $query_details->fetch_assoc()) {
        $id_rd = $row['id_realisasi_detail'];
        // Baris TIDAK DALAM RENCANA dilewati (datanya dari tap langsung, jangan ditimpa jadi kosong)
        if ($gid_tdr > 0 && (int)($row['grup_id'] ?? -1) === $gid_tdr) continue;
        // 2026-11-10: Baris yang digantikan (id_pengganti > 0) TIDAK lagi dilewati
        // sync. Tap mesin dicari pakai PIN/no_absen PENGGANTI (bukan karyawan asli),
        // supaya finger pengganti muncul di baris ini (kolom Jam Masuk/Pulang).
        // Label "Digantikan oleh ..." di Kelola tetap dari id_pengganti. Upah &
        // status manual (status_realisasi_detail = 1, diisi Detail) tetap
        // dipertahankan sync (lihat logika upah di bawah) — kecuali total tidak
        // hadir (pengganti juga tidak finger), upah tetap jadi 0 seperti biasa.
        $id_pengganti = (int)($row['id_pengganti'] ?? 0);
        $no_absen = $row['no_absen'];
        $idk_row = (int)($row['id_karyawan'] ?? 0);
        if ($id_pengganti > 0 && trim((string)($row['no_absen_pengganti'] ?? '')) !== '') {
            $no_absen = trim((string)$row['no_absen_pengganti']);
            $idk_row = $id_pengganti;
        }
        $status_rd = $row['status_realisasi_detail'];
        // 2026-11-21: baris borongan upahnya dari rincian qty x tarif, bukan master.
        $is_borongan_row = !empty($row['is_borongan_row']);

        // 2026-11-21 UPAH_OTOMATIS: baris manual tanpa id_karyawan/no_absen tetap
        // bisa menemukan tap-nya: pakai no_absen_manual, atau PIN master dengan
        // nama yang persis sama (hanya kalau PIN-nya tunggal, supaya tidak salah orang).
        if (trim((string)$no_absen) === '') {
            $pin_manual_rd = trim((string)($row['no_absen_manual'] ?? ''));
            if ($pin_manual_rd !== '') {
                $no_absen = $pin_manual_rd;
            } else {
                $nm_lookup_rd = strtoupper(trim((string)($row['nama_karyawan_manual'] ?? '')));
                if ($nm_lookup_rd === '') $nm_lookup_rd = strtoupper(trim((string)($row['nama_karyawan'] ?? '')));
                if ($nm_lookup_rd !== '' && !empty($upah_peta['nama_pin'][$nm_lookup_rd])) {
                    // Nama bisa punya beberapa PIN (master duplikat). Pilih yang
                    // upahnya paling masuk akal (bukan record duplikat upah 0).
                    $pins_nm_rd = $upah_peta['nama_pin'][$nm_lookup_rd];
                    arsort($pins_nm_rd);
                    $no_absen = (string)array_key_first($pins_nm_rd);
                }
            }
        }

        // Include manual employees (Previously skipped)
        $shift_ket = $row['shift_ket'] ?? '';
        $is_flexible = is_flexible_shift($shift_ket);
        // 2026-11-10: Baris digantikan (id_pengganti > 0) = FLEXIBLE. Tap pengganti
        // jangan didenda telat/pulang-cepat/istirahat/tidak-lengkap terhadap jadwal
        // orang yang digantikan (yang tidak masuk). Jam-nya tetap ditampilkan
        // (dari tap pengganti), tapi tidak ada potongan/denda.
        if ($id_pengganti > 0) $is_flexible = true;

        $s_masuk = $row['s_masuk'] ?? '00:00:00';
        $s_keluar = $row['s_keluar'] ?? '00:00:00';
        $s_ist_masuk = $row['s_ist_masuk'] ?? '00:00:00';
        $s_ist_keluar = $row['s_ist_keluar'] ?? '00:00:00';

        if (empty($no_absen)) {
            $is_total_missing = true;
            $ra_masuk = "'00:00:00'";
            $ra_keluar = "'00:00:00'";
            $ra_ist_keluar = "'00:00:00'";
            $ra_ist_masuk = "'00:00:00'";
        } else {
            // Ambil dari peta preload (kunci EXACT userid, sama kayak WHERE lama).
            // Fallback ke query per baris bila preload tidak tersedia/gagal.
            $raw_logs = [];
            if ($bulk_ok && array_key_exists($no_absen, $bulk_logs)) {
                $raw_logs = $bulk_logs[$no_absen];
            } else {
                // Get logs for today and tomorrow to support cross-midnight night-shift merge
                $tgl_next = date('Y-m-d', strtotime('+1 day', strtotime($tgl_real)));
                $q_logs = $koneksi->query("SELECT * FROM tb_record WHERE userid = '$no_absen' AND tgl IN ('$tgl_real', '$tgl_next') ORDER BY tgl ASC, detail_waktu ASC");
                while ($q_logs && ($r_log = $q_logs->fetch_assoc())) {
                    $raw_logs[] = $r_log;
                }
            }

            // PIN lain milik karyawan yang sama di mesin berbeda (mapping nama per mesin)
            if ($idk_row > 0 && function_exists('karyawan_pins_by_id')) {
                $extra_pins = karyawan_pins_by_id($koneksi)[$idk_row] ?? [];
                $tgl_next2 = date('Y-m-d', strtotime('+1 day', strtotime($tgl_real)));
                    $tgl_e = $koneksi->real_escape_string($tgl_real);
                $tgl_e2 = $koneksi->real_escape_string($tgl_next2);
                $tgl_e0 = $koneksi->real_escape_string($tgl_prev);
                foreach ($extra_pins as $ep) {
                    $did_ep = (int)($ep[0] ?? 0);
                    $pin_ep = trim((string)($ep[1] ?? ''));
                    if ($pin_ep === '' || $pin_ep === (string)$no_absen) continue;
                    // 2026-11-18: jangan ambil tap dari PIN yang merupakan nomor
                    // absen karyawan LAIN (tap itu milik orang tersebut, bukan dia).
                    if (isset($absen_owner[$pin_ep]) && !in_array((int)$idk_row, $absen_owner[$pin_ep], true)) continue;
                    $pin_e = $koneksi->real_escape_string($pin_ep);
                    $q_ep = $koneksi->query("SELECT * FROM tb_record WHERE source_device_id = '$did_ep' AND TRIM(userid) = '$pin_e' AND tgl IN ('$tgl_e0','$tgl_e','$tgl_e2') ORDER BY tgl ASC, detail_waktu ASC");
                    while ($q_ep && ($r_ep = $q_ep->fetch_assoc())) $raw_logs[] = $r_ep;
                }
                if (!empty($raw_logs)) usort($raw_logs, function($a, $b) {
                    return strcmp(($a['tgl'] ?? '') . ' ' . ($a['detail_waktu'] ?? ''), ($b['tgl'] ?? '') . ' ' . ($b['detail_waktu'] ?? ''));
                });
            }

            // 2026-11-10: Fallback PIN duplikat nama sama. Ada karyawan yang
            // dobel di ms_karyawan (nama sama, PIN beda) — mis. AGUS RIYANTO
            // id 912 (PIN 1300, ada tap) vs id 920 (PIN 1308, tidak ada tap).
            // Kalau pengganti menunjuk ke duplikat yang TIDAK ada tap-nya,
            // cari tap di PIN milik master lain yang namanya sama.
            if (empty($raw_logs) && ($idk_row > 0 || trim((string)($row['nama_karyawan_manual'] ?? '')) !== '')) {
                $nm_cari = trim((string)($row['nama_pengganti'] ?? ''));
                if ($id_pengganti <= 0) {
                    $nm_cari = trim((string)($row['nama_karyawan'] ?? ''));
                    // 2026-11-21: baris manual (id_karyawan=0) tetap dicarikan
                    // tap lewat nama di master.
                    if ($nm_cari === '') $nm_cari = trim((string)($row['nama_karyawan_manual'] ?? ''));
                }
                if ($nm_cari !== '') {
                    $nm_e = $koneksi->real_escape_string($nm_cari);
                    $tgl_e = $koneksi->real_escape_string($tgl_real);
                    $tgl_nx = date('Y-m-d', strtotime('+1 day', strtotime($tgl_real)));
                    $tgl_e2 = $koneksi->real_escape_string($tgl_nx);
                    $tgl_e0 = $koneksi->real_escape_string($tgl_prev);
                    $q_dup = $koneksi->query("SELECT no_absen FROM ms_karyawan WHERE id_karyawan <> '$idk_row' AND UPPER(TRIM(nama_karyawan)) = UPPER(TRIM('$nm_e')) AND TRIM(COALESCE(no_absen,'')) <> ''");
                    while ($q_dup && ($r_dup = $q_dup->fetch_assoc())) {
                        $pin_dup = trim((string)($r_dup['no_absen'] ?? ''));
                        if ($pin_dup === '' || $pin_dup === (string)$no_absen) continue;
                        $pin_d = $koneksi->real_escape_string($pin_dup);
                        $q_dd = $koneksi->query("SELECT * FROM tb_record WHERE userid = '$pin_d' AND tgl IN ('$tgl_e0','$tgl_e','$tgl_e2') ORDER BY tgl ASC, detail_waktu ASC");
                        while ($q_dd && ($r_dd = $q_dd->fetch_assoc())) $raw_logs[] = $r_dd;
                    }
                    if (!empty($raw_logs)) usort($raw_logs, function($a, $b) {
                        return strcmp(($a['tgl'] ?? '') . ' ' . ($a['detail_waktu'] ?? ''), ($b['tgl'] ?? '') . ' ' . ($b['detail_waktu'] ?? ''));
                    });
                }
            }

            // 2026-11-25 DINI_HARI: sertakan tap H-1 untuk PIN utama baris ini
            // (dipakai saat mencocokkan MASUK shift yang mulai 00:00).
            if (!empty($prev_logs[$no_absen])) {
                foreach ($prev_logs[$no_absen] as $lpv) $raw_logs[] = $lpv;
            }

            $log_masuk = null;
            $log_keluar = null;
            $ot_hours_auto = 0;
            $log_ist_keluar = null;
            $log_ist_masuk = null;
            
            // 2026-11-09: Masuk/pulang TIDAK lagi ngikutin status in/out mesin
            // (mesin sering kirim semua tap sebagai IN / toggle sendiri).
            // Prinsip: tiap tap dipasangkan ke slot (masuk/pulang) yg PALING DEKAT
            // dgn jadwal. Masuk = tap di $tgl_real yg paling dekat dgn jam masuk,
            // TAPI hanya tap yg memang lebih dekat ke jam masuk daripada ke jam
            // pulang (tap yg lebih dekat ke jam pulang itu tap pulang, bukan masuk).
            // Kalau jadwal kosong, pakai aturan lama.
            $s_masuk_t = trim((string)$s_masuk);
            $s_keluar_t = trim((string)$s_keluar);
            // 2026-11-25 DINI_HARI: jam masuk "00:00:00" TETAP dianggap jadwal
            // selama jam keluar nyata (mis. "Shift 24 - 12" = 00:00-12:00).
            // Shift fleksibel (jam keluar juga 00:00) tetap tanpa jadwal.
            $has_jadwal = ($s_masuk_t !== '' && $s_keluar_t !== '' && $s_keluar_t !== '00:00:00');
            $tgl_next_sched = date('Y-m-d', strtotime('+1 day', strtotime($tgl_real)));
            $sched_in_ts = null; $sched_out_ts = null;
            $best_in_ts = null;
            if ($has_jadwal) {
                $sched_in_ts = strtotime($tgl_real . ' ' . $s_masuk_t);
                // Shift malam (keluar < masuk): jadwal pulang di hari berikutnya.
                $sched_out_ts = ($s_keluar_t < $s_masuk_t)
                    ? strtotime($tgl_next_sched . ' ' . $s_keluar_t)
                    : strtotime($tgl_real . ' ' . $s_keluar_t);
                $best_in = null; $best_in_diff = null;
                // 2026-11-25 DINI_HARI: tap H-1 yang jatuh beberapa jam SEBELUM
                // jam masuk boleh jadi MASUK (karyawan finger sebelum tengah
                // malam untuk shift 00:00). Dibatasi 4 jam sebelum masuk agar
                // tap lama H-1 tidak salah dianggap masuk.
                $early_window = 4 * 3600;
                foreach ($raw_logs as $l) {
                    $is_same_day = ($l['tgl'] == $tgl_real);
                    $ct = substr($l['detail_waktu'], 11, 8);
                    $ts = strtotime($l['tgl'] . ' ' . $ct);
                    $is_early_prev = ($l['tgl'] == $tgl_prev && $ts <= $sched_in_ts && ($sched_in_ts - $ts) <= $early_window);
                    if (!$is_same_day && !$is_early_prev) continue;
                    $d_in = abs($ts - $sched_in_ts);
                    if ($d_in > abs($ts - $sched_out_ts)) continue; // lebih dekat ke pulang -> bukan masuk
                    if ($best_in_diff === null || $d_in < $best_in_diff) { $best_in_diff = $d_in; $best_in = $ct; $best_in_ts = $ts; }
                }
                $log_masuk = $best_in;
            } else {
                // Aturan lama (tanpa jadwal): status 0 paling awal, lalu tap pertama hari itu
                foreach ($raw_logs as $l) {
                    if ($l['tgl'] == $tgl_real && $l['status'] == 0) {
                        $log_masuk = substr($l['detail_waktu'], 11, 8);
                        break;
                    }
                }
                if (!$log_masuk) {
                    foreach ($raw_logs as $l) {
                        if ($l['tgl'] == $tgl_real) {
                            $log_masuk = substr($l['detail_waktu'], 11, 8);
                            break;
                        }
                    }
                }
            }
            
            // Anchor jendela pulang: tap masuk kalau ada; kalau tidak ada tap masuk
            // tapi ada jadwal, pakai jam masuk jadwal (biar tap pulang tetap bisa
            // ke-detek walau orangnya lupa finger masuk).
            $in_timestamp = null;
            if ($log_masuk) {
                // 2026-11-25 DINI_HARI: anchor pakai tanggal tap sebenarnya
                // (tap masuk bisa dari H-1 untuk shift 00:00).
                $in_timestamp = ($best_in_ts !== null) ? $best_in_ts : strtotime($tgl_real . ' ' . $log_masuk);
            } elseif ($has_jadwal) {
                $in_timestamp = $sched_in_ts;
            }
            // Tap <10 menit dari masuk = dobel/absen ganda, abaikan (sama kayak dedup tarik data)
            $gap10 = 10 / 60;

            if ($in_timestamp !== null) {
                if ($has_jadwal) {
                    // Pulang = tap (setelah anchor +10 mnt, dalam 16 jam) yg PALING
                    // DEKAT dgn jam pulang jadwal, dan lebih dekat ke jam pulang
                    // daripada ke jam masuk (biar tap masuk tidak ke-pilih jadi pulang).
                    $best_out = null; $best_out_diff = null; $best_out_ts = null;
                    foreach ($raw_logs as $l) {
                        $curr_time = substr($l['detail_waktu'], 11, 8);
                        $curr_timestamp = strtotime($l['tgl'] . ' ' . $curr_time);
                        $diff_hours = ($curr_timestamp - $in_timestamp) / 3600;
                        // 2026-11-17 PULANG16JAM: tap di TANGGAL YANG SAMA dgn Realisasi
                        // tidak kena batas 16 jam — tap itu tidak mungkin masuk besok.
                        // Batas 16 jam hanya untuk tap dari tanggal lain (pengaman
                        // masuk-besok). Cth MARTRINDA 16/11: masuk 05:53, pulang 21:57
                        // (16j04m) — tadinya kebuang karena >16 jam, pulang jadi kosong.
                        $is_same_day = ($l['tgl'] == $tgl_real);
                        if ($diff_hours <= $gap10 || (!$is_same_day && $diff_hours > 16)) continue;
                        if (abs($curr_timestamp - $sched_out_ts) >= abs($curr_timestamp - $sched_in_ts)) continue; // lebih dekat ke masuk -> bukan pulang
                        $diff = abs($curr_timestamp - $sched_out_ts);
                        if ($best_out_diff === null || $diff < $best_out_diff) { $best_out_diff = $diff; $best_out = $curr_time; $best_out_ts = $curr_timestamp; }
                    }
                    $log_keluar = $best_out;
                    // 2026-11-09: Pengaman pulang-vs-masuk-besok. Tap "pulang" yg ternyata
                    // LEBIH DEKAT ke jam masuk BESOK daripada ke jam pulang hari ini
                    // hampir pasti masuk besok, bukan pulang hari ini -> jangan jadikan
                    // pulang (biarkan kosong). Cth: tap 10:17 (besok) yg ke-pilih jadi
                    // pulang padahal jadwal pulang 21:00 & masuk besok 09:00.
                    if ($best_out !== null && $best_out_ts !== null) {
                        $sched_in_next_ts = strtotime($tgl_next_sched . ' ' . $s_masuk_t);
                        if (abs($best_out_ts - $sched_in_next_ts) < abs($best_out_ts - $sched_out_ts)) {
                            $log_keluar = null;
                        }
                    }
                    // 2026-11-11: Lembur otomatis = pulang melewati jam pulang jadwal.
                    // (Datang pagi tidak dihitung.) Dihitung PER MENIT: tarif/jam dibagi 60
                    // (5000/60 = 83,33/menit). Ambang 10 menit: lembur <= 10 menit = 0
                    // (gratis); kalau LEBIH dari 10 menit, SEMUA menit kehitung — menit 1-10
                    // TIDAK gratis, tetap dibayar. Lewat 10 menit = 0; lewat 11 menit = 11
                    // menit (Rp 917); lewat 18 menit = 18 menit (Rp 1.500); lewat 60 menit
                    // = 60 menit (Rp 5.000); lewat 63 menit = 63 menit (Rp 5.250); lewat 81
                    // menit = 81 menit (Rp 6.750). lembur_jam disimpan sebagai jam desimal
                    // (menit/60) supaya lembur Rp = jam x tarif.
                    if ($log_keluar !== null && $best_out_ts !== null && $sched_out_ts !== null) {
                        $diff_ot = $best_out_ts - $sched_out_ts;
                        if ($diff_ot > 43200) $diff_ot -= 86400;
                        elseif ($diff_ot < -43200) $diff_ot += 86400;
                        // 2026-11-11: Lebih lembur dibulatkan ke MENIT PENUH (floor,
                        // detik dibuang) supaya cocok dengan hitungan klien: pulang 04:03:51
                        // = 63 menit (bukan 63,85) -> 63 x 83,33 = Rp 5.250.
                        $ot_min = (int)floor($diff_ot / 60);
                        if ($ot_min > 10) $ot_hours_auto = $ot_min / 60;
                    }
                } elseif ($log_masuk) {
                    // Aturan lama (tanpa jadwal): status 1 terakhir dalam 16 jam,
                    // lalu tap terakhir dalam 16 jam
                    for ($i = count($raw_logs) - 1; $i >= 0; $i--) {
                        $l = $raw_logs[$i];
                        if ($l['status'] == 1) {
                            $curr_time = substr($l['detail_waktu'], 11, 8);
                            $curr_timestamp = strtotime($l['tgl'] . ' ' . $curr_time);
                            $diff_hours = ($curr_timestamp - $in_timestamp) / 3600;
                            // 2026-11-17 PULANG16JAM: tap tgl sama tidak kena batas 16 jam.
                            $is_same_day = ($l['tgl'] == $tgl_real);
                            if ($diff_hours > $gap10 && ($is_same_day || $diff_hours <= 16)) {
                                $log_keluar = $curr_time;
                                break;
                            }
                        }
                    }
                    if (!$log_keluar) {
                        for ($i = count($raw_logs) - 1; $i >= 0; $i--) {
                            $l = $raw_logs[$i];
                            $curr_time = substr($l['detail_waktu'], 11, 8);
                            if ($l['tgl'] == $tgl_real && $curr_time == $log_masuk) {
                                continue;
                            }
                            $curr_timestamp = strtotime($l['tgl'] . ' ' . $curr_time);
                            $diff_hours = ($curr_timestamp - $in_timestamp) / 3600;
                            // 2026-11-17 PULANG16JAM: tap tgl sama tidak kena batas 16 jam.
                            $is_same_day = ($l['tgl'] == $tgl_real);
                            if ($diff_hours > $gap10 && ($is_same_day || $diff_hours <= 16)) {
                                $log_keluar = $curr_time;
                                break;
                            }
                        }
                    }
                }
            }

            if ($log_masuk) {
                // Find break-out (status 2) and break-in (status 3) within 16 hours
                foreach ($raw_logs as $l) {
                    $curr_time = substr($l['detail_waktu'], 11, 8);
                    $curr_timestamp = strtotime($l['tgl'] . ' ' . $curr_time);
                    $diff_hours = ($curr_timestamp - $in_timestamp) / 3600;
                    if ($diff_hours > $gap10 && $diff_hours <= 16) {
                        if ($l['status'] == 2 && !$log_ist_keluar) {
                            $log_ist_keluar = $curr_time;
                        }
                        if ($l['status'] == 3 && !$log_ist_masuk) {
                            $log_ist_masuk = $curr_time;
                        }
                    }
                }

                // Fallback: mesin tidak mengirim status 2/3, tebak istirahat dari tap tengah.
                // Hari normal 4 tap (masuk, ist-keluar, ist-masuk, pulang): ambil tap ke-2 & tengah-terakhir.
                // Pengaman: abaikan tap ganda (menit sama) + tap yang menempel masuk/pulang (<30 mnt).
                if (!$log_ist_keluar || !$log_ist_masuk) {
                    $pool = []; $seen_min = [];
                    foreach ($raw_logs as $l) {
                        if (in_array((int)$l['status'], [4, 5], true)) continue; // lembur, bukan istirahat
                        $ct = substr($l['detail_waktu'], 11, 8);
                        $mk = substr($ct, 0, 5);
                        if (isset($seen_min[$mk])) continue;
                        $seen_min[$mk] = true;
                        $cts = strtotime($l['tgl'] . ' ' . $ct);
                        $dh = ($cts - $in_timestamp) / 3600;
                        if ($dh >= 0 && $dh <= 16) $pool[] = ['t' => $ct, 'ts' => $cts];
                    }
                    usort($pool, function($a, $b) { return $a['ts'] - $b['ts']; });
                    $m = count($pool);
                    $pul_end = $m > 0 ? $pool[$m - 1]['ts'] : 0;
                    $is_mid = function($ts) use ($in_timestamp, $pul_end) {
                        return ($ts - $in_timestamp) >= 1800 && ($pul_end <= 0 || ($pul_end - $ts) >= 1800);
                    };
                    if ($m >= 4) {
                        if (!$log_ist_keluar && $is_mid($pool[1]['ts'])) $log_ist_keluar = $pool[1]['t'];
                        if (!$log_ist_masuk && $is_mid($pool[$m - 2]['ts'])) $log_ist_masuk = $pool[$m - 2]['t'];
                    } elseif ($m == 3) {
                        // 3 tap: tentukan sisi dari titik tengah jam istirahat shift
                        $bk = trim((string)$s_ist_keluar); $bm = trim((string)$s_ist_masuk);
                        if ($bk !== '' && $bk !== '00:00:00' && $bm !== '' && $bm !== '00:00:00' && $is_mid($pool[1]['ts'])) {
                            $mid = (strtotime($bk) + strtotime($bm)) / 2;
                            $mid_t = date('H:i:s', (int)$mid);
                            if ($pool[1]['t'] < $mid_t) { if (!$log_ist_keluar) $log_ist_keluar = $pool[1]['t']; }
                            else { if (!$log_ist_masuk) $log_ist_masuk = $pool[1]['t']; }
                        }
                    }
                }
            }

            // 2026-11-25 TARIK_SAMA: pakai grup resmi halaman Tarik Data.
            // Aturan tambahan: bila grup ada di H-1 dan jam masuknya <=2 jam
            // SEBELUM jadwal masuk hari ini (mis. 23:5x untuk shift 00:00),
            // tap itu tetap dihitung MASUK & hadir di tanggal Realisasi ini.
            $grouped_elsewhere = false;
            $grp_source_date = $tgl_real;
            $group_key = trim((string)$no_absen) . '_' . $tgl_real;
            $grp = $grouped_map[$group_key] ?? null;
            if ($grp === null) {
                $prev_key = trim((string)$no_absen) . '_' . $tgl_prev;
                if (isset($grouped_map[$prev_key]) && !empty($grouped_map[$prev_key]['masuk'])) {
                    $grp_prev = $grouped_map[$prev_key];
                    $masuk_prev_ts = strtotime($tgl_prev . ' ' . $grp_prev['masuk']);
                    if ($sched_in_ts !== null && $masuk_prev_ts <= $sched_in_ts && ($sched_in_ts - $masuk_prev_ts) <= 2 * 3600) {
                        $grp = $grp_prev;
                        $grp_source_date = $tgl_prev;
                    }
                }
            }
            if ($grp !== null) {
                $log_masuk = !empty($grp['masuk']) ? $grp['masuk'] : null;
                $log_keluar = !empty($grp['pulang']) ? $grp['pulang'] : null;
                $log_ist_keluar = !empty($grp['istirahat_keluar']) ? $grp['istirahat_keluar'] : null;
                $log_ist_masuk = !empty($grp['istirahat_masuk']) ? $grp['istirahat_masuk'] : null;
            } else {
                $log_masuk = null;
                $log_keluar = null;
                $log_ist_keluar = null;
                $log_ist_masuk = null;
                // Tap masuk ke grup tanggal lain dan tidak memenuhi syarat <=2 jam
                // -> baris ini tidak menarik data (sama seperti Tarik Data).
                $grouped_elsewhere = isset($grouped_map[trim((string)$no_absen) . '_' . $tgl_prev]);
            }
            // Hitung ulang lembur otomatis dari hasil grup (pakai tanggal asli
            // grup, karena masuk bisa berasal dari H-1 malam).
            $ot_hours_auto = 0;
            if ($log_keluar && ($log_masuk || $has_jadwal)) {
                $in_grp_ts = $log_masuk ? strtotime($grp_source_date . ' ' . $log_masuk) : $sched_in_ts;
                $out_grp_ts = strtotime($grp_source_date . ' ' . $log_keluar);
                if ($in_grp_ts && $out_grp_ts <= $in_grp_ts) $out_grp_ts = strtotime('+1 day', $out_grp_ts);
                if ($has_jadwal && $sched_out_ts !== null && $in_grp_ts) {
                    $diff_ot = $out_grp_ts - $sched_out_ts;
                    if ($diff_ot > 43200) $diff_ot -= 86400;
                    elseif ($diff_ot < -43200) $diff_ot += 86400;
                    $ot_min = (int)floor($diff_ot / 60);
                    if ($ot_min > 10) $ot_hours_auto = $ot_min / 60;
                }
            }

            $logs = [
                'log_masuk' => $log_masuk,
                'log_keluar' => $log_keluar,
                'log_ist_keluar' => $log_ist_keluar,
                'log_ist_masuk' => $log_ist_masuk
            ];
            
            $ra_masuk = $log_masuk ? "'" . $log_masuk . "'" : "'00:00:00'";
            $ra_keluar = $log_keluar ? "'" . $log_keluar . "'" : "'00:00:00'";
            $ra_ist_keluar = $log_ist_keluar ? "'" . $log_ist_keluar . "'" : "'00:00:00'";
            $ra_ist_masuk = $log_ist_masuk ? "'" . $log_ist_masuk . "'" : "'00:00:00'";
            
            $has_masuk = !empty($log_masuk);
            $has_keluar = !empty($log_keluar);
            $has_ist_keluar = !empty($log_ist_keluar);
            $has_ist_masuk = !empty($log_ist_masuk);
            
            $is_total_missing = (!$has_masuk && !$has_keluar);
        }

        // 4. Calculate Penalties and Handle Absence
        $p_telat = 0;
        $p_pulang = 0;
        $p_ist_awal = 0;
        $p_ist_telat = 0;
        $p_tidak_lengkap = 0;

        // If no_absen was empty, these are already set correctly in the if block.
        // If no_absen was NOT empty, logs were fetched. 
        // We ensure $has_ variables exist for the penalty logic below.
        if (!empty($no_absen)) {
            $has_masuk = !empty($logs['log_masuk']);
            $has_keluar = !empty($logs['log_keluar']);
            $has_ist_keluar = !empty($logs['log_ist_keluar']);
            $has_ist_masuk = !empty($logs['log_ist_masuk']);
            $is_total_missing = (!$has_masuk && !$has_keluar);
        } else {
            $has_masuk = false;
            $has_keluar = false;
            $has_ist_keluar = false;
            $has_ist_masuk = false;
            $is_total_missing = true;
        }

        if (!$is_total_missing && !$is_flexible) {
            // Late Entrance (lewat toleransi; flexi murni bebas)
            if ($has_masuk && late_minutes($logs['log_masuk'], $s_masuk) > $tol['masuk']) {
                $p_telat = $gd_masuk;
            }
            // Early Departure (lewat toleransi)
            if ($has_keluar && early_minutes($logs['log_keluar'], $s_keluar) > $tol['pulang']) {
                $p_pulang = $gd_pulang;
            }
            // Early Break (lewat toleransi)
            if ($has_ist_keluar && early_minutes($logs['log_ist_keluar'], $s_ist_keluar) > $tol['ist_keluar']) {
                $p_ist_awal = $gd_istirahat_awal;
            }
            // Late Break Return (lewat toleransi)
            if ($has_ist_masuk && late_minutes($logs['log_ist_masuk'], $s_ist_masuk) > $tol['ist_masuk']) {
                $p_ist_telat = $gd_istirahat_telat;
            }
        }
        // 2026-11-11: Denda tidak lengkap (absen UTAMA tidak lengkap) dihitung
        // DI LUAR blok FLEXIBLE — tetap kena walau baris digantikan/flexible.
        // FLEXIBLE hanya membebaskan denda WAKTU (telat/cepat/istirahat).
        // Kalau masuk ada pulang tidak ada (atau sebaliknya), tetap 25rb.
        // (Denda TIDAK menghitung istirahat — lihat FEBRIANI 05/11.)
        if (!$is_total_missing) {
            $hasIncompleteMain = ($has_masuk xor $has_keluar);
            if ($hasIncompleteMain) {
                $p_tidak_lengkap = $gd_tidak_lengkap;
            }
        }

        // Determine Wage adjustment for total absence
        // PATOKAN UPAH = RENCANA (tb_rkk_detail.upah), bukan lagi master karyawan.
        // Urutan: Digantikan = 0 (jangan dibayar) > upah RKK (>0) > upah master (>0) > r_upah lama
        // (r_upah lama dipakai untuk baris tanpa RKK & tanpa master, misal karyawan manual
        // tambahan — supaya upah yang diinput manual tidak jadi 0).
        // Baris yang sudah diedit manual (status_realisasi_detail = 1) TIDAK ditimpa upahnya
        // oleh sync, kecuali kalau total tidak hadir (aturan tidak hadir tetap menang).
        $upah_rkk = (float)($row['upah_rkk'] ?? 0);
        $status_rkk = $row['status_rkk'] ?? '';
        $upah_master = (float)($row['upah_master'] ?? 0);
        $r_upah_lama = (float)($row['r_upah_lama'] ?? 0);
        // 2026-11-21 UPAH_OTOMATIS: baris yang tidak terhubung ke master/RKK
        // (id_karyawan = 0, mis. KARYAWAN TAMBAHAN manual) dicarikan upah lewat
        // PIN (no_absen_manual) atau nama yang persis sama di ms_karyawan.
        // Peta dibuat sekali per request supaya tidak query per baris.
        if ($upah_rkk <= 0 && $upah_master <= 0) {
            $pin_rd = trim((string)($row['no_absen_manual'] ?? ''));
            $nm_rd = strtoupper(trim((string)($row['nama_karyawan_manual'] ?? '')));
            if ($nm_rd === '') $nm_rd = strtoupper(trim((string)($row['nama_karyawan'] ?? '')));
            if ($pin_rd !== '' && isset($upah_peta['pin'][$pin_rd])) $upah_master = $upah_peta['pin'][$pin_rd];
            if ($upah_master <= 0 && $nm_rd !== '' && isset($upah_peta['nama'][$nm_rd])) $upah_master = $upah_peta['nama'][$nm_rd];
        }
        if ($status_rkk === 'Digantikan') {
            $upah_pakai = 0;
        } elseif ($upah_rkk > 0) {
            $upah_pakai = $upah_rkk;
        } elseif ($upah_master > 0) {
            $upah_pakai = $upah_master;
        } else {
            $upah_pakai = $r_upah_lama;
        }
        $wage_update_sql = "";
        $status_update_sql = "status_realisasi_detail = 2";
        $status_enum = "Hadir";
        $upah_manual_rd = (int)($row['upah_manual'] ?? 0);
        if ($upah_manual_rd === 1) {
            // 2026-11-21 UPAH_OTOMATIS: upah diisi manual (termasuk 0 yang
            // disengaja) -> jangan pernah ditimpa sync.
            $wage_update_sql = "";
            $status_enum = $is_total_missing ? "Tidak Hadir" : "Hadir";
            $status_update_sql = ((int)($status_rd ?? 0) === 1) ? "status_realisasi_detail = 1" : "status_realisasi_detail = 2";
        } elseif ($is_total_missing) {
            $wage_update_sql = "r_upah = 0,";
            $status_enum = "Tidak Hadir";
        } elseif ($id_pengganti > 0) {
            // Baris digantikan dengan pengganti = BAYAR PENGGANTINYA (1 baris saja,
            // tanpa baris KARYAWAN TAMBAHAN). Upah: RKK posisi > master pengganti >
            // r_upah lama (yang diisi Detail). Slip pengganti baca dari baris ini
            // via id_pengganti.
            $upah_pg = 0;
            if ((float)($row['upah_rkk'] ?? 0) > 0) $upah_pg = (float)$row['upah_rkk'];
            elseif ((float)($row['upah_master_pengganti'] ?? 0) > 0) $upah_pg = (float)$row['upah_master_pengganti'];
            elseif ((float)($row['r_upah_lama'] ?? 0) > 0) $upah_pg = (float)$row['r_upah_lama'];
            $upah_pg = (int)round($upah_pg);
            $wage_update_sql = ($upah_pg > 0) ? "r_upah = '$upah_pg'," : "";
            $status_enum = "Hadir";
            $status_update_sql = ((int)($status_rd ?? 0) === 1) ? "status_realisasi_detail = 1" : "status_realisasi_detail = 2";
        } elseif ((int)($status_rd ?? 0) === 1) {
            // Manual tanpa penanda upah_manual: upah yang sudah > 0 dipertahankan
            // (tidak menimpa input lama), TAPI kalau masih 0 diisi otomatis dari
            // RKK/master/nama supaya tidak nyangkut 0 selamanya.
            // 2026-11-21: baris BORONGAN dikecualikan — upahnya dari rincian
            // qty x tarif, default 0 kalau rincian belum diisi (jangan ikut master).
            $wage_update_sql = (!$is_borongan_row && $r_upah_lama <= 0 && $upah_pakai > 0)
                ? "r_upah = '" . (int)round($upah_pakai) . "',"
                : "";
            $status_update_sql = "status_realisasi_detail = 1";
        } else {
            $wage_update_sql = "r_upah = '" . (int)round($upah_pakai) . "',";
        }

        // 2026-11-10: Baris diganti yang penggantinya TIDAK finger hari itu
        // dibiarkan apa adanya (jangan di-nol-kan) — tap-nya mungkin belum
        // ketarik dari mesin. Baris ini hanya diisi kalau pengganti ada tap-nya.
        if ($id_pengganti > 0 && $is_total_missing && empty($grouped_elsewhere)) continue;

        // 2026-11-10: Baris MANUAL (status=1, diisi Detail / Tambah Karyawan)
        // yang TIDAK ada tap-nya di mesin dibiarkan apa adanya (jangan dikosongkan).
        // Ini melindungi input manual di Detail & KARYAWAN TAMBAHAN dari TDR:
        // kalau tap tidak ketemu (PIN kosong / finger belum terdaftar di mesin),
        // jam yang sudah diinput manual jangan dihapus oleh Tarik Data.
        // 2026-11-25 TARIK_SAMA: kalau tap karyawan justru masuk ke grup tanggal
        // lain, baris ini TIDAK dikunci manual — datanya ikut dikosongkan supaya
        // tidak nyangkut jam lama (sesuai halaman Tarik Data: tgl ini tak ada grup).
        if ((int)($status_rd ?? 0) === 1 && $is_total_missing && empty($grouped_elsewhere)) continue;

        // 2026-11-11: Lembur OTOMATIS (tanpa input manual) — sama seperti rumus
        // di Tarik Data: lembur = pulang melewati jam pulang jadwal (datang pagi
        // tidak dihitung), dihitung PER MENIT (tarif/60) dengan ambang 10 menit
        // (<=10 menit = 0; >10 menit = semua menit kehitung). Urutan: (1) override manual di
        // tb_record_lembur (PIN+tgl) kalau ada; (2) lembur_jam manual di Detail
        // (baris status=1) dipertahankan; (3) kalau tidak ada keduanya, pakai
        // hitungan otomatis dari tap. Tarif = global tb_lembur.lembur_per_jam.
        $lembur_update_sql = "";
        $final_lembur_jam = null;
        if (!empty($no_absen)) {
            $pin_lb = $koneksi->real_escape_string(trim((string)$no_absen));
            $tgl_lb = $koneksi->real_escape_string($tgl_real);
            $qlb2 = @$koneksi->query("SELECT lembur_jam FROM tb_record_lembur WHERE userid = '$pin_lb' AND tgl = '$tgl_lb' LIMIT 1");
            if ($qlb2 && ($rlb2 = $qlb2->fetch_assoc())) $final_lembur_jam = (float)($rlb2['lembur_jam'] ?? 0);
        }
        if ($final_lembur_jam === null && (int)($status_rd ?? 0) === 1 && (float)($row['lembur_jam'] ?? 0) > 0) {
            $final_lembur_jam = (float)$row['lembur_jam'];
        }
        if ($final_lembur_jam === null) $final_lembur_jam = (float)($ot_hours_auto ?? 0);
        if ($final_lembur_jam > 0) {
            $lembur_baru = (int)round($final_lembur_jam * 60 * $lembur_per_menit);
            $lembur_update_sql = "lembur_jam = '$final_lembur_jam', lembur = '$lembur_baru',";
        } elseif ($final_lembur_jam === 0.0 && !empty($log_masuk) && !empty($log_keluar) && (int)($status_rd ?? 0) !== 1) {
            // Masuk+pulang ada tapi tidak lembur (pulang tepat waktu/lebih awal)
            // & bukan baris manual: nol-kan supaya tidak nyangkut nilai lama.
            $lembur_update_sql = "lembur_jam = '0', lembur = '0',";
        }

        // 2026-11-18 (GANTI_SINGLE_ROW): Baris digantikan = bayar pengganti.
        // Lembur dibaris ini tetap dipakai (sudah dihitung dari tap pengganti via FLEXIBLE).
        $is_manual_jam = ((int)($status_rd ?? 0) === 1);
        $jm_pg = ($row['s_masuk_pg'] ?? '') !== '' && ($row['s_masuk_pg'] ?? '') !== '00:00:00' ? $row['s_masuk_pg'] : '00:00:00';
        $jk_pg = ($row['s_keluar_pg'] ?? '') !== '' && ($row['s_keluar_pg'] ?? '') !== '00:00:00' ? $row['s_keluar_pg'] : '00:00:00';
        $im_pg = ($row['s_ist_masuk_pg'] ?? '') !== '' && ($row['s_ist_masuk_pg'] ?? '') !== '00:00:00' ? $row['s_ist_masuk_pg'] : '00:00:00';
        $ik_pg = ($row['s_ist_keluar_pg'] ?? '') !== '' && ($row['s_ist_keluar_pg'] ?? '') !== '00:00:00' ? $row['s_ist_keluar_pg'] : '00:00:00';
        if ($is_manual_jam) {
            $jm_simpan = $s_masuk; $jk_simpan = $s_keluar; $im_simpan = $s_ist_masuk; $ik_simpan = $s_ist_keluar;
        } elseif ($id_pengganti > 0) {
            $jm_simpan = $jm_pg; $jk_simpan = $jk_pg; $im_simpan = $im_pg; $ik_simpan = $ik_pg;
        } else {
            $jm_simpan = $s_masuk; $jk_simpan = $s_keluar; $im_simpan = $s_ist_masuk; $ik_simpan = $s_ist_keluar;
        }

        // 5. Update the detail record and SET STATUS TO 2 (Synced)
        // 2026-11-13: snapshot nama+PIN karyawan ASLI ke baris ini (hanya kalau masih
        // kosong) — jadi history tidak bergantung ke master; hapus master tidak
        // menghilangkan nama di Tarik Data & slip. Pakai $row (K = karyawan asli),
        // BUKAN $no_absen (sudah ditimpa PIN pengganti kalau ada).
        $nm_snap = trim((string)($row['nama_karyawan'] ?? ''));
        $pin_snap = trim((string)($row['no_absen'] ?? ''));
        $nm_snap_esc = $koneksi->real_escape_string($nm_snap);
        $pin_snap_esc = $koneksi->real_escape_string($pin_snap);
        $update = $koneksi->query("UPDATE tb_realisasi_detail SET 
            nama_karyawan_manual = CASE WHEN (nama_karyawan_manual IS NULL OR nama_karyawan_manual = '') AND '$nm_snap_esc' <> '' THEN '$nm_snap_esc' ELSE nama_karyawan_manual END,
            no_absen_manual = CASE WHEN (no_absen_manual IS NULL OR no_absen_manual = '') AND '$pin_snap_esc' <> '' THEN '$pin_snap_esc' ELSE no_absen_manual END,
            r_jam_masuk = '$jm_simpan',
            r_jam_keluar = '$jk_simpan',
            r_istirahat_masuk = '$im_simpan',
            r_istirahat_keluar = '$ik_simpan',
            ra_masuk = $ra_masuk,
            ra_keluar = $ra_keluar,
            ra_istirahat_masuk = $ra_ist_masuk,
            ra_istirahat_keluar = $ra_ist_keluar,
            r_potongan_telat = '$p_telat',
            r_potongan_istirahat_awal = '$p_ist_awal',
            r_potongan_istirahat_telat = '$p_ist_telat',
            r_potongan_pulang = '$p_pulang',
            r_potongan_tidak_lengkap = '$p_tidak_lengkap',
            $wage_update_sql
            $lembur_update_sql
            r_status = '$status_enum',
            $status_update_sql
            WHERE id_realisasi_detail = '$id_rd'");

        if ($update) $count++;

    }

    // 2026-11-18 (GANTI_SINGLE_ROW): Bersihkan baris KARYAWAN TAMBAHAN yang
    // duplikat dengan baris pengganti (digaransi oleh sistem "Ganti Karyawan".
    // Tarik Data CLEAN UP otomatis mengikutinya, supaya tidak nyangkut dobel.
    // Dihapus: baris polos dengan id_karyawan = X di grup KARYAWAN TAMBAHAN
    // yang sama (a) y_link-nya (id_realisasi=0, tgl sama dan id_karyawan=X) ATAU
    // (b) di Realisasi ini dan X adalah id_pengganti di baris lain.
    if ($gid_tambahan > 0) {
        $gt_e = (int)$gid_tambahan;
        $tgl_cap_esc = $koneksi->real_escape_string($tgl_cap);
        // (a) Orphan Tambahan (id_realisasi=0) yang id_karyawannya jadi pengganti
        //     di Realisasi ini & tanggal sama — terlanjur dibuat sistem lama.
        $koneksi->query("DELETE FROM tb_realisasi_detail WHERE id_realisasi = 0 AND id_grup = '$gt_e' AND tgl_realisasi_detail = '$tgl_cap_esc' AND id_karyawan > 0 AND id_karyawan IN (SELECT pg_id FROM (SELECT DISTINCT id_pengganti AS pg_id FROM tb_realisasi_detail WHERE id_realisasi = '$id_realisasi' AND id_pengganti IS NOT NULL AND id_pengganti > 0) AS x)");
        // (b) Duplikat di dalam Realisasi ini: baris polos Tambahan yang id_karyawan-nya
        //     sama dengan id_pengganti di baris lain Realisasi ini (tidak dirujuk digantikan dan
        //     merupakan sisa "KARYAWAN TAMBAHAN" yang tidak terpakai setelah sistem "Ganti Karyawan").
        $koneksi->query("DELETE FROM tb_realisasi_detail WHERE id_grup = '$gt_e' AND id_realisasi = '$id_realisasi' AND id_karyawan > 0 AND (id_pengganti IS NULL OR id_pengganti = 0) AND id_karyawan IN (SELECT pg_id FROM (SELECT DISTINCT id_pengganti AS pg_id FROM tb_realisasi_detail WHERE id_realisasi = '$id_realisasi' AND id_pengganti IS NOT NULL AND id_pengganti > 0) AS x)");
    }

    return $count;
}
