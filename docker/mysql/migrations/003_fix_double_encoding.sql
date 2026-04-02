-- ============================================================
-- Migration 003: Fix Double-Encoded UTF-8 Data
-- ปัญหา: Docker MySQL init โหลด init.sql ด้วย latin1 client charset
--        ทำให้ข้อมูล UTF-8 ถูกเก็บแบบ double-encoded
-- แก้: CONVERT(CAST(CONVERT(col USING latin1) AS BINARY) USING utf8mb4)
-- ============================================================

USE law_system;
SET NAMES utf8mb4;

-- ── offices ──
UPDATE `offices` SET `office_name` = CONVERT(CAST(CONVERT(`office_name` USING latin1) AS BINARY) USING utf8mb4) WHERE `office_name` IS NOT NULL AND `office_name` != '';
UPDATE `offices` SET `office_code` = CONVERT(CAST(CONVERT(`office_code` USING latin1) AS BINARY) USING utf8mb4) WHERE `office_code` IS NOT NULL AND `office_code` != '';
UPDATE `offices` SET `address` = CONVERT(CAST(CONVERT(`address` USING latin1) AS BINARY) USING utf8mb4) WHERE `address` IS NOT NULL AND `address` != '';
UPDATE `offices` SET `phone` = CONVERT(CAST(CONVERT(`phone` USING latin1) AS BINARY) USING utf8mb4) WHERE `phone` IS NOT NULL AND `phone` != '';
UPDATE `offices` SET `email` = CONVERT(CAST(CONVERT(`email` USING latin1) AS BINARY) USING utf8mb4) WHERE `email` IS NOT NULL AND `email` != '';
UPDATE `offices` SET `logo_path` = CONVERT(CAST(CONVERT(`logo_path` USING latin1) AS BINARY) USING utf8mb4) WHERE `logo_path` IS NOT NULL AND `logo_path` != '';
UPDATE `offices` SET `status` = CONVERT(CAST(CONVERT(`status` USING latin1) AS BINARY) USING utf8mb4) WHERE `status` IS NOT NULL AND `status` != '';

-- ── users ──
UPDATE `users` SET `username` = CONVERT(CAST(CONVERT(`username` USING latin1) AS BINARY) USING utf8mb4) WHERE `username` IS NOT NULL AND `username` != '';
UPDATE `users` SET `email` = CONVERT(CAST(CONVERT(`email` USING latin1) AS BINARY) USING utf8mb4) WHERE `email` IS NOT NULL AND `email` != '';
UPDATE `users` SET `password_hash` = CONVERT(CAST(CONVERT(`password_hash` USING latin1) AS BINARY) USING utf8mb4) WHERE `password_hash` IS NOT NULL AND `password_hash` != '';
UPDATE `users` SET `status` = CONVERT(CAST(CONVERT(`status` USING latin1) AS BINARY) USING utf8mb4) WHERE `status` IS NOT NULL AND `status` != '';

-- ── roles ──
UPDATE `roles` SET `role_name` = CONVERT(CAST(CONVERT(`role_name` USING latin1) AS BINARY) USING utf8mb4) WHERE `role_name` IS NOT NULL AND `role_name` != '';

-- ── client_profiles ──
UPDATE `client_profiles` SET `fname` = CONVERT(CAST(CONVERT(`fname` USING latin1) AS BINARY) USING utf8mb4) WHERE `fname` IS NOT NULL AND `fname` != '';
UPDATE `client_profiles` SET `lname` = CONVERT(CAST(CONVERT(`lname` USING latin1) AS BINARY) USING utf8mb4) WHERE `lname` IS NOT NULL AND `lname` != '';
UPDATE `client_profiles` SET `citizen_id` = CONVERT(CAST(CONVERT(`citizen_id` USING latin1) AS BINARY) USING utf8mb4) WHERE `citizen_id` IS NOT NULL AND `citizen_id` != '';
UPDATE `client_profiles` SET `phone` = CONVERT(CAST(CONVERT(`phone` USING latin1) AS BINARY) USING utf8mb4) WHERE `phone` IS NOT NULL AND `phone` != '';
UPDATE `client_profiles` SET `address` = CONVERT(CAST(CONVERT(`address` USING latin1) AS BINARY) USING utf8mb4) WHERE `address` IS NOT NULL AND `address` != '';
UPDATE `client_profiles` SET `profile_photo` = CONVERT(CAST(CONVERT(`profile_photo` USING latin1) AS BINARY) USING utf8mb4) WHERE `profile_photo` IS NOT NULL AND `profile_photo` != '';

-- ── lawyer_profiles ──
UPDATE `lawyer_profiles` SET `fname` = CONVERT(CAST(CONVERT(`fname` USING latin1) AS BINARY) USING utf8mb4) WHERE `fname` IS NOT NULL AND `fname` != '';
UPDATE `lawyer_profiles` SET `lname` = CONVERT(CAST(CONVERT(`lname` USING latin1) AS BINARY) USING utf8mb4) WHERE `lname` IS NOT NULL AND `lname` != '';
UPDATE `lawyer_profiles` SET `license_no` = CONVERT(CAST(CONVERT(`license_no` USING latin1) AS BINARY) USING utf8mb4) WHERE `license_no` IS NOT NULL AND `license_no` != '';
UPDATE `lawyer_profiles` SET `specialization` = CONVERT(CAST(CONVERT(`specialization` USING latin1) AS BINARY) USING utf8mb4) WHERE `specialization` IS NOT NULL AND `specialization` != '';
UPDATE `lawyer_profiles` SET `phone` = CONVERT(CAST(CONVERT(`phone` USING latin1) AS BINARY) USING utf8mb4) WHERE `phone` IS NOT NULL AND `phone` != '';
UPDATE `lawyer_profiles` SET `status` = CONVERT(CAST(CONVERT(`status` USING latin1) AS BINARY) USING utf8mb4) WHERE `status` IS NOT NULL AND `status` != '';
UPDATE `lawyer_profiles` SET `qr_code_file` = CONVERT(CAST(CONVERT(`qr_code_file` USING latin1) AS BINARY) USING utf8mb4) WHERE `qr_code_file` IS NOT NULL AND `qr_code_file` != '';
UPDATE `lawyer_profiles` SET `profile_photo` = CONVERT(CAST(CONVERT(`profile_photo` USING latin1) AS BINARY) USING utf8mb4) WHERE `profile_photo` IS NOT NULL AND `profile_photo` != '';
UPDATE `lawyer_profiles` SET `bio` = CONVERT(CAST(CONVERT(`bio` USING latin1) AS BINARY) USING utf8mb4) WHERE `bio` IS NOT NULL AND `bio` != '';
UPDATE `lawyer_profiles` SET `education` = CONVERT(CAST(CONVERT(`education` USING latin1) AS BINARY) USING utf8mb4) WHERE `education` IS NOT NULL AND `education` != '';

-- ── courts ──
UPDATE `courts` SET `court_name` = CONVERT(CAST(CONVERT(`court_name` USING latin1) AS BINARY) USING utf8mb4) WHERE `court_name` IS NOT NULL AND `court_name` != '';
UPDATE `courts` SET `court_type` = CONVERT(CAST(CONVERT(`court_type` USING latin1) AS BINARY) USING utf8mb4) WHERE `court_type` IS NOT NULL AND `court_type` != '';
UPDATE `courts` SET `location` = CONVERT(CAST(CONVERT(`location` USING latin1) AS BINARY) USING utf8mb4) WHERE `location` IS NOT NULL AND `location` != '';

-- ── case_requests ──
UPDATE `case_requests` SET `detail` = CONVERT(CAST(CONVERT(`detail` USING latin1) AS BINARY) USING utf8mb4) WHERE `detail` IS NOT NULL AND `detail` != '';
UPDATE `case_requests` SET `status` = CONVERT(CAST(CONVERT(`status` USING latin1) AS BINARY) USING utf8mb4) WHERE `status` IS NOT NULL AND `status` != '';
UPDATE `case_requests` SET `reject_reason` = CONVERT(CAST(CONVERT(`reject_reason` USING latin1) AS BINARY) USING utf8mb4) WHERE `reject_reason` IS NOT NULL AND `reject_reason` != '';

-- ── contracts ──
UPDATE `contracts` SET `status` = CONVERT(CAST(CONVERT(`status` USING latin1) AS BINARY) USING utf8mb4) WHERE `status` IS NOT NULL AND `status` != '';
UPDATE `contracts` SET `contract_review_status` = CONVERT(CAST(CONVERT(`contract_review_status` USING latin1) AS BINARY) USING utf8mb4) WHERE `contract_review_status` IS NOT NULL AND `contract_review_status` != '';
UPDATE `contracts` SET `payment_status` = CONVERT(CAST(CONVERT(`payment_status` USING latin1) AS BINARY) USING utf8mb4) WHERE `payment_status` IS NOT NULL AND `payment_status` != '';
UPDATE `contracts` SET `lawyer_note` = CONVERT(CAST(CONVERT(`lawyer_note` USING latin1) AS BINARY) USING utf8mb4) WHERE `lawyer_note` IS NOT NULL AND `lawyer_note` != '';
UPDATE `contracts` SET `client_response` = CONVERT(CAST(CONVERT(`client_response` USING latin1) AS BINARY) USING utf8mb4) WHERE `client_response` IS NOT NULL AND `client_response` != '';

-- ── filings ──
UPDATE `filings` SET `charge` = CONVERT(CAST(CONVERT(`charge` USING latin1) AS BINARY) USING utf8mb4) WHERE `charge` IS NOT NULL AND `charge` != '';

-- ── court_hearings ──
UPDATE `court_hearings` SET `case_number` = CONVERT(CAST(CONVERT(`case_number` USING latin1) AS BINARY) USING utf8mb4) WHERE `case_number` IS NOT NULL AND `case_number` != '';
UPDATE `court_hearings` SET `court_room` = CONVERT(CAST(CONVERT(`court_room` USING latin1) AS BINARY) USING utf8mb4) WHERE `court_room` IS NOT NULL AND `court_room` != '';
UPDATE `court_hearings` SET `status` = CONVERT(CAST(CONVERT(`status` USING latin1) AS BINARY) USING utf8mb4) WHERE `status` IS NOT NULL AND `status` != '';
UPDATE `court_hearings` SET `notes` = CONVERT(CAST(CONVERT(`notes` USING latin1) AS BINARY) USING utf8mb4) WHERE `notes` IS NOT NULL AND `notes` != '';

-- ── announcements ──
UPDATE `announcements` SET `title` = CONVERT(CAST(CONVERT(`title` USING latin1) AS BINARY) USING utf8mb4) WHERE `title` IS NOT NULL AND `title` != '';
UPDATE `announcements` SET `body` = CONVERT(CAST(CONVERT(`body` USING latin1) AS BINARY) USING utf8mb4) WHERE `body` IS NOT NULL AND `body` != '';

-- ── payments ──
UPDATE `payments` SET `slip_file` = CONVERT(CAST(CONVERT(`slip_file` USING latin1) AS BINARY) USING utf8mb4) WHERE `slip_file` IS NOT NULL AND `slip_file` != '';
UPDATE `payments` SET `note` = CONVERT(CAST(CONVERT(`note` USING latin1) AS BINARY) USING utf8mb4) WHERE `note` IS NOT NULL AND `note` != '';
UPDATE `payments` SET `installment_note` = CONVERT(CAST(CONVERT(`installment_note` USING latin1) AS BINARY) USING utf8mb4) WHERE `installment_note` IS NOT NULL AND `installment_note` != '';

-- ── notifications ──
UPDATE `notifications` SET `type` = CONVERT(CAST(CONVERT(`type` USING latin1) AS BINARY) USING utf8mb4) WHERE `type` IS NOT NULL AND `type` != '';
UPDATE `notifications` SET `title` = CONVERT(CAST(CONVERT(`title` USING latin1) AS BINARY) USING utf8mb4) WHERE `title` IS NOT NULL AND `title` != '';
UPDATE `notifications` SET `body` = CONVERT(CAST(CONVERT(`body` USING latin1) AS BINARY) USING utf8mb4) WHERE `body` IS NOT NULL AND `body` != '';
UPDATE `notifications` SET `link` = CONVERT(CAST(CONVERT(`link` USING latin1) AS BINARY) USING utf8mb4) WHERE `link` IS NOT NULL AND `link` != '';
UPDATE `notifications` SET `ref_type` = CONVERT(CAST(CONVERT(`ref_type` USING latin1) AS BINARY) USING utf8mb4) WHERE `ref_type` IS NOT NULL AND `ref_type` != '';

-- ── chat_messages ──
UPDATE `chat_messages` SET `message` = CONVERT(CAST(CONVERT(`message` USING latin1) AS BINARY) USING utf8mb4) WHERE `message` IS NOT NULL AND `message` != '';

-- ── activity_logs ──
UPDATE `activity_logs` SET `action` = CONVERT(CAST(CONVERT(`action` USING latin1) AS BINARY) USING utf8mb4) WHERE `action` IS NOT NULL AND `action` != '';
UPDATE `activity_logs` SET `entity_type` = CONVERT(CAST(CONVERT(`entity_type` USING latin1) AS BINARY) USING utf8mb4) WHERE `entity_type` IS NOT NULL AND `entity_type` != '';
UPDATE `activity_logs` SET `description` = CONVERT(CAST(CONVERT(`description` USING latin1) AS BINARY) USING utf8mb4) WHERE `description` IS NOT NULL AND `description` != '';
UPDATE `activity_logs` SET `ip_address` = CONVERT(CAST(CONVERT(`ip_address` USING latin1) AS BINARY) USING utf8mb4) WHERE `ip_address` IS NOT NULL AND `ip_address` != '';
UPDATE `activity_logs` SET `user_agent` = CONVERT(CAST(CONVERT(`user_agent` USING latin1) AS BINARY) USING utf8mb4) WHERE `user_agent` IS NOT NULL AND `user_agent` != '';

-- ── case_documents ──
UPDATE `case_documents` SET `document_type` = CONVERT(CAST(CONVERT(`document_type` USING latin1) AS BINARY) USING utf8mb4) WHERE `document_type` IS NOT NULL AND `document_type` != '';
UPDATE `case_documents` SET `file_name` = CONVERT(CAST(CONVERT(`file_name` USING latin1) AS BINARY) USING utf8mb4) WHERE `file_name` IS NOT NULL AND `file_name` != '';
UPDATE `case_documents` SET `file_path` = CONVERT(CAST(CONVERT(`file_path` USING latin1) AS BINARY) USING utf8mb4) WHERE `file_path` IS NOT NULL AND `file_path` != '';
UPDATE `case_documents` SET `visibility` = CONVERT(CAST(CONVERT(`visibility` USING latin1) AS BINARY) USING utf8mb4) WHERE `visibility` IS NOT NULL AND `visibility` != '';

-- ── case_summary_docs ──
UPDATE `case_summary_docs` SET `file_name` = CONVERT(CAST(CONVERT(`file_name` USING latin1) AS BINARY) USING utf8mb4) WHERE `file_name` IS NOT NULL AND `file_name` != '';
UPDATE `case_summary_docs` SET `file_path` = CONVERT(CAST(CONVERT(`file_path` USING latin1) AS BINARY) USING utf8mb4) WHERE `file_path` IS NOT NULL AND `file_path` != '';
UPDATE `case_summary_docs` SET `doc_label` = CONVERT(CAST(CONVERT(`doc_label` USING latin1) AS BINARY) USING utf8mb4) WHERE `doc_label` IS NOT NULL AND `doc_label` != '';
UPDATE `case_summary_docs` SET `doc_type` = CONVERT(CAST(CONVERT(`doc_type` USING latin1) AS BINARY) USING utf8mb4) WHERE `doc_type` IS NOT NULL AND `doc_type` != '';

-- ── client_sign_docs ──
UPDATE `client_sign_docs` SET `doc_type` = CONVERT(CAST(CONVERT(`doc_type` USING latin1) AS BINARY) USING utf8mb4) WHERE `doc_type` IS NOT NULL AND `doc_type` != '';
UPDATE `client_sign_docs` SET `doc_title` = CONVERT(CAST(CONVERT(`doc_title` USING latin1) AS BINARY) USING utf8mb4) WHERE `doc_title` IS NOT NULL AND `doc_title` != '';
UPDATE `client_sign_docs` SET `description` = CONVERT(CAST(CONVERT(`description` USING latin1) AS BINARY) USING utf8mb4) WHERE `description` IS NOT NULL AND `description` != '';
UPDATE `client_sign_docs` SET `file_path` = CONVERT(CAST(CONVERT(`file_path` USING latin1) AS BINARY) USING utf8mb4) WHERE `file_path` IS NOT NULL AND `file_path` != '';
UPDATE `client_sign_docs` SET `client_note` = CONVERT(CAST(CONVERT(`client_note` USING latin1) AS BINARY) USING utf8mb4) WHERE `client_note` IS NOT NULL AND `client_note` != '';
UPDATE `client_sign_docs` SET `lawyer_note` = CONVERT(CAST(CONVERT(`lawyer_note` USING latin1) AS BINARY) USING utf8mb4) WHERE `lawyer_note` IS NOT NULL AND `lawyer_note` != '';
UPDATE `client_sign_docs` SET `signed_file` = CONVERT(CAST(CONVERT(`signed_file` USING latin1) AS BINARY) USING utf8mb4) WHERE `signed_file` IS NOT NULL AND `signed_file` != '';

-- ── lawyer_reviews ──
UPDATE `lawyer_reviews` SET `comment` = CONVERT(CAST(CONVERT(`comment` USING latin1) AS BINARY) USING utf8mb4) WHERE `comment` IS NOT NULL AND `comment` != '';

-- ── postponement_requests ──
UPDATE `postponement_requests` SET `request_type` = CONVERT(CAST(CONVERT(`request_type` USING latin1) AS BINARY) USING utf8mb4) WHERE `request_type` IS NOT NULL AND `request_type` != '';
UPDATE `postponement_requests` SET `reason` = CONVERT(CAST(CONVERT(`reason` USING latin1) AS BINARY) USING utf8mb4) WHERE `reason` IS NOT NULL AND `reason` != '';
UPDATE `postponement_requests` SET `status` = CONVERT(CAST(CONVERT(`status` USING latin1) AS BINARY) USING utf8mb4) WHERE `status` IS NOT NULL AND `status` != '';
UPDATE `postponement_requests` SET `lawyer_note` = CONVERT(CAST(CONVERT(`lawyer_note` USING latin1) AS BINARY) USING utf8mb4) WHERE `lawyer_note` IS NOT NULL AND `lawyer_note` != '';

-- ── profile_change_requests ──
UPDATE `profile_change_requests` SET `req_fname` = CONVERT(CAST(CONVERT(`req_fname` USING latin1) AS BINARY) USING utf8mb4) WHERE `req_fname` IS NOT NULL AND `req_fname` != '';
UPDATE `profile_change_requests` SET `req_lname` = CONVERT(CAST(CONVERT(`req_lname` USING latin1) AS BINARY) USING utf8mb4) WHERE `req_lname` IS NOT NULL AND `req_lname` != '';
UPDATE `profile_change_requests` SET `req_phone` = CONVERT(CAST(CONVERT(`req_phone` USING latin1) AS BINARY) USING utf8mb4) WHERE `req_phone` IS NOT NULL AND `req_phone` != '';
UPDATE `profile_change_requests` SET `req_address` = CONVERT(CAST(CONVERT(`req_address` USING latin1) AS BINARY) USING utf8mb4) WHERE `req_address` IS NOT NULL AND `req_address` != '';
UPDATE `profile_change_requests` SET `req_citizen_id` = CONVERT(CAST(CONVERT(`req_citizen_id` USING latin1) AS BINARY) USING utf8mb4) WHERE `req_citizen_id` IS NOT NULL AND `req_citizen_id` != '';
UPDATE `profile_change_requests` SET `admin_note` = CONVERT(CAST(CONVERT(`admin_note` USING latin1) AS BINARY) USING utf8mb4) WHERE `admin_note` IS NOT NULL AND `admin_note` != '';

-- ── verdict_appointments ──
UPDATE `verdict_appointments` SET `note` = CONVERT(CAST(CONVERT(`note` USING latin1) AS BINARY) USING utf8mb4) WHERE `note` IS NOT NULL AND `note` != '';
UPDATE `verdict_appointments` SET `status` = CONVERT(CAST(CONVERT(`status` USING latin1) AS BINARY) USING utf8mb4) WHERE `status` IS NOT NULL AND `status` != '';

-- ── verdicts ──
UPDATE `verdicts` SET `result` = CONVERT(CAST(CONVERT(`result` USING latin1) AS BINARY) USING utf8mb4) WHERE `result` IS NOT NULL AND `result` != '';
UPDATE `verdicts` SET `details` = CONVERT(CAST(CONVERT(`details` USING latin1) AS BINARY) USING utf8mb4) WHERE `details` IS NOT NULL AND `details` != '';
