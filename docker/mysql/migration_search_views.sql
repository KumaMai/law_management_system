-- ============================================================
-- Migration: Search Views v1.0
-- สร้าง Views สำหรับระบบค้นหาในแต่ละหน้า
-- ใช้กับ DB ที่มีข้อมูลอยู่แล้ว (ไม่กระทบข้อมูลเดิม)
-- ============================================================

USE law_system;

-- ============================================================
-- VIEW 1: v_cases
-- รวม case_requests + contracts + ชื่อลูกความ/ทนาย
-- ใช้โดย: case_requests.php, contracts.php, my_cases.php, payments.php
-- ============================================================
DROP VIEW IF EXISTS v_cases;
CREATE VIEW v_cases AS
SELECT
    cr.request_id,
    cr.office_id,
    cr.client_id,
    cr.lawyer_id,
    cr.detail                              AS case_detail,
    cr.request_date,
    cr.expire_date,
    cr.status                              AS request_status,
    cr.reject_reason,
    cr.created_at                          AS request_created_at,

    c.contract_id,
    c.contract_date,
    c.fee_amount,
    c.status                               AS contract_status,
    c.contract_review_status,
    c.payment_status,
    c.lawyer_note,
    c.client_response,
    c.proposed_fee,
    c.created_at                           AS contract_created_at,

    cp.fname                               AS client_fname,
    cp.lname                               AS client_lname,
    cp.citizen_id                          AS client_citizen_id,
    cp.phone                               AS client_phone,
    cp.user_id                             AS client_user_id,
    CONCAT(cp.fname, ' ', cp.lname)        AS client_name,

    lp.fname                               AS lawyer_fname,
    lp.lname                               AS lawyer_lname,
    lp.license_no                          AS lawyer_license,
    lp.specialization                      AS lawyer_specialization,
    lp.phone                               AS lawyer_phone,
    lp.user_id                             AS lawyer_user_id,
    lp.qr_code_file                        AS lawyer_qr,
    CONCAT(lp.fname, ' ', lp.lname)        AS lawyer_name

FROM case_requests cr
LEFT JOIN contracts c          ON c.request_id  = cr.request_id
LEFT JOIN client_profiles cp   ON cp.client_id  = cr.client_id
LEFT JOIN lawyer_profiles lp   ON lp.lawyer_id  = cr.lawyer_id;


-- ============================================================
-- VIEW 2: v_filings
-- รวม filings + courts + ข้อมูลสัญญา/คู่ความ
-- ใช้โดย: filings.php
-- ============================================================
DROP VIEW IF EXISTS v_filings;
CREATE VIEW v_filings AS
SELECT
    f.filing_id,
    f.contract_id,
    f.court_id,
    f.charge,
    f.scheduled_filing_date,
    f.created_at                           AS filing_created_at,

    COALESCE(ct.court_name, '—')           AS court_display,
    ct.court_type,
    ct.location                            AS court_location,

    cr.request_id,
    cr.office_id,
    cr.client_id,
    cr.lawyer_id,
    cr.detail                              AS case_detail,

    con.contract_date,
    con.fee_amount,

    CONCAT(cp.fname, ' ', cp.lname)        AS client_name,
    CONCAT(lp.fname, ' ', lp.lname)        AS lawyer_name

FROM filings f
LEFT JOIN courts ct            ON f.court_id     = ct.court_id
JOIN contracts con             ON f.contract_id  = con.contract_id
JOIN case_requests cr          ON con.request_id = cr.request_id
LEFT JOIN client_profiles cp   ON cr.client_id   = cp.client_id
LEFT JOIN lawyer_profiles lp   ON cr.lawyer_id   = lp.lawyer_id;


-- ============================================================
-- VIEW 3: v_hearings
-- รวม court_hearings + filings + courts + ชื่อคู่ความ
-- ใช้โดย: hearings.php
-- ============================================================
DROP VIEW IF EXISTS v_hearings;
CREATE VIEW v_hearings AS
SELECT
    ch.hearing_id,
    ch.filing_id,
    ch.case_number,
    ch.hearing_date,
    ch.hearing_time,
    ch.court_room,
    ch.hearing_round,
    ch.status,
    ch.reminder_sent,
    ch.notes,
    ch.updated_at,
    ch.created_at,

    f.contract_id,
    f.charge,
    f.court_id,

    ct.court_name,

    cr.request_id,
    cr.office_id,
    cr.client_id,
    cr.lawyer_id,

    CONCAT(cp.fname, ' ', cp.lname)        AS client_name,
    CONCAT(lp.fname, ' ', lp.lname)        AS lawyer_name

FROM court_hearings ch
JOIN filings f                 ON ch.filing_id    = f.filing_id
JOIN contracts con             ON f.contract_id   = con.contract_id
JOIN case_requests cr          ON con.request_id  = cr.request_id
JOIN client_profiles cp        ON cr.client_id    = cp.client_id
JOIN courts ct                 ON f.court_id      = ct.court_id
LEFT JOIN lawyer_profiles lp   ON cr.lawyer_id    = lp.lawyer_id;


-- ============================================================
-- VIEW 4: v_clients
-- รวม client_profiles + users
-- ใช้โดย: clients.php
-- ============================================================
DROP VIEW IF EXISTS v_clients;
CREATE VIEW v_clients AS
SELECT
    cp.client_id,
    cp.user_id,
    cp.fname,
    cp.lname,
    cp.citizen_id,
    cp.phone,
    cp.address,
    cp.profile_photo,
    cp.created_at,
    u.email,
    u.username,
    u.status                               AS user_status,
    u.office_id,
    CONCAT(cp.fname, ' ', cp.lname)        AS full_name
FROM client_profiles cp
JOIN users u ON cp.user_id = u.user_id;


-- ============================================================
-- VIEW 5: v_lawyers
-- รวม lawyer_profiles + users + จำนวนคดี
-- ใช้โดย: lawyers.php
-- ============================================================
DROP VIEW IF EXISTS v_lawyers;
CREATE VIEW v_lawyers AS
SELECT
    lp.lawyer_id,
    lp.user_id,
    lp.fname,
    lp.lname,
    lp.license_no,
    lp.license_exp,
    lp.specialization,
    lp.phone,
    lp.status                              AS lawyer_status,
    lp.qr_code_file,
    lp.profile_photo,
    lp.bio,
    lp.experience_yr,
    lp.education,
    lp.avg_rating,
    lp.created_at,
    u.email,
    u.username,
    u.status                               AS user_status,
    u.office_id,
    CONCAT(lp.fname, ' ', lp.lname)        AS full_name,
    (SELECT COUNT(DISTINCT cr.request_id)
     FROM case_requests cr
     WHERE cr.lawyer_id = lp.lawyer_id
       AND cr.status = 'approved')         AS case_count
FROM lawyer_profiles lp
JOIN users u ON lp.user_id = u.user_id;
