-- Migration: เพิ่มตาราง postponement_requests (คำขอเลื่อนวันนัด)

USE law_system;

DROP PROCEDURE IF EXISTS create_postponement_table;
DELIMITER //
CREATE PROCEDURE create_postponement_table()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = 'law_system'
          AND TABLE_NAME   = 'postponement_requests'
    ) THEN
        CREATE TABLE `postponement_requests` (
          `postpone_id`      int          NOT NULL AUTO_INCREMENT,
          `request_type`     varchar(20)  NOT NULL COMMENT 'filing / hearing',
          `reference_id`     int          NOT NULL COMMENT 'filing_id หรือ hearing_id',
          `client_id`        int          NOT NULL,
          `reason`           text         COMMENT 'เหตุผลที่ขอเลื่อน',
          `requested_date`   date         DEFAULT NULL COMMENT 'วันที่ต้องการเลื่อนไป (ถ้าระบุ)',
          `status`           varchar(20)  NOT NULL DEFAULT 'pending'
                             COMMENT 'pending, approved, rejected',
          `lawyer_note`      text         DEFAULT NULL COMMENT 'หมายเหตุจากทนาย',
          `created_at`       timestamp    DEFAULT CURRENT_TIMESTAMP,
          `updated_at`       timestamp    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`postpone_id`),
          KEY `idx_type_ref`   (`request_type`, `reference_id`),
          KEY `idx_client_id`  (`client_id`),
          KEY `idx_status`     (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COMMENT='คำขอเลื่อนวันนัดยื่นฟ้อง / ขึ้นศาล จากลูกความ';
    END IF;
END //
DELIMITER ;
CALL create_postponement_table();
DROP PROCEDURE IF EXISTS create_postponement_table;

SELECT 'postponement_requests table ready' AS info;
SHOW COLUMNS FROM postponement_requests;
