-- Migration: เพิ่ม contract_review_status สำหรับให้ทนายยืนยัน/ปฏิเสธสัญญา
-- รันใน phpMyAdmin → law_system → SQL tab

USE law_system;

-- เพิ่ม column สถานะการพิจารณาสัญญาโดยทนาย
ALTER TABLE `contracts`
    ADD COLUMN `contract_review_status` varchar(30) NOT NULL DEFAULT 'pending_lawyer_review'
        COMMENT 'pending_lawyer_review, lawyer_accepted, lawyer_rejected, revision_requested, finalized'
        AFTER `status`;

-- อัปเดตสัญญาที่มีอยู่แล้วให้เป็น lawyer_accepted (ที่สร้างก่อนมี feature นี้)
UPDATE `contracts` SET `contract_review_status` = 'lawyer_accepted'
WHERE `contract_review_status` = 'pending_lawyer_review';