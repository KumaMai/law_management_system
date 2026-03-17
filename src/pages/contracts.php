<?php
// contracts.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$officeId = $_SESSION['office_id'];
$userId   = $_SESSION['user_id'];
$error    = '';
$success  = '';

// ==============================
// Handle POST actions
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $contractId = (int)($_POST['contract_id'] ?? 0);
    $action     = $_POST['action'] ?? '';

    // ---- ทนาย: ยืนยันรับสัญญา ----
    if ($action === 'lawyer_accept' && $role === 'lawyer') {
        $lawyerNote  = trim($_POST['lawyer_note'] ?? '');
        $finalFee    = $_POST['fee_amount'] !== '' ? (float)$_POST['fee_amount'] : null;

        $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
        $lp->execute([$userId]);
        $lawyerId = $lp->fetchColumn();

        $chk = $pdo->prepare("
            SELECT c.contract_id FROM contracts c
            JOIN case_requests cr ON c.request_id = cr.request_id
            WHERE c.contract_id = ? AND cr.lawyer_id = ?
        ");
        $chk->execute([$contractId, $lawyerId]);

        if (!$chk->fetch()) {
            $error = 'ไม่พบสัญญาหรือไม่มีสิทธิ์';
        } else {
            $pdo->prepare("
                UPDATE contracts SET
                    contract_review_status = 'lawyer_accepted',
                    negotiation_status     = 'finalized',
                    fee_amount             = COALESCE(?, fee_amount),
                    lawyer_note            = ?,
                    negotiated_at          = NOW()
                WHERE contract_id = ?
            ")->execute([$finalFee, $lawyerNote ?: null, $contractId]);
            $success = '✅ ยืนยันรับสัญญาแล้ว';
        }
    }

    // ---- ทนาย: ตีกลับ/เสนอราคาใหม่ ----
    if ($action === 'request_revision' && $role === 'lawyer') {
        $lawyerNote  = trim($_POST['lawyer_note'] ?? '');
        $proposedFee = $_POST['proposed_fee'] !== '' ? (float)$_POST['proposed_fee'] : null;

        if (!$lawyerNote) {
            $error = 'กรุณาระบุเหตุผลหรือเงื่อนไขที่ต้องการแก้ไข';
        } else {
            $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
            $lp->execute([$userId]);
            $lawyerId = $lp->fetchColumn();

            $chk = $pdo->prepare("
                SELECT c.contract_id FROM contracts c
                JOIN case_requests cr ON c.request_id = cr.request_id
                WHERE c.contract_id = ? AND cr.lawyer_id = ?
            ");
            $chk->execute([$contractId, $lawyerId]);

            if (!$chk->fetch()) {
                $error = 'ไม่พบสัญญาหรือไม่มีสิทธิ์';
            } else {
                $pdo->prepare("
                    UPDATE contracts SET
                        contract_review_status = 'revision_requested',
                        negotiation_status     = 'revision_requested',
                        lawyer_note            = ?,
                        proposed_fee           = ?,
                        client_response        = NULL,
                        negotiated_at          = NOW()
                    WHERE contract_id = ?
                ")->execute([$lawyerNote, $proposedFee, $contractId]);
                $success = '🔄 ส่งคำขอแก้ไขสัญญาแล้ว รอลูกความตอบกลับ';
            }
        }
    }

    // ---- ทนาย: ปฏิเสธสัญญา (ยุติ) ----
    if ($action === 'lawyer_reject' && $role === 'lawyer') {
        $rejectReason = trim($_POST['reject_reason'] ?? '');
        if (!$rejectReason) {
            $error = 'กรุณาระบุเหตุผลในการปฏิเสธสัญญา';
        } else {
            $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
            $lp->execute([$userId]);
            $lawyerId = $lp->fetchColumn();

            $chk = $pdo->prepare("
                SELECT c.contract_id FROM contracts c
                JOIN case_requests cr ON c.request_id = cr.request_id
                WHERE c.contract_id = ? AND cr.lawyer_id = ?
            ");
            $chk->execute([$contractId, $lawyerId]);

            if (!$chk->fetch()) {
                $error = 'ไม่พบสัญญาหรือไม่มีสิทธิ์';
            } else {
                $pdo->prepare("
                    UPDATE contracts SET
                        contract_review_status = 'lawyer_rejected',
                        negotiation_status     = 'lawyer_rejected',
                        status                 = 'terminated',
                        lawyer_note            = ?,
                        negotiated_at          = NOW()
                    WHERE contract_id = ?
                ")->execute([$rejectReason, $contractId]);
                // อัปเดต case_request กลับเป็น pending ให้ลูกความหาทนายใหม่ได้
                $pdo->prepare("
                    UPDATE case_requests SET status = 'rejected',
                        reject_reason = CONCAT('ทนายปฏิเสธสัญญา: ', ?)
                    WHERE request_id = (SELECT request_id FROM contracts WHERE contract_id = ?)
                ")->execute([$rejectReason, $contractId]);
                $success = '❌ ปฏิเสธสัญญาแล้ว';
            }
        }
    }

    // ---- ทนาย: ยืนยันสัญญาสุดท้าย (หลังต่อรอง) ----
    if ($action === 'finalize' && $role === 'lawyer') {
        // ── ตรวจ status ก่อน — finalize ได้เฉพาะจาก lawyer_accepted หรือ negotiating ──
        $statusChk = $pdo->prepare("
            SELECT contract_id FROM contracts
            WHERE contract_id = ?
              AND contract_review_status IN ('lawyer_accepted','negotiating')
        ");
        $statusChk->execute([$contractId]);
        if (!$statusChk->fetch()) {
            $error = 'ไม่สามารถยืนยันสัญญาในขั้นตอนนี้ได้';
        } else {
            $finalFee = $_POST['final_fee'] !== '' ? (float)$_POST['final_fee'] : null;
            $pdo->prepare("
                UPDATE contracts SET
                    contract_review_status = 'finalized',
                    negotiation_status     = 'finalized',
                    fee_amount             = COALESCE(?, fee_amount),
                    negotiated_at          = NOW()
                WHERE contract_id = ?
            ")->execute([$finalFee, $contractId]);
            $success = '🔒 ยืนยันสัญญาสุดท้ายแล้ว';
        }
    }

    // ---- ลูกความ: ยอมรับ/โต้กลับ/ปฏิเสธ ----
    if (in_array($action, ['client_accept','client_counter','client_reject']) && $role === 'client') {
        $cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
        $cp->execute([$userId]);
        $clientId = $cp->fetchColumn();

        $chk = $pdo->prepare("
            SELECT c.contract_id FROM contracts c
            JOIN case_requests cr ON c.request_id = cr.request_id
            WHERE c.contract_id = ? AND cr.client_id = ?
        ");
        $chk->execute([$contractId, $clientId]);

        if (!$chk->fetch()) {
            $error = 'ไม่พบสัญญาหรือไม่มีสิทธิ์';
        } elseif ($action === 'client_accept') {
            $pdo->prepare("
                UPDATE contracts SET
                    negotiation_status     = 'negotiating',
                    contract_review_status = 'negotiating',
                    fee_amount             = COALESCE(proposed_fee, fee_amount),
                    proposed_fee           = NULL,
                    client_response        = 'ยอมรับเงื่อนไข รอทนายยืนยันสัญญาสุดท้าย',
                    negotiated_at          = NOW()
                WHERE contract_id = ?
            ")->execute([$contractId]);
            $success = '✅ ยอมรับเงื่อนไขแล้ว รอทนายยืนยันสัญญาสุดท้าย';
        } elseif ($action === 'client_counter') {
            $msg = trim($_POST['client_response'] ?? '');
            if (!$msg) { $error = 'กรุณาระบุข้อเสนอ'; }
            else {
                $pdo->prepare("
                    UPDATE contracts SET
                        negotiation_status     = 'negotiating',
                        contract_review_status = 'negotiating',
                        client_response        = ?,
                        negotiated_at          = NOW()
                    WHERE contract_id = ?
                ")->execute([$msg, $contractId]);
                $success = '💬 ส่งข้อเสนอโต้กลับแล้ว';
            }
        } elseif ($action === 'client_reject') {
            $msg = trim($_POST['client_response'] ?? 'ปฏิเสธเงื่อนไข');
            $pdo->prepare("
                UPDATE contracts SET
                    negotiation_status     = 'negotiating',
                    contract_review_status = 'negotiating',
                    client_response        = ?,
                    negotiated_at          = NOW()
                WHERE contract_id = ?
            ")->execute([$msg, $contractId]);
            $success = '❌ ส่งการปฏิเสธแล้ว ทนายจะได้รับทราบ';
        }
    }
}

// ==============================
// Fetch contracts
// ==============================
if ($role === 'admin') {
    $stmt = $pdo->prepare("
        SELECT c.*, cr.detail,
               CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
        FROM contracts c
        JOIN case_requests cr ON c.request_id = cr.request_id
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.office_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$officeId]);

} elseif ($role === 'lawyer') {
    $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lp->execute([$userId]);
    $lawyerId = $lp->fetchColumn();
    $stmt = $pdo->prepare("
        SELECT c.*, cr.detail,
               CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
        FROM contracts c
        JOIN case_requests cr ON c.request_id = cr.request_id
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.lawyer_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$lawyerId]);

} else {
    $cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
    $cp->execute([$userId]);
    $clientId = $cp->fetchColumn();
    $stmt = $pdo->prepare("
        SELECT c.*, cr.detail,
               CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
        FROM contracts c
        JOIN case_requests cr ON c.request_id = cr.request_id
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.client_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$clientId]);
}

$contracts   = $stmt->fetchAll();
$contractIds = array_column($contracts, 'contract_id');
$documents   = [];

if (!empty($contractIds)) {
    $ph = implode(',', array_fill(0, count($contractIds), '?'));
    $ds = $pdo->prepare("
        SELECT cd.*, u.email AS uploader_email
        FROM case_documents cd
        JOIN users u ON cd.uploaded_by = u.user_id
        WHERE cd.contract_id IN ($ph)
        ORDER BY cd.created_at DESC
    ");
    $ds->execute($contractIds);
    foreach ($ds->fetchAll() as $doc) {
        $documents[$doc['contract_id']][] = $doc;
    }
}

$docTypeLabel = [
    'contract'          => '📄 สัญญาว่าจ้าง',
    'id_card'           => '🪪 สำเนาบัตรประชาชน',
    'evidence'          => '🔍 หลักฐานประกอบคดี',
    'power_of_attorney' => '📝 หนังสือมอบอำนาจ',
    'other'             => '📎 เอกสารอื่นๆ',
];

// Status badges
$reviewLabel = [
    'pending_lawyer_review' => ['text'=>'⏳ รอทนายพิจารณา',       'bg'=>'#fff3cd','color'=>'#856404'],
    'lawyer_accepted'       => ['text'=>'✅ ทนายยืนยันแล้ว',       'bg'=>'#d1e7dd','color'=>'#0f5132'],
    'lawyer_rejected'       => ['text'=>'❌ ทนายปฏิเสธสัญญา',      'bg'=>'#f8d7da','color'=>'#842029'],
    'revision_requested'    => ['text'=>'🔄 รอลูกความแก้ไข',       'bg'=>'#fff3cd','color'=>'#856404'],
    'negotiating'           => ['text'=>'💬 รอทนายยืนยันสุดท้าย',  'bg'=>'#cfe2ff','color'=>'#084298'],
    'finalized'             => ['text'=>'🔒 ยืนยันสัญญาแล้ว',      'bg'=>'#d1e7dd','color'=>'#0f5132'],
];

$pageTitle = 'สัญญาว่าจ้าง';
include '../includes/header.php';
?>

<style>
.contract-card { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:24px; overflow:hidden; }
.contract-header { background:#1a3a5c; color:#fff; padding:14px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
.info-grid { padding:16px 20px; background:#f8fafc; display:grid; grid-template-columns:repeat(3,1fr); gap:12px; font-size:0.88rem; border-bottom:1px solid #e2e8f0; }
.info-label { color:#888; font-size:0.78rem; margin-bottom:2px; }
.section { padding:16px 20px; border-top:1px solid #e2e8f0; }
.section-title { font-weight:700; color:#1a3a5c; margin-bottom:12px; }
.doc-row { display:flex; align-items:center; justify-content:space-between; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px; margin-bottom:8px; }
.neg-msg { border-radius:6px; padding:10px 14px; margin-bottom:10px; font-size:0.9rem; }
.action-bar { padding:12px 20px; border-top:1px solid #e2e8f0; background:#fafafa; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal-box { background:#fff; border-radius:10px; padding:28px; width:100%; max-width:480px; box-shadow:0 8px 32px rgba(0,0,0,.18); }
.modal-box h3 { color:#1a3a5c; margin-bottom:16px; }
.review-banner { padding:16px 20px; }
</style>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<div class="card">
    <h2>📄 สัญญาว่าจ้าง</h2>

    <?php if (empty($contracts)): ?>
    <p style="color:#888;">ยังไม่มีสัญญาในระบบ</p>
    <?php endif; ?>

    <?php foreach ($contracts as $c):
        $reviewStatus = $c['contract_review_status'] ?? 'pending_lawyer_review';
        $reviewInfo   = $reviewLabel[$reviewStatus] ?? $reviewLabel['pending_lawyer_review'];
        $docCount     = count($documents[$c['contract_id']] ?? []);
        $isPending    = $reviewStatus === 'pending_lawyer_review';
        $isRevision   = $reviewStatus === 'revision_requested';
        $isNegotiating= $reviewStatus === 'negotiating';
        $isRejected   = $reviewStatus === 'lawyer_rejected';
    ?>
    <div class="contract-card" style="<?= $isPending && $role==='lawyer' ? 'border:2px solid #ffc107;' : ($isRejected ? 'border:2px solid #dc3545; opacity:.85;' : '') ?>">

        <!-- Header -->
        <div class="contract-header">
            <span style="font-weight:bold; font-size:1rem;">สัญญา #<?= $c['contract_id'] ?></span>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <span style="font-size:0.82rem; opacity:.8;"><?= $c['contract_date'] ?? 'ยังไม่ระบุวันที่' ?></span>
                <span style="padding:4px 14px; border-radius:12px; font-size:0.8rem; font-weight:700;
                             background:<?= $reviewInfo['bg'] ?>; color:<?= $reviewInfo['color'] ?>;">
                    <?= $reviewInfo['text'] ?>
                </span>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="info-grid">
            <div><div class="info-label">👤 ลูกความ</div><strong><?= htmlspecialchars($c['client_name']) ?></strong></div>
            <div><div class="info-label">👨‍⚖️ ทนาย</div><strong><?= htmlspecialchars($c['lawyer_name']) ?></strong></div>
            <div>
                <div class="info-label">💰 ค่าดำเนินคดี</div>
                <strong><?= $c['fee_amount'] ? number_format($c['fee_amount'],2).' บาท' : '— ยังไม่ระบุ' ?></strong>
                <?php if ($c['proposed_fee'] && $isRevision): ?>
                <div style="font-size:0.82rem; color:#856404; margin-top:2px;">
                    ➜ เสนอใหม่: <strong><?= number_format($c['proposed_fee'],2) ?> บาท</strong>
                </div>
                <?php endif; ?>
            </div>
            <div style="grid-column:span 2;">
                <div class="info-label">📋 รายละเอียดคดี</div>
                <?= htmlspecialchars(mb_substr($c['detail']??'—',0,120)) ?><?= mb_strlen($c['detail']??'')>120?'...':'' ?>
            </div>
            <div>
                <div class="info-label">สถานะชำระเงิน</div>
                <span class="badge badge-pending"><?= htmlspecialchars($c['payment_status']) ?></span>
            </div>
        </div>

        <!-- === ทนายรอพิจารณา (pending_lawyer_review) === -->
        <?php if ($isPending && $role === 'lawyer'): ?>
        <div class="review-banner" style="background:#fffbeb; border-top:3px solid #ffc107;">
            <div style="font-weight:700; color:#856404; margin-bottom:12px; font-size:0.95rem;">
                ⏳ รอคุณพิจารณาสัญญาที่ลูกความส่งมา
            </div>
            <p style="font-size:0.88rem; color:#555; margin-bottom:14px;">
                ตรวจสอบเอกสารด้านล่างก่อนตัดสินใจ คุณสามารถ <strong>ยืนยันรับ</strong>,
                <strong>ตีกลับ/เสนอราคาใหม่</strong> หรือ <strong>ปฏิเสธสัญญา</strong> ได้
            </p>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn btn-success btn-sm"
                        onclick="openModal('modal-accept', <?= $c['contract_id'] ?>, <?= $c['fee_amount']??0 ?>)">
                    ✅ ยืนยันรับสัญญา
                </button>
                <button class="btn btn-sm" style="background:#e67e22; color:#fff;"
                        onclick="openModal('modal-revision', <?= $c['contract_id'] ?>, <?= $c['fee_amount']??0 ?>)">
                    🔄 ตีกลับ / เสนอราคาใหม่
                </button>
                <?php if ($reviewStatus !== 'finalized'): ?>
                <button class="btn btn-danger btn-sm"
                        onclick="openModal('modal-reject', <?= $c['contract_id'] ?>, 0)">
                    ❌ ปฏิเสธสัญญา
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- === ทนายปฏิเสธ === -->
        <?php if ($isRejected): ?>
        <div style="padding:14px 20px; background:#fff0f0; border-top:1px solid #f8d7da;">
            <strong style="color:#842029;">❌ ทนายปฏิเสธสัญญานี้</strong>
            <?php if ($c['lawyer_note']): ?>
            <div style="margin-top:8px; padding:10px; background:#fff; border-radius:6px; font-size:0.9rem; color:#555;">
                <strong>เหตุผล:</strong> <?= nl2br(htmlspecialchars($c['lawyer_note'])) ?>
            </div>
            <?php endif; ?>
            <?php if ($role === 'client'): ?>
            <div style="margin-top:10px; font-size:0.88rem; color:#888;">
                คุณสามารถ <a href="/pages/send_request.php" style="color:#1a3a5c; font-weight:600;">ส่งคำขอใหม่</a> ให้ทนายคนอื่นได้
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- === Negotiation History === -->
        <?php if ($c['lawyer_note'] && !$isRejected): ?>
        <div class="section">
            <div class="section-title">💬 ประวัติการต่อรอง</div>
            <div class="neg-msg" style="background:#fff3cd; border-left:4px solid #ffc107;">
                <strong>👨‍⚖️ ทนายแจ้ง:</strong><br>
                <?= nl2br(htmlspecialchars($c['lawyer_note'])) ?>
                <?php if ($c['proposed_fee']): ?>
                <div style="margin-top:8px; font-weight:600; color:#856404;">
                    เสนอค่าดำเนินคดีใหม่: <?= number_format($c['proposed_fee'],2) ?> บาท
                </div>
                <?php endif; ?>
                <?php if ($c['negotiated_at']): ?>
                <div style="font-size:0.75rem; color:#999; margin-top:6px;">
                    🕐 <?= date('d/m/Y H:i', strtotime($c['negotiated_at'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($c['client_response']): ?>
            <div class="neg-msg" style="background:#e8f4e8; border-left:4px solid #198754;">
                <strong>👤 ลูกความตอบกลับ:</strong><br>
                <?= nl2br(htmlspecialchars($c['client_response'])) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- === ปุ่มสำหรับ Revision (ลูกความ) === -->
        <?php if ($isRevision && $role === 'client'): ?>
        <div style="padding:14px 20px; background:#fff8e1; border-top:3px solid #ffc107;">
            <div style="font-weight:700; color:#856404; margin-bottom:10px;">⚠️ ทนายขอแก้ไขเงื่อนไข กรุณาตอบกลับ:</div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn btn-success btn-sm"
                        onclick="openClientModal(<?= $c['contract_id'] ?>, 'accept', <?= $c['proposed_fee']??'null' ?>)">
                    ✅ ยอมรับ
                </button>
                <button class="btn btn-sm" style="background:#e67e22; color:#fff;"
                        onclick="openClientModal(<?= $c['contract_id'] ?>, 'counter', null)">
                    💬 เสนอโต้กลับ
                </button>
                <button class="btn btn-danger btn-sm"
                        onclick="openClientModal(<?= $c['contract_id'] ?>, 'reject', null)">
                    ❌ ปฏิเสธ
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- === Documents === -->
        <div class="section">
            <div class="section-title">
                📁 เอกสารจากลูกความ
                <span style="font-size:0.8rem; background:#e8f0fe; color:#1a3a5c; padding:2px 8px; border-radius:10px; margin-left:6px;">
                    <?= $docCount ?> ไฟล์
                </span>
            </div>
            <?php if (empty($documents[$c['contract_id']])): ?>
            <div style="color:#aaa; font-size:0.88rem; padding:12px; background:#f8f9fa; border-radius:6px; text-align:center;">
                ⏳ ลูกความยังไม่ได้ส่งเอกสาร
            </div>
            <?php else: ?>
            <?php foreach ($documents[$c['contract_id']] as $doc): ?>
            <div class="doc-row">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:1.3rem;">
                        <?php
                        $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                        echo match($ext){ 'pdf'=>'📕', 'doc','docx'=>'📘', 'jpg','jpeg','png'=>'🖼️', default=>'📎' };
                        ?>
                    </span>
                    <div>
                        <div style="font-weight:600; font-size:0.9rem; color:#1a3a5c;"><?= htmlspecialchars($doc['file_name']) ?></div>
                        <div style="font-size:0.78rem; color:#888;">
                            <?= $docTypeLabel[$doc['document_type']] ?? $doc['document_type'] ?>
                            &nbsp;|&nbsp; <?= $doc['file_size'] ? number_format($doc['file_size']/1024,1).' KB' : '' ?>
                            &nbsp;|&nbsp; <?= date('d/m/Y H:i', strtotime($doc['created_at'])) ?>
                        </div>
                    </div>
                </div>
                <a href="/<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="btn btn-primary btn-sm" download>⬇ ดาวน์โหลด</a>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- === Action Bar === -->
        <div class="action-bar">
            <?php if (!$isRejected): ?>
            <a href="/pages/filings.php?contract_id=<?= $c['contract_id'] ?>" class="btn btn-primary btn-sm">🏛️ การยื่นฟ้อง</a>
            <?php endif; ?>

            <?php if ($role === 'lawyer' && !$isPending && !$isRejected): ?>
                <?php if (in_array($reviewStatus, ['lawyer_accepted','negotiating'])): ?>
                <button class="btn btn-sm" style="background:#e67e22; color:#fff;"
                        onclick="openModal('modal-revision', <?= $c['contract_id'] ?>, <?= $c['fee_amount']??0 ?>)">
                    🔄 ตีกลับ / เสนอราคาใหม่
                </button>
                <?php endif; ?>
                <?php if ($reviewStatus === 'negotiating'): ?>
                <button class="btn btn-success btn-sm"
                        onclick="openModal('modal-finalize', <?= $c['contract_id'] ?>, <?= $c['fee_amount']??0 ?>)">
                    🔒 ยืนยันสัญญาสุดท้าย
                </button>
                <?php endif; ?>
                <?php if ($reviewStatus !== 'finalized'): ?>
                <button class="btn btn-danger btn-sm"
                        onclick="openModal('modal-reject', <?= $c['contract_id'] ?>, 0)">
                    ❌ ปฏิเสธสัญญา
                </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
    <?php endforeach; ?>
</div>

<!-- Modal: ทนายยืนยันรับสัญญา -->
<div class="modal-backdrop" id="modal-accept">
    <div class="modal-box">
        <h3>✅ ยืนยันรับสัญญา</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="lawyer_accept">
            <input type="hidden" name="contract_id" id="accept-id">
            <div class="form-group">
                <label>ค่าดำเนินคดีที่ตกลง (บาท) <span style="color:red">*</span></label>
                <input type="number" name="fee_amount" id="accept-fee" step="0.01" min="0" required>
                <small style="color:#888;">ค่าดำเนินคดีปัจจุบัน: <span id="accept-current-fee"></span> บาท</small>
            </div>
            <div class="form-group">
                <label>หมายเหตุถึงลูกความ (ถ้ามี)</label>
                <textarea name="lawyer_note" rows="3" placeholder="เช่น ยืนยันรับคดี นัดหมายวันเริ่มต้น..."></textarea>
            </div>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:16px;">
                <button type="button" onclick="closeModal('modal-accept')" class="btn btn-sm" style="background:#e2e8f0;color:#333;">ยกเลิก</button>
                <button type="submit" class="btn btn-success btn-sm">✅ ยืนยันรับสัญญา</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: ทนายตีกลับ/เสนอราคา -->
<div class="modal-backdrop" id="modal-revision">
    <div class="modal-box">
        <h3>🔄 ตีกลับ / เสนอเงื่อนไขใหม่</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_revision">
            <input type="hidden" name="contract_id" id="rev-id">
            <div class="form-group">
                <label>เหตุผล / เงื่อนไขที่ต้องการแก้ไข <span style="color:red">*</span></label>
                <textarea name="lawyer_note" rows="4"
                    placeholder="เช่น ค่าดำเนินคดีที่เสนอต่ำเกินไป ต้องการอย่างน้อย X บาท..." required></textarea>
            </div>
            <div class="form-group">
                <label>เสนอค่าดำเนินคดีใหม่ (บาท) — เว้นว่างถ้าไม่ระบุ</label>
                <input type="number" name="proposed_fee" id="rev-fee" step="0.01" min="0">
                <small style="color:#888;">ค่าดำเนินคดีปัจจุบัน: <span id="rev-current-fee"></span> บาท</small>
            </div>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:16px;">
                <button type="button" onclick="closeModal('modal-revision')" class="btn btn-sm" style="background:#e2e8f0;color:#333;">ยกเลิก</button>
                <button type="submit" class="btn btn-primary btn-sm">📨 ส่งคำขอแก้ไข</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: ทนายปฏิเสธสัญญา -->
<div class="modal-backdrop" id="modal-reject">
    <div class="modal-box">
        <h3>❌ ปฏิเสธสัญญา</h3>
        <div style="background:#f8d7da; border-radius:6px; padding:12px; margin-bottom:14px; font-size:0.88rem; color:#842029;">
            ⚠️ การปฏิเสธสัญญาจะยุติการดำเนินคดีนี้ ลูกความจะได้รับแจ้งและสามารถหาทนายใหม่ได้
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="lawyer_reject">
            <input type="hidden" name="contract_id" id="rej-id">
            <div class="form-group">
                <label>เหตุผลในการปฏิเสธ <span style="color:red">*</span></label>
                <textarea name="reject_reason" rows="4"
                    placeholder="เช่น ค่าดำเนินคดีไม่ตรงกับที่ตกลง ลูกความไม่มีงบเพียงพอ..." required></textarea>
            </div>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:16px;">
                <button type="button" onclick="closeModal('modal-reject')" class="btn btn-sm" style="background:#e2e8f0;color:#333;">ยกเลิก</button>
                <button type="submit" class="btn btn-danger btn-sm">❌ ยืนยันปฏิเสธ</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: ยืนยันสัญญาสุดท้าย -->
<div class="modal-backdrop" id="modal-finalize">
    <div class="modal-box">
        <h3>🔒 ยืนยันสัญญาสุดท้าย</h3>
        <p style="color:#555; font-size:0.9rem; margin-bottom:16px;">ลูกความยอมรับเงื่อนไขแล้ว กรุณายืนยันเพื่อปิดการต่อรอง</p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="finalize">
            <input type="hidden" name="contract_id" id="fin-id">
            <div class="form-group">
                <label>ค่าดำเนินคดีสุดท้าย (บาท) — เว้นว่างถ้าใช้ค่าเดิม</label>
                <input type="number" name="final_fee" id="fin-fee" step="0.01" min="0">
                <small style="color:#888;">ค่าดำเนินคดีปัจจุบัน: <span id="fin-current-fee"></span> บาท</small>
            </div>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:16px;">
                <button type="button" onclick="closeModal('modal-finalize')" class="btn btn-sm" style="background:#e2e8f0;color:#333;">ยกเลิก</button>
                <button type="submit" class="btn btn-success btn-sm">🔒 ยืนยันสัญญา</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: ลูกความตอบกลับ -->
<div class="modal-backdrop" id="modal-client">
    <div class="modal-box">
        <h3 id="client-title"></h3>
        <div id="client-accept-info" style="display:none; background:#d1e7dd; padding:12px; border-radius:6px; color:#0f5132; margin-bottom:12px; font-size:0.9rem;"></div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="client-action">
            <input type="hidden" name="contract_id" id="client-id">
            <div class="form-group" id="client-msg-group" style="display:none;">
                <label id="client-msg-label">ข้อความ</label>
                <textarea name="client_response" rows="4" placeholder="ระบุข้อเสนอหรือเหตุผล..."></textarea>
            </div>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:16px;">
                <button type="button" onclick="closeModal('modal-client')" class="btn btn-sm" style="background:#e2e8f0;color:#333;">ยกเลิก</button>
                <button type="submit" id="client-submit" class="btn btn-primary btn-sm">ยืนยัน</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId, contractId, currentFee) {
    if (modalId === 'modal-accept') {
        document.getElementById('accept-id').value = contractId;
        document.getElementById('accept-fee').value = currentFee || '';
        document.getElementById('accept-current-fee').textContent = Number(currentFee).toLocaleString('th-TH',{minimumFractionDigits:2});
    } else if (modalId === 'modal-revision') {
        document.getElementById('rev-id').value = contractId;
        document.getElementById('rev-fee').value = '';
        document.getElementById('rev-current-fee').textContent = Number(currentFee).toLocaleString('th-TH',{minimumFractionDigits:2});
        document.querySelector('#modal-revision textarea').value = '';
    } else if (modalId === 'modal-reject') {
        document.getElementById('rej-id').value = contractId;
        document.querySelector('#modal-reject textarea').value = '';
    } else if (modalId === 'modal-finalize') {
        document.getElementById('fin-id').value = contractId;
        document.getElementById('fin-fee').value = '';
        document.getElementById('fin-current-fee').textContent = Number(currentFee).toLocaleString('th-TH',{minimumFractionDigits:2});
    }
    document.getElementById(modalId).classList.add('open');
}

function openClientModal(contractId, type, proposedFee) {
    document.getElementById('client-id').value = contractId;
    document.getElementById('client-action').value = 'client_' + type;
    const titleEl  = document.getElementById('client-title');
    const infoEl   = document.getElementById('client-accept-info');
    const msgGrp   = document.getElementById('client-msg-group');
    const submitEl = document.getElementById('client-submit');
    const labelEl  = document.getElementById('client-msg-label');

    infoEl.style.display = 'none';
    msgGrp.style.display = 'none';

    if (type === 'accept') {
        titleEl.textContent = '✅ ยืนยันการยอมรับเงื่อนไข';
        let txt = 'คุณกำลังจะ <strong>ยอมรับ</strong> เงื่อนไขที่ทนายเสนอ';
        if (proposedFee) txt += `<br>ค่าดำเนินคดีจะเปลี่ยนเป็น <strong>${Number(proposedFee).toLocaleString('th-TH',{minimumFractionDigits:2})} บาท</strong>`;
        txt += '<br><small style="color:#555;">รอทนายยืนยันสัญญาสุดท้ายอีกครั้ง</small>';
        infoEl.innerHTML = txt;
        infoEl.style.display = 'block';
        submitEl.textContent = '✅ ยืนยันยอมรับ';
        submitEl.className = 'btn btn-success btn-sm';
    } else if (type === 'counter') {
        titleEl.textContent = '💬 เสนอเงื่อนไขโต้กลับ';
        labelEl.textContent = 'ข้อเสนอของคุณ *';
        msgGrp.style.display = 'block';
        submitEl.textContent = '📨 ส่งข้อเสนอ';
        submitEl.className = 'btn btn-primary btn-sm';
    } else if (type === 'reject') {
        titleEl.textContent = '❌ ปฏิเสธเงื่อนไข';
        labelEl.textContent = 'เหตุผล (ถ้ามี)';
        msgGrp.style.display = 'block';
        submitEl.textContent = '❌ ยืนยันปฏิเสธ';
        submitEl.className = 'btn btn-danger btn-sm';
    }
    document.getElementById('modal-client').classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.modal-backdrop').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});
</script>

<?php include '../includes/footer.php'; ?>