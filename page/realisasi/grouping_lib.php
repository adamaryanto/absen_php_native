<?php
// 2026-11-25 TARIK_SAMA: fungsi grouping tap yang SAMA dengan halaman Tarik Data.
// Dipakai sync realisasi supaya tanggal & pasangan masuk/pulang identik dengan
// tampilan halaman Tarik Data (merge shift malam maks 16 jam, label = tanggal
// tap pertama).
if (!function_exists('group_taps_by_pin_date')) {
    function group_taps_by_pin_date($koneksi, $tgl_awal, $tgl_akhir, $os_pin_sub = '') {
        $grouped = [];
        $qa = $koneksi->real_escape_string((string)$tgl_awal);
        $qb = $koneksi->real_escape_string((string)$tgl_akhir);
        $db_records = $koneksi->query("SELECT * FROM tb_record WHERE tgl BETWEEN '$qa' AND '$qb'" . $os_pin_sub . " ORDER BY tgl ASC, id_record ASC");
        if (!$db_records) return $grouped;

        while ($row_rec = $db_records->fetch_assoc()) {
            $PIN = trim((string)$row_rec['userid']);
            $DateTime = $row_rec['detail_waktu'];
            $Status = $row_rec['status'];
            $rec_tgl = $row_rec['tgl'];

            $time = substr($DateTime, 11, 5);
            $is_merged = false;
            $prev_date = date('Y-m-d', strtotime('-1 day', strtotime($rec_tgl)));
            $prev_key = $PIN . '_' . $prev_date;
            if (isset($grouped[$prev_key]) && !empty($grouped[$prev_key]['masuk'])) {
                $prev_masuk = $grouped[$prev_key]['masuk'];
                $prev_pulang = $grouped[$prev_key]['pulang'] ?? '';

                $in_timestamp = strtotime($prev_date . ' ' . $prev_masuk);
                $curr_timestamp = strtotime($rec_tgl . ' ' . $time);
                $diff_hours = ($curr_timestamp - $in_timestamp) / 3600;

                if ($diff_hours > 0 && $diff_hours <= 16) {
                    $is_valid_merge = false;
                    if (empty($prev_pulang)) {
                        $is_valid_merge = true;
                    } else {
                        $prev_pulang_diff = (strtotime($prev_date . ' ' . $prev_pulang) - $in_timestamp) / 3600;
                        if ($prev_pulang_diff <= 16) $is_valid_merge = true;
                    }

                    if ($is_valid_merge) {
                        $prev_pulang_ts = !empty($prev_pulang) ? strtotime($prev_date . ' ' . $prev_pulang) : 0;
                        if (empty($prev_pulang) || $curr_timestamp > $prev_pulang_ts) {
                            $grouped[$prev_key]['pulang'] = $time;
                        }
                        $grouped[$prev_key]['raw_times'][] = ['time' => $time, 'status' => $Status];
                        $is_merged = true;
                    }
                }
            }

            if (!$is_merged) {
                $key = $PIN . '_' . $rec_tgl;
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'pin' => $PIN,
                        'date' => $rec_tgl,
                        'masuk' => '',
                        'istirahat_keluar' => '',
                        'istirahat_masuk' => '',
                        'pulang' => '',
                        'raw_times' => []
                    ];
                }

                $grouped[$key]['raw_times'][] = ['time' => $time, 'status' => $Status];

                if ($Status == 0) {
                    if ($grouped[$key]['masuk'] == '' || $time < $grouped[$key]['masuk']) $grouped[$key]['masuk'] = $time;
                } elseif ($Status == 2) {
                    if ($grouped[$key]['istirahat_keluar'] == '' || $time < $grouped[$key]['istirahat_keluar']) $grouped[$key]['istirahat_keluar'] = $time;
                } elseif ($Status == 3) {
                    if ($grouped[$key]['istirahat_masuk'] == '' || $time < $grouped[$key]['istirahat_masuk']) $grouped[$key]['istirahat_masuk'] = $time;
                } elseif ($Status == 1) {
                    if ($grouped[$key]['pulang'] == '' || $time > $grouped[$key]['pulang']) $grouped[$key]['pulang'] = $time;
                }
            }
        }

        // Fallback jika tombol status tidak ditekan di mesin
        foreach ($grouped as $key => &$row) {
            if (empty($row['masuk']) && empty($row['pulang']) && !empty($row['raw_times'])) {
                usort($row['raw_times'], function($a, $b) {
                    return strcmp($a['time'], $b['time']);
                });
                $row['masuk'] = $row['raw_times'][0]['time'];
                if (count($row['raw_times']) > 1) {
                    $row['pulang'] = $row['raw_times'][count($row['raw_times']) - 1]['time'];
                }
            }
        }
        unset($row);

        // Fix: kalau masuk == pulang (1 tap kehitung 2x), kosongkan pulang
        foreach ($grouped as $key => &$row) {
            if (!empty($row['masuk']) && $row['masuk'] == $row['pulang']) {
                $row['pulang'] = '';
            }
        }
        unset($row);

        // FIX shift malam: merge lintas hari yang terpisah
        $keys = array_keys($grouped);
        sort($keys);
        foreach ($keys as $k) {
            if (!isset($grouped[$k])) continue;
            if (empty($grouped[$k]['masuk']) || !empty($grouped[$k]['pulang'])) continue;
            list($pin_k, $date_k) = explode('_', $k, 2);
            $next_date = date('Y-m-d', strtotime($date_k . ' +1 day'));
            $next_key = $pin_k . '_' . $next_date;
            if (!isset($grouped[$next_key])) continue;
            $next = $grouped[$next_key];
            if (!empty($next['masuk']) || empty($next['pulang'])) {
                if (empty($next['pulang']) && !empty($next['masuk']) && count($next['raw_times']) == 1) {
                    $tNext = $next['masuk'];
                    if (strtotime("1970-01-01 $tNext") < strtotime("1970-01-01 12:00:00")) {
                        $next['pulang'] = $next['masuk']; $next['masuk'] = '';
                    } else continue;
                } else continue;
            }
            $in_ts = strtotime($date_k . ' ' . $grouped[$k]['masuk']);
            $out_ts = strtotime($next_date . ' ' . $next['pulang']);
            $diff = ($out_ts - $in_ts) / 3600;
            if ($diff > 0 && $diff <= 16) {
                $grouped[$k]['pulang'] = $next['pulang'];
                $grouped[$k]['raw_times'] = array_merge($grouped[$k]['raw_times'], $next['raw_times']);
                unset($grouped[$next_key]);
            }
        }

        // Fix: tap tunggal berstatus pulang = anggap MASUK
        foreach ($grouped as $key => &$row) {
            if (empty($row['masuk']) && !empty($row['pulang']) && count($row['raw_times'] ?? []) <= 1) {
                $row['masuk'] = $row['pulang'];
                $row['pulang'] = '';
            }
        }
        unset($row);

        // Fix: mesin kirim semua status IN -> pulang tidak terisi
        foreach ($grouped as $key => &$row) {
            if (!empty($row['masuk']) && empty($row['pulang']) && count($row['raw_times'] ?? []) > 1) {
                $times = array_column($row['raw_times'], 'time');
                rsort($times);
                if ($times[0] !== $row['masuk']) $row['pulang'] = $times[0];
            }
        }
        unset($row);

        return $grouped;
    }
}
