-- ============================================================
-- Law Case Management System — init.sql v4.0
-- ฐานข้อมูลสำหรับ Setup ครั้งแรก (รวม migrations ทั้งหมดไว้แล้ว)
-- ไม่จำเป็นต้องรัน migration files แยกอีกต่อไป
-- ============================================================

CREATE DATABASE IF NOT EXISTS law_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE law_system;

-- ============================================================
-- TABLES
-- ============================================================

CREATE TABLE `offices` (
  `office_id`   int          NOT NULL AUTO_INCREMENT,
  `office_name` varchar(150) NOT NULL,
  `office_code` varchar(20)  DEFAULT NULL,
  `address`     text,
  `phone`       varchar(15)  DEFAULT NULL,
  `email`       varchar(150) DEFAULT NULL,
  `logo_path`   varchar(255) DEFAULT NULL,
  `status`      varchar(20)  DEFAULT 'active',
  `created_at`  timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`office_id`),
  UNIQUE KEY `uq_office_code` (`office_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `roles` (
  `role_id`    int         NOT NULL AUTO_INCREMENT,
  `role_name`  varchar(50) NOT NULL,
  `created_at` timestamp   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `uq_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `users` (
  `user_id`       int          NOT NULL AUTO_INCREMENT,
  `username`      varchar(50)  DEFAULT NULL COMMENT 'สำหรับ login แทน email',
  `office_id`     int          NOT NULL,
  `role_id`       int          NOT NULL,
  `email`         varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status`        varchar(20)  DEFAULT 'active',
  `created_at`    timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_email`    (`email`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `client_profiles` (
  `client_id`     int          NOT NULL AUTO_INCREMENT,
  `user_id`       int          NOT NULL,
  `fname`         varchar(100) NOT NULL,
  `lname`         varchar(100) NOT NULL,
  `citizen_id`    varchar(13)  DEFAULT NULL,
  `phone`         varchar(15)  DEFAULT NULL,
  `address`       text,
  `profile_photo` varchar(500) DEFAULT NULL,
  `created_at`    timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`),
  UNIQUE KEY `uq_user_id`    (`user_id`),
  UNIQUE KEY `uq_citizen_id` (`citizen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `lawyer_profiles` (
  `lawyer_id`      int          NOT NULL AUTO_INCREMENT,
  `user_id`        int          NOT NULL,
  `fname`          varchar(100) NOT NULL,
  `lname`          varchar(100) NOT NULL,
  `license_no`     varchar(50)  DEFAULT NULL,
  `license_exp`    date         DEFAULT NULL,
  `specialization` varchar(150) DEFAULT NULL,
  `phone`          varchar(15)  DEFAULT NULL,
  `status`         varchar(20)  DEFAULT 'active',
  `qr_code_file`   varchar(500) DEFAULT NULL COMMENT 'ชื่อไฟล์ QR Code สำหรับรับชำระเงิน',
  `profile_photo`  varchar(500) DEFAULT NULL,
  `bio`            text,
  `experience_yr`  int          DEFAULT NULL,
  `education`      varchar(500) DEFAULT NULL,
  `avg_rating`     decimal(3,2) DEFAULT NULL COMMENT 'คะแนนเฉลี่ย คำนวณจาก lawyer_reviews',
  `created_at`     timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`lawyer_id`),
  UNIQUE KEY `uq_user_id`    (`user_id`),
  UNIQUE KEY `uq_license_no` (`license_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `case_requests` (
  `request_id`    int         NOT NULL AUTO_INCREMENT,
  `office_id`     int         NOT NULL,
  `client_id`     int         NOT NULL,
  `lawyer_id`     int         NOT NULL,
  `detail`        text,
  `request_date`  date        DEFAULT NULL,
  `expire_date`   date        DEFAULT NULL COMMENT 'หมดอายุ 14 วันหลังสร้าง',
  `status`        varchar(20) DEFAULT 'pending'
                  COMMENT 'pending, approved, rejected, expired',
  `reject_reason` text,
  `created_at`    timestamp   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `idx_office_id` (`office_id`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_lawyer_id` (`lawyer_id`),
  KEY `idx_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `contracts` (
  `contract_id`            int           NOT NULL AUTO_INCREMENT,
  `request_id`             int           NOT NULL,
  `contract_date`          date          DEFAULT NULL,
  `fee_amount`             decimal(12,2) DEFAULT NULL,
  `status`                 varchar(20)   DEFAULT 'active'
                           COMMENT 'active, completed, terminated',
  `contract_review_status` varchar(30)   NOT NULL DEFAULT 'pending_lawyer_review'
                           COMMENT 'pending_lawyer_review, lawyer_accepted, lawyer_rejected, revision_requested, negotiating, finalized',
  `payment_status`         varchar(20)   DEFAULT 'pending'
                           COMMENT 'pending, partial, paid',
  `lawyer_note`            text          COMMENT 'หมายเหตุทนายตอนตีกลับ/ต่อรอง',
  `client_response`        text          COMMENT 'ลูกความตอบกลับ',
  `proposed_fee`           decimal(12,2) DEFAULT NULL COMMENT 'ค่าธรรมเนียมที่เสนอระหว่างต่อรอง',
  `created_at`             timestamp     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`contract_id`),
  UNIQUE KEY `uq_request_id`   (`request_id`),
  KEY `idx_status`             (`status`),
  KEY `idx_payment_status`     (`payment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `courts` (
  `court_id`   int          NOT NULL AUTO_INCREMENT,
  `court_name` varchar(150) NOT NULL,
  `court_type` varchar(50)  DEFAULT NULL,
  `location`   varchar(255) DEFAULT NULL,
  `created_at` timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`court_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `filings` (
  `filing_id`              int          NOT NULL AUTO_INCREMENT,
  `contract_id`            int          NOT NULL,
  `court_id`               int          NOT NULL,
  `charge`                 varchar(255) DEFAULT NULL,
  `scheduled_filing_date`  date         DEFAULT NULL COMMENT 'วันนัดยื่นฟ้อง',
  `created_at`             timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`filing_id`),
  KEY `idx_contract_id` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `court_hearings` (
  `hearing_id`    int         NOT NULL AUTO_INCREMENT,
  `filing_id`     int         NOT NULL,
  `case_number`   varchar(50) DEFAULT NULL COMMENT 'เลขคดีที่ได้รับหลังยื่นฟ้อง',
  `hearing_date`  date        DEFAULT NULL,
  `hearing_time`  time        DEFAULT NULL,
  `court_room`    varchar(50) DEFAULT NULL,
  `hearing_round` int         DEFAULT NULL,
  `status`        varchar(30) DEFAULT 'scheduled'
                  COMMENT 'scheduled, completed, postponed, cancelled, defendant_absent, plaintiff_absent, defendant_guilty_verdict',
  `reminder_sent` tinyint(1)  DEFAULT 0,
  `notes`         text,
  `updated_at`    timestamp   NULL DEFAULT NULL,
  `created_at`    timestamp   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`hearing_id`),
  KEY `idx_filing_id`    (`filing_id`),
  KEY `idx_hearing_date` (`hearing_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `verdicts` (
  `verdict_id`   int       NOT NULL AUTO_INCREMENT,
  `filing_id`    int       NOT NULL,
  `verdict_date` date      DEFAULT NULL,
  `result`       text,
  `details`      text,
  `created_at`   timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`verdict_id`),
  UNIQUE KEY `uq_filing_id` (`filing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `payments` (
  `payment_id`       int           NOT NULL AUTO_INCREMENT,
  `contract_id`      int           NOT NULL,
  `amount`           decimal(12,2) NOT NULL,
  `payment_method`   enum('cash','transfer','cheque','other') DEFAULT 'transfer',
  `payment_date`     date          NOT NULL,
  `slip_file`        varchar(500)  DEFAULT NULL,
  `note`             text,
  `installment_note` varchar(255)  DEFAULT NULL COMMENT 'มัดจำ, จ่ายบางส่วน, จ่ายเต็มจำนวน ฯลฯ',
  `status`           enum('pending','confirmed','rejected') DEFAULT 'pending',
  `paid_by`          int           DEFAULT NULL COMMENT 'user_id ผู้แจ้งชำระ',
  `confirmed_by`     int           DEFAULT NULL COMMENT 'user_id ผู้ยืนยัน',
  `confirmed_at`     datetime      DEFAULT NULL,
  `created_at`       timestamp     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  KEY `idx_contract_id` (`contract_id`),
  KEY `idx_status`      (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `case_documents` (
  `document_id`   int          NOT NULL AUTO_INCREMENT,
  `contract_id`   int          DEFAULT NULL,
  `filing_id`     int          DEFAULT NULL,
  `document_type` varchar(50)  DEFAULT NULL,
  `file_name`     varchar(255) DEFAULT NULL,
  `file_path`     varchar(255) DEFAULT NULL,
  `file_size`     int          DEFAULT NULL,
  `uploaded_by`   int          NOT NULL,
  `visibility`    varchar(20)  DEFAULT 'private'
                  COMMENT 'private, lawyer_only, all',
  `created_at`    timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`document_id`),
  KEY `idx_contract_id` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `case_summary_docs` (
  `doc_id`      int          NOT NULL AUTO_INCREMENT,
  `request_id`  int          NOT NULL,
  `file_name`   varchar(255) NOT NULL,
  `file_path`   varchar(500) NOT NULL,
  `file_size`   int          DEFAULT 0,
  `doc_label`   varchar(255) DEFAULT '',
  `doc_type`    varchar(50)  DEFAULT 'other',
  `uploaded_by` int          DEFAULT NULL,
  `created_at`  timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`doc_id`),
  KEY `idx_request_id`  (`request_id`),
  KEY `idx_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `client_sign_docs` (
  `doc_id`         int          NOT NULL AUTO_INCREMENT,
  `request_id`     int          NOT NULL COMMENT 'คดีที่เกี่ยวข้อง',
  `office_id`      int          NOT NULL,
  `uploaded_by`    int          NOT NULL COMMENT 'lawyer user_id',
  `doc_type`       varchar(100) NOT NULL COMMENT 'หนังสือมอบอำนาจ/ใบยินยอม/อื่นๆ',
  `doc_title`      varchar(300) NOT NULL COMMENT 'ชื่อเอกสาร',
  `description`    text                  COMMENT 'รายละเอียดถึงลูกความ',
  `file_path`      varchar(500) NOT NULL COMMENT 'path ไฟล์ PDF',
  `due_date`       date         DEFAULT NULL COMMENT 'วันที่ต้องการ',
  `status`         enum('pending','acknowledged','signed','rejected') DEFAULT 'pending',
  `client_note`    text                  COMMENT 'หมายเหตุจากลูกความ',
  `lawyer_note`    text,
  `ack_at`         timestamp    NULL DEFAULT NULL COMMENT 'เวลาที่ลูกความรับทราบ',
  `last_remind_at` timestamp    NULL DEFAULT NULL,
  `signed_file`    varchar(500) DEFAULT NULL COMMENT 'path ไฟล์ PDF ที่ลูกความเซ็นกลับ',
  `created_at`     timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`doc_id`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_office_id`  (`office_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `announcements` (
  `ann_id`     int          NOT NULL AUTO_INCREMENT,
  `office_id`  int          NOT NULL,
  `title`      varchar(300) NOT NULL,
  `body`       text,
  `pin`        tinyint(1)   DEFAULT 0,
  `created_by` int          DEFAULT NULL,
  `created_at` timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ann_id`),
  KEY `idx_office_id` (`office_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `lawyer_reviews` (
  `review_id`  int       NOT NULL AUTO_INCREMENT,
  `lawyer_id`  int       NOT NULL,
  `client_id`  int       NOT NULL,
  `rating`     tinyint   NOT NULL DEFAULT 5 COMMENT '1-5 ดาว',
  `comment`    text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `uq_lawyer_client` (`lawyer_id`, `client_id`),
  KEY `idx_lawyer_id` (`lawyer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `profile_change_requests` (
  `req_id`          int          NOT NULL AUTO_INCREMENT,
  `user_id`         int          NOT NULL,
  `office_id`       int          NOT NULL,
  `req_fname`       varchar(100) DEFAULT NULL,
  `req_lname`       varchar(100) DEFAULT NULL,
  `req_phone`       varchar(15)  DEFAULT NULL,
  `req_address`     text,
  `req_citizen_id`  varchar(13)  DEFAULT NULL,
  `status`          enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_note`      varchar(500) DEFAULT NULL,
  `reviewed_by`     int          DEFAULT NULL,
  `reviewed_at`     timestamp    NULL DEFAULT NULL,
  `created_at`      timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`req_id`),
  KEY `idx_user_id`   (`user_id`),
  KEY `idx_office_id` (`office_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FOREIGN KEYS
-- ============================================================

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`),
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`role_id`)   REFERENCES `roles`   (`role_id`);

ALTER TABLE `client_profiles`
  ADD CONSTRAINT `client_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

ALTER TABLE `lawyer_profiles`
  ADD CONSTRAINT `lawyer_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

ALTER TABLE `case_requests`
  ADD CONSTRAINT `case_requests_ibfk_1` FOREIGN KEY (`office_id`) REFERENCES `offices`         (`office_id`),
  ADD CONSTRAINT `case_requests_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`client_id`),
  ADD CONSTRAINT `case_requests_ibfk_3` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyer_profiles` (`lawyer_id`);

ALTER TABLE `contracts`
  ADD CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `case_requests` (`request_id`);

ALTER TABLE `filings`
  ADD CONSTRAINT `filings_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`),
  ADD CONSTRAINT `filings_ibfk_2` FOREIGN KEY (`court_id`)    REFERENCES `courts`    (`court_id`);

ALTER TABLE `court_hearings`
  ADD CONSTRAINT `court_hearings_ibfk_1` FOREIGN KEY (`filing_id`) REFERENCES `filings` (`filing_id`);

ALTER TABLE `verdicts`
  ADD CONSTRAINT `verdicts_ibfk_1` FOREIGN KEY (`filing_id`) REFERENCES `filings` (`filing_id`);

ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`contract_id`)  REFERENCES `contracts` (`contract_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`paid_by`)      REFERENCES `users`     (`user_id`)     ON DELETE SET NULL,
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`confirmed_by`) REFERENCES `users`     (`user_id`)     ON DELETE SET NULL;

ALTER TABLE `case_documents`
  ADD CONSTRAINT `case_documents_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`),
  ADD CONSTRAINT `case_documents_ibfk_2` FOREIGN KEY (`filing_id`)   REFERENCES `filings`   (`filing_id`),
  ADD CONSTRAINT `case_documents_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users`     (`user_id`);

ALTER TABLE `case_summary_docs`
  ADD CONSTRAINT `case_summary_docs_ibfk_1` FOREIGN KEY (`request_id`)  REFERENCES `case_requests` (`request_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `case_summary_docs_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users`         (`user_id`)    ON DELETE SET NULL;

ALTER TABLE `client_sign_docs`
  ADD CONSTRAINT `client_sign_docs_ibfk_1` FOREIGN KEY (`request_id`)  REFERENCES `case_requests` (`request_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `client_sign_docs_ibfk_2` FOREIGN KEY (`office_id`)   REFERENCES `offices`       (`office_id`)  ON DELETE CASCADE,
  ADD CONSTRAINT `client_sign_docs_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users`         (`user_id`)    ON DELETE CASCADE;

ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`office_id`)  REFERENCES `offices` (`office_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `announcements_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users`   (`user_id`)   ON DELETE SET NULL;

ALTER TABLE `lawyer_reviews`
  ADD CONSTRAINT `lawyer_reviews_ibfk_1` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyer_profiles` (`lawyer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyer_reviews_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`client_id`) ON DELETE CASCADE;

ALTER TABLE `profile_change_requests`
  ADD CONSTRAINT `profile_change_requests_ibfk_1` FOREIGN KEY (`user_id`)     REFERENCES `users`   (`user_id`)   ON DELETE CASCADE,
  ADD CONSTRAINT `profile_change_requests_ibfk_2` FOREIGN KEY (`office_id`)   REFERENCES `offices` (`office_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `profile_change_requests_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users`   (`user_id`)   ON DELETE SET NULL;

CREATE TABLE `postponement_requests` (
  `postpone_id`    int         NOT NULL AUTO_INCREMENT,
  `request_type`   varchar(20) NOT NULL COMMENT 'filing / hearing',
  `reference_id`   int         NOT NULL COMMENT 'filing_id หรือ hearing_id',
  `client_id`      int         NOT NULL,
  `reason`         text        COMMENT 'เหตุผลที่ขอเลื่อน',
  `requested_date` date        DEFAULT NULL COMMENT 'วันที่ต้องการเลื่อนไป',
  `status`         varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, approved, rejected',
  `lawyer_note`    text        DEFAULT NULL COMMENT 'หมายเหตุจากทนาย',
  `created_at`     timestamp   DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     timestamp   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`postpone_id`),
  KEY `idx_type_ref`  (`request_type`, `reference_id`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='คำขอเลื่อนวันนัดยื่นฟ้อง / ขึ้นศาล จากลูกความ';

CREATE TABLE `verdict_appointments` (
  `appointment_id`   int         NOT NULL AUTO_INCREMENT,
  `filing_id`        int         NOT NULL,
  `scheduled_date`   date        NOT NULL COMMENT 'วันนัดฟังคำพิพากษา',
  `scheduled_time`   time        DEFAULT NULL,
  `note`             text        COMMENT 'หมายเหตุ',
  `status`           varchar(20) NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled, completed, postponed, cancelled',
  `created_by`       int         DEFAULT NULL,
  `created_at`       timestamp   DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       timestamp   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`appointment_id`),
  KEY `idx_filing_id`      (`filing_id`),
  KEY `idx_scheduled_date` (`scheduled_date`),
  CONSTRAINT `va_ibfk_1` FOREIGN KEY (`filing_id`) REFERENCES `filings` (`filing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='ตารางนัดวันฟังคำพิพากษา';

-- ============================================================
-- INDEXES (Performance)
-- ============================================================

-- court_hearings.case_number — query/แสดงผลบ่อยใน hearings.php
ALTER TABLE `court_hearings` ADD INDEX `idx_case_number` (`case_number`);

-- filings.court_id — JOIN กับ courts ทุก query ใน filings.php
ALTER TABLE `filings` ADD INDEX `idx_court_id` (`court_id`);

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO `offices` (office_name, office_code, address, phone, email, status) VALUES
('สำนักงานกฎหมายตัวอย่าง', 'LAW001', '123 ถนนสุขุมวิท กรุงเทพฯ', '02-000-0000', 'info@lawfirm.com', 'active');

INSERT INTO `roles` (role_name) VALUES ('admin'), ('lawyer'), ('client');

INSERT INTO `courts` (court_name, court_type, location) VALUES
('ศาลแพ่งกรุงเทพใต้', 'ศาลแพ่ง',  'กรุงเทพมหานคร'),
('ศาลอาญากรุงเทพใต้', 'ศาลอาญา', 'กรุงเทพมหานคร');

-- Admin user — password จะถูก hash โดย docker/php/init-hash.sh ตอน build
-- ถ้า run โดยตรงโดยไม่ผ่าน Docker ให้แทนที่ PENDING_HASH ด้วย bcrypt hash:
--   php -r "echo password_hash('YourPassword', PASSWORD_BCRYPT);"
INSERT INTO `users` (username, office_id, role_id, email, password_hash, status) VALUES
('admin', 1, 1, 'admin@lawfirm.com', 'PENDING_HASH', 'active');

-- 1. FK ที่หายไปจริงๆ 2 ตัว:

ALTER TABLE `postponement_requests`
  ADD CONSTRAINT `postponement_requests_ibfk_1`
    FOREIGN KEY (`client_id`)
    REFERENCES `client_profiles` (`client_id`)
    ON DELETE CASCADE;

ALTER TABLE `verdict_appointments`
  ADD CONSTRAINT `va_ibfk_2`
    FOREIGN KEY (`created_by`)
    REFERENCES `users` (`user_id`)
    ON DELETE SET NULL;

-- ============================================================
-- FEATURE: Notifications
-- ============================================================
CREATE TABLE `notifications` (
  `notif_id`   int          NOT NULL AUTO_INCREMENT,
  `office_id`  int          NOT NULL,
  `user_id`    int          NOT NULL COMMENT 'ผู้รับแจ้งเตือน',
  `type`       varchar(50)  NOT NULL COMMENT 'case_request, contract, payment, hearing, filing, verdict, chat, profile_change',
  `title`      varchar(300) NOT NULL,
  `body`       text,
  `link`       varchar(500) DEFAULT NULL,
  `ref_type`   varchar(50)  DEFAULT NULL COMMENT 'entity type',
  `ref_id`     int          DEFAULT NULL COMMENT 'entity id',
  `is_read`    tinyint(1)   DEFAULT 0,
  `created_at` timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notif_id`),
  KEY `idx_user_read`   (`user_id`, `is_read`, `created_at`),
  KEY `idx_office_id`   (`office_id`),
  CONSTRAINT `notif_ibfk_1` FOREIGN KEY (`user_id`)   REFERENCES `users`   (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `notif_ibfk_2` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='การแจ้งเตือนผู้ใช้';

-- ============================================================
-- FEATURE: Activity Logs (Audit Trail)
-- ============================================================
CREATE TABLE `activity_logs` (
  `log_id`      bigint       NOT NULL AUTO_INCREMENT,
  `office_id`   int          NOT NULL,
  `user_id`     int          DEFAULT NULL,
  `action`      varchar(50)  NOT NULL COMMENT 'create, update, delete, approve, reject, login, logout',
  `entity_type` varchar(50)  NOT NULL COMMENT 'case_request, contract, payment, hearing, filing, verdict, user, client, lawyer',
  `entity_id`   int          DEFAULT NULL,
  `description` text         NOT NULL,
  `old_value`   json         DEFAULT NULL,
  `new_value`   json         DEFAULT NULL,
  `ip_address`  varchar(45)  DEFAULT NULL,
  `user_agent`  varchar(500) DEFAULT NULL,
  `created_at`  timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_office_created` (`office_id`, `created_at`),
  KEY `idx_user_id`        (`user_id`),
  KEY `idx_entity`         (`entity_type`, `entity_id`),
  CONSTRAINT `alog_ibfk_1` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE,
  CONSTRAINT `alog_ibfk_2` FOREIGN KEY (`user_id`)   REFERENCES `users`   (`user_id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ประวัติกิจกรรมระบบ (Audit Trail)';

-- ============================================================
-- FEATURE: Chat (Lawyer-Client Messaging)
-- ============================================================
CREATE TABLE `chat_conversations` (
  `conversation_id`  int       NOT NULL AUTO_INCREMENT,
  `office_id`        int       NOT NULL,
  `request_id`       int       NOT NULL COMMENT 'ผูกกับคดี',
  `lawyer_user_id`   int       NOT NULL,
  `client_user_id`   int       NOT NULL,
  `last_message_at`  timestamp NULL DEFAULT NULL,
  `created_at`       timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`conversation_id`),
  UNIQUE KEY `uq_request` (`request_id`),
  KEY `idx_office`        (`office_id`),
  KEY `idx_lawyer_user`   (`lawyer_user_id`),
  KEY `idx_client_user`   (`client_user_id`),
  CONSTRAINT `cc_ibfk_1` FOREIGN KEY (`office_id`)      REFERENCES `offices`       (`office_id`)  ON DELETE CASCADE,
  CONSTRAINT `cc_ibfk_2` FOREIGN KEY (`request_id`)     REFERENCES `case_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `cc_ibfk_3` FOREIGN KEY (`lawyer_user_id`) REFERENCES `users`         (`user_id`)    ON DELETE CASCADE,
  CONSTRAINT `cc_ibfk_4` FOREIGN KEY (`client_user_id`) REFERENCES `users`         (`user_id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='สนทนาระหว่างทนาย-ลูกความ';

CREATE TABLE `chat_messages` (
  `message_id`     bigint    NOT NULL AUTO_INCREMENT,
  `conversation_id` int      NOT NULL,
  `sender_user_id` int       NOT NULL,
  `message`        text      NOT NULL,
  `is_read`        tinyint(1) DEFAULT 0,
  `created_at`     timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`),
  KEY `idx_conv_created` (`conversation_id`, `created_at`),
  KEY `idx_sender`       (`sender_user_id`),
  CONSTRAINT `cm_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`conversation_id`) ON DELETE CASCADE,
  CONSTRAINT `cm_ibfk_2` FOREIGN KEY (`sender_user_id`)  REFERENCES `users`               (`user_id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ข้อความแชท';

-- ============================================================
-- VIEWS — สำหรับระบบค้นหาและลด JOIN ซ้ำซ้อนใน PHP
-- ============================================================

-- v_cases: รวม case_requests + contracts + ชื่อลูกความ/ทนาย
CREATE VIEW v_cases AS
SELECT
    cr.request_id, cr.office_id, cr.client_id, cr.lawyer_id,
    cr.detail AS case_detail, cr.request_date, cr.expire_date,
    cr.status AS request_status, cr.reject_reason,
    cr.created_at AS request_created_at,
    c.contract_id, c.contract_date, c.fee_amount,
    c.status AS contract_status, c.contract_review_status, c.payment_status,
    c.lawyer_note, c.client_response, c.proposed_fee,
    c.created_at AS contract_created_at,
    cp.fname AS client_fname, cp.lname AS client_lname,
    cp.citizen_id AS client_citizen_id, cp.phone AS client_phone,
    cp.user_id AS client_user_id,
    CONCAT(cp.fname,' ',cp.lname) AS client_name,
    lp.fname AS lawyer_fname, lp.lname AS lawyer_lname,
    lp.license_no AS lawyer_license, lp.specialization AS lawyer_specialization,
    lp.phone AS lawyer_phone, lp.user_id AS lawyer_user_id,
    lp.qr_code_file AS lawyer_qr,
    CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
FROM case_requests cr
LEFT JOIN contracts c        ON c.request_id = cr.request_id
LEFT JOIN client_profiles cp ON cp.client_id = cr.client_id
LEFT JOIN lawyer_profiles lp ON lp.lawyer_id = cr.lawyer_id;

-- v_filings: รวม filings + courts + ข้อมูลสัญญา/คู่ความ
CREATE VIEW v_filings AS
SELECT
    f.filing_id, f.contract_id, f.court_id, f.charge,
    f.scheduled_filing_date, f.created_at AS filing_created_at,
    COALESCE(ct.court_name,'—') AS court_display, ct.court_type,
    ct.location AS court_location,
    cr.request_id, cr.office_id, cr.client_id, cr.lawyer_id,
    cr.detail AS case_detail, con.contract_date, con.fee_amount,
    CONCAT(cp.fname,' ',cp.lname) AS client_name,
    CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
FROM filings f
LEFT JOIN courts ct          ON f.court_id    = ct.court_id
JOIN contracts con           ON f.contract_id = con.contract_id
JOIN case_requests cr        ON con.request_id= cr.request_id
LEFT JOIN client_profiles cp ON cr.client_id  = cp.client_id
LEFT JOIN lawyer_profiles lp ON cr.lawyer_id  = lp.lawyer_id;

-- v_hearings: รวม court_hearings + filings + courts + ชื่อคู่ความ
CREATE VIEW v_hearings AS
SELECT
    ch.hearing_id, ch.filing_id, ch.case_number, ch.hearing_date,
    ch.hearing_time, ch.court_room, ch.hearing_round,
    ch.status, ch.reminder_sent, ch.notes,
    ch.updated_at, ch.created_at,
    f.contract_id, f.charge, f.court_id,
    ct.court_name, cr.request_id, cr.office_id,
    cr.client_id, cr.lawyer_id,
    CONCAT(cp.fname,' ',cp.lname) AS client_name,
    CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
FROM court_hearings ch
JOIN filings f               ON ch.filing_id   = f.filing_id
JOIN contracts con           ON f.contract_id  = con.contract_id
JOIN case_requests cr        ON con.request_id = cr.request_id
JOIN client_profiles cp      ON cr.client_id   = cp.client_id
JOIN courts ct               ON f.court_id     = ct.court_id
LEFT JOIN lawyer_profiles lp ON cr.lawyer_id   = lp.lawyer_id;

-- v_clients: รวม client_profiles + users
CREATE VIEW v_clients AS
SELECT
    cp.client_id, cp.user_id, cp.fname, cp.lname,
    cp.citizen_id, cp.phone, cp.address, cp.profile_photo, cp.created_at,
    u.email, u.username, u.status AS user_status, u.office_id,
    CONCAT(cp.fname,' ',cp.lname) AS full_name
FROM client_profiles cp
JOIN users u ON cp.user_id = u.user_id;

-- v_lawyers: รวม lawyer_profiles + users + จำนวนคดี
CREATE VIEW v_lawyers AS
SELECT
    lp.lawyer_id, lp.user_id, lp.fname, lp.lname,
    lp.license_no, lp.license_exp, lp.specialization,
    lp.phone, lp.status AS lawyer_status, lp.qr_code_file,
    lp.profile_photo, lp.bio, lp.experience_yr, lp.education,
    lp.avg_rating, lp.created_at,
    u.email, u.username, u.status AS user_status, u.office_id,
    CONCAT(lp.fname,' ',lp.lname) AS full_name,
    (SELECT COUNT(DISTINCT cr.request_id)
     FROM case_requests cr
     WHERE cr.lawyer_id = lp.lawyer_id AND cr.status = 'approved') AS case_count
FROM lawyer_profiles lp
JOIN users u ON lp.user_id = u.user_id;