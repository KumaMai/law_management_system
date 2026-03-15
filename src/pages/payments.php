<?php
// payments.php
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

// ==============================
// Handle: ทนายอัปโหลด QR Code
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_qr') {
    csrf_verify();
    if ($role !== 'lawyer') { header('Location: /pages/payments.php?error=permission'); exit; }
    if (!empty($_FILES['qr_file']['tmp_name']) && $_FILES['qr_file']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['qr_file']['name'], PATHINFO_EXTENSION));
 
        // ── แก้ File Upload: ตรวจ MIME type จริง ──
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['qr_file']['tmp_name']);
        finfo_close($finfo);
        $allowedMime = ['image/jpeg', 'image/png'];
 
        if (in_array($mimeType, $allowedMime) && $_FILES['qr_file']['size'] <= 5*1024*1024) {
            $saveDir = '/var/www/html/uploads/qr_codes/';
            if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
            $newName = 'qr_lawyer_' . $userId . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['qr_file']['tmp_name'], $saveDir . $newName);
            $lpRow = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
            $lpRow->execute([$userId]);
            $lid = $lpRow->fetchColumn();
            $pdo->prepare("UPDATE lawyer_profiles SET qr_code_file=? WHERE lawyer_id=?")->execute([$newName, $lid]);
        }
    }
    $rid = (int)($_POST['contract_id'] ?? 0);
    header('Location: /pages/payments.php' . ($rid ? '?contract_id='.$rid : '') . '&qr_updated=1');
    exit;
}
 
// ==============================
// Handle: ลูกความส่งหลักฐานชำระ
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    csrf_verify();
    $contractId      = (int)$_POST['contract_id'];
    $amount          = (float)$_POST['amount'];
    $method          = $_POST['payment_method'] ?? 'transfer';
    $paymentDate     = $_POST['payment_date'] ?? date('Y-m-d');
    $note            = trim($_POST['note'] ?? '');
    $installmentNote = trim($_POST['installment_note'] ?? '');
 
    $chk = $pdo->prepare("
        SELECT c.contract_id FROM contracts c
        JOIN case_requests cr ON c.request_id = cr.request_id
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        WHERE c.contract_id=? AND cp.user_id=? AND cr.office_id=?
    ");
    $chk->execute([$contractId, $userId, $officeId]);
    if (!$chk->fetch()) { header('Location: /pages/payments.php?error=permission'); exit; }
 
    $slipFile = null;
    if (!empty($_FILES['slip_file']['tmp_name']) && $_FILES['slip_file']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['slip_file']['name'], PATHINFO_EXTENSION));
 
        // ── แก้ File Upload: ตรวจ MIME type จริง ──
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['slip_file']['tmp_name']);
        finfo_close($finfo);
        $allowedMime = ['image/jpeg', 'image/png', 'application/pdf'];
 
        if (in_array($mimeType, $allowedMime) && $_FILES['slip_file']['size'] <= 10*1024*1024) {
            $saveDir = '/var/www/html/uploads/payment_slips/';
            if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
            $slipFile = 'slip_' . $contractId . '_' . time() . '_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['slip_file']['tmp_name'], $saveDir . $slipFile);
        }
    }
 
    $pdo->prepare("
        INSERT INTO payments
            (contract_id, amount, payment_method, payment_date, slip_file, note, installment_note, status, paid_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ")->execute([$contractId, $amount, $method, $paymentDate, $slipFile, $note, $installmentNote, $userId]);
 
    header('Location: /pages/payments.php?contract_id='.$contractId.'&paid=1');
    exit;
}
 
// ==============================
// Handle: ทนาย/admin ยืนยัน/ปฏิเสธ
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['confirm','reject'])) {
    csrf_verify();
    $paymentId  = (int)$_POST['payment_id'];
    $contractId = (int)$_POST['contract_id'];
    $newStatus  = ($_POST['action'] === 'confirm') ? 'confirmed' : 'rejected';
 
    if (!in_array($role, ['admin','lawyer'])) {
        header('Location: /pages/payments.php?error=permission'); exit;
    }
 
    // ── แก้ IDOR: ตรวจว่า payment_id เป็นของ contract_id นั้นจริง ──
    // และ contract_id นั้นเป็นของ lawyer คนนี้จริง
    $verify = $pdo->prepare("
        SELECT p.payment_id
        FROM payments p
        JOIN contracts c  ON p.contract_id  = c.contract_id
        JOIN case_requests cr ON c.request_id = cr.request_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE p.payment_id  = ?
          AND p.contract_id = ?
          AND cr.office_id  = ?
    ");
    $verify->execute([$paymentId, $contractId, $officeId]);
 
    if (!$verify->fetch()) {
        header('Location: /pages/payments.php?error=permission'); exit;
    }
 
    // ── แก้ Race Condition: ใช้ Transaction ──
    try {
        $pdo->beginTransaction();
 
        $pdo->prepare("
            UPDATE payments
            SET status=?, confirmed_by=?, confirmed_at=NOW()
            WHERE payment_id=?
        ")->execute([$newStatus, $userId, $paymentId]);
 
        if ($newStatus === 'confirmed') {
            // ── ใช้ SELECT ... FOR UPDATE เพื่อล็อก row ป้องกัน race condition ──
            $feeRow = $pdo->prepare("
                SELECT fee_amount FROM contracts WHERE contract_id=? FOR UPDATE
            ");
            $feeRow->execute([$contractId]);
            $feeAmount = (float)($feeRow->fetchColumn() ?? 0);
 
            $paidRow = $pdo->prepare("
                SELECT COALESCE(SUM(amount),0)
                FROM payments
                WHERE contract_id=? AND status='confirmed'
            ");
            $paidRow->execute([$contractId]);
            $totalPaid = (float)$paidRow->fetchColumn();
 
            $ps = ($feeAmount > 0 && $totalPaid >= $feeAmount) ? 'paid' : 'partial';
            $pdo->prepare("UPDATE contracts SET payment_status=? WHERE contract_id=?")
                ->execute([$ps, $contractId]);
        }
 
        $pdo->commit();
 
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Payment confirm error: ' . $e->getMessage());
        header('Location: /pages/payments.php?contract_id='.$contractId.'&error=system');
        exit;
    }
 
    header('Location: /pages/payments.php?contract_id='.$contractId.'&updated=1');
    exit;
}
 
// ==============================
// Handle: ทนายบันทึกรับเงินสดเอง
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_cash') {
    csrf_verify();
    if (!in_array($role, ['admin','lawyer'])) {
        header('Location: /pages/payments.php?error=permission'); exit;
    }
    $contractId      = (int)$_POST['contract_id'];
    $amount          = (float)$_POST['cash_amount'];
    $paymentDate     = $_POST['cash_date'] ?? date('Y-m-d');
    $note            = trim($_POST['cash_note'] ?? '');
    $installmentNote = trim($_POST['cash_installment'] ?? '');
 
    // ── ตรวจว่า contract เป็นของ office นี้ ──
    $chkC = $pdo->prepare("
        SELECT c.contract_id FROM contracts c
        JOIN case_requests cr ON c.request_id=cr.request_id
        WHERE c.contract_id=? AND cr.office_id=?
    ");
    $chkC->execute([$contractId, $officeId]);
    if (!$chkC->fetch()) {
        header('Location: /pages/payments.php?error=permission'); exit;
    }
 
    // ── แก้ Race Condition: ใช้ Transaction ──
    try {
        $pdo->beginTransaction();
 
        $pdo->prepare("
            INSERT INTO payments
                (contract_id, amount, payment_method, payment_date, note, installment_note, status, paid_by, confirmed_by, confirmed_at)
            VALUES (?, ?, 'cash', ?, ?, ?, 'confirmed', ?, ?, NOW())
        ")->execute([$contractId, $amount, $paymentDate, $note, $installmentNote, $userId, $userId]);
 
        $feeRow = $pdo->prepare("SELECT fee_amount FROM contracts WHERE contract_id=? FOR UPDATE");
        $feeRow->execute([$contractId]);
        $feeAmount = (float)($feeRow->fetchColumn() ?? 0);
 
        $paidRow = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0) FROM payments WHERE contract_id=? AND status='confirmed'
        ");
        $paidRow->execute([$contractId]);
        $totalPaid = (float)$paidRow->fetchColumn();
 
        $ps = ($feeAmount > 0 && $totalPaid >= $feeAmount) ? 'paid' : 'partial';
        $pdo->prepare("UPDATE contracts SET payment_status=? WHERE contract_id=?")
            ->execute([$ps, $contractId]);
 
        $pdo->commit();
 
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Cash payment error: ' . $e->getMessage());
        header('Location: /pages/payments.php?contract_id='.$contractId.'&error=system');
        exit;
    }
 
    header('Location: /pages/payments.php?contract_id='.$contractId.'&cash_added=1');
    exit;
}

// ==============================
// ดึงรายการสัญญา
// ==============================
if ($role === 'client') {
    $cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
    $cp->execute([$userId]);
    $clientId = $cp->fetchColumn();
    $contractsStmt = $pdo->prepare("
        SELECT c.*, cr.detail,
               CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
               lp.phone AS lawyer_phone, lp.qr_code_file AS lawyer_qr,
               COALESCE((SELECT SUM(amount) FROM payments WHERE contract_id=c.contract_id AND status='confirmed'),0) AS total_paid
        FROM contracts c
        JOIN case_requests cr ON c.request_id = cr.request_id
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.client_id=? AND c.contract_review_status='finalized'
        ORDER BY c.created_at DESC
    ");
    $contractsStmt->execute([$clientId]);
} elseif ($role === 'lawyer') {
    $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lp->execute([$userId]);
    $lawyerId = $lp->fetchColumn();
    $contractsStmt = $pdo->prepare("
        SELECT c.*, cr.detail,
               CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
               lp.phone AS lawyer_phone, lp.qr_code_file AS lawyer_qr,
               COALESCE((SELECT SUM(amount) FROM payments WHERE contract_id=c.contract_id AND status='confirmed'),0) AS total_paid
        FROM contracts c
        JOIN case_requests cr ON c.request_id = cr.request_id
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.lawyer_id=? AND c.contract_review_status='finalized'
        ORDER BY c.created_at DESC
    ");
    $contractsStmt->execute([$lawyerId]);
} else {
    $contractsStmt = $pdo->prepare("
        SELECT c.*, cr.detail,
               CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
               lp.phone AS lawyer_phone, lp.qr_code_file AS lawyer_qr,
               COALESCE((SELECT SUM(amount) FROM payments WHERE contract_id=c.contract_id AND status='confirmed'),0) AS total_paid
        FROM contracts c
        JOIN case_requests cr ON c.request_id = cr.request_id
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.office_id=? AND c.contract_review_status='finalized'
        ORDER BY c.created_at DESC
    ");
    $contractsStmt->execute([$officeId]);
}
$contracts = $contractsStmt->fetchAll();

$selectedId       = (int)($_GET['contract_id'] ?? ($contracts[0]['contract_id'] ?? 0));
$selectedContract = null;
$payments         = [];

if ($selectedId) {
    foreach ($contracts as $c) {
        if ($c['contract_id'] == $selectedId) { $selectedContract = $c; break; }
    }
    $pymtStmt = $pdo->prepare("
        SELECT p.*,
               CONCAT(cp.fname,' ',cp.lname) AS payer_name,
               CONCAT(lb.fname,' ',lb.lname) AS confirmer_name
        FROM payments p
        LEFT JOIN client_profiles cp ON cp.user_id = p.paid_by
        LEFT JOIN lawyer_profiles lb ON lb.user_id = p.confirmed_by
        WHERE p.contract_id=?
        ORDER BY p.created_at DESC
    ");
    $pymtStmt->execute([$selectedId]);
    $payments = $pymtStmt->fetchAll();
}

$myQr = null;
if ($role === 'lawyer') {
    $qrRow = $pdo->prepare("SELECT qr_code_file FROM lawyer_profiles WHERE user_id=?");
    $qrRow->execute([$userId]);
    $myQr = $qrRow->fetchColumn();
}

$methodTH    = ['transfer'=>'โอนเงิน','cash'=>'เงินสด','cheque'=>'เช็ค','other'=>'อื่นๆ'];
$methodBadge = ['transfer'=>'mb-transfer','cash'=>'mb-cash','cheque'=>'mb-cheque','other'=>'mb-other'];

$pageTitle = 'การชำระเงิน';
include '../includes/header.php';
?>
<style>
:root{--navy:#1a3a5c;--navy2:#2d5a8a;--green:#059669;--yellow:#d97706;--red:#dc2626;--blue:#2563eb;--border:#e2e8f0;--muted:#94a3b8;--bg:#f8fafc;}
.pay-layout{display:grid;grid-template-columns:290px 1fr;gap:20px;align-items:start;}
.contract-list{background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;position:sticky;top:16px;max-height:92vh;display:flex;flex-direction:column;}
.cl-head{background:var(--navy);color:#fff;padding:14px 18px;font-weight:700;font-size:.92rem;}
.cl-search{margin:10px;padding:7px 12px;border:1px solid var(--border);border-radius:8px;font-size:.83rem;width:calc(100% - 20px);outline:none;}
.cl-search:focus{border-color:var(--blue);}
.cl-items{overflow-y:auto;flex:1;padding:0 8px 10px;}
.cl-item{padding:10px 12px;border-radius:8px;cursor:pointer;border:2px solid transparent;margin-bottom:4px;transition:.15s;}
.cl-item:hover{background:#f0f7ff;border-color:#bdd7f5;}
.cl-item.active{background:#eff6ff;border-color:var(--navy);}
.ci-name{font-weight:700;font-size:.85rem;color:var(--navy);}
.ci-meta{font-size:.74rem;color:var(--muted);margin-top:2px;}
.ps-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.67rem;font-weight:700;}
.ps-paid{background:#d1fae5;color:#065f46;}.ps-partial{background:#fef3c7;color:#92400e;}.ps-pending{background:#fee2e2;color:#991b1b;}
.pay-header{background:var(--navy);color:#fff;border-radius:12px;padding:16px 20px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;}
.ph-name{font-size:1.05rem;font-weight:700;}
.ph-meta{font-size:.8rem;opacity:.73;margin-top:2px;}
.prog-wrap{background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:14px;}
.prog-bg{background:#e2e8f0;border-radius:99px;height:12px;overflow:hidden;margin:7px 0 4px;}
.prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#059669,#10b981);transition:width .6s;}
.prog-fill.partial{background:linear-gradient(90deg,#d97706,#f59e0b);}
.pay-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px;}
.pay-stat{background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:10px 12px;text-align:center;}
.ps-val{font-size:1.05rem;font-weight:700;color:var(--navy);display:block;margin-bottom:1px;}
.ps-lbl{font-size:.71rem;color:var(--muted);}
.qr-zone{background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:14px;}
.qr-zone-title{font-weight:700;color:var(--navy);font-size:.9rem;margin-bottom:10px;}
.qr-img-wrap{display:flex;align-items:flex-start;gap:18px;flex-wrap:wrap;}
.qr-img{width:155px;height:155px;object-fit:contain;border-radius:10px;border:1px solid var(--border);}
.qr-upload-box{border:2px dashed #93c5fd;border-radius:10px;padding:12px;background:#f8fbff;}
.pay-form-zone{background:#f0f7ff;border:2px dashed #93c5fd;border-radius:12px;padding:18px;margin-bottom:14px;}
.pfz-title{font-weight:700;color:var(--navy);font-size:.92rem;margin-bottom:12px;}
.pfz-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px;}
.pfz-label{font-size:.76rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;}
.pfz-input,.pfz-select{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:.84rem;background:#fff;outline:none;transition:border-color .2s;}
.pfz-input:focus,.pfz-select:focus{border-color:var(--blue);}
.pfz-file{width:100%;padding:6px;border:1px solid var(--border);border-radius:8px;font-size:.81rem;background:#fff;}
.btn-main{padding:9px 24px;background:var(--navy);color:#fff;border:none;border-radius:8px;font-size:.88rem;font-weight:700;cursor:pointer;transition:.15s;}
.btn-main:hover{background:var(--navy2);}
.btn-green{background:var(--green);}.btn-green:hover{background:#047857;}
.cash-form{background:#fff7ed;border:2px dashed #fdba74;border-radius:12px;padding:16px 18px;margin-bottom:14px;}
.pymt-card{background:#fff;border:1px solid var(--border);border-radius:10px;padding:11px 14px;margin-bottom:8px;display:flex;align-items:flex-start;gap:11px;transition:.15s;}
.pymt-card:hover{border-color:#93c5fd;}
.pymt-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
.pymt-icon.confirmed{background:#d1fae5;}.pymt-icon.pending{background:#fef3c7;}.pymt-icon.rejected{background:#fee2e2;}
.pymt-info{flex:1;min-width:0;}
.pymt-amount{font-size:1rem;font-weight:700;color:var(--navy);}
.pymt-meta{font-size:.74rem;color:var(--muted);margin-top:2px;}
.pymt-note{font-size:.79rem;color:#475569;margin-top:3px;background:#f8fafc;padding:4px 10px;border-radius:6px;}
.pymt-actions{display:flex;gap:5px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;}
.btn-sm{padding:5px 12px;border-radius:6px;font-size:.75rem;font-weight:700;border:none;cursor:pointer;text-decoration:none;display:inline-block;transition:.15s;}
.btn-confirm{background:#d1fae5;color:#065f46;}.btn-confirm:hover{background:var(--green);color:#fff;}
.btn-reject{background:#fee2e2;color:#991b1b;}.btn-reject:hover{background:var(--red);color:#fff;}
.btn-slip{background:#eff6ff;color:var(--blue);}.btn-slip:hover{background:var(--blue);color:#fff;}
.sb{padding:2px 9px;border-radius:9px;font-size:.69rem;font-weight:700;}
.sb-confirmed{background:#d1fae5;color:#065f46;}.sb-pending{background:#fef3c7;color:#92400e;}.sb-rejected{background:#fee2e2;color:#991b1b;}
.method-badge{display:inline-block;padding:1px 9px;border-radius:7px;font-size:.68rem;font-weight:700;}
.mb-transfer{background:#dbeafe;color:#1d4ed8;}.mb-cash{background:#fef9c3;color:#854d0e;}.mb-cheque{background:#f3e8ff;color:#7e22ce;}.mb-other{background:#f1f5f9;color:#64748b;}
.toast{padding:10px 15px;border-radius:8px;margin-bottom:13px;font-size:.86rem;font-weight:600;display:flex;align-items:center;gap:7px;}
.toast-ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;}
.section-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:9px;}
.section-title{font-weight:700;color:var(--navy);font-size:.9rem;}
.count-badge{background:var(--navy);color:#fff;padding:2px 11px;border-radius:11px;font-size:.73rem;font-weight:700;}
.empty-state{text-align:center;padding:36px 20px;color:var(--muted);}
</style>

<?php if (isset($_GET['paid'])): ?>
<div class="toast toast-ok">✅ ส่งหลักฐานการชำระเงินเรียบร้อย — รอทนายความยืนยัน</div>
<?php elseif (isset($_GET['cash_added'])): ?>
<div class="toast toast-ok">✅ บันทึกรับเงินสดเรียบร้อยแล้ว</div>
<?php elseif (isset($_GET['updated'])): ?>
<div class="toast toast-ok">✅ อัปเดตสถานะเรียบร้อยแล้ว</div>
<?php elseif (isset($_GET['qr_updated'])): ?>
<div class="toast toast-ok">✅ อัปโหลด QR Code เรียบร้อยแล้ว</div>
<?php endif; ?>

<div class="pay-layout">

  <!-- ===== ซ้าย ===== -->
  <div class="contract-list">
    <div class="cl-head">💳 สัญญาที่ต้องชำระ <span style="opacity:.6;font-weight:400;font-size:.79rem;">(<?= count($contracts) ?>)</span></div>
    <input type="text" class="cl-search" placeholder="ค้นหา..." onkeyup="filterList(this.value)">
    <div class="cl-items">
      <?php if (empty($contracts)): ?>
      <div style="text-align:center;padding:16px;color:var(--muted);font-size:.82rem;">ไม่มีสัญญาที่ยืนยันแล้ว</div>
      <?php endif; ?>
      <?php foreach ($contracts as $c):
        $fee  = (float)($c['fee_amount'] ?? 0);
        $paid = (float)($c['total_paid'] ?? 0);
        $pct  = $fee > 0 ? min(100, round($paid/$fee*100)) : 0;
        // คำนวณ badge จาก total_paid realtime
        if ($fee > 0 && $paid >= $fee)  $badge = ['ps-paid',    'ชำระครบ'];
        elseif ($paid > 0)              $badge = ['ps-partial', 'บางส่วน'];
        else                            $badge = ['ps-pending', 'ยังไม่ชำระ'];
      ?>
      <div class="cl-item <?= $selectedId==$c['contract_id']?'active':'' ?>"
           data-search="<?= strtolower(htmlspecialchars($c['client_name'].' '.$c['lawyer_name'].' '.($c['detail']??''))) ?>"
           onclick="location.href='/pages/payments.php?contract_id=<?= $c['contract_id'] ?>'">
        <div class="ci-name"><?= htmlspecialchars($c['client_name']) ?>
          <span class="ps-badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
        </div>
        <div class="ci-meta">
          ทนาย: <?= htmlspecialchars($c['lawyer_name']) ?><br>
          <?= $fee>0 ? number_format($fee,0).' บาท | จ่ายแล้ว '.$pct.'%' : 'ยังไม่ระบุค่าดำเนินคดี' ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ===== ขวา ===== -->
  <div>
    <?php if (!$selectedContract): ?>
    <div style="background:#fff;border-radius:14px;border:1px solid var(--border);padding:70px 20px;text-align:center;color:var(--muted);">
      <div style="font-size:2.8rem;margin-bottom:10px;opacity:.28;">💳</div>
      <div>เลือกสัญญาจากรายการทางซ้าย</div>
    </div>

    <?php else:
      $fee          = (float)($selectedContract['fee_amount'] ?? 0);
      $totalPaid    = (float)($selectedContract['total_paid'] ?? 0);
      $remaining    = max(0, $fee - $totalPaid);
      $pctPaid      = $fee > 0 ? min(100, round($totalPaid/$fee*100)) : 0;
      // ใช้ total_paid realtime แทน payment_status ที่อาจค้าง
      $isPaid       = ($fee > 0 && $totalPaid >= $fee);
      $lawyerQr     = $selectedContract['lawyer_qr'] ?? null;
      $pendingCount = count(array_filter($payments, fn($p) => $p['status'] === 'pending'));
    ?>

    <!-- Header -->
    <div class="pay-header">
      <div>
        <div class="ph-name">💼 <?= htmlspecialchars($selectedContract['client_name']) ?> — โจทก์</div>
        <div class="ph-meta">ทนาย: <?= htmlspecialchars($selectedContract['lawyer_name']) ?><?= $selectedContract['lawyer_phone'] ? ' | โทร. '.$selectedContract['lawyer_phone'] : '' ?></div>
        <div class="ph-meta" style="margin-top:2px;"><?= htmlspecialchars(mb_substr($selectedContract['detail']??'',0,80)) ?></div>
      </div>
      <div style="text-align:right;">
        <div style="font-size:1.45rem;font-weight:700;"><?= $fee>0 ? number_format($fee,2).' บาท' : 'ยังไม่ระบุ' ?></div>
        <div style="font-size:.74rem;opacity:.7;">ค่าดำเนินคดีทั้งหมด</div>
      </div>
    </div>

    <!-- Progress -->
    <div class="prog-wrap">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div style="font-weight:700;color:var(--navy);font-size:.88rem;">สถานะการชำระเงิน</div>
        <div style="font-weight:700;color:<?= $isPaid?'#059669':($pctPaid>0?'#d97706':'#dc2626') ?>;">
          <?= $pctPaid ?>% <?= $isPaid ? '✅ ชำระครบ' : ($pctPaid>0 ? '⏳ บางส่วน' : '❌ ยังไม่ชำระ') ?>
        </div>
      </div>
      <div class="prog-bg">
        <div class="prog-fill <?= $pctPaid<100&&$pctPaid>0?'partial':'' ?>" style="width:<?= $pctPaid ?>%"></div>
      </div>
      <div class="pay-stats">
        <div class="pay-stat"><span class="ps-val"><?= number_format($fee,2) ?></span><span class="ps-lbl">ค่าดำเนินคดี (บาท)</span></div>
        <div class="pay-stat"><span class="ps-val" style="color:var(--green);"><?= number_format($totalPaid,2) ?></span><span class="ps-lbl">ยืนยันแล้ว (บาท)</span></div>
        <div class="pay-stat"><span class="ps-val" style="color:<?= $remaining>0?'var(--red)':'var(--green)' ?>;"><?= number_format($remaining,2) ?></span><span class="ps-lbl">คงเหลือ (บาท)</span></div>
      </div>
    </div>

    <!-- QR Zone -->
    <?php if ($lawyerQr || $role === 'lawyer'): ?>
    <div class="qr-zone">
      <div class="qr-zone-title">📱 QR Code สำหรับชำระเงิน</div>
      <div class="qr-img-wrap">
        <!-- รูป QR -->
        <?php if ($lawyerQr): ?>
        <div style="text-align:center;">
          <img src="/uploads/qr_codes/<?= htmlspecialchars($lawyerQr) ?>" class="qr-img" alt="QR Code">
          <div style="font-size:.72rem;color:var(--muted);margin-top:5px;">QR ของ <?= htmlspecialchars($selectedContract['lawyer_name']) ?></div>
          <?php if ($role === 'client'): ?>
          <button onclick="openQr()" style="margin-top:7px;padding:5px 14px;background:var(--navy);color:#fff;border:none;border-radius:6px;font-size:.77rem;cursor:pointer;">🔍 ขยาย QR</button>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="width:155px;height:155px;border-radius:10px;border:2px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;flex-direction:column;color:var(--muted);font-size:.79rem;text-align:center;gap:6px;">
          <div style="font-size:2rem;">📷</div>ยังไม่มี QR Code
        </div>
        <?php endif; ?>

        <!-- ทนายอัปโหลด QR -->
        <?php if ($role === 'lawyer'): ?>
        <div class="qr-upload-box" style="min-width:190px;">
          <div style="font-size:.8rem;font-weight:700;color:var(--navy);margin-bottom:7px;"><?= $myQr ? '🔄 เปลี่ยน QR Code' : '⬆️ อัปโหลด QR Code' ?></div>
          <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_qr">
            <input type="hidden" name="contract_id" value="<?= $selectedId ?>">
            <input type="file" name="qr_file" accept=".jpg,.jpeg,.png" required
                   style="width:100%;padding:5px;border:1px solid var(--border);border-radius:6px;font-size:.79rem;background:#fff;margin-bottom:7px;">
            <div style="font-size:.67rem;color:var(--muted);margin-bottom:7px;">JPG/PNG | สูงสุด 5MB<br>ใช้กับทุกคดีของคุณ</div>
            <button type="submit" class="btn-main" style="padding:7px 16px;font-size:.8rem;width:100%;">💾 บันทึก QR</button>
          </form>
        </div>
        <?php endif; ?>

        <!-- คำแนะนำสำหรับลูกความ -->
        <?php if ($lawyerQr && $role === 'client'): ?>
        <div style="flex:1;min-width:180px;">
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;">
            <div style="font-weight:700;color:#065f46;font-size:.83rem;margin-bottom:6px;">📋 วิธีชำระผ่าน QR</div>
            <ol style="font-size:.78rem;color:#166534;margin:0;padding-left:17px;line-height:1.9;">
              <li>เปิดแอปธนาคาร / พร้อมเพย์</li>
              <li>สแกน QR Code ด้านซ้าย</li>
              <li>กรอกยอดเงินที่ต้องการจ่าย</li>
              <li>ยืนยัน แล้วถ่ายรูปสลิป</li>
              <li>แนบสลิปในฟอร์มด้านล่าง</li>
            </ol>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ฟอร์มลูกความแจ้งชำระ -->
    <?php if ($role === 'client'): ?>
    <div class="pay-form-zone">
      <div class="pfz-title">💳 แจ้งการชำระเงิน</div>
      <?php if ($pendingCount > 0): ?>
      <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:.8rem;color:#92400e;">
        ⏳ มีรายการรอยืนยัน <?= $pendingCount ?> รายการ — สามารถส่งรายการใหม่เพิ่มได้
      </div>
      <?php endif; ?>
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pay">
        <input type="hidden" name="contract_id" value="<?= $selectedId ?>">
        <div class="pfz-grid">
          <div>
            <label class="pfz-label">จำนวนเงิน (บาท) <span style="color:red">*</span></label>
            <input type="number" name="amount" class="pfz-input" required step="0.01" min="1" placeholder="ระบุจำนวน (ไม่จำกัดยอด)">
          </div>
          <div>
            <label class="pfz-label">วันที่ชำระ <span style="color:red">*</span></label>
            <input type="date" name="payment_date" class="pfz-input" required value="<?= date('Y-m-d') ?>">
          </div>
          <div>
            <label class="pfz-label">ช่องทาง</label>
            <select name="payment_method" class="pfz-select">
              <option value="transfer">🏦 โอนเงิน / PromptPay / QR</option>
              <option value="cash">💵 เงินสด</option>
              <option value="other">อื่นๆ</option>
            </select>
          </div>
          <div>
            <label class="pfz-label">ประเภทการจ่าย</label>
            <select name="installment_note" class="pfz-select">
              <option value="">— เลือกประเภท —</option>
              <option value="จ่ายเต็มจำนวน">💯 จ่ายเต็มจำนวน</option>
              <option value="จ่ายบางส่วน">📊 จ่ายบางส่วน</option>
              <option value="จ่ายล่วงหน้า">⏩ จ่ายล่วงหน้า</option>
              <option value="มัดจำ">🔒 มัดจำ</option>
            </select>
          </div>
          <div>
            <label class="pfz-label">แนบสลิป / หลักฐาน</label>
            <input type="file" name="slip_file" class="pfz-file" accept=".jpg,.jpeg,.png,.pdf">
            <div style="font-size:.68rem;color:var(--muted);margin-top:2px;">JPG, PNG, PDF | สูงสุด 10MB</div>
          </div>
          <div>
            <label class="pfz-label">หมายเหตุ</label>
            <input type="text" name="note" class="pfz-input" placeholder="เลขอ้างอิง หรือข้อความถึงทนาย...">
          </div>
        </div>
        <button type="submit" class="btn-main" style="margin-top:12px;">📤 ส่งหลักฐานการชำระเงิน</button>
      </form>
    </div>
    <?php endif; ?>

    <!-- ฟอร์มทนายบันทึกรับเงินสด -->
    <?php if (in_array($role, ['admin','lawyer'])): ?>
    <div class="cash-form">
      <div style="font-weight:700;color:#92400e;font-size:.9rem;margin-bottom:11px;">💵 บันทึกรับเงินสด — ทนายยืนยันเอง</div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_cash">
        <input type="hidden" name="contract_id" value="<?= $selectedId ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
          <div>
            <label class="pfz-label">จำนวนเงิน (บาท) <span style="color:red">*</span></label>
            <input type="number" name="cash_amount" class="pfz-input" required step="0.01" min="1" placeholder="ระบุจำนวน">
          </div>
          <div>
            <label class="pfz-label">วันที่รับเงิน</label>
            <input type="date" name="cash_date" class="pfz-input" value="<?= date('Y-m-d') ?>">
          </div>
          <div>
            <label class="pfz-label">ประเภทการจ่าย</label>
            <select name="cash_installment" class="pfz-select">
              <option value="">— เลือกประเภท —</option>
              <option value="จ่ายเต็มจำนวน">💯 จ่ายเต็มจำนวน</option>
              <option value="จ่ายบางส่วน">📊 จ่ายบางส่วน</option>
              <option value="จ่ายล่วงหน้า">⏩ จ่ายล่วงหน้า</option>
              <option value="มัดจำ">🔒 มัดจำ</option>
            </select>
          </div>
          <div style="grid-column:1/-1;">
            <label class="pfz-label">หมายเหตุ</label>
            <input type="text" name="cash_note" class="pfz-input" placeholder="บันทึกเพิ่มเติม...">
          </div>
        </div>
        <button type="submit" class="btn-main btn-green" style="margin-top:11px;"
                onclick="return confirm('ยืนยันการรับเงินสดจำนวนนี้?')">✅ ยืนยันรับเงินสด</button>
      </form>
    </div>
    <?php endif; ?>

    <!-- ประวัติ -->
    <div>
      <div class="section-head">
        <div class="section-title">ประวัติการชำระเงินทั้งหมด</div>
        <span class="count-badge"><?= count($payments) ?> รายการ</span>
      </div>

      <?php if (empty($payments)): ?>
      <div class="empty-state"><div style="font-size:2.3rem;opacity:.28;margin-bottom:7px;">📭</div><div>ยังไม่มีประวัติ</div></div>
      <?php else: ?>
      <?php foreach ($payments as $p):
        $si = match($p['status']) {
          'confirmed' => ['✅','confirmed','sb-confirmed','ยืนยันแล้ว'],
          'rejected'  => ['❌','rejected', 'sb-rejected', 'ปฏิเสธ'],
          default     => ['⏳','pending',  'sb-pending',  'รอยืนยัน'],
        };
      ?>
      <div class="pymt-card">
        <div class="pymt-icon <?= $si[1] ?>"><?= $si[0] ?></div>
        <div class="pymt-info">
          <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
            <span class="pymt-amount"><?= number_format((float)$p['amount'],2) ?> บาท</span>
            <span class="sb <?= $si[2] ?>"><?= $si[3] ?></span>
            <span class="method-badge <?= $methodBadge[$p['payment_method']]??'mb-other' ?>"><?= $methodTH[$p['payment_method']]??$p['payment_method'] ?></span>
            <?php if (!empty($p['installment_note'])): ?>
            <span style="background:#f3e8ff;color:#6b21a8;padding:1px 9px;border-radius:7px;font-size:.68rem;font-weight:700;">📌 <?= htmlspecialchars($p['installment_note']) ?></span>
            <?php endif; ?>
          </div>
          <div class="pymt-meta">
            วันที่ชำระ: <?= date('d/m/Y', strtotime($p['payment_date'])) ?>
            | แจ้งเมื่อ: <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?>
            <?= $p['payer_name'] ? ' | โดย: '.htmlspecialchars($p['payer_name']) : '' ?>
          </div>
          <?php if (!empty($p['note'])): ?>
          <div class="pymt-note">📝 <?= htmlspecialchars($p['note']) ?></div>
          <?php endif; ?>
          <?php if ($p['confirmed_at']): ?>
          <div style="font-size:.71rem;color:var(--muted);margin-top:2px;">
            ยืนยันเมื่อ: <?= date('d/m/Y H:i', strtotime($p['confirmed_at'])) ?>
            <?= $p['confirmer_name'] ? ' โดย '.htmlspecialchars($p['confirmer_name']) : '' ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="pymt-actions">
          <?php if (!empty($p['slip_file'])): ?>
          <a href="/uploads/payment_slips/<?= urlencode($p['slip_file']) ?>" target="_blank" class="btn-sm btn-slip">🧾 สลิป</a>
          <?php endif; ?>
          <?php if (in_array($role,['admin','lawyer']) && $p['status']==='pending'): ?>
          <form method="POST" style="margin:0;display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="payment_id" value="<?= $p['payment_id'] ?>">
            <input type="hidden" name="contract_id" value="<?= $selectedId ?>">
            <button type="submit" class="btn-sm btn-confirm" onclick="return confirm('ยืนยันการชำระเงินนี้?')">✅ ยืนยัน</button>
          </form>
          <form method="POST" style="margin:0;display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="payment_id" value="<?= $p['payment_id'] ?>">
            <input type="hidden" name="contract_id" value="<?= $selectedId ?>">
            <button type="submit" class="btn-sm btn-reject" onclick="return confirm('ปฏิเสธรายการนี้?')">❌ ปฏิเสธ</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php endif; ?>
  </div>
</div>

<!-- QR Modal -->
<div id="qrModal" onclick="closeQr()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:9999;align-items:center;justify-content:center;cursor:zoom-out;">
  <div onclick="event.stopPropagation()" style="background:#fff;border-radius:16px;padding:22px;text-align:center;max-width:340px;width:90%;">
    <div style="font-weight:700;color:var(--navy);margin-bottom:10px;">📱 QR Code ชำระเงิน</div>
    <?php if ($lawyerQr ?? null): ?>
    <img src="/uploads/qr_codes/<?= htmlspecialchars($lawyerQr ?? '') ?>" style="width:100%;max-width:270px;border-radius:10px;">
    <div style="font-size:.76rem;color:var(--muted);margin-top:8px;">สแกนด้วยแอปธนาคาร → กรอกยอดเงินเอง</div>
    <?php endif; ?>
    <button onclick="closeQr()" style="margin-top:12px;padding:7px 22px;background:var(--navy);color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:700;">ปิด</button>
  </div>
</div>

<script>
function filterList(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.cl-item').forEach(el => el.style.display = el.dataset.search.includes(q) ? '' : 'none');
}
function openQr()  { document.getElementById('qrModal').style.display = 'flex'; }
function closeQr() { document.getElementById('qrModal').style.display = 'none'; }
</script>

<?php include '../includes/footer.php'; ?>