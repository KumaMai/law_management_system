-- Migration: Dashboard ใหม่ — โปรไฟล์ทนาย, ประกาศ, รีวิว
-- รันใน phpMyAdmin → law_system → SQL tab
-- ใช้ได้กับ MySQL 5.7 และ 8.0

USE law_system;

-- ===================================================
-- เพิ่ม columns ให้ lawyer_profiles (ทีละ column)
-- ถ้า error ว่า column มีอยู่แล้ว ข้ามได้เลย
-- ===================================================

ALTER TABLE `lawyer_profiles` ADD COLUMN `profile_photo` VARCHAR(500) DEFAULT NULL;
ALTER TABLE `lawyer_profiles` ADD COLUMN `bio` TEXT DEFAULT NULL;
ALTER TABLE `lawyer_profiles` ADD COLUMN `experience_yr` INT DEFAULT NULL;
ALTER TABLE `lawyer_profiles` ADD COLUMN `education` VARCHAR(500) DEFAULT NULL;

-- หมายเหตุ: license_no มีอยู่แล้วในตาราง ไม่ต้องเพิ่ม

-- ===================================================
-- สร้างตารางประกาศ
-- ===================================================

CREATE TABLE IF NOT EXISTS `announcements` (
    `ann_id`     INT NOT NULL AUTO_INCREMENT,
    `office_id`  INT NOT NULL,
    `title`      VARCHAR(300) NOT NULL,
    `body`       TEXT DEFAULT NULL,
    `pin`        TINYINT(1) DEFAULT 0,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ann_id`),
    KEY `idx_office` (`office_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===================================================
-- สร้างตารางรีวิว/ดาว
-- ===================================================

CREATE TABLE IF NOT EXISTS `lawyer_reviews` (
    `review_id`  INT NOT NULL AUTO_INCREMENT,
    `lawyer_id`  INT NOT NULL,
    `client_id`  INT NOT NULL,
    `rating`     TINYINT NOT NULL DEFAULT 5,
    `comment`    TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`review_id`),
    UNIQUE KEY `uq_lawyer_client` (`lawyer_id`, `client_id`),
    KEY `idx_lawyer` (`lawyer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- เพิ่ม profile_photo ให้ client_profiles
ALTER TABLE `client_profiles` ADD COLUMN `profile_photo` VARCHAR(500) DEFAULT NULL;