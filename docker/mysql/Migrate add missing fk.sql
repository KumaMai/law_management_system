-- ============================================================
-- Migration: เพิ่ม Foreign Keys ที่ยังขาดอยู่
-- รันใน phpMyAdmin → law_system → SQL tab
-- ============================================================

USE law_system;

-- ============================================================
-- FK ที่มีอยู่แล้ว (ไม่ต้องรันซ้ำ) สำหรับอ้างอิง:
-- ============================================================
-- case_documents   → contracts, filings, users
-- case_requests    → offices, client_profiles, lawyer_profiles
-- client_profiles  → users
-- contracts        → case_requests
-- court_hearings   → filings
-- filings          → contracts, courts
-- lawyer_profiles  → users
-- users            → offices, roles
-- verdicts         → filings
-- ============================================================

-- ============================================================
-- FK ที่ยังขาด — เพิ่มทั้งหมดด้านล่าง
-- ============================================================

-- 1. announcements → offices (created_by → users)
ALTER TABLE `announcements`
    ADD CONSTRAINT `announcements_ibfk_1`
        FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`)
        ON DELETE CASCADE;

ALTER TABLE `announcements`
    ADD CONSTRAINT `announcements_ibfk_2`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL;

-- 2. case_summary_docs → case_requests, users
ALTER TABLE `case_summary_docs`
    ADD CONSTRAINT `case_summary_docs_ibfk_1`
        FOREIGN KEY (`request_id`) REFERENCES `case_requests` (`request_id`)
        ON DELETE CASCADE;

ALTER TABLE `case_summary_docs`
    ADD CONSTRAINT `case_summary_docs_ibfk_2`
        FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL;

-- 3. client_sign_docs → case_requests, offices, users
ALTER TABLE `client_sign_docs`
    ADD CONSTRAINT `client_sign_docs_ibfk_1`
        FOREIGN KEY (`request_id`) REFERENCES `case_requests` (`request_id`)
        ON DELETE CASCADE;

ALTER TABLE `client_sign_docs`
    ADD CONSTRAINT `client_sign_docs_ibfk_2`
        FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`)
        ON DELETE CASCADE;

ALTER TABLE `client_sign_docs`
    ADD CONSTRAINT `client_sign_docs_ibfk_3`
        FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`)
        ON DELETE CASCADE;

-- 4. lawyer_reviews → lawyer_profiles, client_profiles
ALTER TABLE `lawyer_reviews`
    ADD CONSTRAINT `lawyer_reviews_ibfk_1`
        FOREIGN KEY (`lawyer_id`) REFERENCES `lawyer_profiles` (`lawyer_id`)
        ON DELETE CASCADE;

ALTER TABLE `lawyer_reviews`
    ADD CONSTRAINT `lawyer_reviews_ibfk_2`
        FOREIGN KEY (`client_id`) REFERENCES `client_profiles` (`client_id`)
        ON DELETE CASCADE;

-- 5. payments → contracts, users (paid_by, confirmed_by)
ALTER TABLE `payments`
    ADD CONSTRAINT `payments_ibfk_1`
        FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`contract_id`)
        ON DELETE CASCADE;

ALTER TABLE `payments`
    ADD CONSTRAINT `payments_ibfk_2`
        FOREIGN KEY (`paid_by`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL;

ALTER TABLE `payments`
    ADD CONSTRAINT `payments_ibfk_3`
        FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL;

-- 6. profile_change_requests → users, offices, users (reviewed_by)
ALTER TABLE `profile_change_requests`
    ADD CONSTRAINT `profile_change_requests_ibfk_1`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
        ON DELETE CASCADE;

ALTER TABLE `profile_change_requests`
    ADD CONSTRAINT `profile_change_requests_ibfk_2`
        FOREIGN KEY (`office_id`) REFERENCES `offices` (`office_id`)
        ON DELETE CASCADE;

ALTER TABLE `profile_change_requests`
    ADD CONSTRAINT `profile_change_requests_ibfk_3`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL;