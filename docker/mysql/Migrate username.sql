-- Migration: เพิ่ม username ใน users table
-- รันใน phpMyAdmin → law_system → SQL tab ทีละ statement

USE law_system;

-- เพิ่ม column username
ALTER TABLE `users` ADD COLUMN `username` VARCHAR(50) DEFAULT NULL AFTER `user_id`;

-- เพิ่ม unique index
ALTER TABLE `users` ADD UNIQUE KEY `uq_username` (`username`);

-- ตั้งค่า username เริ่มต้นจาก email (ส่วนก่อน @) + user_id กัน duplicate
UPDATE `users` SET `username` = CONCAT(SUBSTRING_INDEX(email,'@',1), user_id)
WHERE username IS NULL;