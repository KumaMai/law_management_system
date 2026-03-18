-- Migration: เพิ่ม updated_at และรองรับสถานะ "ฝ่ายไม่มาศาล"
-- รันใน phpMyAdmin → law_system → SQL tab

USE law_system;

-- เพิ่ม updated_at ให้ court_hearings (ถ้ายังไม่มี)
ALTER TABLE `court_hearings`
    ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT NULL
        COMMENT 'เวลาอัปเดตล่าสุด'
        AFTER `reminder_sent`;

-- หมายเหตุ: status ที่เพิ่มใหม่ใช้ varchar อยู่แล้ว ไม่ต้อง ALTER
-- สถานะใหม่ที่ระบบรองรับ:
--   defendant_absent = จำเลยไม่มาศาล
--   plaintiff_absent = โจทก์ไม่มาศาล