<?php
// Halaman "Input Detail Pekerjaan Borongan" — khusus role Admin Boneless & Borongan.
// Daftar detail realisasi per tanggal (semua OS) + tombol Input per baris:
// buka modal isi rincian pekerjaan (nama pekerjaan, qty, tarif).
$gate_ok = isset($_SESSION['role']) && in_array(strtolower((string)$_SESSION['role']), ['admin boneless & borongan', 'admin master', 'owner'], true);
if (!$gate_ok) {
    echo '<div class="container-fluid px-6 mt-6"><div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-xl p-4 text-sm font-bold">Akses ditolak.</div></div>';
    return;
}

$q = trim((string)($_GET['q'] ?? ''));
$ftgl = trim((string)($_GET['tgl'] ?? ''));
if ($ftgl !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ftgl)) $ftgl = '';

$tgl_list = [];
$qt = $koneksi->query("SELECT DISTINCT tgl_realisasi FROM tb_realisasi WHERE tgl_realisasi IS NOT NULL ORDER BY tgl_realisasi DESC LIMIT 60");
if ($qt) { while ($rt = $qt->fetch_assoc()) $tgl_list[] = (string)$rt['tgl_realisasi']; }
if ($ftgl === '') $ftgl = $tgl_list[0] ?? date('Y-m-d');
$ftglE = $koneksi->real_escape_string($ftgl);

// Total borongan per id_realisasi_detail (untuk kolom Hasil & Rp)
$bor_map = [];
$qb = $koneksi->query("SELECT b.id_realisasi_detail, b.nama_pekerjaan, b.qty, b.tarif, b.satuan
    FROM tb_realisasi_borongan b
    JOIN tb_realisasi_detail d ON d.id_realisasi_detail = b.id_realisasi_detail
    JOIN tb_realisasi r ON r.id_realisasi = d.id_realisasi
    WHERE r.tgl_realisasi = '$ftglE'
    ORDER BY b.id_realisasi_detail ASC, b.id_borongan ASC");
if ($qb) { while ($b = $qb->fetch_assoc()) $bor_map[(int)$b['id_realisasi_detail']][] = $b; }

$where = "WHERE C.tgl_realisasi = '$ftglE'
    AND A.id_karyawan IN (SELECT id_karyawan FROM ms_grup_borongan_anggota WHERE is_borongan = 1)";
if ($q !== '') {
    $qe = $koneksi->real_escape_string($q);
    $where .= " AND (A.nama_karyawan_manual LIKE '%$qe%' OR B.nama_karyawan LIKE '%$qe%' OR B.no_absen LIKE '%$qe%')";
}
$detail = $koneksi->query("SELECT A.id_realisasi_detail, A.id_karyawan, A.id_grup,
        IF(A.id_karyawan = 0, '-', B.no_absen) AS no_absen_tampil,
        IF(A.id_karyawan = 0, A.nama_karyawan_manual, COALESCE(B.nama_karyawan, A.nama_karyawan_manual)) AS nama_tampil,
        COALESCE(O.OS_DHK, O_M.OS_DHK, '-') AS label_os,
        COALESCE(G.golongan, G_M.golongan, '-') AS label_gol
    FROM tb_realisasi_detail A
    LEFT JOIN ms_karyawan B ON A.id_karyawan = B.id_karyawan
    LEFT JOIN tb_realisasi C ON A.id_realisasi = C.id_realisasi
    LEFT JOIN ms_os_dhk O ON B.id_os_dhk = O.id_os_dhk
    LEFT JOIN ms_os_dhk O_M ON O_M.id_os_dhk = A.id_os_dhk
    LEFT JOIN ms_golongan G ON B.id_golongan = G.id_golongan
    LEFT JOIN ms_golongan G_M ON G_M.id_golongan = A.id_golongan
    $where
    ORDER BY label_os ASC, nama_tampil ASC");
$rows = [];
$totalBor = 0;
if ($detail) {
    while ($d = $detail->fetch_assoc()) {
        $lines = $bor_map[(int)$d['id_realisasi_detail']] ?? [];
        $part = [];
        $sum_unit = [];
        $tot = 0;
        foreach ($lines as $ln) {
            $satuan = strtolower(trim((string)($ln['satuan'] ?? 'kg')));
            if ($satuan !== 'ekor') $satuan = 'kg';
            $qq = (float)$ln['qty'];
            $tt = (int)$ln['tarif'];
            $tot += (int)round($qq * $tt);
            if (!isset($sum_unit[$satuan])) $sum_unit[$satuan] = 0;
            $sum_unit[$satuan] += $qq;
            $part[] = strtoupper((string)$ln['nama_pekerjaan']) . ' : '
                . rtrim(rtrim(number_format($qq, 2, '.', ''), '0'), '.') . ' ' . $satuan
                . ' x ' . number_format($tt, 0, ',', '.') . ' = Rp' . number_format((int)round($qq * $tt), 0, ',', '.');
        }
        $hasil = [];
        foreach ($sum_unit as $u => $v) $hasil[] = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . ' ' . $u;
        $d['bor_lines'] = count($lines);
        $d['bor_detail'] = $part ? implode(', ', $part) : '-';
        $d['bor_hasil'] = $hasil ? implode(', ', $hasil) : '-';
        $d['bor_total'] = $tot;
        $totalBor += $tot;
        $rows[] = $d;
    }
}
$rp = function ($n) { return 'Rp ' . number_format((float)$n, 0, ',', '.'); };
$n = 1;
$ftgl_disp = $ftgl !== '' ? date('d/m/Y', strtotime($ftgl)) : '-';
?>
<div class="container-fluid px-3 md:px-6 mt-6 mb-10">
  <div class="bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden">
    <div class="px-6 py-5" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);">
      <h1 class="text-xl font-extrabold text-white m-0"><i class="fas fa-boxes mr-2"></i>Input Detail Pekerjaan Borongan</h1>
      <p class="text-blue-100 text-sm mt-1">Daftar detail realisasi tanggal <b><?= htmlspecialchars($ftgl_disp) ?></b> (semua OS). Klik <b>Input</b> untuk mengisi rincian pekerjaan.</p>
    </div>

    <div class="p-4 md:p-5">
      <form method="GET" id="formFilterBor" class="flex flex-wrap items-end gap-3 mb-4">
        <input type="hidden" name="page" value="detail_pekerjaan_borongan">
        <div>
          <label class="block text-xs font-bold text-slate-500 uppercase mb-1"><i class="fas fa-calendar-alt mr-1"></i> Tanggal</label>
          <select name="tgl" id="selTgl" class="border border-slate-300 rounded-lg px-3 py-2 text-sm font-semibold">
            <?php foreach ($tgl_list as $tgl_opt): ?>
              <option value="<?= htmlspecialchars($tgl_opt) ?>" <?= $tgl_opt === $ftgl ? 'selected' : '' ?>><?= date('d/m/Y', strtotime($tgl_opt)) ?></option>
            <?php endforeach; ?>
            <?php if (empty($tgl_list)): ?><option value="" disabled>Belum ada realisasi</option><?php endif; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Cari Nama / No. Absen</label>
          <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Ketik nama..." class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-64" autocomplete="off">
        </div>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold px-5 py-2 rounded-lg"><i class="fas fa-sync-alt mr-1"></i> Tampilkan</button>
      </form>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[1000px] text-sm border-collapse">
          <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
              <th class="py-2 px-3 text-[12px] font-bold text-slate-600 uppercase align-middle text-center w-10">No</th>
              <th class="py-2 px-3 text-[12px] font-bold text-slate-600 uppercase align-middle">Nama</th>
              <th class="py-2 px-3 text-[12px] font-bold text-slate-600 uppercase align-middle text-center">OS</th>
              <th class="py-2 px-3 text-[12px] font-bold text-slate-600 uppercase align-middle text-center">Golongan</th>
              <th class="py-2 px-3 text-[12px] font-bold text-slate-600 uppercase align-middle">Detail Borongan</th>
              <th class="py-2 px-3 text-[12px] font-bold text-slate-600 uppercase align-middle">Hasil Kerja</th>
              <th class="py-2 px-3 text-[12px] font-bold text-slate-600 uppercase align-middle text-right">Total Borongan</th>
              <th class="py-2 px-3 text-[12px] font-bold text-slate-600 uppercase align-middle text-center w-36">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if (!count($rows)): ?>
              <tr><td colspan="8" class="py-12 text-center text-slate-400 italic">Tidak ada detail realisasi pada tanggal ini.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $d): ?>
            <tr class="hover:bg-slate-50">
              <td class="py-2 px-3 text-center text-slate-600"><?= $n++ ?></td>
              <td class="py-2 px-3 font-bold text-slate-800"><?= htmlspecialchars((string)$d['nama_tampil']) ?></td>
              <td class="py-2 px-3 text-center text-slate-700"><?= htmlspecialchars((string)$d['label_os']) ?></td>
              <td class="py-2 px-3 text-center text-slate-600"><?= htmlspecialchars((string)$d['label_gol']) ?></td>
              <td data-label="Detail Borongan" class="py-2 px-3 text-slate-700 max-w-[380px]"><?= ($d['bor_detail'] !== '-' ? htmlspecialchars($d['bor_detail']) : '<span class="text-slate-300">-</span>') ?></td>
              <td data-label="Hasil Kerja" class="py-2 px-3 text-slate-700"><?= ($d['bor_hasil'] !== '-' ? htmlspecialchars($d['bor_hasil']) : '<span class="text-slate-300">-</span>') ?></td>
              <td data-label="Total Borongan" class="py-2 px-3 text-right font-bold whitespace-nowrap <?= $d['bor_total'] > 0 ? 'text-emerald-700' : 'text-slate-300' ?>"><?= $d['bor_total'] > 0 ? $rp($d['bor_total']) : '-' ?></td>
              <td class="py-2 px-3 text-center">
                <button type="button" class="btn-borongan inline-flex items-center gap-1.5 bg-violet-600 hover:bg-violet-700 text-white text-[13px] font-bold px-4 py-2 rounded-lg transition" data-id="<?= (int)$d['id_realisasi_detail'] ?>">
                  <i class="fas fa-list"></i> Input
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <?php if (count($rows)): ?>
          <tfoot>
            <tr class="bg-slate-100 font-bold">
              <td class="px-3 py-2" colspan="6">TOTAL BORONGAN (<?= count($rows) ?> detail)</td>
              <td class="px-3 py-2 text-right"><?= $rp($totalBor) ?></td>
              <td></td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalBorongan" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header" style="background:#8b5cf6; color:#fff;">
        <h5 class="modal-title font-bold" id="borJudul">Detail Pekerjaan Borongan</h5>
        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="borIdDetail" value="">
        <div style="overflow-x:auto;">
          <div id="borRows"></div>
        </div>
        <button type="button" id="borModalAdd" class="btn btn-sm btn-success mt-2">+ Tambah Pekerjaan</button>
        <div class="mt-2 text-right">Total borongan: <b id="borModalGrand">Rp 0</b></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
        <button type="button" id="borModalSave" class="btn btn-primary" style="background:#8b5cf6; border:none;">Simpan</button>
      </div>
    </div>
  </div>
</div>

<script>
$(function () {
    let borDefaultTarif = 0, borGrupOpt = '', borNamesOpt = '', borCurId = null;

    $('#selTgl').on('change', function () { $('#formFilterBor').submit(); });

    function borEsc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function borSel2(sel) {
        if ($.fn.select2) $(sel).select2({ theme: 'bootstrap4', tags: true, width: '100%', dropdownParent: $('#modalBorongan'), placeholder: '-- Pilih / ketik baru --' });
    }
    function borModalCalc() {
        let grand = 0;
        $('#borRows .bor-row').each(function() {
            const q = parseFloat($(this).find('.bor-qty').val()) || 0;
            const t = parseFloat($(this).find('.bor-tarif').val()) || 0;
            const line = Math.round(q * t);
            grand += line;
            $(this).find('.bor-total').val(line ? 'Rp ' + line.toLocaleString('id-ID') : '');
        });
        $('#borModalGrand').text('Rp ' + grand.toLocaleString('id-ID'));
    }
    function borAllOpt() {
        return '<option value="">-- Pilih / ketik baru --</option>' + borGrupOpt + borNamesOpt;
    }
    function borRowHtml(nama, qty, tarif, satuan) {
        const sat = (String(satuan || 'kg').toLowerCase() === 'ekor') ? 'ekor' : 'kg';
        return '<div class="bor-row" style="display:flex;align-items:center;gap:8px;flex-wrap:nowrap;margin-bottom:8px;">'
            + '<select class="form-control bor-nama" style="min-width:170px;flex:1 1 auto;">' + borAllOpt() + '</select>'
            + '<span style="white-space:nowrap;">:</span>'
            + '<input type="number" step="any" class="form-control text-center bor-qty" style="width:90px;flex:0 0 auto;" value="' + borEsc(qty) + '">'
            + '<select class="form-control bor-satuan" style="width:85px;flex:0 0 auto;">'
            + '<option value="kg"' + (sat === 'kg' ? ' selected' : '') + '>kg</option>'
            + '<option value="ekor"' + (sat === 'ekor' ? ' selected' : '') + '>ekor</option>'
            + '</select>'
            + '<span style="white-space:nowrap;">x</span>'
            + '<input type="number" class="form-control text-right bor-tarif" style="width:130px;flex:0 0 auto;" value="' + borEsc(tarif) + '">'
            + '<span>=</span>'
            + '<input type="text" class="form-control text-right bor-total" style="width:150px;flex:0 0 auto;" readonly>'
            + '<button type="button" class="btn btn-sm btn-danger bor-del" style="flex:0 0 auto;">X</button>'
            + '</div>';
    }

    $(document).on('click', '.btn-borongan', function() {
        borCurId = $(this).data('id');
        $.getJSON('page/realisasi/borongan_ajax.php', { id_detail: borCurId }, function(res) {
            if (!res.ok) {
                Swal.fire({ icon: 'error', title: 'Gagal', text: res.msg || 'Tidak bisa memuat data', confirmButtonColor: '#8b5cf6' });
                return;
            }
            $('#borJudul').text('Detail Pekerjaan - ' + res.nama + (res.grup ? ' (' + res.grup + ')' : ''));
            borDefaultTarif = res.default_tarif || 0;
            const seenBor = {};
            borGrupOpt = '';
            (res.grups || []).forEach(function(g) {
                const nm = g.nama || '';
                if (!nm || seenBor[nm]) return;
                seenBor[nm] = true;
                const rt = (g.harga !== null && g.harga !== '') ? g.harga : '';
                borGrupOpt += '<option value="' + borEsc(nm) + '" data-rate="' + borEsc(rt) + '">' + borEsc(nm) + (rt !== '' ? ' (Rp' + Number(rt).toLocaleString('id-ID') + '/kg)' : '') + '</option>';
            });
            borNamesOpt = '';
            (res.names || []).forEach(function(n) {
                if (!n || seenBor[n]) return;
                seenBor[n] = true;
                borNamesOpt += '<option>' + borEsc(n) + '</option>';
            });
            const tb = $('#borRows');
            tb.empty();
            (res.lines || []).forEach(function(l) {
                const tr = $(borRowHtml(l.nama_pekerjaan, l.qty, l.tarif, l.satuan));
                tr.find('.bor-nama').val(l.nama_pekerjaan);
                tb.append(tr);
            });
            tb.find('.bor-nama').each(function() { borSel2(this); });
            borModalCalc();
            $('#modalBorongan').modal('show');
        }).fail(function() {
            Swal.fire({ icon: 'error', title: 'Gagal', text: 'Tidak bisa menghubungi server', confirmButtonColor: '#8b5cf6' });
        });
    });

    $('#borModalAdd').on('click', function() {
        const tr = $(borRowHtml('', '', borDefaultTarif, 'kg'));
        $('#borRows').append(tr);
        borSel2(tr.find('.bor-nama'));
        borModalCalc();
    });

    $(document).on('change', '.bor-nama', function() {
        const r = $(this).find(':selected').data('rate');
        if (r !== undefined && r !== null && String(r) !== '') $(this).closest('.bor-row').find('.bor-tarif').val(r);
    });

    $(document).on('click', '.bor-del', function() {
        $(this).closest('.bor-row').remove();
        borModalCalc();
    });
    $(document).on('input change', '.bor-qty, .bor-tarif, .bor-nama, .bor-satuan', borModalCalc);

    $('#borModalSave').on('click', function() {
        const lines = [];
        $('#borRows .bor-row').each(function() {
            const nm = $(this).find('.bor-nama').val();
            if (!nm || !String(nm).trim()) return;
            lines.push({ nama: String(nm).trim(), qty: $(this).find('.bor-qty').val() || 0, tarif: $(this).find('.bor-tarif').val() || 0, satuan: $(this).find('.bor-satuan').val() || 'kg' });
        });
        $.post('page/realisasi/borongan_ajax.php', { id_detail: borCurId, lines: lines }, function(res) {
            if (res && res.ok) {
                Swal.fire({ icon: 'success', title: 'Tersimpan', text: res.n + ' baris rincian', confirmButtonColor: '#8b5cf6' }).then(function() {
                    location.reload();
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Gagal', text: (res && res.msg) || 'Gagal menyimpan', confirmButtonColor: '#8b5cf6' });
            }
        }, 'json').fail(function() {
            Swal.fire({ icon: 'error', title: 'Gagal', text: 'Tidak bisa menghubungi server', confirmButtonColor: '#8b5cf6' });
        });
    });
});
</script>
