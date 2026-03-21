-- Migration: ย้าย case_number จาก filings → court_hearings
-- MySQL 8.0 compatible version

USE law_system;

-- ============================================================
-- Step 1: เพิ่ม case_number ใน court_hearings (ถ้ายังไม่มี)
-- ============================================================
DROP PROCEDURE IF EXISTS add_case_number_col;
DELIMITER //
CREATE PROCEDURE add_case_number_col()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'law_system'
          AND TABLE_NAME   = 'court_hearings'
          AND COLUMN_NAME  = 'case_number'
    ) THEN
        ALTER TABLE `court_hearings`
          ADD COLUMN `case_number` varchar(50) DEFAULT NULL
          COMMENT 'เลขคดีที่ได้รับหลังยื่นฟ้อง';
    END IF;
END //
DELIMITER ;
CALL add_case_number_col();
DROP PROCEDURE IF EXISTS add_case_number_col;

-- ============================================================
-- Step 2: ย้ายข้อมูล case_number จาก filings → court_hearings
-- ============================================================
UPDATE court_hearings ch
JOIN (
    SELECT filing_id, MIN(hearing_id) AS first_hearing_id
    FROM court_hearings
    GROUP BY filing_id
) first ON ch.hearing_id = first.first_hearing_id
JOIN filings f ON ch.filing_id = f.filing_id
SET ch.case_number = f.case_number
WHERE f.case_number IS NOT NULL;

-- ============================================================
-- Step 3: เปลี่ยน filing_date → scheduled_filing_date
-- ============================================================
DROP PROCEDURE IF EXISTS rename_filing_date;
DELIMITER //
CREATE PROCEDURE rename_filing_date()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'law_system'
          AND TABLE_NAME   = 'filings'
          AND COLUMN_NAME  = 'filing_date'
    ) THEN
        ALTER TABLE `filings`
          CHANGE COLUMN `filing_date` `scheduled_filing_date` date DEFAULT NULL
          COMMENT 'วันนัดยื่นฟ้อง';
    END IF;
END //
DELIMITER ;
CALL rename_filing_date();
DROP PROCEDURE IF EXISTS rename_filing_date;

-- ============================================================
-- Step 4: ลบ unique key uq_case_number (ถ้ามี)
-- ============================================================
DROP PROCEDURE IF EXISTS drop_case_number_key;
DELIMITER //
CREATE PROCEDURE drop_case_number_key()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = 'law_system'
          AND TABLE_NAME   = 'filings'
          AND INDEX_NAME   = 'uq_case_number'
    ) THEN
        ALTER TABLE `filings` DROP KEY `uq_case_number`;
    END IF;
END //
DELIMITER ;
CALL drop_case_number_key();
DROP PROCEDURE IF EXISTS drop_case_number_key;

-- ============================================================
-- Step 5: ลบ column case_number ออกจาก filings (ถ้ายังมี)
-- ============================================================
DROP PROCEDURE IF EXISTS drop_case_number_col;
DELIMITER //
CREATE PROCEDURE drop_case_number_col()
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'law_system'
          AND TABLE_NAME   = 'filings'
          AND COLUMN_NAME  = 'case_number'
    ) THEN
        ALTER TABLE `filings` DROP COLUMN `case_number`;
    END IF;
END //
DELIMITER ;
CALL drop_case_number_col();
DROP PROCEDURE IF EXISTS drop_case_number_col;

-- ============================================================
-- ตรวจสอบผลลัพธ์
-- ============================================================
SELECT 'filings columns:' AS info;
SHOW COLUMNS FROM filings;

SELECT 'court_hearings case_number:' AS info;
SHOW COLUMNS FROM court_hearings LIKE 'case_number';