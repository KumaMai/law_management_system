<?php
// client_sign_docs.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/file_upload_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$officeId = $_SESSION['office_id'];
$userId   = $_SESSION['user_id'];

if (!in_array($role, ['admin','lawyer','client'])) {
    header('Location: /pages/dashboard.php'); exit;
}

// ==============================
// ดึง ID ของ user ตาม role
// ==============================
$lawyerId = null;
$clientId = null;
if ($role === 'lawyer') {
    $r = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $r->execute([$userId]); $lawyerId = $r->fetchColumn();
}
if ($role === 'client') {
    $r = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
    $r->execute([$userId]); $clientId = $r->fetchColumn();
}

// ==============================
// Handle: ทนาย/admin อัปโหลดเอกสาร
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_doc') {
    csrf_verify();
    if (!in_array($role, ['admin','lawyer'])) { header('Location: /pages/client_sign_docs.php'); exit; }

    $requestId  = (int)$_POST['request_id'];
    $docType    = trim($_POST['doc_type']  ?? '');
    $docTitle   = trim($_POST['doc_title'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $dueDate    = trim($_POST['due_date']  ?? '') ?: null;

    // ตรวจสิทธิ์ — ทนายต้องเป็นทนายในคดีนี้
    if ($role === 'lawyer') {
        $chk = $pdo->prepare("SELECT 1 FROM case_requests WHERE request_id=? AND lawyer_id=? AND office_id=?");
        $chk->execute([$requestId, $lawyerId, $officeId]);
        if (!$chk->fetch()) { header('Location: /pages/client_sign_docs.php?err=noauth'); exit; }
    }

    // Debug: แสดง error code จริง
    if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] === UPLOAD_ERR_NO_FILE) {
        header('Location: /pages/client_sign_docs.php?err=nofile'); exit;
    }
    if ($_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['pdf_file']['error'];
        header('Location: /pages/client_sign_docs.php?err=upload&code='.$errCode); exit;
    }
    if (empty($_FILES['pdf_file']['tmp_name'])) {
        header('Location: /pages/client_sign_docs.php?err=nofile'); exit;
    }

    $ext = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        header('Location: /pages/client_sign_docs.php?err=notpdf'); exit;
    }
    if ($_FILES['pdf_file']['size'] > 20*1024*1024) {
        header('Location: /pages/client_sign_docs.php?err=toobig'); exit;
    }

    $dir = '/var/www/html/uploads/sign_docs/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'signdoc_'.$requestId.'_'.time().'.'.$ext;
    move_uploaded_file($_FILES['pdf_file']['tmp_name'], $dir.$filename);

    $pdo->prepare("
        INSERT INTO client_sign_docs (request_id, office_id, uploaded_by, doc_type, doc_title, description, file_path, due_date)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([$requestId, $officeId, $userId, $docType, $docTitle, $desc, $filename, $dueDate]);

    header('Location: /pages/client_sign_docs.php?uploaded=1&rid='.$requestId); exit;
}

// ==============================
// Handle: ลูกความรับทราบเอกสาร
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acknowledge') {
    if ($role !== 'client') { header('Location: /pages/client_sign_docs.php'); exit; }
    csrf_verify();

    $docId = (int)$_POST['doc_id'];
    $note  = trim($_POST['client_note'] ?? '');

    // ตรวจสิทธิ์
    $chk = $pdo->prepare("
        SELECT d.doc_id FROM client_sign_docs d
        JOIN case_requests cr ON d.request_id = cr.request_id
        WHERE d.doc_id=? AND cr.client_id=? AND d.office_id=?
    ");
    $chk->execute([$docId, $clientId, $officeId]);
    if ($chk->fetch()) {
        $pdo->prepare("UPDATE client_sign_docs SET status='acknowledged', client_note=?, ack_at=NOW() WHERE doc_id=?")
            ->execute([$note, $docId]);
    }
    header('Location: /pages/client_sign_docs.php?acked=1'); exit;
}

// ==============================
// Handle: ลูกความปฏิเสธเอกสาร
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_doc') {
    if ($role !== 'client') { header('Location: /pages/client_sign_docs.php'); exit; }
    csrf_verify();

    $docId = (int)$_POST['doc_id'];
    $note  = trim($_POST['client_note'] ?? '');

    $chk = $pdo->prepare("
        SELECT d.doc_id FROM client_sign_docs d
        JOIN case_requests cr ON d.request_id = cr.request_id
        WHERE d.doc_id=? AND cr.client_id=? AND d.office_id=?
    ");
    $chk->execute([$docId, $clientId, $officeId]);
    if ($chk->fetch()) {
        $pdo->prepare("UPDATE client_sign_docs SET status='rejected', client_note=? WHERE doc_id=?")
            ->execute([$note, $docId]);
    }
    header('Location: /pages/client_sign_docs.php?rejected=1'); exit;
}

// ==============================
// Handle: ลูกความส่ง PDF ที่เซ็นแล้วกลับ
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_signed') {
    csrf_verify();
    if ($role !== 'client') { header('Location: /pages/client_sign_docs.php'); exit; }

    $docId = (int)$_POST['doc_id'];

    // ตรวจสิทธิ์
    $chk = $pdo->prepare("
        SELECT d.doc_id FROM client_sign_docs d
        JOIN case_requests cr ON d.request_id=cr.request_id
        WHERE d.doc_id=? AND cr.client_id=? AND d.office_id=?
        AND d.status IN ('pending','acknowledged')
    ");
    $chk->execute([$docId, $clientId, $officeId]);
    if (!$chk->fetch()) { header('Location: /pages/client_sign_docs.php?err=noauth'); exit; }

    if (empty($_FILES['signed_pdf']['tmp_name']) || $_FILES['signed_pdf']['error'] !== UPLOAD_ERR_OK) {
        header('Location: /pages/client_sign_docs.php?err=nofile'); exit;
    }
    $ext = strtolower(pathinfo($_FILES['signed_pdf']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') { header('Location: /pages/client_sign_docs.php?err=notpdf'); exit; }
    if ($_FILES['signed_pdf']['size'] > 20*1024*1024) { header('Location: /pages/client_sign_docs.php?err=toobig'); exit; }

    $dir = '/var/www/html/uploads/sign_docs/signed/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $filename = 'signed_'.$docId.'_'.time().'.pdf';
    move_uploaded_file($_FILES['signed_pdf']['tmp_name'], $dir.$filename);

    $note = trim($_POST['client_note'] ?? '');
    try {
        $pdo->prepare("UPDATE client_sign_docs SET status='signed', signed_file=?, client_note=?, ack_at=COALESCE(ack_at,NOW()) WHERE doc_id=?")
            ->execute([$filename, $note, $docId]);
    } catch(Exception $e) {
        // fallback ถ้า signed_file column ยังไม่มี
        $pdo->prepare("UPDATE client_sign_docs SET status='signed', client_note=?, ack_at=COALESCE(ack_at,NOW()) WHERE doc_id=?")
            ->execute([$note, $docId]);
    }
    header('Location: /pages/client_sign_docs.php?signed=1'); exit;
}

// ==============================
// Handle: ลบเอกสาร (ทนาย/admin)
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_doc') {
    csrf_verify();
    if (!in_array($role, ['admin','lawyer'])) { header('Location: /pages/client_sign_docs.php'); exit; }

    $docId = (int)$_POST['doc_id'];
    $r = $pdo->prepare("SELECT file_path, uploaded_by FROM client_sign_docs WHERE doc_id=? AND office_id=?");
    $r->execute([$docId, $officeId]); $docRow = $r->fetch();

    if ($docRow && ($role === 'admin' || $docRow['uploaded_by'] === $userId)) {
        @unlink('/var/www/html/uploads/sign_docs/'.$docRow['file_path']);
        $pdo->prepare("DELETE FROM client_sign_docs WHERE doc_id=?")->execute([$docId]);
    }
    header('Location: /pages/client_sign_docs.php?deleted=1'); exit;
}

// ==============================
// Handle: ทนาย remind ลูกความ (บันทึกเวลา + note)
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remind') {
    if (!in_array($role, ['admin','lawyer'])) { header('Location: /pages/client_sign_docs.php'); exit; }
    csrf_verify();
    $docId = (int)$_POST['doc_id'];
    try {
        $pdo->prepare("UPDATE client_sign_docs SET last_remind_at=NOW() WHERE doc_id=? AND office_id=?")
            ->execute([$docId, $officeId]);
    } catch(Exception $e) {}
    header('Location: /pages/client_sign_docs.php?reminded=1'); exit;
}

// ==============================
// Handle: ทนายเพิ่ม note ติดตาม
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_lawyer_note') {
    if (!in_array($role, ['admin','lawyer'])) { header('Location: /pages/client_sign_docs.php'); exit; }
    csrf_verify();
    $docId = (int)$_POST['doc_id'];
    $note  = trim($_POST['lawyer_note'] ?? '');
    try {
        $pdo->prepare("UPDATE client_sign_docs SET lawyer_note=? WHERE doc_id=? AND office_id=?")
            ->execute([$note, $docId, $officeId]);
    } catch(Exception $e) {}
    header('Location: /pages/client_sign_docs.php?note_saved=1'); exit;
}

// ==============================
// ดึงรายการคดีสำหรับ dropdown
// ==============================
if ($role === 'lawyer') {
    $caseStmt = $pdo->prepare("
        SELECT cr.request_id,
               CONCAT('คดี #', cr.request_id, ' — ', cp.fname, ' ', cp.lname) AS case_label,
               cp.fname, cp.lname
        FROM case_requests cr
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        WHERE cr.lawyer_id=? AND cr.office_id=? AND cr.status='approved'
        ORDER BY cr.request_id DESC
    ");
    $caseStmt->execute([$lawyerId, $officeId]);
} elseif ($role === 'admin') {
    $caseStmt = $pdo->prepare("
        SELECT cr.request_id,
               CONCAT('คดี #', cr.request_id, ' — ', cp.fname, ' ', cp.lname, ' / ทนาย ', lp.fname, ' ', lp.lname) AS case_label
        FROM case_requests cr
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.office_id=? AND cr.status='approved'
        ORDER BY cr.request_id DESC
    ");
    $caseStmt->execute([$officeId]);
} else {
    $caseStmt = null;
}
$cases = $caseStmt ? $caseStmt->fetchAll() : [];

// ==============================
// ดึงเอกสารทั้งหมด
// ==============================
$filterRid = (int)($_GET['rid'] ?? 0);

if ($role === 'lawyer') {
    $sql = "
        SELECT d.*, cr.request_id, cp.fname AS cli_fname, cp.lname AS cli_lname,
               cr.detail AS case_detail,
               DATEDIFF(NOW(), d.due_date) AS days_overdue
        FROM client_sign_docs d
        JOIN case_requests cr ON d.request_id = cr.request_id
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        WHERE cr.lawyer_id=? AND d.office_id=?
        ".($filterRid ? "AND d.request_id=?" : "")."
        ORDER BY FIELD(d.status,'pending','acknowledged','signed','rejected'), d.created_at DESC
    ";
    $params = [$lawyerId, $officeId];
    if ($filterRid) $params[] = $filterRid;
    $docStmt = $pdo->prepare($sql); $docStmt->execute($params);

} elseif ($role === 'admin') {
    $sql = "
        SELECT d.*, cr.request_id, cp.fname AS cli_fname, cp.lname AS cli_lname,
               lp.fname AS law_fname, lp.lname AS law_lname, cr.detail AS case_detail,
               DATEDIFF(NOW(), d.due_date) AS days_overdue
        FROM client_sign_docs d
        JOIN case_requests cr ON d.request_id = cr.request_id
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE d.office_id=?
        ".($filterRid ? "AND d.request_id=?" : "")."
        ORDER BY FIELD(d.status,'pending','acknowledged','signed','rejected'), d.created_at DESC
    ";
    $params = [$officeId];
    if ($filterRid) $params[] = $filterRid;
    $docStmt = $pdo->prepare($sql); $docStmt->execute($params);

} else {
    // client — ดูเฉพาะของตัวเอง
    $docStmt = $pdo->prepare("
        SELECT d.*, cr.request_id,
               lp.fname AS law_fname, lp.lname AS law_lname,
               lp.phone AS law_phone, lp.specialization,
               cr.detail AS case_detail
        FROM client_sign_docs d
        JOIN case_requests cr ON d.request_id = cr.request_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.client_id=? AND d.office_id=?
        ORDER BY FIELD(d.status,'pending','acknowledged','signed','rejected'), d.created_at DESC
    ");
    $docStmt->execute([$clientId, $officeId]);
}
$docs = $docStmt->fetchAll();

// แยก pending สำหรับ badge
$pendingCount = count(array_filter($docs, fn($d) => $d['status'] === 'pending'));

// ==============================
// Doc types
// ==============================
$docTypes = [
    'หนังสือมอบอำนาจ',
    'หนังสือยินยอม',
    'แบบฟอร์มข้อมูลคดี',
    'สัญญาเพิ่มเติม',
    'บันทึกข้อตกลง',
    'เอกสารศาล',
    'อื่นๆ',
];

$statusLabel = [
    'pending'      => ['🔴 รอดำเนินการ',   '#dc2626', '#fee2e2'],
    'acknowledged' => ['🟡 รับทราบแล้ว',    '#d97706', '#fffbeb'],
    'signed'       => ['🟢 เซ็นแล้ว',       '#059669', '#ecfdf5'],
    'rejected'     => ['⚫ ปฏิเสธ',          '#6b7280', '#f3f4f6'],
];

$pageTitle = 'เอกสารสำหรับลูกความ';
include '../includes/header.php';
?>
<style>
:root{--navy:#1a3a5c;--navy2:#2d5a8a;--green:#059669;--red:#dc2626;--yellow:#d97706;--blue:#2563eb;--border:#e2e8f0;--muted:#94a3b8;}

/* Layout */
.sd-wrap{display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start;}
@media(max-width:800px){.sd-wrap{grid-template-columns:1fr;}}

/* Sidebar */
.sd-sidebar{background:#fff;border:1px solid var(--border);border-radius:14px;overflow:hidden;position:sticky;top:14px;}
.sd-sidebar-head{background:var(--navy);color:#fff;padding:14px 16px;font-weight:700;font-size:.92rem;}

/* Upload form */
.upload-box{background:#fff;border:2px dashed var(--border);border-radius:12px;padding:18px;margin-bottom:16px;transition:.2s;}
.upload-box:hover{border-color:var(--navy2);background:#f8fafc;}

/* Document card */
.doc-card{background:#fff;border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:14px;transition:.15s;}
.doc-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.07);}
.doc-card-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:flex-start;gap:10px;}
.doc-card-body{padding:14px 18px;}
.doc-type-badge{display:inline-block;padding:2px 12px;border-radius:20px;font-size:.72rem;font-weight:700;background:#eff6ff;color:var(--blue);}
.doc-title{font-size:1rem;font-weight:700;color:var(--navy);margin:5px 0 3px;}
.doc-meta{font-size:.75rem;color:var(--muted);}
.doc-desc{background:#f8fafc;border-left:3px solid var(--navy2);padding:10px 14px;border-radius:0 8px 8px 0;font-size:.83rem;color:#334155;line-height:1.6;margin:10px 0;}
.doc-due{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:20px;font-size:.75rem;font-weight:700;color:#c2410c;}
.doc-due.overdue{background:#fee2e2;border-color:#fca5a5;color:#dc2626;}

/* Buttons */
.btn{padding:8px 18px;border:none;border-radius:8px;font-size:.83rem;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;transition:.15s;}
.btn:hover{opacity:.85;}
.btn-navy{background:var(--navy);color:#fff;}
.btn-green{background:var(--green);color:#fff;}
.btn-red{background:var(--red);color:#fff;}
.btn-gray{background:#f1f5f9;color:#475569;}
.btn-sm{padding:5px 12px;font-size:.77rem;}

/* Form */
.fl{font-size:.76rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;}
.fi{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:.83rem;background:#fff;outline:none;transition:.2s;}
.fi:focus{border-color:var(--blue);}
.fta{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:.83rem;background:#fff;outline:none;resize:vertical;min-height:70px;transition:.2s;}
.fta:focus{border-color:var(--blue);}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}

/* Toast */
.toast{padding:10px 16px;border-radius:8px;margin-bottom:14px;font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:7px;}
.t-ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;}
.t-warn{background:#fffbeb;color:#92400e;border:1px solid #fcd34d;}

/* Alert banner สำหรับ client */
.alert-pending{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border-radius:12px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;gap:14px;}
.alert-pending-icon{font-size:2rem;flex-shrink:0;}
.alert-pending-count{font-size:2rem;font-weight:800;line-height:1;}
.alert-pending-text{font-size:.85rem;line-height:1.5;opacity:.92;}

/* Modal */
.modal-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;}
.modal-box{background:#fff;border-radius:16px;padding:22px;width:90%;max-width:500px;max-height:90vh;overflow-y:auto;}
.m-title{font-weight:700;color:var(--navy);font-size:.98rem;margin-bottom:14px;}

/* PDF preview */
.pdf-frame{width:100%;height:500px;border:1px solid var(--border);border-radius:8px;}
.pdf-modal{max-width:820px;width:95%;}

/* Status bar */
.status-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
.status-chip{padding:4px 14px;border-radius:20px;font-size:.76rem;font-weight:700;cursor:pointer;border:2px solid transparent;transition:.15s;}
.status-chip.active{border-color:var(--navy);}

/* Empty state */
.empty-state{text-align:center;padding:50px 20px;color:var(--muted);}
.empty-icon{font-size:3rem;opacity:.3;margin-bottom:10px;}
</style>

<?php
// Toast messages
$_errCode = $_GET['code'] ?? '?';
$_toast = null;
if (isset($_GET['uploaded']))          $_toast = ['ok',   '✅ อัปโหลดเอกสารเรียบร้อยแล้ว'];
elseif (isset($_GET['acked']))         $_toast = ['ok',   '✅ รับทราบเอกสารเรียบร้อยแล้ว'];
elseif (isset($_GET['rejected']))      $_toast = ['warn', '⚫ ปฏิเสธเอกสารแล้ว'];
elseif (isset($_GET['deleted']))       $_toast = ['ok',   '🗑️ ลบเอกสารแล้ว'];
elseif (isset($_GET['reminded']))      $_toast = ['ok',   '🔔 ส่งการแจ้งเตือนซ้ำแล้ว'];
elseif (isset($_GET['note_saved']))    $_toast = ['ok',   '📝 บันทึก note เรียบร้อยแล้ว'];
elseif (isset($_GET['signed']))         $_toast = ['ok',   '✅ ส่ง PDF ที่เซ็นแล้วเรียบร้อย ทนายจะได้รับแจ้ง'];
elseif (isset($_GET['err'])) {
    $errMap = [
        'nofile' => '⚠️ กรุณาเลือกไฟล์ PDF ก่อนกดส่ง',
        'notpdf' => '⚠️ รองรับเฉพาะไฟล์ .pdf เท่านั้น',
        'toobig' => '⚠️ ไฟล์ใหญ่เกิน 20MB',
        'noauth' => '⚠️ ไม่มีสิทธิ์ดำเนินการ',
        'upload' => '⚠️ PHP อัปโหลดไม่สำเร็จ (error code: '.$_errCode.') — ตรวจ upload_max_filesize ใน php.ini',
    ];
    $_toast = ['warn', $errMap[$_GET['err']] ?? '⚠️ เกิดข้อผิดพลาด: '.$_GET['err']];
}
if ($_toast) {
    $cls = $_toast[0] === 'ok' ? 't-ok' : 't-warn';
    echo "<div class='toast {$cls}'>{$_toast[1]}</div>";
}
?>

<!-- แจ้งเตือน client มีเอกสารรอ -->
<?php if ($role === 'client' && $pendingCount > 0): ?>
<div class="alert-pending">
  <div class="alert-pending-icon">📄</div>
  <div style="flex:1;">
    <div style="font-size:1rem;font-weight:800;margin-bottom:3px;">มีเอกสารสำคัญรอการดำเนินการ!</div>
    <div class="alert-pending-text">ทนายความส่งเอกสาร <strong><?= $pendingCount ?> รายการ</strong> ที่ต้องการให้คุณรับทราบหรือเซ็น กรุณาตรวจสอบและดำเนินการโดยเร็ว</div>
  </div>
  <div class="alert-pending-count"><?= $pendingCount ?></div>
</div>
<?php endif; ?>

<div class="sd-wrap">

  <!-- ===== Sidebar ===== -->
  <div>
    <div class="sd-sidebar">
      <div class="sd-sidebar-head">📄 เอกสารสำหรับลูกความเซ็น</div>

      <!-- สถิติ -->
      <div style="padding:14px 16px;border-bottom:1px solid var(--border);">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
          <?php
            $allCount    = count($docs);
            $pendCount   = count(array_filter($docs, fn($d)=>$d['status']==='pending'));
            $ackedCount  = count(array_filter($docs, fn($d)=>$d['status']==='acknowledged'));
            $signedCount = count(array_filter($docs, fn($d)=>$d['status']==='signed'));
            $rejCount    = count(array_filter($docs, fn($d)=>$d['status']==='rejected'));
          ?>
          <div style="background:#f8fafc;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:800;color:var(--navy);"><?= $allCount ?></div>
            <div style="font-size:.7rem;color:var(--muted);">ทั้งหมด</div>
          </div>
          <div style="background:#fee2e2;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:800;color:var(--red);"><?= $pendCount ?></div>
            <div style="font-size:.7rem;color:var(--red);">รอดำเนินการ</div>
          </div>
          <div style="background:#fffbeb;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:800;color:var(--yellow);"><?= $ackedCount ?></div>
            <div style="font-size:.7rem;color:var(--yellow);">รับทราบแล้ว</div>
          </div>
          <div style="background:#ecfdf5;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:800;color:var(--green);"><?= $signedCount ?></div>
            <div style="font-size:.7rem;color:var(--green);">เซ็นแล้ว</div>
          </div>
        </div>
      </div>

      <!-- Filter คดี -->
      <?php if (!empty($cases) && in_array($role,['admin','lawyer'])): ?>
      <div style="padding:12px 16px;border-bottom:1px solid var(--border);">
        <label class="fl">📁 กรองตามคดี</label>
        <select class="fi" onchange="location='?rid='+this.value" style="font-size:.8rem;">
          <option value="0" <?= !$filterRid?'selected':'' ?>>— แสดงทั้งหมด —</option>
          <?php foreach ($cases as $c): ?>
          <option value="<?= $c['request_id'] ?>" <?= $filterRid==$c['request_id']?'selected':'' ?>>
            <?= htmlspecialchars($c['case_label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <!-- ลิงก์กลับ dashboard -->
      <div style="padding:12px 16px;">
        <a href="/pages/dashboard.php" class="btn btn-gray btn-sm" style="width:100%;text-align:center;display:block;">← กลับหน้าหลัก</a>
      </div>
    </div>
  </div>

  <!-- ===== Main Content ===== -->
  <div>

    <!-- ===== ฟอร์มอัปโหลด (ทนาย/admin) ===== -->
    <?php if (in_array($role,['admin','lawyer']) && !empty($cases)): ?>
    <div style="background:#fff;border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:18px;">
      <div style="background:var(--navy);color:#fff;padding:13px 18px;font-weight:700;font-size:.92rem;display:flex;justify-content:space-between;align-items:center;">
        <span>📤 ส่งเอกสารให้ลูกความ</span>
        <button type="button" onclick="toggleUploadForm()"
                style="background:rgba(255,255,255,.15);border:none;color:#fff;padding:4px 12px;border-radius:6px;cursor:pointer;font-size:.78rem;"
                id="btnToggleUpload">+ เพิ่มเอกสาร</button>
      </div>
      <div id="uploadForm" style="display:none;padding:18px;">
        <form method="POST" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_doc">
          <div class="fg2">
            <div style="grid-column:1/-1;">
              <label class="fl">📁 เลือกคดี *</label>
              <select name="request_id" class="fi" required>
                <option value="">— เลือกคดี —</option>
                <?php foreach ($cases as $c): ?>
                <option value="<?= $c['request_id'] ?>" <?= $filterRid==$c['request_id']?'selected':'' ?>>
                  <?= htmlspecialchars($c['case_label']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="fl">📂 ประเภทเอกสาร *</label>
              <select name="doc_type" class="fi" required>
                <option value="">— เลือกประเภท —</option>
                <?php foreach ($docTypes as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="fl">📆 วันที่ต้องการ (ถ้ามี)</label>
              <input type="date" name="due_date" class="fi">
            </div>
            <div style="grid-column:1/-1;">
              <label class="fl">📝 ชื่อเอกสาร *</label>
              <input type="text" name="doc_title" class="fi" placeholder="เช่น หนังสือมอบอำนาจคดีที่ดิน" required>
            </div>
            <div style="grid-column:1/-1;">
              <label class="fl">💬 รายละเอียด / คำอธิบายให้ลูกความ</label>
              <textarea name="description" class="fta"
                        placeholder="อธิบายให้ลูกความเข้าใจว่าเอกสารนี้คืออะไร ต้องทำอะไรบ้าง และส่งคืนเมื่อไหร่..."></textarea>
            </div>
            <div style="grid-column:1/-1;">
              <label class="fl">📎 แนบไฟล์ PDF * (สูงสุด 20MB)</label>
              <div class="upload-box" id="dropZone"
                   ondragover="this.style.borderColor='var(--navy)';event.preventDefault();"
                   ondragleave="this.style.borderColor='var(--border)';"
                   ondrop="handleDrop(event)">
                <div style="text-align:center;color:var(--muted);">
                  <div style="font-size:2rem;">📁</div>
                  <div style="font-size:.82rem;margin:4px 0;">ลาก PDF มาวางที่นี่ หรือ</div>
                  <label for="pdfInput" style="color:var(--blue);font-weight:700;cursor:pointer;font-size:.82rem;">คลิกเพื่อเลือกไฟล์</label>
                  <input type="file" name="pdf_file" id="pdfInput" accept=".pdf" style="display:none;"
                         onchange="showFileName(this)">
                  <div id="fileNameDisplay" style="margin-top:6px;font-size:.78rem;color:var(--navy);font-weight:600;"></div>
                </div>
              </div>
            </div>
          </div>
          <div style="text-align:right;">
            <button type="button" class="btn btn-gray btn-sm" onclick="toggleUploadForm()">ยกเลิก</button>
            <button type="submit" class="btn btn-navy" style="margin-left:8px;">📤 ส่งเอกสาร</button>
          </div>
        </form>
      </div>
    </div>
    <?php elseif (in_array($role,['admin','lawyer']) && empty($cases)): ?>
    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:.84rem;color:#92400e;">
      ⚠️ ยังไม่มีคดีที่รับไว้ (status = approved) — รับคดีก่อนแล้วค่อยส่งเอกสาร
    </div>
    <?php endif; ?>

    <!-- ===== รายการเอกสาร ===== -->
    <div style="font-weight:700;color:var(--navy);font-size:.95rem;margin-bottom:12px;">
      📋 รายการเอกสารทั้งหมด
      <?php if ($pendingCount > 0): ?>
      <span style="background:var(--red);color:#fff;border-radius:20px;padding:1px 10px;font-size:.72rem;margin-left:6px;"><?= $pendingCount ?> รอดำเนินการ</span>
      <?php endif; ?>
    </div>

    <?php if (empty($docs)): ?>
    <div class="empty-state">
      <div class="empty-icon">📂</div>
      <div style="font-size:.9rem;">ยังไม่มีเอกสาร</div>
      <?php if (in_array($role,['lawyer','admin'])): ?>
      <div style="font-size:.78rem;margin-top:5px;">กดปุ่ม "เพิ่มเอกสาร" เพื่อส่งเอกสารให้ลูกความ</div>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <?php foreach ($docs as $doc):
        [$statusText, $statusColor, $statusBg] = $statusLabel[$doc['status']] ?? ['?', '#666', '#eee'];
        $dueDate   = $doc['due_date'] ?? null;
        $isOverdue = $dueDate && strtotime($dueDate) < time() && !in_array($doc['status'],['signed','rejected']);
    ?>
    <div class="doc-card" id="doc<?= $doc['doc_id'] ?>">
      <div class="doc-card-head">
        <div style="flex:1;">
          <!-- ประเภท + สถานะ -->
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:5px;">
            <span class="doc-type-badge"><?= htmlspecialchars($doc['doc_type']) ?></span>
            <span style="padding:2px 12px;border-radius:20px;font-size:.72rem;font-weight:700;
                         color:<?= $statusColor ?>;background:<?= $statusBg ?>;border:1px solid <?= $statusColor ?>40;">
              <?= $statusText ?>
            </span>
            <?php if ($dueDate): ?>
            <span class="doc-due <?= $isOverdue?'overdue':'' ?>">
              📅 <?= $isOverdue ? '⚠️ เลยกำหนด! ' : 'ต้องการภายใน ' ?><?= date('d/m/Y', strtotime($dueDate)) ?>
            </span>
            <?php endif; ?>
          </div>
          <div class="doc-title"><?= htmlspecialchars($doc['doc_title']) ?></div>
          <!-- ชื่อทนาย/ลูกความ -->
          <div class="doc-meta">
            <?php if ($role === 'client'): ?>
              ส่งโดย ทนาย<?= htmlspecialchars($doc['law_fname'].' '.$doc['law_lname']) ?>
              <?= $doc['specialization'] ? ' · '.$doc['specialization'] : '' ?>
              <?= $doc['law_phone'] ? ' · 📞 '.$doc['law_phone'] : '' ?>
            <?php else: ?>
              ลูกความ: <?= htmlspecialchars($doc['cli_fname'].' '.$doc['cli_lname']) ?>
              <?php if ($role === 'admin'): ?> · ทนาย: <?= htmlspecialchars($doc['law_fname'].' '.$doc['law_lname']) ?><?php endif; ?>
            <?php endif; ?>
            · คดี #<?= $doc['request_id'] ?>
            · <?= date('d/m/Y H:i', strtotime($doc['created_at'])) ?>
          </div>
        </div>
        <!-- ปุ่ม action -->
        <div style="display:flex;gap:6px;align-items:flex-start;flex-shrink:0;flex-wrap:wrap;">
          <button class="btn btn-gray btn-sm"
                  onclick="previewPDF('<?= htmlspecialchars($doc['file_path']) ?>', '<?= htmlspecialchars(addslashes($doc['doc_title'])) ?>')">
            👁 ดู PDF
          </button>
          <a href="/uploads/sign_docs/<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" download
             class="btn btn-navy btn-sm">⬇ ดาวน์โหลด</a>
          <?php if (in_array($role,['admin','lawyer']) && $doc['status']==='pending'): ?>
          <form method="POST" style="margin:0;" onsubmit="return confirm('ส่งแจ้งเตือนซ้ำถึงลูกความ?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remind">
            <input type="hidden" name="doc_id"  value="<?= $doc['doc_id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:#d97706;color:#fff;">🔔 Remind</button>
          </form>
          <?php endif; ?>
          <?php if (in_array($role,['admin','lawyer'])): ?>
          <button class="btn btn-sm" style="background:#7c3aed;color:#fff;"
                  onclick="openNoteModal(<?= $doc['doc_id'] ?>, '<?= htmlspecialchars(addslashes($doc['lawyer_note']??'')) ?>')">
            📝 Note
          </button>
          <form method="POST" style="margin:0;" onsubmit="return confirm('ลบเอกสารนี้?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_doc">
            <input type="hidden" name="doc_id"  value="<?= $doc['doc_id'] ?>">
            <button type="submit" class="btn btn-red btn-sm">🗑</button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="doc-card-body">
        <!-- รายละเอียด -->
        <?php if ($doc['description']): ?>
        <div class="doc-desc">
          <div style="font-size:.72rem;font-weight:700;color:var(--navy2);margin-bottom:4px;">
            💬 <?= $role === 'client' ? 'ข้อความจากทนาย' : 'รายละเอียด/คำอธิบาย' ?>
          </div>
          <?= nl2br(htmlspecialchars($doc['description'])) ?>
        </div>
        <?php endif; ?>

        <!-- หมายเหตุลูกความ -->
        <?php if ($doc['client_note']): ?>
        <div style="background:#f0fdf4;border-left:3px solid var(--green);padding:8px 12px;border-radius:0 6px 6px 0;font-size:.8rem;color:#166534;margin-top:8px;">
          <strong>💬 หมายเหตุจากลูกความ:</strong> <?= htmlspecialchars($doc['client_note']) ?>
        </div>
        <?php endif; ?>

        <?php if ($doc['ack_at']): ?>
        <div style="font-size:.73rem;color:var(--muted);margin-top:6px;">
          ✅ รับทราบเมื่อ <?= date('d/m/Y H:i', strtotime($doc['ack_at'])) ?>
        </div>
        <?php endif; ?>

        <?php if (in_array($role,['admin','lawyer'])): ?>
        <!-- ส่วนข้อมูลการติดตาม (เห็นเฉพาะทนาย/admin) -->
        <div style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--border);">

          <?php if ($doc['status']==='signed' && !empty($doc['signed_file'])): ?>
          <!-- PDF ที่ลูกความส่งคืน -->
          <div style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:10px 14px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
            <div>
              <div style="font-weight:700;color:#065f46;font-size:.85rem;">📥 ลูกความส่ง PDF ที่เซ็นแล้วมาให้</div>
              <div style="font-size:.74rem;color:#059669;margin-top:2px;">ได้รับไฟล์เซ็นชื่อจากลูกความ กรุณาตรวจสอบ</div>
            </div>
            <div style="display:flex;gap:6px;">
              <button class="btn btn-gray btn-sm"
                      onclick="previewPDF('/signed/<?= htmlspecialchars($doc['signed_file']) ?>', 'เอกสารเซ็น — <?= htmlspecialchars(addslashes($doc['doc_title'])) ?>')">
                👁 ดู
              </button>
              <a href="/uploads/sign_docs/signed/<?= htmlspecialchars($doc['signed_file']) ?>" download
                 class="btn btn-green btn-sm">⬇ ดาวน์โหลด</a>
            </div>
          </div>
          <?php endif; ?>

          <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
            <?php if (!empty($doc['lawyer_note'])): ?>
            <div style="background:#f5f3ff;border-left:3px solid #7c3aed;padding:7px 12px;border-radius:0 6px 6px 0;font-size:.79rem;color:#4c1d95;flex:1;min-width:200px;">
              <strong>📝 Note:</strong> <?= htmlspecialchars($doc['lawyer_note']) ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($doc['last_remind_at'])): ?>
            <div style="font-size:.72rem;color:var(--muted);white-space:nowrap;">
              🔔 Remind ล่าสุด: <?= date('d/m/Y H:i', strtotime($doc['last_remind_at'])) ?>
            </div>
            <?php endif; ?>
            <?php
              $daysOver = $doc['days_overdue'] ?? null;
              if (in_array($doc['status'],['pending','acknowledged']) && $doc['due_date'] && $daysOver > 0):
            ?>
            <div style="background:#fee2e2;color:#dc2626;padding:4px 12px;border-radius:20px;font-size:.73rem;font-weight:700;white-space:nowrap;">
              ⚠️ เลยกำหนดมาแล้ว <?= $daysOver ?> วัน
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- ปุ่มสำหรับ client -->
        <?php if ($role === 'client' && in_array($doc['status'],['pending','acknowledged'])): ?>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">

          <?php if ($doc['status']==='pending'): ?>
          <!-- Step 1: รับทราบก่อน -->
          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:9px 12px;margin-bottom:10px;font-size:.8rem;color:#1e40af;">
            📌 <strong>ขั้นตอน:</strong> 1) ดาวน์โหลด PDF → 2) เซ็นชื่อ → 3) สแกน/ถ่ายรูป → 4) อัปโหลดส่งกลับ
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-green"
                    onclick="openAckModal(<?= $doc['doc_id'] ?>, '<?= htmlspecialchars(addslashes($doc['doc_title'])) ?>')">
              ✅ รับทราบ
            </button>
            <button class="btn btn-sm" style="background:#2563eb;color:#fff;"
                    onclick="openSignModal(<?= $doc['doc_id'] ?>, '<?= htmlspecialchars(addslashes($doc['doc_title'])) ?>')">
              📤 ส่ง PDF ที่เซ็นแล้ว
            </button>
            <button class="btn btn-red btn-sm"
                    onclick="openRejectModal(<?= $doc['doc_id'] ?>, '<?= htmlspecialchars(addslashes($doc['doc_title'])) ?>')">
              ❌ ปฏิเสธ
            </button>
          </div>

          <?php else: /* acknowledged */ ?>
          <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:9px 12px;margin-bottom:10px;font-size:.8rem;color:#92400e;">
            🟡 รับทราบแล้ว — กรุณาเซ็นชื่อในเอกสารแล้ว<strong>ส่ง PDF กลับ</strong>ให้ทนาย
          </div>
          <button class="btn" style="background:#2563eb;color:#fff;"
                  onclick="openSignModal(<?= $doc['doc_id'] ?>, '<?= htmlspecialchars(addslashes($doc['doc_title'])) ?>')">
            📤 ส่ง PDF ที่เซ็นแล้วให้ทนาย
          </button>
          <?php endif; ?>

        </div>
        <?php endif; ?>

        <?php if ($role === 'client' && $doc['status'] === 'signed' && !empty($doc['signed_file'])): ?>
        <div style="margin-top:10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:9px 12px;font-size:.8rem;color:#065f46;display:flex;align-items:center;justify-content:space-between;gap:10px;">
          <span>🟢 ส่ง PDF แล้ว — ทนายได้รับเอกสารที่เซ็นของคุณแล้ว</span>
          <a href="/uploads/sign_docs/signed/<?= htmlspecialchars($doc['signed_file']) ?>" target="_blank"
             style="color:#059669;font-weight:700;font-size:.76rem;white-space:nowrap;">ดูไฟล์ที่ส่ง ↗</a>
        </div>
        <?php endif; ?>

      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

  </div><!-- end main -->
</div><!-- end grid -->

<!-- Modal: Preview PDF -->
<div class="modal-ov" id="pdfModal" onclick="closeModal('pdfModal')">
  <div class="modal-box pdf-modal" onclick="event.stopPropagation()">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <div class="m-title" id="pdfModalTitle" style="margin:0;"></div>
      <button onclick="closeModal('pdfModal')" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--muted);">✕</button>
    </div>
    <iframe id="pdfFrame" class="pdf-frame" src=""></iframe>
  </div>
</div>

<!-- Modal: รับทราบเอกสาร -->
<div class="modal-ov" id="ackModal" onclick="closeModal('ackModal')">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="m-title">✅ รับทราบเอกสาร</div>
    <div id="ackDocTitle" style="font-weight:700;color:var(--navy);margin-bottom:10px;font-size:.9rem;"></div>
    <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:.81rem;color:#065f46;">
      กรุณาดาวน์โหลด PDF เซ็นชื่อ แล้วนำส่งทนายตามวิธีที่ตกลงกันไว้ กดปุ่มนี้เพื่อยืนยันว่าคุณรับทราบและดำเนินการแล้ว
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="acknowledge">
      <input type="hidden" name="doc_id" id="ackDocId">
      <label class="fl">💬 หมายเหตุ (ถ้ามี)</label>
      <textarea name="client_note" class="fta" placeholder="เช่น เซ็นแล้ว จะนำส่งพรุ่งนี้..."></textarea>
      <div style="text-align:right;margin-top:12px;">
        <button type="button" class="btn btn-gray" onclick="closeModal('ackModal')" style="margin-right:8px;">ยกเลิก</button>
        <button type="submit" class="btn btn-green">✅ ยืนยันรับทราบ</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: ปฏิเสธเอกสาร -->
<div class="modal-ov" id="rejectModal" onclick="closeModal('rejectModal')">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="m-title">❌ ปฏิเสธเอกสาร</div>
    <div id="rejectDocTitle" style="font-weight:700;color:var(--navy);margin-bottom:10px;font-size:.9rem;"></div>
    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:.81rem;color:#991b1b;">
      กรุณาระบุเหตุผลที่ไม่สามารถดำเนินการกับเอกสารนี้ได้
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject_doc">
      <input type="hidden" name="doc_id" id="rejectDocId">
      <label class="fl">💬 เหตุผล *</label>
      <textarea name="client_note" class="fta" placeholder="ระบุเหตุผล..." required></textarea>
      <div style="text-align:right;margin-top:12px;">
        <button type="button" class="btn btn-gray" onclick="closeModal('rejectModal')" style="margin-right:8px;">ยกเลิก</button>
        <button type="submit" class="btn btn-red">❌ ยืนยันปฏิเสธ</button>
      </div>
    </form>
  </div>
</div>

<script>
function closeModal(id) { document.getElementById(id).style.display='none'; }

function previewPDF(file, title) {
    document.getElementById('pdfModalTitle').textContent = title;
    document.getElementById('pdfFrame').src = '/uploads/sign_docs/' + file;
    document.getElementById('pdfModal').style.display = 'flex';
}

function openAckModal(docId, title) {
    document.getElementById('ackDocId').value = docId;
    document.getElementById('ackDocTitle').textContent = '📄 ' + title;
    document.getElementById('ackModal').style.display = 'flex';
}

function openRejectModal(docId, title) {
    document.getElementById('rejectDocId').value = docId;
    document.getElementById('rejectDocTitle').textContent = '📄 ' + title;
    document.getElementById('rejectModal').style.display = 'flex';
}

function toggleUploadForm() {
    var f = document.getElementById('uploadForm');
    var b = document.getElementById('btnToggleUpload');
    if (f.style.display === 'none') {
        f.style.display = 'block';
        b.textContent = '✕ ปิด';
    } else {
        f.style.display = 'none';
        b.textContent = '+ เพิ่มเอกสาร';
    }
}

function showFileName(input) {
    var name = input.files[0] ? input.files[0].name : '';
    document.getElementById('fileNameDisplay').textContent = name ? '📎 ' + name : '';
}

function handleDrop(e) {
    e.preventDefault();
    document.getElementById('dropZone').style.borderColor = 'var(--border)';
    var file = e.dataTransfer.files[0];
    if (file && file.type === 'application/pdf') {
        var input = document.getElementById('pdfInput');
        var dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        document.getElementById('fileNameDisplay').textContent = '📎 ' + file.name;
    }
}

function openSignModal(docId, title) {
    document.getElementById('signDocId').value = docId;
    document.getElementById('signDocTitle').textContent = '📄 ' + title;
    document.getElementById('signModal').style.display = 'flex';
}
function openNoteModal(docId, currentNote) {
    document.getElementById('noteDocId').value = docId;
    document.getElementById('noteText').value = currentNote;
    document.getElementById('noteModal').style.display = 'flex';
}
</script>

<!-- Modal: เพิ่ม Note ติดตาม -->
<div class="modal-ov" id="noteModal" onclick="closeModal('noteModal')">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="m-title">📝 บันทึก Note การติดตาม</div>
    <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:.8rem;color:#4c1d95;">
      บันทึกสิ่งที่ทำไปแล้ว เช่น "โทรแจ้งแล้ว 13/03", "นัดรับเอกสารพรุ่งนี้" ฯลฯ
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_lawyer_note">
      <input type="hidden" name="doc_id" id="noteDocId">
      <label class="fl">📝 Note</label>
      <textarea name="lawyer_note" id="noteText" class="fta" rows="4"
                placeholder="เช่น โทรหาลูกความแล้ว 13/03 เวลา 10:00 — รอการตอบกลับ..."></textarea>
      <div style="text-align:right;margin-top:12px;">
        <button type="button" class="btn btn-gray" onclick="closeModal('noteModal')" style="margin-right:8px;">ยกเลิก</button>
        <button type="submit" class="btn" style="background:#7c3aed;color:#fff;">💾 บันทึก Note</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: ลูกความส่ง PDF ที่เซ็นแล้ว -->
<div class="modal-ov" id="signModal" onclick="closeModal('signModal')">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="m-title">📤 ส่ง PDF ที่เซ็นแล้วให้ทนาย</div>
    <div id="signDocTitle" style="font-weight:700;color:var(--navy);margin-bottom:12px;font-size:.9rem;"></div>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:.81rem;color:#1e40af;">
      📋 <strong>ขั้นตอน:</strong><br>
      1. ดาวน์โหลด PDF ต้นฉบับจากปุ่ม "ดาวน์โหลด"<br>
      2. พิมพ์และเซ็นชื่อ หรือเซ็นดิจิทัล<br>
      3. สแกนหรือถ่ายรูปเป็น PDF<br>
      4. อัปโหลดไฟล์ด้านล่างนี้
    </div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_signed">
      <input type="hidden" name="doc_id" id="signDocId">
      <div style="margin-bottom:12px;">
        <label class="fl">📎 ไฟล์ PDF ที่เซ็นแล้ว * (สูงสุด 20MB)</label>
        <input type="file" name="signed_pdf" accept=".pdf" required
               style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;font-size:.83rem;background:#fff;">
      </div>
      <div style="margin-bottom:12px;">
        <label class="fl">💬 หมายเหตุถึงทนาย (ถ้ามี)</label>
        <textarea name="client_note" class="fta" rows="2"
                  placeholder="เช่น เซ็นแล้ว ส่งคืนตามที่ตกลงไว้..."></textarea>
      </div>
      <div style="text-align:right;">
        <button type="button" class="btn btn-gray" onclick="closeModal('signModal')" style="margin-right:8px;">ยกเลิก</button>
        <button type="submit" class="btn" style="background:#2563eb;color:#fff;">📤 ส่ง PDF ให้ทนาย</button>
      </div>
    </form>
  </div>
</div>

<?php include '../includes/footer.php'; ?>