-- Chạy trong phpMyAdmin trên hosting (PHP 5.6 / MySQL cũ)
-- Nếu trang thu_chi.php bị 500, chạy file này trước

ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','hdt_manager','thuquy') DEFAULT 'admin';

CREATE TABLE IF NOT EXISTS thuchi_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ten VARCHAR(100) NOT NULL,
  thu_dung TINYINT(1) NOT NULL DEFAULT 1,
  chi_dung TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_ten (ten)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS thuchi_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loai ENUM('thu','chi') NOT NULL,
  noi_dung VARCHAR(500) NOT NULL,
  so_tien DECIMAL(15,0) NOT NULL,
  ngay DATE NOT NULL,
  ghi_chu TEXT NULL,
  phan_loai VARCHAR(100) NOT NULL,
  created_by INT NULL,
  created_at DATETIME DEFAULT NULL,
  updated_at DATETIME DEFAULT NULL,
  INDEX idx_ngay (ngay),
  INDEX idx_loai (loai),
  INDEX idx_phan_loai (phan_loai)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT IGNORE INTO thuchi_categories (ten, sort_order, created_at) VALUES
('Khóa học', 1, NOW()),
('Công viện chung', 2, NOW()),
('Chiến dịch', 3, NOW()),
('Làm bác ái', 4, NOW()),
('Sinh hoạt đoàn', 5, NOW()),
('Trại / sự kiện', 6, NOW()),
('Đóng góp / ủng hộ', 7, NOW()),
('Văn phòng phẩm', 8, NOW()),
('Khác', 9, NOW());

INSERT INTO users (username, password, role)
SELECT 'thuquy', 'thuquy124', 'thuquy'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'thuquy' LIMIT 1);

UPDATE users SET password = 'thuquy124', role = 'thuquy' WHERE username = 'thuquy';
