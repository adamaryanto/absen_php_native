-- Restore karyawan PUTRA YAKINASIH (id 723, No. Absen 1137)
-- untuk database production `db_hr_update`.
-- Aman dijalankan berulang: kolom dicek dulu, master hanya ditambah jika belum ada.
-- Tidak menghapus data apa pun.

-- 1) Pastikan kolom cadangan (snapshot) ada (portable MariaDB/MySQL).
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE tb_realisasi_detail ADD COLUMN nama_karyawan_manual VARCHAR(100) NULL','DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_realisasi_detail' AND COLUMN_NAME='nama_karyawan_manual');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE tb_realisasi_detail ADD COLUMN no_absen_manual VARCHAR(32) NULL','DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_realisasi_detail' AND COLUMN_NAME='no_absen_manual');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE tb_rkk_detail ADD COLUMN nama_karyawan_manual VARCHAR(100) NULL','DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_rkk_detail' AND COLUMN_NAME='nama_karyawan_manual');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE tb_rkk_detail ADD COLUMN no_absen_manual VARCHAR(32) NULL','DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_rkk_detail' AND COLUMN_NAME='no_absen_manual');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := (SELECT IF(COUNT(*)=0,'ALTER TABLE tb_sync_log ADD COLUMN nama_karyawan VARCHAR(100) NULL','DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tb_sync_log' AND COLUMN_NAME='nama_karyawan');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) Kembalikan master karyawan id 723 (hanya jika belum ada).
INSERT INTO ms_karyawan
(id_karyawan, id_departmen, id_jabatan, no_absen, nama_karyawan, tempat_lahir, tgl_lahir, agama, status_kawin, jenis_kelamin, no_ktp, alamat_ktp, alamat_tinggal, status_karyawan, tgl_aktif, tgl_nonaktif, no_bpjs, no_telp, whatsapp, id_jadwal, id_sub_department, id_os_dhk, id_golongan, upah, target_mingguan, target_bulanan, lembur_per_jam, insentif_bulanan)
SELECT 723,46,0,1137,'PUTRA YAKINASIH','',NULL,'',NULL,'Laki-laki','','','','Aktif','2026-10-14',NULL,'','','',4,46,11,3,115000,20.00,40.00,NULL,0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM ms_karyawan WHERE id_karyawan = 723);

-- 3) Isi nama + no. absen cadangan pada history 723 (realisasi & RKK).
UPDATE tb_realisasi_detail
   SET nama_karyawan_manual = 'PUTRA YAKINASIH',
       no_absen_manual      = '1137'
 WHERE id_karyawan = 723
   AND (nama_karyawan_manual IS NULL OR nama_karyawan_manual = '');

UPDATE tb_rkk_detail
   SET nama_karyawan_manual = 'PUTRA YAKINASIH',
       no_absen_manual      = '1137'
 WHERE id_karyawan = 723
   AND (nama_karyawan_manual IS NULL OR nama_karyawan_manual = '');

UPDATE tb_sync_log
   SET nama_karyawan = 'PUTRA YAKINASIH'
 WHERE karyawan_id = 723
   AND (nama_karyawan IS NULL OR nama_karyawan = '');

-- 4) OPSIONAL: sembunyikan duplikat hasil auto-sync (id 1097, No. Absen 1459,
--    tanpa history) supaya tidak dobel di daftar. Lebih aman lewat menu
--    Karyawan -> Hapus. Atau buka komentar di bawah.
-- UPDATE ms_karyawan SET status_karyawan = 'Non Aktif' WHERE id_karyawan = 1097;

-- 5) Cek hasil.
SELECT id_karyawan, no_absen, nama_karyawan, status_karyawan FROM ms_karyawan WHERE id_karyawan IN (723,1097);
SELECT id_karyawan, COUNT(*) baris, MIN(nama_karyawan_manual) nm, MIN(no_absen_manual) pin
  FROM tb_realisasi_detail WHERE id_karyawan = 723 GROUP BY id_karyawan;
SELECT id_karyawan, COUNT(*) baris, MIN(nama_karyawan_manual) nm, MIN(no_absen_manual) pin
  FROM tb_rkk_detail WHERE id_karyawan = 723 GROUP BY id_karyawan;
