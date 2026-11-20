-- Migrasi kolom cadangan (snapshot) untuk server production db_hr_update.
-- Aman dijalankan berulang (MariaDB 10.4 mendukung IF NOT EXISTS).
-- Jalankan lewat phpMyAdmin: pilih database db_hr_update > Import > file ini.
-- Hanya menambah kolom; TIDAK menyentuh/menghapus data dan sistem hapus.

ALTER TABLE tb_realisasi_detail ADD COLUMN IF NOT EXISTS nama_karyawan_manual VARCHAR(100) NULL;
ALTER TABLE tb_realisasi_detail ADD COLUMN IF NOT EXISTS no_absen_manual      VARCHAR(32)  NULL;
ALTER TABLE tb_rkk_detail       ADD COLUMN IF NOT EXISTS nama_karyawan_manual VARCHAR(100) NULL;
ALTER TABLE tb_rkk_detail       ADD COLUMN IF NOT EXISTS no_absen_manual      VARCHAR(32)  NULL;
ALTER TABLE tb_sync_log         ADD COLUMN IF NOT EXISTS nama_karyawan        VARCHAR(100) NULL;

-- OPSIONAL: isi tarif lembur global kalau masih 0 (sesuaikan angkanya).
-- UPDATE tb_lembur SET lembur_per_jam = 5000 WHERE id_lembur = 1 AND (lembur_per_jam IS NULL OR lembur_per_jam = 0);
