<?php
// Halaman Periode - ringkasan upah per OS (non-borongan / borongan).

$__ck_periode = $koneksi->query("SHOW COLUMNS FROM ms_karyawan LIKE 'periode'");
if ($__ck_periode && $__ck_periode->num_rows == 0) {
    $koneksi->query("ALTER TABLE ms_karyawan ADD COLUMN periode VARCHAR(20) NOT NULL DEFAULT 'Harian'");
}

$PERIODE_OPTIONS = ['Harian', '1 Minggu', '2 Minggu', '3 Minggu', '4 Minggu'];
$GOL_OPTIONS = ['semua' => 'Semua', 'non' => 'Non-Borongan', 'borongan' => 'Borongan'];

$q = trim((string)($_GET['q'] ?? ''));

$speriode = trim((string)($_GET['speriode'] ?? 'Harian'));
if (!in_array($speriode, $PERIODE_OPTIONS, true)) $speriode = 'Harian';
$sgol = trim((string)($_GET['sgol'] ?? 'semua'));
if (!isset($GOL_OPTIONS[$sgol])) $sgol = 'semua';
$sos = trim((string)($_GET['sos'] ?? 'semua'));
if ($sos !== 'semua' && !ctype_digit($sos)) $sos = 'semua';
$sdari = trim((string)($_GET['sdari'] ?? ''));
$ssampai = trim((string)($_GET['ssampai'] ?? ''));
if ($sdari === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sdari)) $sdari = date('Y-m-01');
if ($ssampai === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ssampai)) $ssampai = date('Y-m-d');
if ($sdari > $ssampai) { $tmp = $sdari; $sdari = $ssampai; $ssampai = $tmp; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'bulk_periode') {
    $target = (string)($_POST['target'] ?? '');
    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
    if (!in_array($target, $PERIODE_OPTIONS, true)) {
        $_SESSION['periode_flash'] = ['type' => 'error', 'msg' => 'Periode tujuan tidak valid.'];
    } elseif (!count($ids)) {
        $_SESSION['periode_flash'] = ['type' => 'error', 'msg' => 'Belum ada karyawan yang dipilih.'];
    } else {
        $targetE = $koneksi->real_escape_string($target);
        $in = implode(',', $ids);
        $ok = false;
        try { $ok = $koneksi->query("UPDATE ms_karyawan SET periode = '$targetE' WHERE id_karyawan IN ($in)") !== false; } catch (Throwable $e) { $ok = false; }
        if ($ok) {
            $_SESSION['periode_flash'] = ['type' => 'ok', 'msg' => $koneksi->affected_rows . ' karyawan diubah ke periode ' . $target . '.'];
        } else {
            $_SESSION['periode_flash'] = ['type' => 'error', 'msg' => 'Gagal mengubah periode: ' . $koneksi->error];
        }
    }
    $qs = ['page' => 'periode'];
    foreach (['q', 'speriode', 'sos', 'sgol', 'sdari', 'ssampai'] as $k) {
        if (isset($_POST[$k]) && $_POST[$k] !== '') $qs[$k] = (string)$_POST[$k];
    }
    echo "<script>window.location.href='index.php?" . http_build_query($qs) . "';</script>";
    exit;
}
$flash = $_SESSION['periode_flash'] ?? null;
unset($_SESSION['periode_flash']);

$osList = [];
$q_os = $koneksi->query("SELECT id_os_dhk, OS_DHK FROM ms_os_dhk ORDER BY OS_DHK ASC");
if ($q_os) { while ($r = $q_os->fetch_assoc()) $osList[] = $r; }

$where = [];
$where[] = "COALESCE(NULLIF(TRIM(k.periode),''),'Harian') = '" . $koneksi->real_escape_string($speriode) . "'";
if ($sos !== 'semua') $where[] = "k.id_os_dhk = " . (int)$sos;
if ($q !== '') {
    $qe = $koneksi->real_escape_string($q);
    $where[] = "(k.nama_karyawan LIKE '%$qe%' OR k.no_absen LIKE '%$qe%')";
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

$sdE = $koneksi->real_escape_string($sdari);
$seE = $koneksi->real_escape_string($ssampai);
$dateCond = "d.tgl_realisasi_detail BETWEEN '$sdE' AND '$seE'
    AND (rd.id_rkk_detail IS NULL OR rd.status_rkk <> 'Digantikan')
    AND d.id_karyawan <> 0";

$hasBor = false;
$ckBor = $koneksi->query("SHOW TABLES LIKE 'tb_realisasi_borongan'");
if ($ckBor && $ckBor->num_rows) $hasBor = true;
$borJoin = $hasBor
    ? "LEFT JOIN (SELECT id_realisasi_detail, SUM(ROUND(qty * tarif)) AS total_bor FROM tb_realisasi_borongan GROUP BY id_realisasi_detail) bt ON bt.id_realisasi_detail = d.id_realisasi_detail"
    : "LEFT JOIN (SELECT NULL AS id_realisasi_detail, 0 AS total_bor) bt ON 1 = 0";

$agg = [];
try {
    $normExpr = "GREATEST(IFNULL(d.r_upah,0) + IFNULL(d.lembur,0)
        - IF(IFNULL(d.r_upah,0) = 0, 0,
            (IFNULL(d.r_potongan_telat,0) + IFNULL(d.r_potongan_istirahat_awal,0) + IFNULL(d.r_potongan_istirahat_telat,0)
             + IFNULL(d.r_potongan_pulang,0) + IFNULL(d.r_potongan_tidak_lengkap,0) + IFNULL(d.r_potongan_lainnya,0))), 0)";
    $borExpr = "GREATEST(IFNULL(bt.total_bor,0) - IFNULL(d.r_potongan_telat,0) - IFNULL(d.r_potongan_pulang,0), 0)";
    $q_agg = $koneksi->query("SELECT d.id_karyawan,
            SUM(IF(IFNULL(bt.total_bor,0) > 0, 0, $normExpr)) AS non_total,
            COUNT(DISTINCT IF(IFNULL(bt.total_bor,0) > 0, NULL, d.tgl_realisasi_detail)) AS non_hari,
            SUM(IF(IFNULL(bt.total_bor,0) > 0, $borExpr, 0)) AS bor_total,
            COUNT(DISTINCT IF(IFNULL(bt.total_bor,0) > 0, d.tgl_realisasi_detail, NULL)) AS bor_hari,
            COUNT(DISTINCT d.tgl_realisasi_detail) AS all_hari
        FROM tb_realisasi_detail d
        $borJoin
        LEFT JOIN tb_rkk_detail rd ON rd.id_rkk_detail = d.id_rkk_detail
        WHERE $dateCond
        GROUP BY d.id_karyawan");
    if ($q_agg) {
        while ($r = $q_agg->fetch_assoc()) {
            $agg[(int)$r['id_karyawan']] = [
                'non_total' => (int)round((float)$r['non_total']),
                'non_hari' => (int)$r['non_hari'],
                'bor_total' => (int)round((float)$r['bor_total']),
                'bor_hari' => (int)$r['bor_hari'],
                'all_hari' => (int)$r['all_hari'],
            ];
        }
    }
} catch (Throwable $e) { $agg = []; }

$borDetail = [];
if ($hasBor) {
    try {
        $q_bd = $koneksi->query("SELECT d.id_karyawan, b.nama_pekerjaan, b.satuan,
                SUM(b.qty) AS qty, SUM(ROUND(b.qty * b.tarif)) AS upah
            FROM tb_realisasi_detail d
            JOIN tb_realisasi_borongan b ON b.id_realisasi_detail = d.id_realisasi_detail
            LEFT JOIN tb_rkk_detail rd ON rd.id_rkk_detail = d.id_rkk_detail
            WHERE $dateCond
            GROUP BY d.id_karyawan, b.nama_pekerjaan, b.satuan
            ORDER BY d.id_karyawan ASC, b.nama_pekerjaan ASC");
        if ($q_bd) {
            while ($b = $q_bd->fetch_assoc()) {
                $borDetail[(int)$b['id_karyawan']][] = [
                    'nama' => (string)$b['nama_pekerjaan'],
                    'satuan' => (string)$b['satuan'],
                    'qty' => (float)$b['qty'],
                    'upah' => (int)round((float)$b['upah']),
                ];
            }
        }
    } catch (Throwable $e) { $borDetail = []; }
}

$rows = [];
// 2026-11-17 (Reza): karyawan yang dihapus dari master tetap tampil di Periode
// (pakai snapshot nama/no_absen di tb_realisasi_detail). Periode aslinya ikut
// hilang saat dihapus, jadi baris snapshot masuk ke 'Harian' saja (default sistem).
$rows_sql = "SELECT k.id_karyawan, k.no_absen, k.nama_karyawan, k.no_ktp,
        COALESCE(NULLIF(TRIM(k.periode),''),'Harian') AS periode,
        COALESCE(NULLIF(TRIM(o.OS_DHK),''),'Tanpa OS') AS os
    FROM ms_karyawan k
    LEFT JOIN ms_os_dhk o ON o.id_os_dhk = k.id_os_dhk
    $where_sql";
if ($speriode === 'Harian') {
    $__ck_snap = @$koneksi->query("SHOW COLUMNS FROM tb_realisasi_detail LIKE 'nama_karyawan_manual'");
    if ($__ck_snap && $__ck_snap->num_rows > 0) {
        $snap_where = [];
        if ($sos !== 'semua') $snap_where[] = "d.id_os_dhk = " . (int)$sos;
        if ($q !== '') { $qe2 = $koneksi->real_escape_string($q); $snap_where[] = "(d.nama_karyawan_manual LIKE '%$qe2%' OR d.no_absen_manual LIKE '%$qe2%')"; }
        $snap_sql = $snap_where ? ' AND ' . implode(' AND ', $snap_where) : '';
        $rows_sql .= " UNION ALL
        SELECT d.id_karyawan, d.no_absen_manual AS no_absen, d.nama_karyawan_manual AS nama_karyawan, '' AS no_ktp,
            'Harian' AS periode,
            COALESCE(NULLIF(TRIM(o2.OS_DHK),''),'Tanpa OS') AS os
        FROM (SELECT id_karyawan, MAX(no_absen_manual) AS no_absen_manual, MAX(nama_karyawan_manual) AS nama_karyawan_manual, MAX(id_os_dhk) AS id_os_dhk FROM tb_realisasi_detail WHERE nama_karyawan_manual IS NOT NULL AND TRIM(nama_karyawan_manual) <> '' GROUP BY id_karyawan) d
        LEFT JOIN ms_karyawan k2 ON k2.id_karyawan = d.id_karyawan
        LEFT JOIN ms_os_dhk o2 ON o2.id_os_dhk = d.id_os_dhk
        WHERE k2.id_karyawan IS NULL" . $snap_sql;
    }
}
$rows_sql .= " ORDER BY os ASC, nama_karyawan ASC";
$q_rows = $koneksi->query($rows_sql);
if ($q_rows) {
    while ($r = $q_rows->fetch_assoc()) {
        $id = (int)$r['id_karyawan'];
        $a = $agg[$id] ?? ['non_total' => 0, 'non_hari' => 0, 'bor_total' => 0, 'bor_hari' => 0, 'all_hari' => 0];
        if ($sgol === 'borongan') {
            if ($a['bor_hari'] <= 0) continue;
            $r['total'] = $a['bor_total'];
            $r['hari'] = $a['bor_hari'];
        } elseif ($sgol === 'non') {
            if ($a['non_hari'] <= 0) continue;
            $r['total'] = $a['non_total'];
            $r['hari'] = $a['non_hari'];
        } else {
            if ($a['all_hari'] <= 0) continue;
            $r['total'] = $a['non_total'] + $a['bor_total'];
            $r['hari'] = $a['all_hari'];
        }
        $r['bor_detail'] = ($sgol === 'non') ? [] : ($borDetail[$id] ?? []);
        $rows[] = $r;
    }
}

$groups = [];
foreach ($rows as $r) $groups[(string)$r['os']][] = $r;
$grand = 0; $grandHari = 0; $grandCount = 0;
foreach ($groups as $osN => $list) {
    foreach ($list as $lr) { $grand += (int)$lr['total']; $grandHari += (int)$lr['hari']; }
    $grandCount += count($list);
}

$rp = function ($n) { return 'Rp ' . number_format((float)$n, 0, ',', '.'); };
$qtyFmt = function ($q) { $s = number_format((float)$q, 2, ',', '.'); $s = rtrim(rtrim($s, '0'), ','); return $s === '' ? '0' : $s; };
$qsFilter = ['page' => 'periode', 'speriode' => $speriode, 'sdari' => $sdari, 'ssampai' => $ssampai, 'sos' => $sos, 'sgol' => $sgol];
if ($q !== '') $qsFilter['q'] = $q;
$form_hidden = ['page' => 'periode', 'speriode' => $speriode, 'sdari' => $sdari, 'ssampai' => $ssampai, 'sos' => $sos, 'sgol' => $sgol];
$form_hidden['q'] = $q;
?>
<div class="container-fluid px-3 md:px-6 mt-6 mb-10">
  <div class="bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden">
    <div class="bg-gradient-to-r from-indigo-600 to-blue-600 px-6 py-5">
      <h1 class="text-xl font-extrabold text-white m-0"><i class="fas fa-calendar-alt mr-2"></i>Periode Pembayaran</h1>
      <p class="text-indigo-100 text-sm mt-1">Ringkasan upah per OS berdasarkan rentang tanggal dan periode.</p>
    </div>

    <div class="p-5">
      <?php if ($flash): ?>
        <div class="mb-4 rounded-xl border p-3 text-sm font-semibold <?= $flash['type'] === 'ok' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800' ?>">
          <?= htmlspecialchars((string)$flash['msg']) ?>
        </div>
      <?php endif; ?>

      <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 mb-4">
        <form method="GET" id="formFilter" class="flex flex-wrap items-end gap-3">
          <input type="hidden" name="page" value="periode">
          <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
          <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Jenis Periode</label>
            <select name="speriode" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
              <?php foreach ($PERIODE_OPTIONS as $opt): ?>
                <option value="<?= $opt ?>" <?= $speriode === $opt ? 'selected' : '' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tanggal Awal</label>
            <input type="date" name="sdari" value="<?= htmlspecialchars($sdari) ?>" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tanggal Akhir</label>
            <input type="date" name="ssampai" value="<?= htmlspecialchars($ssampai) ?>" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">OS</label>
            <select name="sos" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
              <option value="semua" <?= $sos === 'semua' ? 'selected' : '' ?>>Semua OS</option>
              <?php foreach ($osList as $o): ?>
                <option value="<?= (int)$o['id_os_dhk'] ?>" <?= $sos === (string)$o['id_os_dhk'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['OS_DHK']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Jenis Data</label>
            <select name="sgol" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
              <?php foreach ($GOL_OPTIONS as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $sgol === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="inline-flex items-center bg-slate-800 hover:bg-slate-700 text-white text-sm font-bold px-4 py-2 rounded-lg"><i class="fas fa-filter mr-1"></i> Tampilkan</button>
          <a href="<?= htmlspecialchars('page/periode/export_upah_excel.php?' . http_build_query($qsFilter)) ?>" class="inline-flex items-center bg-white border border-emerald-600 text-emerald-700 hover:bg-emerald-50 text-sm font-bold px-4 py-2 rounded-lg"><i class="fas fa-file-excel mr-1"></i> Download Excel</a>
        </form>
      </div>

      <form method="GET" id="formCari" class="flex flex-wrap items-end gap-3 mb-4">
        <?php foreach ($form_hidden as $hk => $hv): ?>
          <input type="hidden" name="<?= htmlspecialchars($hk) ?>" value="<?= htmlspecialchars((string)$hv) ?>">
        <?php endforeach; ?>
        <div>
          <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Cari Nama / No. Absen</label>
          <input type="text" id="qInput" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Ketik nama atau no. absen..." class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-64">
        </div>
      </form>

      <form method="POST" id="formBulkPeriode">
        <input type="hidden" name="action" value="bulk_periode">
        <?php foreach ($form_hidden as $hk => $hv): ?>
          <input type="hidden" name="<?= htmlspecialchars($hk) ?>" value="<?= htmlspecialchars((string)$hv) ?>">
        <?php endforeach; ?>

        <div class="flex flex-wrap items-center gap-3 mb-3 p-3 rounded-xl bg-slate-50 border border-slate-200">
          <span class="text-sm font-bold text-slate-700">Ubah periode terpilih ke:</span>
          <select name="target" class="border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <?php foreach ($PERIODE_OPTIONS as $opt): ?>
              <option value="<?= $opt ?>" <?= $speriode === $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-5 py-2 rounded-lg"><i class="fas fa-check mr-1"></i> Simpan ke Terpilih</button>
          <span class="text-xs text-slate-500" id="ckInfo">0 dipilih</span>
        </div>

        <div id="upahArea"><!--AREA_START-->
        <?php if (!count($groups)): ?>
          <div class="border border-slate-200 rounded-xl px-4 py-10 text-center text-slate-500">Tidak ada karyawan pada filter ini.</div>
        <?php endif; ?>
        <?php foreach ($groups as $osN => $list): ?>
          <?php $osTotal = 0; foreach ($list as $lr) { $osTotal += (int)$lr['total']; } ?>
        <?php
        $OS_HEAD = [
            'HJS' => ['bg-rose-500', 'border-rose-600'],
            'MHS' => ['bg-sky-500', 'border-sky-600'],
            'WJS' => ['bg-emerald-500', 'border-emerald-600'],
            'CP'  => ['bg-violet-500', 'border-violet-600'],
            'DHK' => ['bg-amber-500', 'border-amber-600'],
        ];
        $osKey = strtoupper(trim((string)$osN));
        $headC = $OS_HEAD[$osKey] ?? ['bg-slate-500', 'border-slate-600'];
        ?>
          <div class="mb-5 border border-slate-200 rounded-xl overflow-hidden os-panel" data-os="<?= htmlspecialchars((string)$osN) ?>">
            <div class="<?= $headC[0] ?> text-white px-4 py-2 font-extrabold flex flex-wrap items-center justify-between gap-2">
              <span><i class="fas fa-building mr-1"></i> OS: <?= htmlspecialchars((string)$osN) ?></span>
              <span class="flex items-center gap-2">
                <span class="text-xs font-bold text-white/90"><?= count($list) ?> karyawan</span>
                <a href="?page=periode&amp;aksi=detailos&amp;sos=<?= urlencode((string)$osN) ?>&amp;speriode=<?= urlencode($speriode) ?>&amp;sdari=<?= urlencode($sdari) ?>&amp;ssampai=<?= urlencode($ssampai) ?>&amp;sgol=<?= urlencode($sgol) ?><?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>" class="inline-flex items-center bg-white/20 hover:bg-white/30 text-white text-[11px] font-bold px-3 py-1.5 rounded-lg"><i class="fas fa-eye mr-1"></i> Detail</a>
                <button type="button" class="os-toggle inline-flex items-center bg-white/20 hover:bg-white/30 text-white text-[11px] font-bold px-3 py-1.5 rounded-lg" title="Sembunyikan/Tampilkan tabel" aria-expanded="true"><i class="fas fa-chevron-down os-toggle-icon transition-transform duration-200"></i> <span class="os-toggle-text">Sembunyikan</span></button>
              </span>
            </div>
            <div class="os-collapse-body overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                  <tr>
                    <th class="px-3 py-2 w-10"><input type="checkbox" class="ckAllOs" title="Pilih semua di tabel ini"></th>
                    <th class="text-left px-3 py-2">Nama</th>
                    <?php if ($sgol !== 'non'): ?><th class="text-left px-3 py-2">Detail Kerja (Borongan)</th><?php endif; ?>
                    <th class="text-right px-3 py-2">Total Upah</th>
                    <th class="text-left px-3 py-2">OS</th>
                    <th class="text-left px-3 py-2">Periode</th>
                    <th class="text-center px-3 py-2">Total Hari Kerja</th>
                    <th class="text-right px-3 py-2">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <?php foreach ($list as $r): ?>
                  <tr class="hover:bg-slate-50">
                    <td class="px-3 py-2 text-center"><input type="checkbox" name="ids[]" value="<?= (int)$r['id_karyawan'] ?>" class="ckRow"></td>
                    <td class="px-3 py-2">
                      <strong class="text-slate-800"><?= htmlspecialchars((string)$r['nama_karyawan']) ?></strong>
                      <div class="text-[11px] text-gray-500 leading-tight">KTP: <?= htmlspecialchars(trim((string)$r['no_ktp']) !== '' ? (string)$r['no_ktp'] : '-') ?></div>
                    </td>
                    <?php if ($sgol !== 'non'): ?>
                    <td class="px-3 py-2 text-slate-600">
                      <?php if (!empty($r['bor_detail'])): ?>
                        <ul class="m-0 p-0 list-none text-[11px] leading-snug">
                          <?php foreach ($r['bor_detail'] as $bd): ?>
                            <li><?= htmlspecialchars($bd['nama'] !== '' ? $bd['nama'] : '-') ?>: <b><?= $qtyFmt($bd['qty']) . htmlspecialchars($bd['satuan'] !== '' ? $bd['satuan'] : '') ?></b></li>
                          <?php endforeach; ?>
                        </ul>
                      <?php else: ?>
                        <span class="text-slate-300">-</span>
                      <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td class="px-3 py-2 text-right font-semibold <?= !empty($r['total']) ? 'text-emerald-700' : 'text-slate-400' ?>"><?= $rp($r['total']) ?></td>
                    <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars((string)$r['os']) ?></td>
                    <td class="px-3 py-2"><span class="text-[11px] font-bold px-2 py-1 rounded border <?= $r['periode'] === 'Harian' ? 'bg-slate-100 text-slate-700 border-slate-200' : ($r['periode'] === '1 Minggu' ? 'bg-sky-100 text-sky-700 border-sky-200' : ($r['periode'] === '2 Minggu' ? 'bg-amber-100 text-amber-700 border-amber-200' : ($r['periode'] === '3 Minggu' ? 'bg-orange-100 text-orange-700 border-orange-200' : ($r['periode'] === '4 Minggu' ? 'bg-purple-100 text-purple-700 border-purple-200' : 'bg-emerald-100 text-emerald-700 border-emerald-200')))) ?>"><?= htmlspecialchars((string)$r['periode']) ?></span></td>
                    <td class="px-3 py-2 text-center font-semibold text-slate-700"><?= (int)$r['hari'] ?> hari</td>
                    <td class="px-3 py-2 text-right">
                      <a href="?page=realisasi&amp;aksi=slip&amp;id=<?= (int)$r['id_karyawan'] ?>&amp;ttgl1=<?= urlencode($sdari) ?>&amp;ttgl2=<?= urlencode($ssampai) ?>&amp;cari=Search" class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white text-[11px] font-bold px-3 py-1.5 rounded-lg"><i class="fas fa-file-invoice mr-1"></i> Detail</a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr class="bg-slate-100 font-bold">
                    <td class="px-3 py-2" colspan="2">Jumlah <?= htmlspecialchars((string)$osN) ?> (<?= count($list) ?> karyawan)</td>
                    <?php if ($sgol !== 'non'): ?><td class="px-3 py-2"></td><?php endif; ?>
                    <td class="px-3 py-2 text-right"><?= $rp($osTotal) ?></td>
                    <td class="px-3 py-2" colspan="3"></td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (count($groups)): ?>
          <div class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 flex flex-wrap items-center justify-between gap-2 font-extrabold text-emerald-900">
            <span>TOTAL SEMUA OS (<?= $grandCount ?> karyawan)</span>
            <span><?= $rp($grand) ?></span>
          </div>
        <?php endif; ?>
        <!--AREA_END--></div>
      </form>

      <p class="mt-4 text-xs text-slate-500">Kolom <b>Total Upah</b> dihitung dari tanggal <b><?= htmlspecialchars($sdari) ?></b> s/d <b><?= htmlspecialchars($ssampai) ?></b>. Data Borongan memakai hasil kerja (qty &times; tarif) per baris realisasi, denda telat/pulang tetap dihitung.</p>
    </div>
  </div>
</div>

<script>
$(function () {
  var fF = document.getElementById('formFilter');
  var PERIODE_DAYS = {'Harian':0,'1 Minggu':6,'2 Minggu':13,'3 Minggu':20,'4 Minggu':27};
  if (fF) {
    var selP = fF.querySelector('select[name="speriode"]');
    var inStart = fF.querySelector('input[name="sdari"]');
    var inEnd = fF.querySelector('input[name="ssampai"]');
    var syncAkhir = function () {
      var off = PERIODE_DAYS[(selP && selP.value) || ''] || 0;
      var sv = (inStart && inStart.value) || '';
      if (!sv) return;
      var d = new Date(sv + 'T00:00:00');
      if (isNaN(d.getTime())) return;
      d.setDate(d.getDate() + off);
      var y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0'), da = String(d.getDate()).padStart(2, '0');
      if (inEnd) inEnd.value = y + '-' + m + '-' + da;
    };
    if (selP) selP.addEventListener('change', syncAkhir);
    if (inStart) inStart.addEventListener('change', syncAkhir);
    // Semua input selain jenis periode: auto-submit agar tidak perlu klik Tampilkan
    fF.querySelectorAll('select, input[type="date"]').forEach(function (el) {
      if (el.name === 'speriode') return;
      el.addEventListener('change', function () { fF.submit(); });
    });
  }

  var applyOsPanel = function (panel, collapsed) {
    var body = panel.querySelector('.os-collapse-body');
    var icon = panel.querySelector('.os-toggle-icon');
    var txt = panel.querySelector('.os-toggle-text');
    var btn = panel.querySelector('.os-toggle');
    if (body) body.style.display = collapsed ? 'none' : '';
    if (icon) icon.style.transform = collapsed ? 'rotate(-90deg)' : '';
    if (txt) txt.textContent = collapsed ? 'Tampilkan' : 'Sembunyikan';
    if (btn) btn.setAttribute('aria-expanded', String(!collapsed));
  };
  var applyAllOsPanels = function () {
    document.querySelectorAll('.os-panel').forEach(function (panel) {
      var key = 'os-collapse-' + (panel.getAttribute('data-os') || '');
      if (localStorage.getItem(key) === '1') applyOsPanel(panel, true); else applyOsPanel(panel, false);
    });
  };
  var ckAlls = function () { return Array.prototype.slice.call(document.querySelectorAll('.ckAllOs')); };
  applyAllOsPanels();
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.os-toggle');
    if (!btn) return;
    var panel = btn.closest('.os-panel');
    if (!panel) return;
    var os = panel.getAttribute('data-os') || '';
    var key = 'os-collapse-' + os;
    var collapsed = localStorage.getItem(key) === '1';
    collapsed = !collapsed;
    localStorage.setItem(key, collapsed ? '1' : '0');
    applyOsPanel(panel, collapsed);
  });
  var ckInfo = document.getElementById('ckInfo');
  function currentRows() { return Array.prototype.slice.call(document.querySelectorAll('#upahArea .ckRow')); }
  function checkedCount() { return currentRows().filter(function (c) { return c.checked; }).length; }
  function refresh() {
    var n = checkedCount();
    if (ckInfo) ckInfo.textContent = n + ' dipilih';
    document.querySelectorAll('#upahArea table').forEach(function (tbl) {
      var rows = Array.prototype.slice.call(tbl.querySelectorAll('.ckRow'));
      var all = tbl.querySelector('.ckAllOs');
      if (all) all.checked = rows.length > 0 && rows.every(function (c) { return c.checked; });
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target && e.target.classList.contains('ckAllOs')) {
      var tbl = e.target.closest('table');
      if (tbl) tbl.querySelectorAll('.ckRow').forEach(function (c) { c.checked = e.target.checked; });
      refresh();
    } else if (e.target && e.target.classList.contains('ckRow')) {
      refresh();
    }
  });

  var form = document.getElementById('formBulkPeriode');
  if (form) form.addEventListener('submit', function (e) {
    var n = checkedCount();
    if (!n) { e.preventDefault(); alert('Pilih minimal satu karyawan.'); return; }
    var target = form.querySelector('select[name="target"]').value;
    if (!confirm('Ubah ' + n + ' karyawan terpilih ke periode ' + target + '?')) e.preventDefault();
  });

  var qInput = document.getElementById('qInput');
  var formCari = document.getElementById('formCari');
  if (qInput && formCari) {
    var tmr = null, lastVal = qInput.value, req = null;
    function doSearch() {
      var val = qInput.value;
      if (val === lastVal) return;
      lastVal = val;
      var params = new URLSearchParams(new FormData(formCari));
      if (req) req.abort();
      req = ('AbortController' in window) ? new AbortController() : null;
      fetch('page/periode/ajax_list.php?' + params.toString(), req ? { signal: req.signal } : {})
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var area = document.getElementById('upahArea');
          if (area) area.innerHTML = d.area;
          if (typeof applyAllOsPanels === 'function') applyAllOsPanels();
          refresh();
          var u = new URL(window.location.href);
          if (val) u.searchParams.set('q', val); else u.searchParams.delete('q');
          document.querySelectorAll('input[type="hidden"][name="q"]').forEach(function (h) { h.value = val; });
          area.querySelectorAll('a[href*="aksi=detailos"]').forEach(function (a) {
            try {
              var u2 = new URL(a.getAttribute('href'), window.location.href);
              if (val) u2.searchParams.set('q', val); else u2.searchParams.delete('q');
              a.setAttribute('href', u2.pathname + u2.search);
            } catch (e) {}
          });
          history.replaceState(null, '', u.toString());
        })
        .catch(function () {});
    }
    qInput.addEventListener('input', function () {
      clearTimeout(tmr);
      tmr = setTimeout(doSearch, 250);
    });
    qInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); clearTimeout(tmr); lastVal = null; doSearch(); }
    });
  }
  refresh();
});
</script>
