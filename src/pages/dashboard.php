<?php
// dashboard.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$officeId = $_SESSION['office_id'];
$userId   = $_SESSION['user_id'];

// ==============================
// Handle: ทนายอัปเดตโปรไฟล์
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    if ($role !== 'lawyer') { header('Location: /pages/dashboard.php'); exit; }
    csrf_verify();

    $fname          = trim($_POST['fname'] ?? '');
    $lname          = trim($_POST['lname'] ?? '');
    $bio            = trim($_POST['bio'] ?? '');
    $expYr          = (int)($_POST['experience_yr'] ?? 0);
    $education      = trim($_POST['education'] ?? '');
    $licenseNo      = trim($_POST['license_no'] ?? '');
    $licenseExp     = trim($_POST['license_exp'] ?? '') ?: null;
    $phone          = trim($_POST['phone'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');

    $lpRow = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lpRow->execute([$userId]);
    $lid = $lpRow->fetchColumn();

    $photoName = null;
    if (!empty($_FILES['profile_photo']['tmp_name']) && $_FILES['profile_photo']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png']) && $_FILES['profile_photo']['size'] <= 5*1024*1024) {
            $dir = '/var/www/html/uploads/lawyer_photos/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $photoName = 'lawyer_'.$lid.'_'.time().'.'.$ext;
            move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dir.$photoName);
        }
    }

    $sql    = "UPDATE lawyer_profiles SET fname=?, lname=?, bio=?, experience_yr=?, education=?, license_no=?, license_exp=?, phone=?, specialization=?";
    $params = [$fname, $lname, $bio, $expYr ?: null, $education, $licenseNo, $licenseExp, $phone, $specialization];
    if ($photoName) { $sql .= ", profile_photo=?"; $params[] = $photoName; }
    $sql .= " WHERE lawyer_id=?";
    $params[] = $lid;
    $pdo->prepare($sql)->execute($params);

    header('Location: /pages/dashboard.php?profile_saved=1'); exit;
}

// ==============================
// Handle: ลูกความอัปเดตโปรไฟล์
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_client') {
    if ($role !== 'client') { header('Location: /pages/dashboard.php'); exit; }
    csrf_verify();

    $cpRow = $pdo->prepare("SELECT client_id, citizen_id FROM client_profiles WHERE user_id=?");
    $cpRow->execute([$userId]);
    $cpData = $cpRow->fetch();
    $cid = $cpData['client_id'];

    // ===== รูปโปรไฟล์: อัปเดตทันที ไม่ต้องรอ admin =====
    $photoName = null;
    if (!empty($_FILES['client_photo']['tmp_name']) && $_FILES['client_photo']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['client_photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png']) && $_FILES['client_photo']['size'] <= 5*1024*1024) {
            $dir = '/var/www/html/uploads/client_photos/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $photoName = 'client_'.$cid.'_'.time().'.'.$ext;
            move_uploaded_file($_FILES['client_photo']['tmp_name'], $dir.$photoName);
            try { $pdo->prepare("UPDATE client_profiles SET profile_photo=? WHERE client_id=?")->execute([$photoName, $cid]); } catch(Exception $e){}
        }
    }

    // ===== ข้อมูลส่วนตัว: สร้างคำขอรอ admin อนุมัติ =====
    $fname     = trim($_POST['fname']      ?? '');
    $lname     = trim($_POST['lname']      ?? '');
    $phone     = trim($_POST['phone']      ?? '');
    $address   = trim($_POST['address']    ?? '');
    $citizenId = trim($_POST['citizen_id'] ?? '');

    // validate citizen_id ถ้ากรอก
    if ($citizenId && !preg_match('/^[0-9]{13}$/', $citizenId)) { $citizenId = ''; }

    // ส่งเฉพาะถ้ามีการเปลี่ยนแปลงอย่างน้อย 1 ฟิลด์
    try {
        $hasChange = $fname || $lname || $phone || $address || $citizenId;
        if ($hasChange) {
            // ยกเลิก pending เก่าก่อน (ถ้ามี)
            $pdo->prepare("UPDATE profile_change_requests SET status='rejected', admin_note='ยกเลิกโดยอัตโนมัติเพราะมีคำขอใหม่' WHERE user_id=? AND status='pending'")
                ->execute([$userId]);

            $pdo->prepare("INSERT INTO profile_change_requests (user_id, office_id, req_fname, req_lname, req_phone, req_address, req_citizen_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$userId, $officeId, $fname?:null, $lname?:null, $phone?:null, $address?:null, $citizenId?:null]);
        }
    } catch (Exception $e) { /* ตาราง profile_change_requests ยังไม่มี */ }

    $redirect = $photoName ? '?profile_photo_saved=1' : ($hasChange ? '?profile_req_sent=1' : '?profile_saved=1');
    header('Location: /pages/dashboard.php'.$redirect); exit;
}

// ==============================
// Handle: admin อนุมัติ/ปฏิเสธคำขอแก้ไข
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_profile_req') {
    if ($role !== 'admin') { header('Location: /pages/dashboard.php'); exit; }
    csrf_verify();

    $reqId    = (int)$_POST['req_id'];
    $decision = $_POST['decision'] === 'approved' ? 'approved' : 'rejected';
    $note     = trim($_POST['admin_note'] ?? '');

    try {
        $req = $pdo->prepare("SELECT * FROM profile_change_requests WHERE req_id=? AND office_id=? AND status='pending'");
        $req->execute([$reqId, $officeId]);
        $reqData = $req->fetch();

        if ($reqData && $decision === 'approved') {
            // อัปเดตข้อมูลจริงใน client_profiles
            $fields = []; $params = [];
            if ($reqData['req_fname'])      { $fields[] = 'fname=?';      $params[] = $reqData['req_fname']; }
            if ($reqData['req_lname'])      { $fields[] = 'lname=?';      $params[] = $reqData['req_lname']; }
            if ($reqData['req_phone'])      { $fields[] = 'phone=?';      $params[] = $reqData['req_phone']; }
            if ($reqData['req_address'])    { $fields[] = 'address=?';    $params[] = $reqData['req_address']; }
            if ($reqData['req_citizen_id']) { $fields[] = 'citizen_id=?'; $params[] = $reqData['req_citizen_id']; }
            if ($fields) {
                $params[] = $reqData['user_id'];
                $pdo->prepare("UPDATE client_profiles SET ".implode(',',$fields)." WHERE user_id=?")->execute($params);
            }
        }

        if ($reqData) {
            $pdo->prepare("UPDATE profile_change_requests SET status=?, admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE req_id=?")
                ->execute([$decision, $note, $userId, $reqId]);
        }
    } catch (Exception $e) {}

    header('Location: /pages/dashboard.php?req_reviewed=1'); exit;
}

// ==============================
// Handle: เพิ่มประกาศ
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_ann') {
    if (!in_array($role, ['admin','lawyer'])) { header('Location: /pages/dashboard.php'); exit; }
    csrf_verify();
    $title = trim($_POST['ann_title'] ?? '');
    $body  = trim($_POST['ann_body']  ?? '');
    $pin   = isset($_POST['ann_pin']) ? 1 : 0;
    if ($title) {
        $pdo->prepare("INSERT INTO announcements (office_id,title,body,pin,created_by) VALUES (?,?,?,?,?)")
            ->execute([$officeId, $title, $body, $pin, $userId]);
    }
    header('Location: /pages/dashboard.php?ann_added=1'); exit;
}

// Handle: ลบประกาศ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del_ann') {
    if (!in_array($role, ['admin','lawyer'])) { header('Location: /pages/dashboard.php'); exit; }
    csrf_verify();
    $pdo->prepare("DELETE FROM announcements WHERE ann_id=? AND office_id=?")->execute([(int)$_POST['ann_id'], $officeId]);
    header('Location: /pages/dashboard.php'); exit;
}

// ==============================
// Handle: ลูกความรีวิวทนาย
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    if ($role !== 'client') { header('Location: /pages/dashboard.php'); exit; }
    csrf_verify();
    $lawyerId = (int)$_POST['lawyer_id'];
    $rating   = max(1, min(5, (int)$_POST['rating']));
    $comment  = trim($_POST['comment'] ?? '');

    $cpRow = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
    $cpRow->execute([$userId]);
    $cid = $cpRow->fetchColumn();

    // ตรวจว่าเคยว่าจ้างทนายนี้จริง
    $chk = $pdo->prepare("
        SELECT 1 FROM case_requests cr
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        WHERE cr.lawyer_id=? AND cp.user_id=? AND cr.office_id=?
        LIMIT 1
    ");
    $chk->execute([$lawyerId, $userId, $officeId]);
    if ($chk->fetch()) {
        try {
            $pdo->prepare("
                INSERT INTO lawyer_reviews (lawyer_id,client_id,rating,comment)
                VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment)
            ")->execute([$lawyerId, $cid, $rating, $comment]);
        } catch (Exception $e) { /* ตาราง lawyer_reviews ยังไม่มี */ }
    }
    header('Location: /pages/dashboard.php?reviewed=1'); exit;
}

// ==============================
// ดึงข้อมูล
// ==============================
$myLawyer = null;
if ($role === 'lawyer') {
    try {
        $s = $pdo->prepare("
            SELECT lp.*, u.email,
                   ROUND(COALESCE(AVG(r.rating),0),1) AS avg_rating,
                   COUNT(r.review_id) AS review_count
            FROM lawyer_profiles lp
            JOIN users u ON lp.user_id = u.user_id
            LEFT JOIN lawyer_reviews r ON lp.lawyer_id = r.lawyer_id
            WHERE lp.user_id=?
            GROUP BY lp.lawyer_id
        ");
        $s->execute([$userId]); $myLawyer = $s->fetch();
    } catch (Exception $e) {
        $s = $pdo->prepare("SELECT lp.*, u.email, 0 AS avg_rating, 0 AS review_count FROM lawyer_profiles lp JOIN users u ON lp.user_id=u.user_id WHERE lp.user_id=?");
        $s->execute([$userId]); $myLawyer = $s->fetch();
    }
}

$myLawyers = [];
$myClientId = null;
if ($role === 'client') {
    $cpRow = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
    $cpRow->execute([$userId]); $myClientId = $cpRow->fetchColumn();

    try {
        $s = $pdo->prepare("
            SELECT DISTINCT lp.lawyer_id, lp.fname, lp.lname, lp.specialization,
                   lp.phone, lp.profile_photo, lp.bio, lp.experience_yr,
                   ROUND(COALESCE(AVG(r.rating),0),1) AS avg_rating,
                   COUNT(r.review_id) AS review_count,
                   (SELECT rating  FROM lawyer_reviews WHERE lawyer_id=lp.lawyer_id AND client_id=?) AS my_rating,
                   (SELECT comment FROM lawyer_reviews WHERE lawyer_id=lp.lawyer_id AND client_id=?) AS my_comment
            FROM case_requests cr
            JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
            LEFT JOIN lawyer_reviews r ON lp.lawyer_id = r.lawyer_id
            WHERE cr.client_id=? AND cr.office_id=?
            GROUP BY lp.lawyer_id
        ");
        $s->execute([$myClientId, $myClientId, $myClientId, $officeId]);
        $myLawyers = $s->fetchAll();
    } catch (Exception $e) {
        $s = $pdo->prepare("
            SELECT DISTINCT lp.lawyer_id, lp.fname, lp.lname, lp.specialization,
                   lp.phone, lp.profile_photo, lp.bio, lp.experience_yr,
                   0 AS avg_rating, 0 AS review_count, NULL AS my_rating, NULL AS my_comment
            FROM case_requests cr
            JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
            WHERE cr.client_id=? AND cr.office_id=?
            GROUP BY lp.lawyer_id
        ");
        $s->execute([$myClientId, $officeId]);
        $myLawyers = $s->fetchAll();
    }
}

// ประกาศ
$annStmt = $pdo->prepare("
    SELECT a.*, CONCAT(lp.fname,' ',lp.lname) AS author
    FROM announcements a
    LEFT JOIN lawyer_profiles lp ON lp.user_id = a.created_by
    WHERE a.office_id=?
    ORDER BY a.pin DESC, a.created_at DESC
    LIMIT 20
");
$annStmt->execute([$officeId]);
$announcements = $annStmt->fetchAll();

// stats
$stats = [];
if ($role === 'lawyer') {
    $lpRow = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lpRow->execute([$userId]); $lid = $lpRow->fetchColumn();
    $r1 = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE lawyer_id=? AND status='pending'"); $r1->execute([$lid]);
    $r2 = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE lawyer_id=? AND office_id=?");     $r2->execute([$lid,$officeId]);
    $stats = ['pending'=>$r1->fetchColumn(),'total'=>$r2->fetchColumn()];
} elseif ($role === 'client') {
    $r1 = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE client_id=? AND office_id=?"); $r1->execute([$myClientId,$officeId]);
    $r2 = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN case_requests cr ON c.request_id=cr.request_id WHERE cr.client_id=? AND c.contract_review_status IN ('lawyer_accepted','negotiating','revision_requested','finalized')"); $r2->execute([$myClientId]);
    $stats = ['total_cases'=>$r1->fetchColumn(),'active_contracts'=>$r2->fetchColumn()];
} else {
    $r1 = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE office_id=? AND status='pending'"); $r1->execute([$officeId]);
    $r2 = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE office_id=?");                      $r2->execute([$officeId]);
    $r3 = $pdo->prepare("SELECT COUNT(*) FROM lawyer_profiles lp JOIN users u ON lp.user_id=u.user_id WHERE u.office_id=?"); $r3->execute([$officeId]);
    $r4 = $pdo->prepare("SELECT COUNT(*) FROM client_profiles cp JOIN users u ON cp.user_id=u.user_id WHERE u.office_id=?"); $r4->execute([$officeId]);
    $r5 = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN case_requests cr ON c.request_id=cr.request_id WHERE cr.office_id=?"); $r5->execute([$officeId]);
    $stats = ['pending'=>$r1->fetchColumn(),'total'=>$r2->fetchColumn(),'lawyers'=>$r3->fetchColumn(),'clients'=>$r4->fetchColumn(),'contracts'=>$r5->fetchColumn()];
}

// รีวิวที่ทนายได้รับ
$myReviews = [];
if ($role === 'lawyer' && $myLawyer && ($myLawyer['review_count'] ?? 0) > 0) {
    try {
        $rs = $pdo->prepare("
            SELECT r.*, CONCAT(cp.fname,' ',cp.lname) AS client_name
            FROM lawyer_reviews r
            JOIN client_profiles cp ON r.client_id = cp.client_id
            JOIN lawyer_profiles lp ON r.lawyer_id  = lp.lawyer_id
            WHERE lp.user_id=?
            ORDER BY r.created_at DESC LIMIT 10
        ");
        $rs->execute([$userId]); $myReviews = $rs->fetchAll();
    } catch (Exception $e) { $myReviews = []; }
}

// ข้อมูล client
$meClient = null;
if ($role === 'client') {
    try {
        $s = $pdo->prepare("SELECT cp.*, u.email FROM client_profiles cp JOIN users u ON cp.user_id=u.user_id WHERE cp.user_id=?");
        $s->execute([$userId]);
        $meClient = $s->fetch();
    } catch(Exception $e) {
        // fallback: ไม่ดึง profile_photo (column อาจยังไม่มี)
        $s = $pdo->prepare("SELECT cp.client_id, cp.user_id, cp.fname, cp.lname, cp.citizen_id, cp.phone, cp.address, cp.created_at, u.email FROM client_profiles cp JOIN users u ON cp.user_id=u.user_id WHERE cp.user_id=?");
        $s->execute([$userId]);
        $meClient = $s->fetch();
    }
}

// pending change request ของ client
$myPendingReq = null;
if ($role === 'client') {
    try {
        $pr = $pdo->prepare("SELECT * FROM profile_change_requests WHERE user_id=? AND status='pending' ORDER BY created_at DESC LIMIT 1");
        $pr->execute([$userId]); $myPendingReq = $pr->fetch();
    } catch (Exception $e) {}
}

// ==============================
// เอกสารเซ็น: นับ pending
// ==============================
$signDocsBadge = 0;
$signDocsPending = [];
// ดึง clientId / lawyerId สำหรับใช้ใน query
$clientId = null;
$lawyerIdDash = null;
if ($role === 'client') {
    try {
        $r = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
        $r->execute([$userId]); $clientId = $r->fetchColumn();
    } catch(Exception $e) {}
} elseif ($role === 'lawyer') {
    try {
        $r = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
        $r->execute([$userId]); $lawyerIdDash = $r->fetchColumn();
    } catch(Exception $e) {}
}
try {
    if ($role === 'client' && $clientId) {
        $sq = $pdo->prepare("
            SELECT d.doc_id, d.doc_title, d.doc_type, d.due_date, d.created_at,
                   lp.fname AS law_fname, lp.lname AS law_lname
            FROM client_sign_docs d
            JOIN case_requests cr ON d.request_id=cr.request_id
            JOIN lawyer_profiles lp ON cr.lawyer_id=lp.lawyer_id
            WHERE cr.client_id=? AND d.office_id=? AND d.status='pending'
            ORDER BY d.due_date ASC, d.created_at ASC
        ");
        $sq->execute([$clientId, $officeId]);
        $signDocsPending = $sq->fetchAll();
        $signDocsBadge = count($signDocsPending);
    } elseif ($role === 'lawyer') {
        if ($lawyerIdDash) {
            $sq = $pdo->prepare("
                SELECT d.doc_id, d.doc_title, d.doc_type, d.due_date, d.created_at,
                       cp.fname AS cli_fname, cp.lname AS cli_lname
                FROM client_sign_docs d
                JOIN case_requests cr ON d.request_id=cr.request_id
                JOIN client_profiles cp ON cr.client_id=cp.client_id
                WHERE cr.lawyer_id=? AND d.office_id=? AND d.status='pending'
                ORDER BY d.due_date ASC, d.created_at ASC
            ");
            $sq->execute([$lawyerIdDash, $officeId]);
            $signDocsPending = $sq->fetchAll();
            $signDocsBadge = count($signDocsPending);
        }
    } elseif ($role === 'admin') {
        $sq = $pdo->prepare("
            SELECT d.doc_id, d.doc_title, d.doc_type, d.due_date, d.created_at,
                   cp.fname AS cli_fname, cp.lname AS cli_lname,
                   lp.fname AS law_fname, lp.lname AS law_lname
            FROM client_sign_docs d
            JOIN case_requests cr ON d.request_id=cr.request_id
            JOIN client_profiles cp ON cr.client_id=cp.client_id
            JOIN lawyer_profiles lp ON cr.lawyer_id=lp.lawyer_id
            WHERE d.office_id=? AND d.status='pending'
            ORDER BY d.due_date ASC, d.created_at ASC LIMIT 5
        ");
        $sq->execute([$officeId]);
        $signDocsPending = $sq->fetchAll();
        $signDocsBadge = count($signDocsPending);
    }
} catch (Exception $e) { $signDocsBadge = 0; $signDocsPending = []; }

// admin: คำขอแก้ไขข้อมูล pending ทั้งหมดในสำนักงาน
$pendingProfileReqs = [];
if ($role === 'admin') {
    try {
        $pr = $pdo->prepare("
            SELECT r.*, CONCAT(cp.fname,' ',cp.lname) AS current_name,
                   cp.fname AS cur_fname, cp.lname AS cur_lname,
                   cp.phone AS cur_phone, cp.citizen_id AS cur_citizen,
                   u.email
            FROM profile_change_requests r
            JOIN users u ON r.user_id = u.user_id
            JOIN client_profiles cp ON cp.user_id = r.user_id
            WHERE r.office_id=? AND r.status='pending'
            ORDER BY r.created_at ASC
        ");
        $pr->execute([$officeId]); $pendingProfileReqs = $pr->fetchAll();
    } catch (Exception $e) {}
}

function starHtml(float $n, bool $big=false): string {
    $full = (int)round($n);
    $s    = $big ? '1.2rem' : '.9rem';
    $full = max(0, min(5, $full));
    return '<span style="color:#f59e0b;font-size:'.$s.';letter-spacing:1px;">'.
           str_repeat('★',$full).str_repeat('☆',5-$full).'</span>';
}

$pageTitle = 'หน้าหลัก';
include '../includes/header.php';
?>
<style>
:root{--navy:#1a3a5c;--navy2:#2d5a8a;--green:#059669;--red:#dc2626;--blue:#2563eb;--border:#e2e8f0;--muted:#94a3b8;--bg:#f8fafc;}

/* Layout */
.dash-grid{display:block;width:100%;}

/* Profile card */
.profile-card{background:#fff;border:1px solid var(--border);border-radius:16px;overflow:hidden;position:sticky;top:14px;}
.profile-banner{height:75px;background:linear-gradient(135deg,#1a3a5c 0%,#2d5a8a 55%,#3b82f6 100%);}
.profile-body{padding:0 18px 18px;}
.p-avatar-wrap{margin-top:-40px;margin-bottom:8px;}
.p-avatar{width:80px;height:80px;border-radius:50%;border:4px solid #fff;object-fit:cover;box-shadow:0 2px 10px rgba(0,0,0,.13);}
.p-avatar-ph{width:80px;height:80px;border-radius:50%;border:4px solid #fff;background:var(--navy);display:flex;align-items:center;justify-content:center;font-size:2rem;color:#fff;box-shadow:0 2px 10px rgba(0,0,0,.13);}
.p-name{font-size:1.05rem;font-weight:700;color:var(--navy);}
.p-sub{font-size:.77rem;color:var(--muted);margin-top:1px;}
.p-spec{display:inline-block;margin-top:6px;background:#eff6ff;color:var(--blue);padding:2px 12px;border-radius:12px;font-size:.73rem;font-weight:600;}
.p-info{margin:10px 0;border-top:1px solid var(--border);padding-top:10px;}
.p-row{display:flex;gap:8px;font-size:.81rem;margin-bottom:5px;align-items:flex-start;}
.p-lbl{color:var(--muted);min-width:76px;flex-shrink:0;}
.p-val{color:var(--navy);font-weight:600;}
.p-bio{margin-top:7px;font-size:.8rem;color:#475569;line-height:1.55;background:#f8fafc;padding:8px 10px;border-radius:8px;}
.btn-edit{width:100%;padding:8px;background:var(--navy);color:#fff;border:none;border-radius:8px;font-size:.83rem;font-weight:700;cursor:pointer;margin-top:8px;}
.btn-edit:hover{background:var(--navy2);}

/* Stat cards */
.stat-cards{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px;}
.stat-cards.four{grid-template-columns:repeat(4,1fr);}
.stat-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px;text-align:center;}
.sc-val{font-size:2rem;font-weight:700;color:var(--navy);display:block;}
.sc-lbl{font-size:.73rem;color:var(--muted);}

/* Announcements */
.box{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:14px;}
.box-head{background:var(--navy);color:#fff;padding:11px 16px;display:flex;justify-content:space-between;align-items:center;font-weight:700;font-size:.9rem;}
.ann-item{padding:12px 16px;border-bottom:1px solid var(--border);transition:.12s;}
.ann-item:last-child{border-bottom:none;}
.ann-item:hover{background:#f8fafc;}
.ann-title{font-weight:700;color:var(--navy);font-size:.88rem;}
.ann-text{font-size:.8rem;color:#475569;margin-top:3px;line-height:1.5;}
.ann-meta{font-size:.7rem;color:var(--muted);margin-top:4px;}
.ann-form{padding:12px 16px;background:#f8fafc;border-top:1px solid var(--border);}
.af-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end;}
.af-input{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.83rem;background:#fff;outline:none;margin-bottom:7px;}
.af-input:focus{border-color:var(--blue);}
.af-ta{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;background:#fff;outline:none;resize:vertical;min-height:60px;margin-bottom:7px;}
.af-ta:focus{border-color:var(--blue);}

/* Star picker */
.star-picker{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:3px;}
.star-picker input{display:none;}
.star-picker label{font-size:1.7rem;cursor:pointer;color:#cbd5e1;line-height:1;transition:.1s;}
.star-picker input:checked ~ label,.star-picker label:hover,.star-picker label:hover ~ label{color:#f59e0b;}

/* Review card */
.rev-card{padding:14px 16px;border-bottom:1px solid var(--border);}
.rev-card:last-child{border-bottom:none;}
.rev-top{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.rev-avatar{width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--border);background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;}
.rev-name{font-weight:700;color:var(--navy);font-size:.89rem;}
.rev-spec{font-size:.73rem;color:var(--muted);}

/* Quick links */
.ql-btn{display:inline-block;padding:8px 16px;background:var(--navy);color:#fff;border:none;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;cursor:pointer;transition:.15s;}
.ql-btn:hover{opacity:.85;}

/* Toast */
.toast{padding:10px 15px;border-radius:8px;margin-bottom:13px;font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:7px;}
.t-ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;}

/* Modal */
.modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;}
.modal-box{background:#fff;border-radius:16px;padding:22px;width:90%;max-width:500px;max-height:90vh;overflow-y:auto;}
.m-title{font-weight:700;color:var(--navy);font-size:.98rem;margin-bottom:14px;}
.fg{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
.fl{font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;}
.fi{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.83rem;background:#fff;outline:none;}
.fi:focus{border-color:var(--blue);}
.fta{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;background:#fff;outline:none;resize:vertical;min-height:75px;}
.fta:focus{border-color:var(--blue);}
.btn-save{padding:8px 22px;background:var(--navy);color:#fff;border:none;border-radius:8px;font-size:.86rem;font-weight:700;cursor:pointer;}
.btn-save:hover{background:var(--navy2);}
.btn-cc{padding:8px 16px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-size:.86rem;cursor:pointer;margin-right:6px;}
</style>

<?php if (isset($_GET['profile_saved'])): ?><div class="toast t-ok">✅ บันทึกโปรไฟล์เรียบร้อยแล้ว</div>
<?php elseif (isset($_GET['profile_photo_saved'])): ?><div class="toast t-ok">🖼️ อัปเดตรูปโปรไฟล์เรียบร้อยแล้ว</div>
<?php elseif (isset($_GET['profile_req_sent'])): ?><div class="toast" style="background:#fffbeb;color:#92400e;border:1px solid #fcd34d;">📋 ส่งคำขอแก้ไขข้อมูลแล้ว รอ Admin ยืนยัน</div>
<?php elseif (isset($_GET['ann_added'])): ?><div class="toast t-ok">✅ เพิ่มประกาศเรียบร้อยแล้ว</div>
<?php elseif (isset($_GET['reviewed'])): ?><div class="toast t-ok">⭐ บันทึกรีวิวเรียบร้อยแล้ว ขอบคุณ!</div>
<?php elseif (isset($_GET['req_reviewed'])): ?><div class="toast t-ok">✅ ดำเนินการคำขอเรียบร้อยแล้ว</div>
<?php endif; ?>

<?php if ($signDocsBadge > 0): ?>
<?php if ($role === 'client'): ?>
<!-- ===== แจ้งเตือนลูกความ: เอกสารสำคัญรอเซ็น ===== -->
<div style="background:linear-gradient(135deg,#dc2626 0%,#b91c1c 100%);color:#fff;border-radius:14px;padding:18px 22px;margin-bottom:18px;display:flex;align-items:center;gap:18px;box-shadow:0 4px 20px rgba(220,38,38,.35);">
  <div style="font-size:2.8rem;flex-shrink:0;filter:drop-shadow(0 2px 4px rgba(0,0,0,.3));">📄</div>
  <div style="flex:1;">
    <div style="font-size:1.05rem;font-weight:800;margin-bottom:4px;">มีเอกสารสำคัญรอการดำเนินการ <?= $signDocsBadge ?> รายการ!</div>
    <div style="font-size:.83rem;opacity:.9;line-height:1.5;margin-bottom:10px;">
      ทนายความส่งเอกสารที่ต้องการให้คุณ<strong>รับทราบหรือเซ็นชื่อ</strong> กรุณาตรวจสอบและดำเนินการโดยเร็ว
    </div>
    <!-- แสดงรายการย่อ -->
    <?php foreach (array_slice($signDocsPending, 0, 3) as $sd): ?>
    <div style="background:rgba(255,255,255,.15);border-radius:8px;padding:6px 12px;margin-bottom:5px;font-size:.79rem;display:flex;justify-content:space-between;align-items:center;">
      <span>📌 <strong><?= htmlspecialchars($sd['doc_title']) ?></strong>
        <span style="opacity:.8;"> · <?= htmlspecialchars($sd['doc_type']) ?></span>
        <span style="opacity:.8;"> · ทนาย<?= htmlspecialchars($sd['law_fname'].' '.$sd['law_lname']) ?></span>
      </span>
      <?php if ($sd['due_date']): ?>
      <span style="background:rgba(0,0,0,.25);border-radius:12px;padding:2px 9px;font-size:.7rem;white-space:nowrap;margin-left:8px;">
        📅 <?= date('d/m/Y', strtotime($sd['due_date'])) ?>
      </span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if ($signDocsBadge > 3): ?>
    <div style="font-size:.75rem;opacity:.8;margin-top:4px;">และอีก <?= $signDocsBadge-3 ?> รายการ...</div>
    <?php endif; ?>
  </div>
  <a href="/pages/client_sign_docs.php"
     style="flex-shrink:0;background:#fff;color:#dc2626;padding:10px 20px;border-radius:10px;font-weight:800;font-size:.85rem;text-decoration:none;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.2);">
    📄 ดูเอกสาร →
  </a>
</div>

<?php elseif ($role === 'lawyer'): ?>
<!-- ===== แจ้งเตือนทนาย: ลูกความยังไม่รับเอกสาร ===== -->
<div style="background:linear-gradient(135deg,#d97706 0%,#b45309 100%);color:#fff;border-radius:14px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;gap:16px;box-shadow:0 4px 16px rgba(217,119,6,.3);">
  <div style="font-size:2.2rem;flex-shrink:0;">⏳</div>
  <div style="flex:1;">
    <div style="font-size:.95rem;font-weight:800;margin-bottom:3px;">เอกสาร <?= $signDocsBadge ?> รายการ ยังรอลูกความดำเนินการ</div>
    <div style="font-size:.8rem;opacity:.9;margin-bottom:8px;">ลูกความยังไม่รับทราบเอกสารที่คุณส่งไป</div>
    <?php foreach (array_slice($signDocsPending, 0, 3) as $sd): ?>
    <div style="background:rgba(255,255,255,.15);border-radius:6px;padding:5px 10px;margin-bottom:4px;font-size:.78rem;">
      📌 <?= htmlspecialchars($sd['doc_title']) ?> · ลูกความ<?= htmlspecialchars($sd['cli_fname'].' '.$sd['cli_lname']) ?>
      <?php if ($sd['due_date']): ?> · 📅 <?= date('d/m/Y', strtotime($sd['due_date'])) ?><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <a href="/pages/client_sign_docs.php"
     style="flex-shrink:0;background:#fff;color:#d97706;padding:9px 18px;border-radius:10px;font-weight:800;font-size:.82rem;text-decoration:none;white-space:nowrap;">
    📄 จัดการเอกสาร →
  </a>
</div>

<?php elseif ($role === 'admin'): ?>
<!-- ===== แจ้งเตือน admin ===== -->
<div style="background:linear-gradient(135deg,#1a3a5c 0%,#2d5a8a 100%);color:#fff;border-radius:14px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:14px;box-shadow:0 4px 16px rgba(26,58,92,.25);">
  <div style="font-size:1.8rem;">📋</div>
  <div style="flex:1;font-size:.84rem;">มีเอกสาร <strong><?= $signDocsBadge ?> รายการ</strong> ที่ยังรอลูกความดำเนินการในสำนักงานนี้</div>
  <a href="/pages/client_sign_docs.php"
     style="flex-shrink:0;background:rgba(255,255,255,.2);color:#fff;padding:7px 16px;border-radius:8px;font-weight:700;font-size:.8rem;text-decoration:none;white-space:nowrap;border:1px solid rgba(255,255,255,.3);">
    ดูทั้งหมด →
  </a>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="dash-grid">

  <!-- ===== เนื้อหา Dashboard ===== -->
  <div>

    <!-- Stats -->
    <div class="stat-cards <?= $role==='admin'?'four':'' ?>">
      <?php if ($role === 'lawyer'): ?>
        <div class="stat-card"><span class="sc-val"><?= $stats['pending'] ?></span><span class="sc-lbl">คำขอรอตอบรับ</span></div>
        <div class="stat-card"><span class="sc-val"><?= $stats['total'] ?></span><span class="sc-lbl">คดีที่รับแล้ว</span></div>
      <?php elseif ($role === 'client'): ?>
        <div class="stat-card"><span class="sc-val"><?= $stats['total_cases'] ?></span><span class="sc-lbl">คดีทั้งหมด</span></div>
        <div class="stat-card"><span class="sc-val"><?= $stats['active_contracts'] ?></span><span class="sc-lbl">สัญญาที่ยืนยันแล้ว</span></div>
      <?php else: ?>
        <div class="stat-card"><span class="sc-val"><?= $stats['lawyers'] ?></span><span class="sc-lbl">ทนายความ</span></div>
        <div class="stat-card"><span class="sc-val"><?= $stats['clients'] ?></span><span class="sc-lbl">ลูกความ</span></div>
        <div class="stat-card"><span class="sc-val"><?= $stats['pending'] ?></span><span class="sc-lbl">คำขอรอดำเนินการ</span></div>
        <div class="stat-card"><span class="sc-val"><?= $stats['contracts'] ?></span><span class="sc-lbl">สัญญาทั้งหมด</span></div>
      <?php endif; ?>
    </div>

    <!-- Admin: คำขอแก้ไขข้อมูล pending -->
    <?php if ($role === 'admin' && !empty($pendingProfileReqs)): ?>
    <div class="box" style="border-color:#fcd34d;">
      <div class="box-head" style="background:linear-gradient(90deg,#d97706,#f59e0b);">
        <span>🔔 คำขอแก้ไขข้อมูลลูกความ รอยืนยัน</span>
        <span style="background:#fff;color:#d97706;border-radius:20px;padding:1px 10px;font-size:.78rem;font-weight:700;"><?= count($pendingProfileReqs) ?></span>
      </div>
      <?php foreach ($pendingProfileReqs as $req): ?>
      <div style="padding:11px 16px;border-bottom:1px solid #fef3c7;display:flex;justify-content:space-between;align-items:center;gap:10px;">
        <div>
          <div style="font-weight:700;color:var(--navy);font-size:.87rem;">👤 <?= htmlspecialchars($req['current_name']) ?></div>
          <div style="font-size:.75rem;color:#92400e;margin-top:2px;">
            <?= $req['req_fname'] ? 'ชื่อ · ' : '' ?><?= $req['req_phone'] ? 'เบอร์ · ' : '' ?><?= $req['req_address'] ? 'ที่อยู่ · ' : '' ?><?= $req['req_citizen_id'] ? 'บัตรปชช. · ' : '' ?>
            <span style="color:var(--muted);"><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></span>
          </div>
        </div>
        <button onclick="document.getElementById('reqModal<?= $req['req_id'] ?>').style.display='flex'"
                style="padding:6px 14px;background:var(--navy);color:#fff;border:none;border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer;white-space:nowrap;">
          🔍 ตรวจสอบ
        </button>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ประกาศ -->
    <div class="box">
      <div class="box-head">
        <span>📢 ข่าวสาร &amp; ประกาศสำนักงาน</span>
        <span style="font-size:.73rem;opacity:.7;"><?= count($announcements) ?> รายการ</span>
      </div>

      <?php if (empty($announcements)): ?>
      <div style="text-align:center;padding:28px;color:var(--muted);font-size:.84rem;">
        <div style="font-size:2rem;opacity:.28;margin-bottom:5px;">📭</div>ยังไม่มีประกาศ
      </div>
      <?php endif; ?>

      <?php foreach ($announcements as $a): ?>
      <div class="ann-item">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
          <div style="flex:1;">
            <?php if ($a['pin']): ?><span style="color:#f59e0b;font-size:.72rem;font-weight:700;">📌 </span><?php endif; ?>
            <span class="ann-title"><?= htmlspecialchars($a['title']) ?></span>
            <?php if ($a['body']): ?><div class="ann-text"><?= nl2br(htmlspecialchars($a['body'])) ?></div><?php endif; ?>
            <div class="ann-meta"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?><?= $a['author'] ? ' · '.htmlspecialchars($a['author']) : '' ?></div>
          </div>
          <?php if (in_array($role,['admin','lawyer'])): ?>
          <form method="POST" style="margin:0;" onsubmit="return confirm('ลบประกาศนี้?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="del_ann">
            <input type="hidden" name="ann_id"  value="<?= $a['ann_id'] ?>">
            <button type="submit" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:.8rem;padding:2px 6px;border-radius:4px;" onmouseover="this.style.background='#fee2e2';this.style.color='#dc2626'" onmouseout="this.style.background='none';this.style.color='var(--muted)'">🗑</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if (in_array($role,['admin','lawyer'])): ?>
      <div class="ann-form">
        <div style="font-weight:700;color:var(--navy);font-size:.83rem;margin-bottom:7px;">➕ เพิ่มประกาศใหม่</div>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_ann">
          <input type="text"  name="ann_title" class="af-input" placeholder="หัวข้อประกาศ *" required>
          <textarea           name="ann_body"  class="af-ta"   placeholder="รายละเอียด (ถ้ามี)..."></textarea>
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <label style="font-size:.79rem;color:#64748b;display:flex;align-items:center;gap:5px;cursor:pointer;">
              <input type="checkbox" name="ann_pin"> 📌 ปักหมุด
            </label>
            <button type="submit" class="btn-save" style="padding:6px 18px;font-size:.81rem;">📢 โพสต์</button>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- ลูกความ: รีวิวทนาย -->
    <?php if ($role === 'client' && !empty($myLawyers)): ?>
    <div class="box">
      <div class="box-head" style="background:linear-gradient(90deg,#1a3a5c,#2d5a8a);">⭐ รีวิวทนายความของคุณ</div>
      <?php foreach ($myLawyers as $lw): ?>
      <div class="rev-card">
        <div class="rev-top">
          <?php if ($lw['profile_photo']): ?>
            <img src="/uploads/lawyer_photos/<?= htmlspecialchars($lw['profile_photo']) ?>" class="rev-avatar" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--border);">
          <?php else: ?><div class="rev-avatar">👨‍⚖️</div><?php endif; ?>
          <div>
            <div class="rev-name"><?= htmlspecialchars($lw['fname'].' '.$lw['lname']) ?></div>
            <div class="rev-spec"><?= htmlspecialchars($lw['specialization']??'') ?><?= $lw['experience_yr'] ? ' · '.$lw['experience_yr'].' ปี' : '' ?></div>
            <div style="display:flex;align-items:center;gap:5px;margin-top:2px;">
              <?= starHtml((float)$lw['avg_rating']) ?>
              <span style="font-size:.72rem;color:var(--muted);"><?= $lw['avg_rating'] ?> (<?= $lw['review_count'] ?> รีวิว)</span>
            </div>
          </div>
        </div>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action"    value="review">
          <input type="hidden" name="lawyer_id" value="<?= $lw['lawyer_id'] ?>">
          <div style="margin-bottom:8px;">
            <div style="font-size:.76rem;color:#64748b;font-weight:600;margin-bottom:4px;">ให้คะแนน</div>
            <div class="star-picker">
              <?php for ($s=5;$s>=1;$s--): ?>
              <input type="radio" id="s<?= $lw['lawyer_id'] ?>_<?= $s ?>" name="rating" value="<?= $s ?>"
                     <?= ($lw['my_rating']==$s)?'checked':'' ?> required>
              <label for="s<?= $lw['lawyer_id'] ?>_<?= $s ?>">★</label>
              <?php endfor; ?>
            </div>
          </div>
          <textarea name="comment" class="af-ta" placeholder="เขียนรีวิว... (ถ้ามี)"><?= htmlspecialchars($lw['my_comment']??'') ?></textarea>
          <div style="display:flex;align-items:center;gap:10px;margin-top:6px;">
            <button type="submit" class="btn-save" style="font-size:.81rem;padding:7px 18px;">
              <?= $lw['my_rating'] ? '🔄 แก้ไขรีวิว' : '⭐ ส่งรีวิว' ?>
            </button>
            <?php if ($lw['my_rating']): ?>
            <span style="font-size:.75rem;color:var(--green);">✅ รีวิวแล้ว <?= $lw['my_rating'] ?> ดาว</span>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ทนาย: แสดงรีวิวที่ได้รับ -->
    <?php if ($role === 'lawyer' && !empty($myReviews)): ?>
    <div class="box">
      <div class="box-head" style="background:linear-gradient(90deg,#1a3a5c,#2d5a8a);">
        ⭐ รีวิวที่ได้รับ (<?= $myLawyer['review_count'] ?>)
      </div>
      <?php foreach ($myReviews as $rv): ?>
      <div style="padding:11px 16px;border-bottom:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span style="font-weight:600;color:var(--navy);font-size:.85rem;">👤 <?= htmlspecialchars($rv['client_name']) ?></span>
          <span><?= starHtml($rv['rating']) ?> <span style="font-size:.71rem;color:var(--muted);"><?= $rv['rating'] ?> ดาว</span></span>
        </div>
        <?php if ($rv['comment']): ?>
        <div style="font-size:.8rem;color:#475569;margin-top:4px;background:#f8fafc;padding:5px 10px;border-radius:6px;line-height:1.5;">
          "<?= htmlspecialchars($rv['comment']) ?>"
        </div>
        <?php endif; ?>
        <div style="font-size:.7rem;color:var(--muted);margin-top:3px;"><?= date('d/m/Y H:i', strtotime($rv['created_at'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- Modal แก้ไขโปรไฟล์ทนาย -->
<?php if ($role === 'lawyer' && $myLawyer): ?>
<div class="modal-ov" id="editModal" onclick="closeModal()">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="m-title">✏️ แก้ไขข้อมูลส่วนตัว</div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_profile">
      <div style="margin-bottom:10px;">
        <label class="fl">📷 รูปโปรไฟล์ (JPG/PNG สูงสุด 5MB)</label>
        <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png" style="width:100%;padding:5px;border:1px solid var(--border);border-radius:8px;font-size:.81rem;background:#fff;">
        <?php if ($myLawyer['profile_photo']): ?><div style="font-size:.7rem;color:var(--muted);margin-top:2px;">มีรูปอยู่แล้ว — อัปโหลดใหม่เพื่อเปลี่ยน</div><?php endif; ?>
      </div>
      <div class="fg">
        <div><label class="fl">👤 ชื่อ</label><input type="text" name="fname" class="fi" value="<?= htmlspecialchars($myLawyer['fname']??'') ?>" placeholder="ชื่อ" required></div>
        <div><label class="fl">👤 นามสกุล</label><input type="text" name="lname" class="fi" value="<?= htmlspecialchars($myLawyer['lname']??'') ?>" placeholder="นามสกุล" required></div>
        <div><label class="fl">📞 เบอร์โทรศัพท์</label><input type="text" name="phone" class="fi" value="<?= htmlspecialchars($myLawyer['phone']??'') ?>" placeholder="0812345678"></div>
        <div><label class="fl">⚖️ ความเชี่ยวชาญ</label><input type="text" name="specialization" class="fi" value="<?= htmlspecialchars($myLawyer['specialization']??'') ?>" placeholder="เช่น คดีแพ่ง"></div>
        <div><label class="fl">🪪 เลขใบอนุญาต</label><input type="text" name="license_no" class="fi" value="<?= htmlspecialchars($myLawyer['license_no']??'') ?>"></div>
        <div><label class="fl">📆 ใบอนุญาตหมดอายุ</label><input type="date" name="license_exp" class="fi" value="<?= htmlspecialchars($myLawyer['license_exp']??'') ?>"></div>
        <div><label class="fl">📅 ประสบการณ์ (ปี)</label><input type="number" name="experience_yr" class="fi" min="0" max="60" value="<?= $myLawyer['experience_yr']??'' ?>"></div>
        <div><label class="fl">🎓 การศึกษา</label><input type="text" name="education" class="fi" value="<?= htmlspecialchars($myLawyer['education']??'') ?>" placeholder="เช่น นิติศาสตรบัณฑิต มหาวิทยาลัย..."></div>
        <div style="grid-column:1/-1;"><label class="fl">📝 แนะนำตัว / Bio</label><textarea name="bio" class="fta"><?= htmlspecialchars($myLawyer['bio']??'') ?></textarea></div>
      </div>
      <div style="text-align:right;margin-top:4px;">
        <button type="button" class="btn-cc" onclick="closeModal()">ยกเลิก</button>
        <button type="submit" class="btn-save">💾 บันทึก</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Modal แก้ไขโปรไฟล์ลูกความ -->
<?php if ($role === 'client' && $meClient): ?>
<div class="modal-ov" id="clientModal" onclick="closeClientModal()">
  <div class="modal-box" style="max-width:560px;" onclick="event.stopPropagation()">
    <div class="m-title">✏️ แก้ไขข้อมูลส่วนตัว</div>

    <!-- Tab switcher -->
    <div style="display:flex;border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:16px;">
      <button type="button" id="tabPhoto" onclick="switchTab('photo')"
              style="flex:1;padding:9px;border:none;background:var(--navy);color:#fff;font-size:.83rem;font-weight:700;cursor:pointer;">
        🖼️ รูปโปรไฟล์
      </button>
      <button type="button" id="tabInfo" onclick="switchTab('info')"
              style="flex:1;padding:9px;border:none;background:#f1f5f9;color:#64748b;font-size:.83rem;cursor:pointer;">
        📋 ข้อมูลส่วนตัว
      </button>
    </div>

    <!-- ===== Panel รูปโปรไฟล์: submit ทันที ===== -->
    <div id="panelPhoto">
      <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:.8rem;color:#065f46;">
        ✅ รูปโปรไฟล์จะ <strong>อัปเดตทันที</strong> ไม่ต้องรออนุมัติ
      </div>
      <?php if (!empty($meClient['profile_photo']??null)): ?>
      <div style="text-align:center;margin-bottom:10px;">
        <img src="/uploads/client_photos/<?= htmlspecialchars($meClient['profile_photo']) ?>"
             style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid var(--border);">
        <div style="font-size:.71rem;color:var(--muted);margin-top:4px;">รูปปัจจุบัน — อัปโหลดใหม่เพื่อเปลี่ยน</div>
      </div>
      <?php endif; ?>
      <form method="POST" enctype="multipart/form-data" id="formPhoto">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_client">
        <label class="fl">📷 เลือกรูปใหม่ (JPG/PNG สูงสุด 5MB)</label>
        <input type="file" name="client_photo" id="clientPhotoFile" accept=".jpg,.jpeg,.png"
               style="width:100%;padding:6px;border:1px solid var(--border);border-radius:8px;font-size:.82rem;background:#fff;margin-bottom:12px;"
               onchange="document.getElementById('btnSavePhoto').disabled=false;">
        <div style="text-align:right;">
          <button type="button" class="btn-cc" onclick="closeClientModal()">ยกเลิก</button>
          <button type="submit" id="btnSavePhoto" class="btn-save" disabled>🖼️ บันทึกรูป</button>
        </div>
      </form>
    </div>

    <!-- ===== Panel ข้อมูลส่วนตัว: รอ Admin อนุมัติ ===== -->
    <div id="panelInfo" style="display:none;">
      <?php if ($myPendingReq): ?>
      <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px;margin-bottom:12px;">
        <div style="font-size:.82rem;font-weight:700;color:#92400e;">⏳ มีคำขอแก้ไขรออนุมัติอยู่</div>
        <div style="font-size:.77rem;color:#b45309;margin-top:4px;">
          ส่งเมื่อ: <?= date('d/m/Y H:i', strtotime($myPendingReq['created_at'])) ?><br>
          <?php if ($myPendingReq['req_fname']): ?>ชื่อ: <strong><?= htmlspecialchars($myPendingReq['req_fname'].' '.$myPendingReq['req_lname']) ?></strong><br><?php endif; ?>
          <?php if ($myPendingReq['req_phone']): ?>เบอร์: <strong><?= htmlspecialchars($myPendingReq['req_phone']) ?></strong><?php endif; ?>
        </div>
        <div style="font-size:.71rem;color:#b45309;margin-top:4px;">⚠️ ส่งคำขอใหม่จะยกเลิกคำขอเดิมอัตโนมัติ</div>
      </div>
      <?php else: ?>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:.8rem;color:#1e40af;">
        ℹ️ ข้อมูลส่วนตัวจะ <strong>รอ Admin ยืนยัน</strong> ก่อนมีผล
      </div>
      <?php endif; ?>

      <form method="POST" id="formInfo">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_client">
        <div class="fg">
          <div>
            <label class="fl">👤 ชื่อ</label>
            <input type="text" name="fname" class="fi" value="<?= htmlspecialchars($meClient['fname']??'') ?>">
          </div>
          <div>
            <label class="fl">👤 นามสกุล</label>
            <input type="text" name="lname" class="fi" value="<?= htmlspecialchars($meClient['lname']??'') ?>">
          </div>
          <div>
            <label class="fl">📞 เบอร์โทร</label>
            <input type="text" name="phone" class="fi" value="<?= htmlspecialchars($meClient['phone']??'') ?>" placeholder="0812345678">
          </div>
          <div>
            <label class="fl">
              🪪 บัตรประชาชน
              <?php if ($meClient['citizen_id']): ?>
                <span style="color:var(--muted);font-size:.67rem;">🔒 ติดต่อ Admin เพื่อเปลี่ยน</span>
              <?php else: ?>
                <span style="color:#d97706;font-size:.67rem;">⚠️ กรอกครั้งแรก</span>
              <?php endif; ?>
            </label>
            <?php if ($meClient['citizen_id']): ?>
              <input type="text" class="fi" value="<?= substr($meClient['citizen_id'],0,4) ?>X-XXXX-XXXXX-XX-X"
                     readonly style="background:#f1f5f9;color:var(--muted);">
            <?php else: ?>
              <input type="text" name="citizen_id" class="fi" maxlength="13" placeholder="กรอก 13 หลัก">
            <?php endif; ?>
          </div>
          <div style="grid-column:1/-1;">
            <label class="fl">📧 อีเมล <span style="color:var(--red);font-size:.67rem;">🔒 ติดต่อ Admin เพื่อเปลี่ยน</span></label>
            <input type="text" class="fi" value="<?= htmlspecialchars($meClient['email']??'') ?>"
                   readonly style="background:#f1f5f9;color:var(--muted);">
          </div>
          <div style="grid-column:1/-1;">
            <label class="fl">📍 ที่อยู่</label>
            <textarea name="address" class="fta"><?= htmlspecialchars($meClient['address']??'') ?></textarea>
          </div>
        </div>
        <div style="text-align:right;margin-top:10px;">
          <button type="button" class="btn-cc" onclick="closeClientModal()">ยกเลิก</button>
          <button type="submit" class="btn-save" style="background:#d97706;">📋 ส่งคำขอแก้ไข</button>
        </div>
      </form>
    </div>

  </div>
</div>
<?php endif; ?>

<!-- Admin: Modal ยืนยันคำขอแก้ไขข้อมูล -->
<?php if ($role === 'admin' && !empty($pendingProfileReqs)): ?>
<?php foreach ($pendingProfileReqs as $req): ?>
<div class="modal-ov" id="reqModal<?= $req['req_id'] ?>" onclick="this.style.display='none'">
  <div class="modal-box" style="max-width:480px;" onclick="event.stopPropagation()">
    <div class="m-title">🔍 ตรวจสอบคำขอแก้ไขข้อมูล</div>
    <div style="font-size:.8rem;color:#64748b;margin-bottom:12px;">
      👤 <?= htmlspecialchars($req['current_name']) ?> · <?= htmlspecialchars($req['email']) ?><br>
      ส่งเมื่อ <?= date('d/m/Y H:i', strtotime($req['created_at'])) ?>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:.82rem;margin-bottom:14px;">
      <tr style="background:#f8fafc;">
        <th style="padding:6px 10px;text-align:left;border:1px solid var(--border);color:var(--muted);">ฟิลด์</th>
        <th style="padding:6px 10px;text-align:left;border:1px solid var(--border);color:var(--muted);">ข้อมูลเดิม</th>
        <th style="padding:6px 10px;text-align:left;border:1px solid var(--border);color:var(--navy);">ขอเปลี่ยนเป็น</th>
      </tr>
      <?php if ($req['req_fname']): ?>
      <tr>
        <td style="padding:6px 10px;border:1px solid var(--border);">ชื่อ</td>
        <td style="padding:6px 10px;border:1px solid var(--border);color:var(--muted);"><?= htmlspecialchars($req['cur_fname']) ?></td>
        <td style="padding:6px 10px;border:1px solid var(--border);color:var(--green);font-weight:700;"><?= htmlspecialchars($req['req_fname']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($req['req_lname']): ?>
      <tr>
        <td style="padding:6px 10px;border:1px solid var(--border);">นามสกุล</td>
        <td style="padding:6px 10px;border:1px solid var(--border);color:var(--muted);"><?= htmlspecialchars($req['cur_lname']) ?></td>
        <td style="padding:6px 10px;border:1px solid var(--border);color:var(--green);font-weight:700;"><?= htmlspecialchars($req['req_lname']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($req['req_phone']): ?>
      <tr>
        <td style="padding:6px 10px;border:1px solid var(--border);">เบอร์โทร</td>
        <td style="padding:6px 10px;border:1px solid var(--border);color:var(--muted);"><?= htmlspecialchars($req['cur_phone']) ?></td>
        <td style="padding:6px 10px;border:1px solid var(--border);color:var(--green);font-weight:700;"><?= htmlspecialchars($req['req_phone']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($req['req_citizen_id']): ?>
      <tr>
        <td style="padding:6px 10px;border:1px solid var(--border);">บัตรประชาชน</td>
        <td style="padding:6px 10px;border:1px solid var(--border);color:var(--muted);"><?= $req['cur_citizen'] ? substr($req['cur_citizen'],0,4).'X-XXXX-XXXXX-XX-X' : '(ยังไม่มี)' ?></td>
        <td style="padding:6px 10px;border:1px solid var(--border);color:var(--green);font-weight:700;"><?= substr($req['req_citizen_id'],0,4) ?>X-XXXX-XXXXX-XX-X</td>
      </tr>
      <?php endif; ?>
      <?php if ($req['req_address']): ?>
      <tr>
        <td style="padding:6px 10px;border:1px solid var(--border);">ที่อยู่</td>
        <td style="padding:6px 10px;border:1px solid var(--border);color:var(--muted);"><?= htmlspecialchars(mb_substr($req['cur_phone'],0,30)) ?>...</td>
        <td style="padding:6px 10px;border:1px solid var(--border);color:var(--green);font-weight:700;"><?= htmlspecialchars(mb_substr($req['req_address'],0,50)) ?>...</td>
      </tr>
      <?php endif; ?>
    </table>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action"  value="review_profile_req">
      <input type="hidden" name="req_id"  value="<?= $req['req_id'] ?>">
      <div style="margin-bottom:10px;">
        <label class="fl">หมายเหตุ (ถ้ามี)</label>
        <input type="text" name="admin_note" class="fi" placeholder="เหตุผลอนุมัติ/ปฏิเสธ">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button type="button" class="btn-cc" onclick="document.getElementById('reqModal<?= $req['req_id'] ?>').style.display='none'">ปิด</button>
        <button type="submit" name="decision" value="rejected"
                style="padding:8px 18px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:700;cursor:pointer;">
          ❌ ปฏิเสธ
        </button>
        <button type="submit" name="decision" value="approved"
                style="padding:8px 18px;background:var(--green);color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:700;cursor:pointer;">
          ✅ อนุมัติ
        </button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
function openModal()       { var el=document.getElementById('editModal');   if(el) el.style.display='flex'; }
function closeModal()      { var el=document.getElementById('editModal');   if(el) el.style.display='none'; }
function openClientModal() { var el=document.getElementById('clientModal'); if(el){ el.style.display='flex'; switchTab('photo'); } }
function closeClientModal(){ var el=document.getElementById('clientModal'); if(el) el.style.display='none'; }
function switchTab(t) {
    var isPhoto = t === 'photo';
    var pp = document.getElementById('panelPhoto');
    var pi = document.getElementById('panelInfo');
    var tp = document.getElementById('tabPhoto');
    var ti = document.getElementById('tabInfo');
    if(pp) pp.style.display = isPhoto ? '' : 'none';
    if(pi) pi.style.display = isPhoto ? 'none' : '';
    if(tp){ tp.style.background = isPhoto ? 'var(--navy)' : '#f1f5f9'; tp.style.color = isPhoto ? '#fff' : '#64748b'; }
    if(ti){ ti.style.background = isPhoto ? '#f1f5f9' : 'var(--navy)'; ti.style.color = isPhoto ? '#64748b' : '#fff'; }
}
</script>

<?php include '../includes/footer.php'; ?>