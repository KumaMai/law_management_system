-- Migration: เพิ่ม QR Code สำหรับทนาย + ปรับตาราง payments
-- รันใน phpMyAdmin → law_system → SQL tab

USE law_system;

-- เพิ่ม column qr_code ให้ lawyer_profiles
ALTER TABLE `lawyer_profiles`
    ADD COLUMN IF NOT EXISTS `qr_code_file` VARCHAR(500) DEFAULT NULL
        COMMENT 'ชื่อไฟล์ QR Code ของทนาย';

-- อัปเดต payments: เพิ่มคอลัมน์ที่จำเป็น (ถ้า table มีอยู่แล้ว)
ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `installment_note` VARCHAR(255) DEFAULT NULL
        COMMENT 'หมายเหตุงวด เช่น งวดที่ 1, มัดจำ';

-- สร้าง payments ใหม่ถ้ายังไม่มี
CREATE TABLE IF NOT EXISTS `payments` (
    `payment_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `contract_id`      INT NOT NULL,
    `amount`           DECIMAL(12,2) NOT NULL,
    `payment_method`   ENUM('cash','transfer','cheque','other') DEFAULT 'transfer',
    `payment_date`     DATE NOT NULL,
    `slip_file`        VARCHAR(500) DEFAULT NULL,
    `note`             TEXT DEFAULT NULL,
    `installment_note` VARCHAR(255) DEFAULT NULL,
    `status`           ENUM('pending','confirmed','rejected') DEFAULT 'pending',
    `paid_by`          INT DEFAULT NULL,
    `confirmed_by`     INT DEFAULT NULL,
    `confirmed_at`     DATETIME DEFAULT NULL,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_contract_id` (`contract_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;