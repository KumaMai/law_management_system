-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: law_db
-- Generation Time: Mar 17, 2026 at 06:02 AM
-- Server version: 8.0.44
-- PHP Version: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `law_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `ann_id` int NOT NULL,
  `office_id` int NOT NULL,
  `title` varchar(300) NOT NULL,
  `body` text,
  `pin` tinyint(1) DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `case_documents`
--

CREATE TABLE `case_documents` (
  `document_id` int NOT NULL,
  `contract_id` int DEFAULT NULL,
  `filing_id` int DEFAULT NULL,
  `document_type` varchar(50) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_by` int NOT NULL,
  `visibility` varchar(20) DEFAULT 'private',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `case_documents`
--

INSERT INTO `case_documents` (`document_id`, `contract_id`, `filing_id`, `document_type`, `file_name`, `file_path`, `file_size`, `uploaded_by`, `visibility`, `created_at`) VALUES
(11, 10, NULL, 'contract', '1.7-8logic.pdf', 'uploads/contracts/contract_10_1773648923_69b7bc1bafdd9.pdf', 146947, 8, 'lawyer_only', '2026-03-16 08:15:23'),
(12, 10, NULL, 'id_card', 'AUCC-Accept-Letter (1).pdf', 'uploads/contracts/contract_10_1773648930_69b7bc22e554e.pdf', 134829, 8, 'lawyer_only', '2026-03-16 08:15:30'),
(13, 10, NULL, 'evidence', 'AUCC-Award-Certificate (2).pdf', 'uploads/contracts/contract_10_1773648938_69b7bc2a0348b.pdf', 184521, 8, 'lawyer_only', '2026-03-16 08:15:38');

-- --------------------------------------------------------

--
-- Table structure for table `case_requests`
--

CREATE TABLE `case_requests` (
  `request_id` int NOT NULL,
  `office_id` int NOT NULL,
  `client_id` int NOT NULL,
  `lawyer_id` int NOT NULL,
  `detail` text,
  `request_date` date DEFAULT NULL,
  `expire_date` date DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `reject_reason` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `case_requests`
--

INSERT INTO `case_requests` (`request_id`, `office_id`, `client_id`, `lawyer_id`, `detail`, `request_date`, `expire_date`, `status`, `reject_reason`, `created_at`) VALUES
(10, 1, 3, 1, 'chjsdnkfljdsbjks', '2026-03-16', '2026-03-30', 'approved', NULL, '2026-03-16 08:14:56');

-- --------------------------------------------------------

--
-- Table structure for table `case_summary_docs`
--

CREATE TABLE `case_summary_docs` (
  `doc_id` int NOT NULL,
  `request_id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int DEFAULT '0',
  `doc_label` varchar(255) DEFAULT '',
  `doc_type` varchar(50) DEFAULT 'other',
  `uploaded_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `case_summary_docs`
--

INSERT INTO `case_summary_docs` (`doc_id`, `request_id`, `file_name`, `file_path`, `file_size`, `doc_label`, `doc_type`, `uploaded_by`, `created_at`) VALUES
(5, 10, 'AUCC-Accept-Letter (1).pdf', 'extdoc_10_1773649223_69b7bd4738eaa.pdf', 134829, 'AUCC-Accept-Letter (1)', 'other', 5, '2026-03-16 08:20:23');

-- --------------------------------------------------------

--
-- Table structure for table `client_profiles`
--

CREATE TABLE `client_profiles` (
  `client_id` int NOT NULL,
  `user_id` int NOT NULL,
  `fname` varchar(100) NOT NULL,
  `lname` varchar(100) NOT NULL,
  `citizen_id` varchar(13) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `profile_photo` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `client_profiles`
--

INSERT INTO `client_profiles` (`client_id`, `user_id`, `fname`, `lname`, `citizen_id`, `phone`, `address`, `created_at`, `profile_photo`) VALUES
(1, 2, 'กันตวิชญ์', 'สิงห์เนี่ยว', '1939900588461', '0630565896', '27 ม.7 ต.พญาขัน อ.เมือง จ.พัทลุง 93000', '2026-02-18 13:12:05', 'client_1_1773406287.jpg'),
(2, 7, 'เสฏฐพงค์', 'อ่อนน้อย', '1939900554949', '0935939670', '50 หมู่ 6 ถนน.ดอนรุน อำเภอเมือง จ.พัทลุง ต.คูหาสวรรค์', '2026-03-15 11:24:48', NULL),
(3, 8, 'ทัก', 'กาญ', '1234567890123', '0917323876', '28 vg 423', '2026-03-16 08:13:40', NULL),
(4, 10, 'test2', 'test2', '1231234564560', '9090808088', 'test2', '2026-03-16 10:03:40', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `client_sign_docs`
--

CREATE TABLE `client_sign_docs` (
  `doc_id` int NOT NULL,
  `request_id` int NOT NULL COMMENT 'คดีที่เกี่ยวข้อง',
  `office_id` int NOT NULL,
  `uploaded_by` int NOT NULL COMMENT 'lawyer user_id',
  `doc_type` varchar(100) NOT NULL COMMENT 'หนังสือมอบอำนาจ/ใบยินยอม/อื่นๆ',
  `doc_title` varchar(300) NOT NULL COMMENT 'ชื่อเอกสาร',
  `description` text COMMENT 'รายละเอียดถึงลูกความ',
  `file_path` varchar(500) NOT NULL COMMENT 'path ไฟล์ PDF',
  `due_date` date DEFAULT NULL COMMENT 'วันที่ต้องการ',
  `status` enum('pending','acknowledged','signed','rejected') DEFAULT 'pending',
  `client_note` text COMMENT 'หมายเหตุจากลูกความ',
  `ack_at` timestamp NULL DEFAULT NULL COMMENT 'เวลาที่ลูกความรับทราบ',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `lawyer_note` text,
  `last_remind_at` timestamp NULL DEFAULT NULL,
  `signed_file` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `client_sign_docs`
--

INSERT INTO `client_sign_docs` (`doc_id`, `request_id`, `office_id`, `uploaded_by`, `doc_type`, `doc_title`, `description`, `file_path`, `due_date`, `status`, `client_note`, `ack_at`, `created_at`, `lawyer_note`, `last_remind_at`, `signed_file`) VALUES
(3, 10, 1, 5, 'หนังสือมอบอำนาจ', 'หนังสือยินยอม', 'test', 'signdoc_10_1773653502.pdf', '2026-03-16', 'signed', '', '2026-03-16 14:25:51', '2026-03-16 09:31:42', NULL, NULL, 'signed_3_1773671151.pdf');

-- --------------------------------------------------------

--
-- Table structure for table `contracts`
--

CREATE TABLE `contracts` (
  `contract_id` int NOT NULL,
  `request_id` int NOT NULL,
  `contract_date` date DEFAULT NULL,
  `fee_amount` decimal(12,2) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `contract_review_status` varchar(30) NOT NULL DEFAULT 'pending_lawyer_review',
  `payment_status` varchar(20) DEFAULT 'pending',
  `negotiation_status` varchar(30) NOT NULL DEFAULT 'accepted',
  `lawyer_note` text,
  `proposed_fee` decimal(12,2) DEFAULT NULL,
  `client_response` text,
  `negotiated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `contracts`
--

INSERT INTO `contracts` (`contract_id`, `request_id`, `contract_date`, `fee_amount`, `status`, `contract_review_status`, `payment_status`, `negotiation_status`, `lawyer_note`, `proposed_fee`, `client_response`, `negotiated_at`, `created_at`) VALUES
(10, 10, '2026-03-16', 10000000.00, 'completed', 'lawyer_accepted', 'paid', 'finalized', NULL, NULL, NULL, '2026-03-16 08:36:21', '2026-03-16 08:15:04');

-- --------------------------------------------------------

--
-- Table structure for table `courts`
--

CREATE TABLE `courts` (
  `court_id` int NOT NULL,
  `court_name` varchar(150) NOT NULL,
  `court_type` varchar(50) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `courts`
--

INSERT INTO `courts` (`court_id`, `court_name`, `court_type`, `location`, `created_at`) VALUES
(1, 'ศาล 9', 'ศาลแพ่ง', '30/3 ', '2026-02-18 13:01:16'),
(3, 'ศาลจังหวัดพัทลุง', NULL, NULL, '2026-03-15 14:15:03'),
(4, 'ศาลจังหวัดสงขลา', NULL, NULL, '2026-03-16 08:16:14');

-- --------------------------------------------------------

--
-- Table structure for table `court_hearings`
--

CREATE TABLE `court_hearings` (
  `hearing_id` int NOT NULL,
  `filing_id` int NOT NULL,
  `hearing_date` date DEFAULT NULL,
  `hearing_time` time DEFAULT NULL,
  `court_room` varchar(50) DEFAULT NULL,
  `hearing_round` int DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'scheduled',
  `reminder_sent` tinyint(1) DEFAULT '0',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `court_hearings`
--

INSERT INTO `court_hearings` (`hearing_id`, `filing_id`, `hearing_date`, `hearing_time`, `court_room`, `hearing_round`, `status`, `reminder_sent`, `notes`, `created_at`, `updated_at`) VALUES
(8, 5, '2026-03-21', NULL, '5', 2, 'defendant_absent', 0, 'sadasds', '2026-03-16 08:17:59', '2026-03-16 08:18:25');

-- --------------------------------------------------------

--
-- Table structure for table `filings`
--

CREATE TABLE `filings` (
  `filing_id` int NOT NULL,
  `contract_id` int NOT NULL,
  `court_id` int DEFAULT NULL,
  `case_number` varchar(50) DEFAULT NULL,
  `charge` varchar(255) DEFAULT NULL,
  `filing_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `filings`
--

INSERT INTO `filings` (`filing_id`, `contract_id`, `court_id`, `case_number`, `charge`, `filing_date`, `created_at`) VALUES
(5, 10, 4, '1231242', 'acasfas', '2026-03-19', '2026-03-16 08:16:14');

-- --------------------------------------------------------

--
-- Table structure for table `lawyer_profiles`
--

CREATE TABLE `lawyer_profiles` (
  `lawyer_id` int NOT NULL,
  `user_id` int NOT NULL,
  `fname` varchar(100) NOT NULL,
  `lname` varchar(100) NOT NULL,
  `license_no` varchar(50) DEFAULT NULL,
  `license_exp` date DEFAULT NULL,
  `specialization` varchar(150) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `qr_code_file` varchar(500) DEFAULT NULL COMMENT 'ชื่อไฟล์ QR Code ของทนาย',
  `profile_photo` varchar(500) DEFAULT NULL,
  `bio` text,
  `experience_yr` int DEFAULT NULL,
  `education` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `lawyer_profiles`
--

INSERT INTO `lawyer_profiles` (`lawyer_id`, `user_id`, `fname`, `lname`, `license_no`, `license_exp`, `specialization`, `phone`, `status`, `created_at`, `qr_code_file`, `profile_photo`, `bio`, `experience_yr`, `education`) VALUES
(1, 5, 'ทนายสัมพันธ์', 'อ่อนน้อย', '123456', '2030-05-13', 'คดีแพ่ง', '0898761091', 'active', '2026-02-21 05:47:24', 'qr_lawyer_5_1773043894.jpg', 'lawyer_1_1773314293.jpg', 'DM.0898761091\r\nLine:0898761091\r\nFB:สัมพันธ์ อ่อนน้อย \r\nสำนักงาน:พรรณชม อ่อนน้อย', 30, ''),
(2, 6, 'ทนายอรรถพล', 'อ่อนน้อย', '1287/2534', '2028-03-15', 'คดีแพ่ง', '0898761091', 'active', '2026-03-15 11:13:13', NULL, 'lawyer_2_1773573349.jpg', '', 8, ''),
(3, 9, 'test1', 'test1', '123456780988', '2031-11-16', 'คดีแพ่ง', '0900900900', 'active', '2026-03-16 10:02:49', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `lawyer_reviews`
--

CREATE TABLE `lawyer_reviews` (
  `review_id` int NOT NULL,
  `lawyer_id` int NOT NULL,
  `client_id` int NOT NULL,
  `rating` tinyint NOT NULL DEFAULT '5',
  `comment` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `offices`
--

CREATE TABLE `offices` (
  `office_id` int NOT NULL,
  `office_name` varchar(150) NOT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `address` text,
  `phone` varchar(15) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `offices`
--

INSERT INTO `offices` (`office_id`, `office_name`, `office_code`, `address`, `phone`, `email`, `logo_path`, `status`, `created_at`) VALUES
(1, 'สำนักงานพันชรรม', 'LAW001', '23/1', '02-000-0000', 'สำนักงานพันชรรม@gmail.com', NULL, 'active', '2026-02-18 13:01:16');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int NOT NULL,
  `contract_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','transfer','cheque','other') DEFAULT 'transfer',
  `payment_date` date NOT NULL,
  `slip_file` varchar(500) DEFAULT NULL,
  `note` text,
  `status` enum('pending','confirmed','rejected') DEFAULT 'pending',
  `paid_by` int DEFAULT NULL COMMENT 'user_id ของลูกความ',
  `confirmed_by` int DEFAULT NULL COMMENT 'user_id ของทนาย/admin',
  `confirmed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `installment_note` varchar(255) DEFAULT NULL COMMENT 'หมายเหตุงวด เช่น งวดที่ 1, มัดจำ'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `contract_id`, `amount`, `payment_method`, `payment_date`, `slip_file`, `note`, `status`, `paid_by`, `confirmed_by`, `confirmed_at`, `created_at`, `installment_note`) VALUES
(4, 10, 10000000.00, 'transfer', '2026-03-16', 'slip_10_1773653367_69b7cd7751b90.jpg', 'test', 'confirmed', 8, 5, '2026-03-16 09:29:40', '2026-03-16 09:29:27', 'จ่ายเต็มจำนวน');

-- --------------------------------------------------------

--
-- Table structure for table `profile_change_requests`
--

CREATE TABLE `profile_change_requests` (
  `req_id` int NOT NULL,
  `user_id` int NOT NULL,
  `office_id` int NOT NULL,
  `req_fname` varchar(100) DEFAULT NULL,
  `req_lname` varchar(100) DEFAULT NULL,
  `req_phone` varchar(15) DEFAULT NULL,
  `req_address` text,
  `req_citizen_id` varchar(13) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` varchar(500) DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`, `created_at`) VALUES
(1, 'admin', '2026-02-18 13:01:16'),
(2, 'lawyer', '2026-02-18 13:01:16'),
(3, 'client', '2026-02-18 13:01:16');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `office_id` int NOT NULL,
  `role_id` int NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `office_id`, `role_id`, `email`, `password_hash`, `status`, `created_at`) VALUES
(1, 'admin', 1, 1, 'admin@admin.com', '$2y$10$PAZShmim.yF196CQm2cY0uuXdffMi3AI3hCY9meZLM1pcDszlaIRW', 'active', '2026-02-18 13:01:16'),
(2, 'KumaMai', 1, 3, 'dgvhjfgh7@gmail.com', '$2y$10$HlthZrsvh.6x7Xn8DlYeIum/OC6Wpn8CJl6zL0dBcerOCsWCZ7KBO', 'active', '2026-02-18 13:12:05'),
(5, 'test', 1, 2, 'test@gmail.com', '$2y$10$5U0Oa8hJDCS8FcZZFXVkGecx0fxg94CW9XlsxXTSkIKJdxRENx.9K', 'active', '2026-02-21 05:47:24'),
(6, 'aut', 1, 2, 'AutAuttapon@gmail.com', '$2y$10$XGqPNzoKnZBOlIDof.UmPeFoJFEFGo2cFF5UZnk78VJFAyShi4S6i', 'active', '2026-03-15 11:13:13'),
(7, 'setthaphong', 1, 3, 'setthaphong.o@rmutsvmail.com', '$2y$10$Eg4GPKnlhGUD9T6.QYfAnO1qbTxrMN379vukj5aGxQpTZVjfDlDie', 'active', '2026-03-15 11:24:48'),
(8, 'yanis', 1, 3, 'thakdanai2222@gmail.com', '$2y$10$O/ERj5dECKttkxbdEsR8UOqPPcGNV1rNl8jfpXNzJ01pNcpUZ4hGS', 'active', '2026-03-16 08:13:40'),
(9, 'test1', 1, 2, 'test1@gmail.com', '$2y$10$sCTkzsaD6VNQIEDSMP4edOBGSYTfarPInvdtW11BAsC2zzjLcOVji', 'active', '2026-03-16 10:02:49'),
(10, 'test2', 1, 3, 'test2@gmail.com', '$2y$10$FcEjLP2g9BBG5cW3e5E1geFIRn.d0BODTMOCOco4icDpxb68xjyRK', 'active', '2026-03-16 10:03:40');

-- --------------------------------------------------------

--
-- Table structure for table `verdicts`
--

CREATE TABLE `verdicts` (
  `verdict_id` int NOT NULL,
  `filing_id` int NOT NULL,
  `verdict_date` date DEFAULT NULL,
  `result` text,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `verdicts`
--

INSERT INTO `verdicts` (`verdict_id`, `filing_id`, `verdict_date`, `result`, `details`, `created_at`, `updated_at`) VALUES
(3, 5, '2026-03-25', 'ผู้ชนะ: โจทก์\n\nสรุปคำพิพากษา:\nasda\n\nเหตุผลของศาล:\nsadasd\n\nค่าเสียหาย/ค่าปรับ: 123,132,123.00 บาท (ชำระให้: จำเลย)', NULL, '2026-03-16 08:19:08', '2026-03-16 08:19:48');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`ann_id`),
  ADD KEY `idx_office` (`office_id`),
  ADD KEY `announcements_ibfk_2` (`created_by`);

--
-- Indexes for table `case_documents`
--
ALTER TABLE `case_documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `contract_id` (`contract_id`),
  ADD KEY `filing_id` (`filing_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `case_requests`
--
ALTER TABLE `case_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `office_id` (`office_id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `lawyer_id` (`lawyer_id`);

--
-- Indexes for table `case_summary_docs`
--
ALTER TABLE `case_summary_docs`
  ADD PRIMARY KEY (`doc_id`),
  ADD KEY `idx_request_id` (`request_id`),
  ADD KEY `case_summary_docs_ibfk_2` (`uploaded_by`);

--
-- Indexes for table `client_profiles`
--
ALTER TABLE `client_profiles`
  ADD PRIMARY KEY (`client_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `citizen_id` (`citizen_id`);

--
-- Indexes for table `client_sign_docs`
--
ALTER TABLE `client_sign_docs`
  ADD PRIMARY KEY (`doc_id`),
  ADD KEY `idx_request` (`request_id`),
  ADD KEY `idx_office` (`office_id`),
  ADD KEY `idx_uploader` (`uploaded_by`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`contract_id`),
  ADD UNIQUE KEY `request_id` (`request_id`);

--
-- Indexes for table `courts`
--
ALTER TABLE `courts`
  ADD PRIMARY KEY (`court_id`);

--
-- Indexes for table `court_hearings`
--
ALTER TABLE `court_hearings`
  ADD PRIMARY KEY (`hearing_id`),
  ADD KEY `filing_id` (`filing_id`);

--
-- Indexes for table `filings`
--
ALTER TABLE `filings`
  ADD PRIMARY KEY (`filing_id`),
  ADD UNIQUE KEY `case_number` (`case_number`),
  ADD KEY `contract_id` (`contract_id`),
  ADD KEY `court_id` (`court_id`);

--
-- Indexes for table `lawyer_profiles`
--
ALTER TABLE `lawyer_profiles`
  ADD PRIMARY KEY (`lawyer_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `license_no` (`license_no`);

--
-- Indexes for table `lawyer_reviews`
--
ALTER TABLE `lawyer_reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD UNIQUE KEY `uq_lawyer_client` (`lawyer_id`,`client_id`),
  ADD KEY `idx_lawyer` (`lawyer_id`),
  ADD KEY `lawyer_reviews_ibfk_2` (`client_id`);

--
-- Indexes for table `offices`
--
ALTER TABLE `offices`
  ADD PRIMARY KEY (`office_id`),
  ADD UNIQUE KEY `office_code` (`office_code`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_contract_id` (`contract_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `payments_ibfk_2` (`paid_by`),
  ADD KEY `payments_ibfk_3` (`confirmed_by`);

--
-- Indexes for table `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  ADD PRIMARY KEY (`req_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_office` (`office_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `profile_change_requests_ibfk_3` (`reviewed_by`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uq_username` (`username`),
  ADD KEY `office_id` (`office_id`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `verdicts`
--
ALTER TABLE `verdicts`
  ADD PRIMARY KEY (`verdict_id`),
  ADD UNIQUE KEY `filing_id` (`filing_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `ann_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `case_documents`
--
ALTER TABLE `case_documents`
  MODIFY `document_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `case_requests`
--
ALTER TABLE `case_requests`
  MODIFY `request_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `case_summary_docs`
--
ALTER TABLE `case_summary_docs`
  MODIFY `doc_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `client_profiles`
--
ALTER TABLE `client_profiles`
  MODIFY `client_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `client_sign_docs`
--
ALTER TABLE `client_sign_docs`
  MODIFY `doc_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `contracts`
--
ALTER TABLE `contracts`
  MODIFY `contract_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `courts`
--
ALTER TABLE `courts`
  MODIFY `court_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `court_hearings`
--
ALTER TABLE `court_hearings`
  MODIFY `hearing_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `filings`
--
ALTER TABLE `filings`
  MODIFY `filing_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `lawyer_profiles`
--
ALTER TABLE `lawyer_profiles`
  MODIFY `lawyer_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `lawyer_reviews`
--
ALTER TABLE `lawyer_reviews`
  MODIFY `review_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `offices`
--
ALTER TABLE `offices`
  MODIFY `office_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  MODIFY `req_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `verdicts`
--
ALTER TABLE `verdicts`
  MODIFY `verdict_id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `announcements_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `case_documents`
--
ALTER TABLE `case_documents`
  ADD CONSTRAINT `case_documents_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`),
  ADD CONSTRAINT `case_documents_ibfk_2` FOREIGN KEY (`filing_id`) REFERENCES `filings` (`filing_id`),
  ADD CONSTRAINT `case_documents_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `case_requests`
--
ALTER TABLE `case_requests`
  ADD CONSTRAINT `case_requests_ibfk_1` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`),
  ADD CONSTRAINT `case_requests_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`client_id`),
  ADD CONSTRAINT `case_requests_ibfk_3` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyer_profiles` (`lawyer_id`);

--
-- Constraints for table `case_summary_docs`
--
ALTER TABLE `case_summary_docs`
  ADD CONSTRAINT `case_summary_docs_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `case_requests` (`request_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `case_summary_docs_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `client_profiles`
--
ALTER TABLE `client_profiles`
  ADD CONSTRAINT `client_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `client_sign_docs`
--
ALTER TABLE `client_sign_docs`
  ADD CONSTRAINT `client_sign_docs_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `case_requests` (`request_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `client_sign_docs_ibfk_2` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `client_sign_docs_ibfk_3` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `contracts`
--
ALTER TABLE `contracts`
  ADD CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `case_requests` (`request_id`);

--
-- Constraints for table `court_hearings`
--
ALTER TABLE `court_hearings`
  ADD CONSTRAINT `court_hearings_ibfk_1` FOREIGN KEY (`filing_id`) REFERENCES `filings` (`filing_id`);

--
-- Constraints for table `filings`
--
ALTER TABLE `filings`
  ADD CONSTRAINT `filings_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`),
  ADD CONSTRAINT `filings_ibfk_2` FOREIGN KEY (`court_id`) REFERENCES `courts` (`court_id`);

--
-- Constraints for table `lawyer_profiles`
--
ALTER TABLE `lawyer_profiles`
  ADD CONSTRAINT `lawyer_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `lawyer_reviews`
--
ALTER TABLE `lawyer_reviews`
  ADD CONSTRAINT `lawyer_reviews_ibfk_1` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyer_profiles` (`lawyer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lawyer_reviews_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`client_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`paid_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `profile_change_requests`
--
ALTER TABLE `profile_change_requests`
  ADD CONSTRAINT `profile_change_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `profile_change_requests_ibfk_2` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `profile_change_requests_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`),
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`);

--
-- Constraints for table `verdicts`
--
ALTER TABLE `verdicts`
  ADD CONSTRAINT `verdicts_ibfk_1` FOREIGN KEY (`filing_id`) REFERENCES `filings` (`filing_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
