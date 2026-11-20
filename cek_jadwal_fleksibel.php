<?php
// Cek jadwal Fleksibel di tb_jadwal — taruh di root payroll, buka di browser,
// hapus setelah dipakai. READ-ONLY, tidak mengubah apa pun.
require_once 'koneksi.php';
$q = $koneksi->query("SELECT id_jadwal, keterangan, jam_masuk, jam_keluar FROM tb_jadwal WHERE LOWER(keterangan) LIKE '%fleksibel%' OR LOWER(keterangan) LIKE '%flexible%' ORDER BY id_jadwal");
echo "<h2>Jadwal Fleksibel di tb_jadwal</h2>";
if (!$q || $q->num_rows == 0) {
    echo "<p style='color:red'><b>TIDAK ADA jadwal Fleksibel sama sekali di database ini.</b> Jalankan tambah_jadwal_fleksibel.sql dulu.</p>";
} else {
    echo "<table border='1' cellpadding='6'><tr><th>id</th><th>keterangan</th><th>jam_masuk</th><th>jam_keluar</th></tr>";
    while ($r = $q->fetch_assoc()) {
        echo "<tr><td>{$r['id_jadwal']}</td><td>" . htmlspecialchars($r['keterangan']) . "</td><td>{$r['jam_masuk']}</td><td>{$r['jam_keluar']}</td></tr>";
    }
    echo "</table>";
    echo "<p>Harusnya ada 3 baris: <b>Flexible 7 Jam</b>, <b>Flexible 12 Jam</b>, <b>Fleksibel Bebas</b>. Kalau ada yang kurang, jadwal itu belum ada di DB — import tidak bisa memilihnya.</p>";
}
echo "<p>Hapus file ini setelah dipakai.</p>";
