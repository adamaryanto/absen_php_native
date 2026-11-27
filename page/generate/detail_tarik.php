<?php
// 2026-11-25: Halaman khusus EDIT TAP Tarik Data (bukan Detail Realisasi).
// Diakses dari tombol "Detail" di halaman Tarik Data (generate).
$role_edit_tarik = strtolower($_SESSION['role'] ?? '');
if (!in_array($role_edit_tarik, ['admin master', 'kepala pabrik'], true)) {
    echo "<script>alert('Akses ditolak: halaman ini hanya untuk Admin Master dan Kepala Pabrik.'); window.location.href='?page=generate&aksi=tarik';</script>"; exit;
}

$pin_edit = trim((string)($_GET['pin'] ?? $_POST['pin'] ?? ''));
$tgl_edit = (string)($_GET['tgl'] ?? $_POST['tgl'] ?? '');
if ($pin_edit === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_edit)) {
    echo "<script>alert('Data absen tidak valid.'); window.location.href='?page=generate&aksi=tarik';</script>"; exit;
}
$pin_e = $koneksi->real_escape_string($pin_edit);
$tgl_e = $koneksi->real_escape_string($tgl_edit);

$nm_emp = '-';
$qemp = $koneksi->query("SELECT nama_karyawan FROM ms_karyawan WHERE TRIM(no_absen) = '$pin_e' LIMIT 1");
if ($qemp && ($remp = $qemp->fetch_assoc())) $nm_emp = $remp['nama_karyawan'];

$simpan_msg = '';
if (isset($_POST['simpan_tap']) && is_array($_POST['tap'] ?? null)) {
    foreach ($_POST['tap'] as $id_rec => $v) {
        $id_rec = (int)$id_rec;
        $jam = trim((string)($v['time'] ?? ''));
        $stt = (int)($v['status'] ?? 0);
        if ($id_rec <= 0 || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $jam)) continue;
        if (!in_array($stt, [0, 1, 2, 3], true)) $stt = 0;
        $qr = $koneksi->query("SELECT tgl, detail_waktu, source_device_id FROM tb_record WHERE id_record = $id_rec AND TRIM(userid) = '$pin_e' LIMIT 1");
        if (!$qr || !($rr = $qr->fetch_assoc())) continue;
        $jam = (strlen($jam) === 5) ? ($jam . ':00') : $jam;
        $dt_lama_e = $koneksi->real_escape_string($rr['detail_waktu']);
        $dt_e = $koneksi->real_escape_string($rr['tgl'] . ' ' . $jam);
        $did_e = (int)$rr['source_device_id'];
        $koneksi->query("UPDATE tb_record SET detail_waktu = '$dt_e', status = '$stt' WHERE id_record = $id_rec AND TRIM(userid) = '$pin_e'");
        // 2026-11-25: edit = REPLACE, bukan menambah baris. Hapus tap kembar
        // (jam lama maupun jam baru) di mesin yang sama untuk PIN ini.
        $koneksi->query("DELETE FROM tb_record WHERE TRIM(userid) = '$pin_e' AND source_device_id = '$did_e' AND id_record <> $id_rec AND detail_waktu IN ('$dt_lama_e','$dt_e')");
    }
    $simpan_msg = 'Tap berhasil disimpan. Jalankan Tarik Data / sinkron Realisasi agar perubahan ikut terhitung.';
}

$qtap = $koneksi->query("SELECT id_record, tgl, detail_waktu, status, verifikasi, source_device_id FROM tb_record WHERE TRIM(userid) = '$pin_e' AND tgl = '$tgl_e' ORDER BY detail_waktu ASC, id_record ASC");
$taps = [];
$seen_tap = [];
while ($qtap && ($rt = $qtap->fetch_assoc())) {
    $k_seen = $rt['detail_waktu'] . '|' . $rt['source_device_id'];
    if (isset($seen_tap[$k_seen])) continue;
    $seen_tap[$k_seen] = true;
    $taps[] = $rt;
}
$nama_mesin_map = [];
$qmes = $koneksi->query("SELECT id_mesin, no_mesin, nama_mesin FROM tb_mesin");
while ($qmes && ($rmes = $qmes->fetch_assoc())) {
    $nama_mesin_map[(int)$rmes['id_mesin']] = $rmes['nama_mesin'];
    $nama_mesin_map[(int)$rmes['no_mesin']] = $rmes['nama_mesin'];
}
$back_url = '?page=generate&aksi=tarik&tanggal_awal=' . urlencode($tgl_edit) . '&tanggal_akhir=' . urlencode($tgl_edit);
?>
<div class="content-header">
  <div class="container-fluid">
    <div class="mb-3">
      <h1 class="m-0 font-weight-bold">Edit Absen — Tarik Data</h1>
      <p class="text-muted mb-0">
        <b><?= htmlspecialchars($nm_emp) ?></b> · PIN <b><?= htmlspecialchars($pin_edit) ?></b> · Grup tanggal <b><?= htmlspecialchars($tgl_edit) ?></b>
      </p>
    </div>
  </div>
</div>

<section class="content">
  <div class="container-fluid">
    <?php if ($simpan_msg !== ''): ?>
      <div class="alert alert-success"><?= htmlspecialchars($simpan_msg) ?></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title font-weight-bold">Tap mesin (sumber grouping Tarik Data)</h3>
        <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-sm btn-default float-right">
          <i class="fas fa-arrow-left mr-1"></i> Kembali ke Tarik Data
        </a>
      </div>
      <div class="card-body">
        <?php if (empty($taps)): ?>
          <div class="alert alert-warning mb-0">Tidak ada tap untuk PIN ini pada tanggal <?= htmlspecialchars($tgl_edit) ?>.</div>
        <?php else: ?>
        <form method="POST" action="">
          <input type="hidden" name="pin" value="<?= htmlspecialchars($pin_edit) ?>">
          <input type="hidden" name="tgl" value="<?= htmlspecialchars($tgl_edit) ?>">
          <input type="hidden" name="simpan_tap" value="1">
          <table class="table table-bordered table-sm">
            <thead>
              <tr>
                <th>ID Record</th>
                <th>Nama Karyawan</th>
                <th>Tanggal</th>
                <th>Jam</th>
                <th>Mesin</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($taps as $t): ?>
              <tr>
                <td class="align-middle"><?= (int)$t['id_record'] ?></td>
                <td class="align-middle font-weight-bold"><?= htmlspecialchars($nm_emp) ?></td>
                <td class="align-middle"><?= htmlspecialchars($t['tgl']) ?></td>
                <td>
                  <input type="time" step="1" name="tap[<?= (int)$t['id_record'] ?>][time]" value="<?= htmlspecialchars(substr($t['detail_waktu'], 11, 8)) ?>" class="form-control form-control-sm" style="width:130px;">
                  <input type="hidden" name="tap[<?= (int)$t['id_record'] ?>][status]" value="<?= (int)$t['status'] ?>">
                </td>
                <td class="align-middle"><?php
                  $did_tap = (int)($t['source_device_id'] ?? 0);
                  $nm_mesin = $nama_mesin_map[$did_tap] ?? '-';
                  echo htmlspecialchars($nm_mesin . ' (' . ($did_tap ?: '-') . ')');
                ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save mr-1"></i> Simpan Tap
          </button>
          <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-default">Batal</a>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
