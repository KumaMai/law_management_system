-- ============================================================
-- 4 New tables for: Notifications, Activity Logs, Chat
-- Run: docker exec -i law_db mysql -u law_user -plaw_password law_system < new_tables.sql
-- ============================================================

USE law_system;

CREATE TABLE IF NOT EXISTS `notifications` (
  `notif_id`   int          NOT NULL AUTO_INCREMENT,
  `office_id`  int          NOT NULL,
  `user_id`    int          NOT NULL COMMENT 'ผู้รับแจ้งเตือน',
  `type`       varchar(50)  NOT NULL,
  `title`      varchar(300) NOT NULL,
  `body`       text,
  `link`       varchar(500) DEFAULT NULL,
  `ref_type`   varchar(50)  DEFAULT NULL,
  `ref_id`     int          DEFAULT NULL,
  `is_read`    tinyint(1)   DEFAULT 0,
  `created_at` timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notif_id`),
  KEY `idx_user_read` (`user_id`, `is_read`, `created_at`),
  KEY `idx_office_id` (`office_id`),
  CONSTRAINT `notif_ibfk_1` FOREIGN KEY (`user_id`)   REFERENCES `users`   (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `notif_ibfk_2` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='การแจ้งเตือนผู้ใช้';

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `log_id`      bigint       NOT NULL AUTO_INCREMENT,
  `office_id`   int          NOT NULL,
  `user_id`     int          DEFAULT NULL,
  `action`      varchar(50)  NOT NULL,
  `entity_type` varchar(50)  NOT NULL,
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

CREATE TABLE IF NOT EXISTS `chat_conversations` (
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

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `message_id`      bigint     NOT NULL AUTO_INCREMENT,
  `conversation_id` int        NOT NULL,
  `sender_user_id`  int        NOT NULL,
  `message`         text       NOT NULL,
  `is_read`         tinyint(1) DEFAULT 0,
  `created_at`      timestamp  DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`),
  KEY `idx_conv_created` (`conversation_id`, `created_at`),
  KEY `idx_sender`       (`sender_user_id`),
  CONSTRAINT `cm_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `chat_conversations` (`conversation_id`) ON DELETE CASCADE,
  CONSTRAINT `cm_ibfk_2` FOREIGN KEY (`sender_user_id`)  REFERENCES `users`               (`user_id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ข้อความแชท';
