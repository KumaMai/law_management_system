-- ============================================================
-- Migration: Global Search Stored Procedure v1.0
-- ค้นหาข้ามทุกตาราง สำหรับ search bar หลักที่ header
-- ============================================================

USE law_system;

DROP PROCEDURE IF EXISTS global_search;

DELIMITER //
CREATE PROCEDURE global_search(
    IN p_office_id INT,
    IN p_user_id   INT,
    IN p_role      VARCHAR(20),
    IN p_keyword   VARCHAR(255),
    IN p_max       INT
)
BEGIN
    DECLARE v_lawyer_id INT DEFAULT NULL;
    DECLARE v_client_id INT DEFAULT NULL;
    DECLARE v_kw VARCHAR(260);

    SET v_kw  = CONCAT('%', p_keyword, '%');
    SET p_max = IFNULL(p_max, 30);

    -- หา profile ID ของ user
    IF p_role = 'lawyer' THEN
        SELECT lawyer_id INTO v_lawyer_id
        FROM lawyer_profiles WHERE user_id = p_user_id LIMIT 1;
    ELSEIF p_role = 'client' THEN
        SELECT client_id INTO v_client_id
        FROM client_profiles WHERE user_id = p_user_id LIMIT 1;
    END IF;

    -- ============ UNION ALL ข้ามหลายตาราง ============

    (-- 1. ค้นลูกความ (Admin + Lawyer)
     SELECT 'client'                              AS entity_type,
            cp.client_id                           AS entity_id,
            CONCAT(cp.fname, ' ', cp.lname)        AS title,
            COALESCE(cp.citizen_id, cp.phone, '')   AS subtitle,
            CONCAT('/pages/clients.php?search=', cp.fname) AS link
     FROM client_profiles cp
     JOIN users u ON cp.user_id = u.user_id
     WHERE u.office_id = p_office_id
       AND p_role IN ('admin', 'lawyer')
       AND (cp.fname LIKE v_kw OR cp.lname LIKE v_kw
            OR CONCAT(cp.fname, ' ', cp.lname) LIKE v_kw
            OR cp.citizen_id LIKE v_kw OR cp.phone LIKE v_kw
            OR u.email LIKE v_kw)
     LIMIT 5)

    UNION ALL

    (-- 2. ค้นทนายความ (Admin + Client)
     SELECT 'lawyer', lp.lawyer_id,
            CONCAT(lp.fname, ' ', lp.lname),
            COALESCE(lp.license_no, lp.specialization, ''),
            CONCAT('/pages/lawyers.php?search=', lp.fname)
     FROM lawyer_profiles lp
     JOIN users u ON lp.user_id = u.user_id
     WHERE u.office_id = p_office_id
       AND p_role IN ('admin', 'client')
       AND (lp.fname LIKE v_kw OR lp.lname LIKE v_kw
            OR CONCAT(lp.fname, ' ', lp.lname) LIKE v_kw
            OR lp.license_no LIKE v_kw OR lp.specialization LIKE v_kw
            OR u.email LIKE v_kw)
     LIMIT 5)

    UNION ALL

    (-- 3. ค้นคดี/สัญญา (ตาม role)
     SELECT 'contract', c.contract_id,
            CONCAT('สัญญา #', c.contract_id),
            CONCAT(COALESCE(cp.fname,''), ' ', COALESCE(cp.lname,''),
                   ' — ', COALESCE(lp.fname,''), ' ', COALESCE(lp.lname,'')),
            CONCAT('/pages/contracts.php?search=', COALESCE(cp.fname,''))
     FROM contracts c
     JOIN case_requests cr ON c.request_id = cr.request_id
     LEFT JOIN client_profiles cp ON cr.client_id = cp.client_id
     LEFT JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
     WHERE cr.office_id = p_office_id
       AND (p_role = 'admin'
            OR (p_role = 'lawyer'  AND cr.lawyer_id = v_lawyer_id)
            OR (p_role = 'client'  AND cr.client_id = v_client_id))
       AND (cr.detail LIKE v_kw
            OR CONCAT(cp.fname, ' ', cp.lname) LIKE v_kw
            OR CONCAT(lp.fname, ' ', lp.lname) LIKE v_kw
            OR CAST(c.fee_amount AS CHAR) LIKE v_kw
            OR c.lawyer_note LIKE v_kw)
     LIMIT 5)

    UNION ALL

    (-- 4. ค้นนัดยื่นฟ้อง (Admin + Lawyer)
     SELECT 'filing', f.filing_id,
            CONCAT('ยื่นฟ้อง — ', COALESCE(f.charge, '(ไม่ระบุข้อหา)')),
            CONCAT(COALESCE(ct.court_name, ''), ' ', COALESCE(f.scheduled_filing_date, '')),
            CONCAT('/pages/filings.php?search=', COALESCE(f.charge,''))
     FROM filings f
     LEFT JOIN courts ct ON f.court_id = ct.court_id
     JOIN contracts c ON f.contract_id = c.contract_id
     JOIN case_requests cr ON c.request_id = cr.request_id
     WHERE cr.office_id = p_office_id
       AND (p_role IN ('admin', 'lawyer'))
       AND (f.charge LIKE v_kw
            OR ct.court_name LIKE v_kw
            OR CAST(f.scheduled_filing_date AS CHAR) LIKE v_kw)
     LIMIT 5)

    UNION ALL

    (-- 5. ค้นนัดขึ้นศาล (Admin + Lawyer)
     SELECT 'hearing', ch.hearing_id,
            CONCAT('นัดศาล ', COALESCE(ch.hearing_date,''), ' ครั้งที่ ', COALESCE(ch.hearing_round,'')),
            CONCAT(COALESCE(ct.court_name,''), ' ห้อง ', COALESCE(ch.court_room,'')),
            CONCAT('/pages/hearings.php?search=', COALESCE(ch.case_number,''))
     FROM court_hearings ch
     JOIN filings f ON ch.filing_id = f.filing_id
     JOIN contracts c ON f.contract_id = c.contract_id
     JOIN case_requests cr ON c.request_id = cr.request_id
     JOIN courts ct ON f.court_id = ct.court_id
     WHERE cr.office_id = p_office_id
       AND (p_role IN ('admin', 'lawyer'))
       AND (ch.case_number LIKE v_kw
            OR ct.court_name LIKE v_kw
            OR ch.court_room LIKE v_kw
            OR ch.notes LIKE v_kw
            OR f.charge LIKE v_kw)
     LIMIT 5)

    UNION ALL

    (-- 6. ค้นศาล (Admin + Lawyer)
     SELECT 'court', ct.court_id,
            ct.court_name,
            CONCAT(COALESCE(ct.court_type,''), ' — ', COALESCE(ct.location,'')),
            CONCAT('/pages/courts.php')
     FROM courts ct
     WHERE p_role IN ('admin', 'lawyer')
       AND (ct.court_name LIKE v_kw
            OR ct.court_type LIKE v_kw
            OR ct.location LIKE v_kw)
     LIMIT 5)

    UNION ALL

    (-- 7. ค้นประกาศ (ทุก role)
     SELECT 'announcement', a.ann_id,
            a.title,
            LEFT(COALESCE(a.body,''), 60),
            '/pages/dashboard.php'
     FROM announcements a
     WHERE a.office_id = p_office_id
       AND (a.title LIKE v_kw OR a.body LIKE v_kw)
     LIMIT 3)

    LIMIT p_max;

END //
DELIMITER ;
