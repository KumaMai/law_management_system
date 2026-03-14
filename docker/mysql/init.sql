CREATE DATABASE IF NOT EXISTS law_system;
USE law_system;

CREATE TABLE `offices` (
  `office_id` int PRIMARY KEY AUTO_INCREMENT,
  `office_name` varchar(150) NOT NULL,
  `office_code` varchar(20) UNIQUE,
  `address` text,
  `phone` varchar(15),
  `email` varchar(150),
  `logo_path` varchar(255),
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `roles` (
  `role_id` int PRIMARY KEY AUTO_INCREMENT,
  `role_name` varchar(50) UNIQUE NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `users` (
  `user_id` int PRIMARY KEY AUTO_INCREMENT,
  `office_id` int NOT NULL,
  `role_id` int NOT NULL,
  `email` varchar(150) UNIQUE NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `client_profiles` (
  `client_id` int PRIMARY KEY AUTO_INCREMENT,
  `user_id` int UNIQUE NOT NULL,
  `fname` varchar(100) NOT NULL,
  `lname` varchar(100) NOT NULL,
  `citizen_id` varchar(13) UNIQUE,
  `phone` varchar(15),
  `address` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `lawyer_profiles` (
  `lawyer_id` int PRIMARY KEY AUTO_INCREMENT,
  `user_id` int UNIQUE NOT NULL,
  `fname` varchar(100) NOT NULL,
  `lname` varchar(100) NOT NULL,
  `license_no` varchar(50) UNIQUE,
  `license_exp` date,
  `specialization` varchar(150),
  `phone` varchar(15),
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `case_requests` (
  `request_id` int PRIMARY KEY AUTO_INCREMENT,
  `office_id` int NOT NULL,
  `client_id` int NOT NULL,
  `lawyer_id` int NOT NULL,
  `detail` text,
  `request_date` date,
  `expire_date` date,
  `status` varchar(20) DEFAULT 'pending',
  `reject_reason` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `contracts` (
  `contract_id` int PRIMARY KEY AUTO_INCREMENT,
  `request_id` int UNIQUE NOT NULL,
  `contract_date` date,
  `fee_amount` decimal(12,2),
  `status` varchar(20) DEFAULT 'active',
  `payment_status` varchar(20) DEFAULT 'pending',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `courts` (
  `court_id` int PRIMARY KEY AUTO_INCREMENT,
  `court_name` varchar(150) NOT NULL,
  `court_type` varchar(50),
  `location` varchar(255),
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `filings` (
  `filing_id` int PRIMARY KEY AUTO_INCREMENT,
  `contract_id` int NOT NULL,
  `court_id` int NOT NULL,
  `case_number` varchar(50) UNIQUE,
  `charge` varchar(255),
  `filing_date` date,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `court_hearings` (
  `hearing_id` int PRIMARY KEY AUTO_INCREMENT,
  `filing_id` int NOT NULL,
  `hearing_date` date,
  `hearing_time` time,
  `court_room` varchar(50),
  `hearing_round` int,
  `status` varchar(20) DEFAULT 'scheduled',
  `reminder_sent` tinyint(1) DEFAULT 0,
  `notes` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `verdicts` (
  `verdict_id` int PRIMARY KEY AUTO_INCREMENT,
  `filing_id` int UNIQUE NOT NULL,
  `verdict_date` date,
  `result` text,
  `details` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `case_documents` (
  `document_id` int PRIMARY KEY AUTO_INCREMENT,
  `contract_id` int,
  `filing_id` int,
  `document_type` varchar(50),
  `file_name` varchar(255),
  `file_path` varchar(255),
  `file_size` int,
  `uploaded_by` int NOT NULL,
  `visibility` varchar(20) DEFAULT 'private',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
);

-- Foreign Keys
ALTER TABLE `users` ADD FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`);
ALTER TABLE `users` ADD FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`);
ALTER TABLE `client_profiles` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);
ALTER TABLE `lawyer_profiles` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);
ALTER TABLE `case_requests` ADD FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`);
ALTER TABLE `case_requests` ADD FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`client_id`);
ALTER TABLE `case_requests` ADD FOREIGN KEY (`lawyer_id`) REFERENCES `lawyer_profiles` (`lawyer_id`);
ALTER TABLE `contracts` ADD FOREIGN KEY (`request_id`) REFERENCES `case_requests` (`request_id`);
ALTER TABLE `filings` ADD FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`);
ALTER TABLE `filings` ADD FOREIGN KEY (`court_id`) REFERENCES `courts` (`court_id`);
ALTER TABLE `court_hearings` ADD FOREIGN KEY (`filing_id`) REFERENCES `filings` (`filing_id`);
ALTER TABLE `verdicts` ADD FOREIGN KEY (`filing_id`) REFERENCES `filings` (`filing_id`);
ALTER TABLE `case_documents` ADD FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`);
ALTER TABLE `case_documents` ADD FOREIGN KEY (`filing_id`) REFERENCES `filings` (`filing_id`);
ALTER TABLE `case_documents` ADD FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`);

-- Seed Data
INSERT INTO `offices` (office_name, office_code, address, phone, email, status) VALUES
('สำนักงานกฎหมายตัวอย่าง', 'LAW001', '123 ถนนสุขุมวิท กรุงเทพฯ', '02-000-0000', 'info@lawfirm.com', 'active');

INSERT INTO `roles` (role_name) VALUES ('admin'), ('lawyer'), ('client');

INSERT INTO `courts` (court_name, court_type, location) VALUES
('ศาลแพ่งกรุงเทพใต้', 'ศาลแพ่ง', 'กรุงเทพมหานคร'),
('ศาลอาญากรุงเทพใต้', 'ศาลอาญา', 'กรุงเทพมหานคร');

-- Admin user (password: admin1234)
-- Hash is bcrypt of 'admin1234' cost=10, generated by PHP password_hash()
INSERT INTO `users` (office_id, role_id, email, password_hash, status) VALUES
(1, 1, 'admin@lawfirm.com', 'PENDING_HASH', 'active');
