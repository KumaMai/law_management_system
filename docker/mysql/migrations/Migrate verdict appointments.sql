-- Migration: เพิ่มตาราง verdict_appointments (นัดวันพิพากษา)
-- รันใน phpMyAdmin หรือ: docker exec -i law_db mysql -u root -proot law_system < migrate_verdict_appointments.sql

USE law_system;

CREATE TABLE IF NOT EXISTS `verdict_appointments` (
  `appointment_id`   int          NOT NULL AUTO_INCREMENT,
  `filing_id`        int          NOT NULL,
  `scheduled_date`   date         NOT NULL COMMENT 'วันนัดฟังคำพิพากษา',
  `scheduled_time`   time         DEFAULT NULL COMMENT 'เวลานัด',
  `note`             text         COMMENT 'หมายเหตุ/รายละเอียดเพิ่มเติม',
  `status`           varchar(20)  NOT NULL DEFAULT 'scheduled'
                     COMMENT 'scheduled, completed, postponed, cancelled',
  `created_by`       int          DEFAULT NULL COMMENT 'user_id ที่สร้าง',
  `created_at`       timestamp    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       timestamp    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`appointment_id`),
  KEY `idx_filing_id`    (`filing_id`),
  KEY `idx_scheduled_date` (`scheduled_date`),
  CONSTRAINT `va_ibfk_1` FOREIGN KEY (`filing_id`) REFERENCES `filings` (`filing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ตารางนัดวันฟังคำพิพากษา';