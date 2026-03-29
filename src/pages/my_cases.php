<?php
// my_cases.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/notification_helper.php';
require_once '../config/activity_log_helper.php';
requireLogin();

$pdo      = getDB();
$userId   = $_SESSION['user_id'];
$officeId = $_SESSION['office_id'];
$error  = '';
$success= '';

$cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id = ?");
$cp->execute([$userId]);
$clientId = $cp->fetchColumn();

if (!$clientId) {
    echo "<div class='container'><p>ไม่พบข้อมูลลูกความ กรุณาติดต่อผู้ดูแลระบบ</p></div>";
    exit;
}

// ==============================
// Handle negotiation response
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    csrf_verify();
    $contractId = (int)($_POST['contract_id'] ?? 0);
    $action     = $_POST['action'] ?? '';

    // ---- ขอเลื่อนวันนัด (ไม่ต้องใช้ contract_id) ----
    if ($action === 'request_postpone') {
        $requestType   = $_POST['request_type'] ?? '';   // filing / hearing
        $referenceId   = (int)($_POST['reference_id'] ?? 0);
        $reason        = trim($_POST['reason'] ?? '');
        $requestedDate = $_POST['requested_date'] ?: null;

        if (!in_array($requestType, ['filing','hearing']) || !$referenceId) {
            $error = 'ข้อมูลไม่ถูกต้อง';
        } elseif (!$reason) {
            $error = 'กรุณาระบุเหตุผลที่ขอเลื่อน';
        } else {
            // ตรวจ ownership
            if ($requestType === 'filing') {
                $own = $pdo->prepare("SELECT f.filing_id FROM filings f JOIN contracts con ON f.contract_id=con.contract_id JOIN case_requests cr ON con.request_id=cr.request_id WHERE f.filing_id=? AND cr.client_id=?");
            } else {
                $own = $pdo->prepare("SELECT ch.hearing_id FROM court_hearings ch JOIN filings f ON ch.filing_id=f.filing_id JOIN contracts con ON f.contract_id=con.contract_id JOIN case_requests cr ON con.request_id=cr.request_id WHERE ch.hearing_id=? AND cr.client_id=?");
            }
            $own->execute([$referenceId, $clientId]);
            if (!$own->fetch()) {
                $error = 'ไม่พบข้อมูลหรือไม่มีสิทธิ์';
            } else {
                // ตรวจว่ามี pending request อยู่แล้วหรือเปล่า
                $dup = $pdo->prepare("SELECT postpone_id FROM postponement_requests WHERE request_type=? AND reference_id=? AND status='pending'");
                $dup->execute([$requestType, $referenceId]);
                if ($dup->fetch()) {
                    $error = 'มีคำขอเลื่อนที่รอดำเนินการอยู่แล้ว';
                } else {
                    $pdo->prepare("INSERT INTO postponement_requests (request_type, reference_id, client_id, reason, requested_date, status) VALUES (?, ?, ?, ?, ?, 'pending')")
                        ->execute([$requestType, $referenceId, $clientId, $reason, $requestedDate]);
                    audit_log($pdo, $officeId, $userId, 'create', 'postponement', (int)$pdo->lastInsertId(), 'ลูกความขอเลื่อน '.$requestType.' #'.$referenceId);
                    $success = '📨 ส่งคำขอเลื่อนวันนัดแล้ว ทนายจะได้รับทราบ';
                }
            }
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => empty($error), 'msg' => $error ?: $success]);
            exit;
        }
    }

    // ตรวจสอบว่า contract เป็นของ client คนนี้จริง (สำหรับ action อื่นๆ)
    $chk = $pdo->prepare("
        SELECT c.contract_id FROM contracts c
        JOIN case_requests cr ON c.request_id = cr.request_id
        WHERE c.contract_id = ? AND cr.client_id = ?
    ");
    $chk->execute([$contractId, $clientId]);

    if (!$chk->fetch()) {
        $error = 'ไม่พบสัญญาหรือไม่มีสิทธิ์ดำเนินการ';
    } else {
        if ($action === 'client_accept') {
            $pdo->prepare("
                UPDATE contracts SET
                    contract_review_status = 'negotiating',
                    fee_amount         = COALESCE(proposed_fee, fee_amount),
                    proposed_fee       = NULL,
                    client_response        = 'ยอมรับเงื่อนไข รอทนายยืนยันสัญญาสุดท้าย'
                WHERE contract_id = ?
            ")->execute([$contractId]);
            // แจ้งทนาย
            $lInfo = $pdo->prepare("SELECT cr.lawyer_id FROM contracts c JOIN case_requests cr ON c.request_id=cr.request_id WHERE c.contract_id=?");
            $lInfo->execute([$contractId]); $lid = $lInfo->fetchColumn();
            $lwUid = $lid ? notif_get_user_id_by_lawyer($pdo, (int)$lid) : null;
            if ($lwUid) notif_create($pdo, $officeId, $lwUid, 'contract', 'ลูกความยอมรับเงื่อนไขสัญญา', 'สัญญา #'.$contractId, '/pages/contracts.php', 'contract', $contractId);
            audit_log($pdo, $officeId, $userId, 'approve', 'contract', $contractId, 'ลูกความยอมรับเงื่อนไขสัญญา #'.$contractId);
            $success = '✅ ยอมรับเงื่อนไขแล้ว รอทนายยืนยันสัญญาสุดท้าย';

        } elseif ($action === 'client_counter') {
            $msg = trim($_POST['client_response'] ?? '');
            if (!$msg) { $error = 'กรุณาระบุข้อเสนอของคุณ'; }
            else {
                $pdo->prepare("
                    UPDATE contracts SET
                        contract_review_status = 'negotiating',
                        client_response        = ?
                    WHERE contract_id = ?
                ")->execute([$msg, $contractId]);
                $lInfo2 = $pdo->prepare("SELECT cr.lawyer_id FROM contracts c JOIN case_requests cr ON c.request_id=cr.request_id WHERE c.contract_id=?");
                $lInfo2->execute([$contractId]); $lid2 = $lInfo2->fetchColumn();
                $lwUid2 = $lid2 ? notif_get_user_id_by_lawyer($pdo, (int)$lid2) : null;
                if ($lwUid2) notif_create($pdo, $officeId, $lwUid2, 'contract', 'ลูกความส่งข้อเสนอโต้กลับ', 'สัญญา #'.$contractId, '/pages/contracts.php', 'contract', $contractId);
                audit_log($pdo, $officeId, $userId, 'update', 'contract', $contractId, 'ลูกความโต้กลับสัญญา #'.$contractId);
                $success = '💬 ส่งข้อเสนอโต้กลับแล้ว';
            }

        } elseif ($action === 'client_reject') {
            $msg = trim($_POST['client_response'] ?? 'ปฏิเสธเงื่อนไข');
            $pdo->prepare("
                UPDATE contracts SET
                    contract_review_status = 'negotiating',
                    client_response        = ?
                WHERE contract_id = ?
            ")->execute([$msg, $contractId]);
            $lInfo3 = $pdo->prepare("SELECT cr.lawyer_id FROM contracts c JOIN case_requests cr ON c.request_id=cr.request_id WHERE c.contract_id=?");
            $lInfo3->execute([$contractId]); $lid3 = $lInfo3->fetchColumn();
            $lwUid3 = $lid3 ? notif_get_user_id_by_lawyer($pdo, (int)$lid3) : null;
            if ($lwUid3) notif_create($pdo, $officeId, $lwUid3, 'contract', 'ลูกความปฏิเสธเงื่อนไขสัญญา', 'สัญญา #'.$contractId, '/pages/contracts.php', 'contract', $contractId);
            audit_log($pdo, $officeId, $userId, 'reject', 'contract', $contractId, 'ลูกความปฏิเสธสัญญา #'.$contractId);
            $success = '❌ ส่งการปฏิเสธแล้ว ทนายจะได้รับทราบ';
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => empty($error), 'msg' => $error ?: $success]);
        exit;
    }
}

// ==============================
// Fetch cases
// ==============================
$stmt = $pdo->prepare("
    SELECT cr.*,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           lp.specialization, lp.phone AS lawyer_phone,
           con.contract_id, con.contract_date, con.fee_amount,
           con.status AS contract_status,
           con.payment_status, con.contract_review_status,
           con.lawyer_note, con.proposed_fee, con.client_response,
           f.filing_id, f.charge, f.scheduled_filing_date,
           (SELECT MAX(ch.case_number) FROM court_hearings ch WHERE ch.filing_id = f.filing_id) AS case_number,
           ct.court_name,
           v.result AS verdict_result, v.verdict_date,
           va.scheduled_date AS verdict_appt_date, va.scheduled_time AS verdict_appt_time,
           va.note AS verdict_appt_note, va.status AS verdict_appt_status
    FROM case_requests cr
    JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
    LEFT JOIN contracts con ON cr.request_id = con.request_id
    LEFT JOIN filings f ON con.contract_id = f.contract_id
    LEFT JOIN courts ct ON f.court_id = ct.court_id
    LEFT JOIN verdicts v ON f.filing_id = v.filing_id
    LEFT JOIN verdict_appointments va ON f.filing_id = va.filing_id
    WHERE cr.client_id = ?
    ORDER BY cr.created_at DESC
");
$stmt->execute([$clientId]);
$cases = $stmt->fetchAll();

// ดึงนัดขึ้นศาลทั้งหมดของลูกความคนนี้ (เฉพาะ scheduled + ยังไม่เลยมากกว่า 30 วัน)
$hearingStmt = $pdo->prepare("
    SELECT ch.hearing_id, ch.hearing_date, ch.hearing_time, ch.court_room,
           ch.hearing_round, ch.status, ch.notes,
           ch.case_number, f.charge,
           ct.court_name,
           cr.request_id
    FROM court_hearings ch
    JOIN filings f        ON ch.filing_id    = f.filing_id
    JOIN contracts con    ON f.contract_id   = con.contract_id
    JOIN case_requests cr ON con.request_id  = cr.request_id
    JOIN courts ct        ON f.court_id      = ct.court_id
    WHERE cr.client_id = ?
      AND ch.status = 'scheduled'
      AND ch.hearing_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    ORDER BY ch.hearing_date ASC, ch.hearing_time ASC
");
$hearingStmt->execute([$clientId]);
$allHearings = $hearingStmt->fetchAll();

// จัดกลุ่มตาม request_id
$hearingsByRequest = [];
foreach ($allHearings as $h) {
    $hearingsByRequest[$h['request_id']][] = $h;
}

// นับสัญญาที่รอตอบกลับ
$pendingNeg = array_filter($cases, fn($c) => ($c['contract_review_status'] ?? '') === 'revision_requested');

// ดึง postponement requests ทั้งหมดของลูกความ
$postponeStmt = $pdo->prepare("
    SELECT postpone_id, request_type, reference_id, status, reason, requested_date, lawyer_note, created_at
    FROM postponement_requests
    WHERE client_id = ?
    ORDER BY created_at DESC
");
$postponeStmt->execute([$clientId]);
$allPostponeRequests = $postponeStmt->fetchAll();

// จัดกลุ่มตาม type+reference_id
$postponeMap = [];
foreach ($allPostponeRequests as $pr) {
    $key = $pr['request_type'] . '_' . $pr['reference_id'];
    $postponeMap[$key][] = $pr;
}

// ดึง scheduled_filing_date สำหรับแสดง countdown
$filingDateMap = [];
foreach ($cases as $c) {
    if ($c['filing_id'] && $c['scheduled_filing_date']) {
        $filingDateMap[$c['request_id']] = [
            'filing_id'             => $c['filing_id'],
            'scheduled_filing_date' => $c['scheduled_filing_date'],
            'charge'                => $c['charge'] ?? '—',
            'case_number'           => $c['case_number'] ?? null,
        ];
    }
}

$pageTitle = 'คดีของฉัน';
include '../includes/header.php';
?>
<style>
.countdown-pill {
    display:inline-block; padding:4px 14px; border-radius:20px;
    font-size:0.82rem; font-weight:700; color:#fff;
    background:#1a3a5c; min-width:130px; text-align:center;
}
.countdown-pill.urgent  { background:#e67e22; }
.countdown-pill.overdue { background:#dc3545; }
.hearing-card { border-radius:8px; padding:12px 16px; margin-bottom:8px; }
.hearing-card.upcoming { background:#f0f7ff; border-left:4px solid #1a3a5c; }
.hearing-card.overdue  { background:#fff0f0; border-left:4px solid #dc3545; }
</style>
<?php

$statusTH = ['pending'=>'รอทนายตอบรับ','approved'=>'ดำเนินการอยู่','rejected'=>'ถูกปฏิเสธ','expired'=>'หมดอายุ'];
$badgeMap = ['pending'=>'badge-pending','approved'=>'badge-approved','rejected'=>'badge-rejected','expired'=>'badge-expired'];
$negLabel = [
    'accepted'           => ['text'=>'✅ ยอมรับแล้ว',       'bg'=>'#d1e7dd','color'=>'#0f5132'],
    'revision_requested' => ['text'=>'⚠️ รอคุณตอบกลับ',    'bg'=>'#fff3cd','color'=>'#856404'],
    'negotiating'        => ['text'=>'💬 กำลังต่อรอง',      'bg'=>'#cfe2ff','color'=>'#084298'],
    'finalized'          => ['text'=>'🔒 ยืนยันแล้ว',       'bg'=>'#d1e7dd','color'=>'#0f5132'],
];
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.neg-alert { border-radius:8px; padding:14px 18px; margin-bottom:16px; border-left:5px solid; font-size:0.92rem; }
.neg-alert.warning { background:#fff8e1; border-color:#ffc107; }
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal-box { background:#fff; border-radius:10px; padding:28px; width:100%; max-width:460px; box-shadow:0 8px 32px rgba(0,0,0,.18); }
.modal-box h3 { color:#1a3a5c; margin-bottom:14px; }
</style>

<h2 style="margin-bottom:16px; color:#1a3a5c;">📁 คดีของฉัน</h2>

<!-- แจ้งเตือนรวมถ้ามีสัญญาที่รอตอบกลับ -->
<?php if (count($pendingNeg) > 0): ?>
<div class="neg-alert warning" style="border-left:5px solid #ffc107; background:#fff8e1; margin-bottom:20px;">
    🔔 <strong>มี <?= count($pendingNeg) ?> สัญญาที่ทนายขอแก้ไขเงื่อนไข</strong> — กรุณาเลื่อนลงเพื่อตอบกลับ
</div>
<?php endif; ?>

<?php if (empty($cases)): ?>
<div class="card"><p style="color:#888;">ยังไม่มีคดีในระบบ</p></div>
<?php endif; ?>

<?php foreach ($cases as $case):
    $negStatus = $case['contract_review_status'] ?? 'accepted';
    $negInfo   = $negLabel[$negStatus] ?? $negLabel['accepted'];
    $isRevision = $negStatus === 'revision_requested';

    // คำนวณ display status โดยดู contract_status ด้วย
    $contractStatus = $case['contract_status'] ?? null;
    if ($contractStatus === 'completed') {
        $displayStatusTH  = '✅ คดีปิดแล้ว';
        $displayBadgeClass = 'badge-approved';
        $displayBadgeStyle = 'background:#198754;color:#fff;';
    } elseif ($contractStatus === 'terminated') {
        $displayStatusTH  = '🚫 ยุติคดี';
        $displayBadgeClass = 'badge-rejected';
        $displayBadgeStyle = 'background:#dc3545;color:#fff;';
    } else {
        $displayStatusTH  = $statusTH[$case['status']] ?? $case['status'];
        $displayBadgeClass = $badgeMap[$case['status']] ?? '';
        $displayBadgeStyle = '';
    }
?>
<div class="card" style="padding:0; overflow:hidden; <?= $isRevision ? 'border:2px solid #ffc107;' : '' ?>">

    <!-- Clickable Header -->
    <div onclick="toggleCase('case-<?= $case['request_id'] ?>')"
         style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px;
                cursor:pointer; user-select:none;
                background:<?= $isRevision ? '#fff8e1' : '#f8fafc' ?>;
                border-bottom:1px solid #e2e8f0; transition:background .15s;"
         onmouseover="this.style.background='<?= $isRevision ? '#fff3cd' : '#f0f7ff' ?>'"
         onmouseout="this.style.background='<?= $isRevision ? '#fff8e1' : '#f8fafc' ?>'">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h2 style="margin:0; font-size:1rem;">
                📁 คำขอ #<?= $case['request_id'] ?>
                — <?= htmlspecialchars($case['lawyer_name']) ?>
            </h2>
            <span class="badge <?= $displayBadgeClass ?>" <?= $displayBadgeStyle ? 'style="'.$displayBadgeStyle.'"' : '' ?>>
                <?= $displayStatusTH ?>
            </span>
            <?php if ($isRevision): ?>
            <span style="background:#ffc107;color:#333;padding:2px 10px;border-radius:10px;font-size:.75rem;font-weight:700;">⚠️ รอตอบกลับ</span>
            <?php endif; ?>
            <?php if ($case['verdict_result']): ?>
            <span style="background:#198754;color:#fff;padding:2px 10px;border-radius:10px;font-size:.75rem;font-weight:700;">⚖️ มีคำพิพากษา</span>
            <?php endif; ?>
        </div>
        <div style="display:flex; align-items:center; gap:12px; flex-shrink:0;">
            <small style="color:#888; font-size:.78rem;">ส่งเมื่อ <?= $case['request_date'] ?></small>
            <span class="toggle-icon" id="icon-case-<?= $case['request_id'] ?>"
                  style="font-size:1.1rem; color:#94a3b8; transition:transform .25s;">▼</span>
        </div>
    </div>

    <!-- Collapsible Body -->
    <div id="case-<?= $case['request_id'] ?>" style="display:none; padding:18px 20px;">

    <!-- Lawyer Info -->
    <div style="background:#f8f9fa; padding:12px; border-radius:6px; margin-bottom:16px;">
        <strong>👨‍⚖️ ทนาย:</strong> <?= htmlspecialchars($case['lawyer_name']) ?>
        <?php if ($case['specialization']): ?>
        <span style="color:#666; margin-left:6px;">(<?= htmlspecialchars($case['specialization']) ?>)</span>
        <?php endif; ?>
        <?php if ($case['lawyer_phone']): ?>
        | 📞 <?= htmlspecialchars($case['lawyer_phone']) ?>
        <?php endif; ?>
        <div style="font-size:.78rem;color:#888;margin-top:4px;">ส่งเมื่อ <?= $case['request_date'] ?> | หมดอายุ <?= $case['expire_date'] ?></div>
        <?php if ($case['status'] === 'approved'): ?>
        <a href="/pages/chat.php?request_id=<?= $case['request_id'] ?>"
           style="display:inline-block;margin-top:8px;padding:5px 14px;background:#1e3a5f;color:#fff;border-radius:6px;text-decoration:none;font-size:.85rem;font-weight:600;">
            💬 แชทกับทนาย
        </a>
        <?php endif; ?>
    </div>

    <!-- Case Detail -->
    <div style="margin-bottom:16px;">
        <strong>รายละเอียดคดี:</strong>
        <p style="color:#555; margin-top:4px;"><?= nl2br(htmlspecialchars($case['detail'] ?? '—')) ?></p>
    </div>

    <!-- ===== NEGOTIATION BANNER ===== -->
    <?php if ($case['contract_id'] && $negStatus !== 'accepted'): ?>
    <div style="margin-bottom:16px; border-radius:8px; overflow:hidden; border:1px solid #e2e8f0;">

        <!-- Status bar -->
        <div style="padding:10px 16px; background:<?= $negInfo['bg'] ?>; color:<?= $negInfo['color'] ?>; font-weight:700; font-size:0.92rem;">
            <?= $negInfo['text'] ?>
        </div>

        <div style="padding:14px 16px; background:#fffdf0;">

            <!-- ทนายแจ้ง -->
            <?php if ($case['lawyer_note']): ?>
            <div style="margin-bottom:12px;">
                <div style="font-size:0.8rem; color:#888; margin-bottom:4px;">👨‍⚖️ ทนายแจ้ง:</div>
                <div style="background:#fff3cd; border-radius:6px; padding:10px 14px; font-size:0.9rem;">
                    <?= nl2br(htmlspecialchars($case['lawyer_note'])) ?>
                    <?php if ($case['proposed_fee']): ?>
                    <div style="margin-top:8px; padding:8px; background:#fff; border-radius:4px; border:1px solid #ffc107;">
                        💰 เสนอค่าดำเนินคดีใหม่:
                        <strong style="font-size:1.05rem; color:#856404;">
                            <?= number_format($case['proposed_fee'], 2) ?> บาท
                        </strong>
                        <?php if ($case['fee_amount']): ?>
                        <span style="font-size:0.8rem; color:#888; margin-left:6px;">
                            (เดิม <?= number_format($case['fee_amount'], 2) ?> บาท)
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- คำตอบของลูกความ (ถ้าเคยตอบแล้ว) -->
            <?php if ($case['client_response'] && $negStatus !== 'revision_requested'): ?>
            <div style="margin-bottom:12px;">
                <div style="font-size:0.8rem; color:#888; margin-bottom:4px;">👤 คุณตอบกลับ:</div>
                <div style="background:#e8f4e8; border-radius:6px; padding:10px 14px; font-size:0.9rem;">
                    <?= nl2br(htmlspecialchars($case['client_response'])) ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ปุ่มตอบกลับ (เฉพาะ revision_requested) -->
            <?php if ($isRevision): ?>
            <div style="background:#fff8e1; border:1px dashed #ffc107; border-radius:6px; padding:12px 14px;">
                <div style="font-weight:700; color:#856404; margin-bottom:10px;">
                    ⚠️ กรุณาเลือกการตอบกลับ:
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button class="btn btn-success btn-sm"
                            onclick="openReply(<?= $case['contract_id'] ?>, 'accept', <?= $case['proposed_fee'] ?? 'null' ?>, <?= $case['fee_amount'] ?? 0 ?>)">
                        ✅ ยอมรับเงื่อนไข
                    </button>
                    <button class="btn btn-sm" style="background:#e67e22; color:#fff;"
                            onclick="openReply(<?= $case['contract_id'] ?>, 'counter', null, null)">
                        💬 เสนอโต้กลับ
                    </button>
                    <button class="btn btn-danger btn-sm"
                            onclick="openReply(<?= $case['contract_id'] ?>, 'reject', null, null)">
                        ❌ ปฏิเสธ
                    </button>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>
    <!-- ===== END NEGOTIATION ===== -->

    <!-- Progress Steps -->
    <div style="margin-top:12px;">
        <strong>ความคืบหน้า:</strong>
        <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; align-items:center;">
            <div style="text-align:center;">
                <div style="width:36px;height:36px;border-radius:50%;background:<?= in_array($case['status'],['approved','rejected']) ? '#198754':'#ffc107' ?>;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto;">1</div>
                <small style="display:block;margin-top:4px;">คำขอ</small>
            </div>
            <div style="flex:1;height:2px;background:<?= $case['contract_id'] ? '#198754':'#ddd' ?>;min-width:30px;"></div>
            <div style="text-align:center;">
                <div style="width:36px;height:36px;border-radius:50%;background:<?= $case['contract_id'] ? '#198754':'#ddd' ?>;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto;">2</div>
                <small style="display:block;margin-top:4px;">สัญญา</small>
            </div>
            <div style="flex:1;height:2px;background:<?= $case['filing_id'] ? '#198754':'#ddd' ?>;min-width:30px;"></div>
            <div style="text-align:center;">
                <div style="width:36px;height:36px;border-radius:50%;background:<?= $case['filing_id'] ? '#198754':'#ddd' ?>;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto;">3</div>
                <small style="display:block;margin-top:4px;">ยื่นฟ้อง</small>
            </div>
            <div style="flex:1;height:2px;background:<?= $case['verdict_result'] ? '#198754':'#ddd' ?>;min-width:30px;"></div>
            <div style="text-align:center;">
                <div style="width:36px;height:36px;border-radius:50%;background:<?= $case['verdict_result'] ? '#198754':'#ddd' ?>;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto;">4</div>
                <small style="display:block;margin-top:4px;">คำพิพากษา</small>
            </div>
        </div>
    </div>

    <!-- Filing Detail + Countdown + Postpone Request -->
    <?php if ($case['filing_id']): ?>
    <div style="margin-top:16px;padding:14px;background:#e8f4e8;border-radius:8px;border:1px solid #b7dbb7;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
            <div>
                <strong>🏛️ นัดวันยื่นฟ้อง:</strong><br>
                <span style="font-size:.88rem;color:#374151;">
                    <?php if ($case['case_number']): ?>
                    📁 เลขคดี <?= htmlspecialchars($case['case_number']) ?> &nbsp;|&nbsp;
                    <?php else: ?>
                    <span style="color:#92400e;font-size:.78rem;">⏳ รอรับเลขคดี</span> &nbsp;|&nbsp;
                    <?php endif; ?>
                    ศาล: <?= htmlspecialchars($case['court_name'] ?? '—') ?> &nbsp;|&nbsp;
                    ข้อหา: <?= htmlspecialchars($case['charge'] ?? '—') ?><br>
                    <?php if ($case['scheduled_filing_date']): ?>
                    📅 วันนัด: <strong><?= date('d/m/Y', strtotime($case['scheduled_filing_date'])) ?></strong>
                    <?php else: ?>
                    <span style="color:#94a3b8;">ยังไม่ได้กำหนดวันยื่น</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($case['scheduled_filing_date']): ?>
            <?php
                $fDateStr = $case['scheduled_filing_date'] . 'T00:00:00';
                $fIsPast  = strtotime($case['scheduled_filing_date']) < strtotime('today');
                $postponeKeyF = 'filing_' . $case['filing_id'];
                $fPostpone = $postponeMap[$postponeKeyF][0] ?? null;
            ?>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                <div class="countdown-pill <?= $fIsPast ? 'overdue' : '' ?>"
                     data-datetime="<?= $fDateStr ?>">กำลังคำนวณ...</div>
                <?php if (!$fIsPast && $case['contract_status'] === 'active'): ?>
                    <?php if ($fPostpone && $fPostpone['status'] === 'pending'): ?>
                    <span style="font-size:.75rem;background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:10px;font-weight:700;">
                        ⏳ รอทนายอนุมัติคำขอเลื่อน
                    </span>
                    <?php elseif ($fPostpone && $fPostpone['status'] === 'approved'): ?>
                    <span style="font-size:.75rem;background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:10px;font-weight:700;">
                        ✅ ทนายอนุมัติการเลื่อนแล้ว
                    </span>
                    <?php elseif ($fPostpone && $fPostpone['status'] === 'rejected'): ?>
                    <span style="font-size:.75rem;background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:10px;font-weight:700;">
                        ❌ ทนายไม่อนุมัติ: <?= htmlspecialchars(mb_substr($fPostpone['lawyer_note'] ?? '—', 0, 40)) ?>
                    </span>
                    <?php else: ?>
                    <button class="btn btn-sm" style="background:#f97316;color:#fff;font-size:.75rem;padding:4px 12px;"
                        onclick="openPostponeModal('filing', <?= $case['filing_id'] ?>, '<?= $case['scheduled_filing_date'] ?>')">
                        📆 ขอเลื่อนวันยื่นฟ้อง
                    </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- นัดขึ้นศาล (สำหรับลูกความ) -->
    <?php if (!empty($hearingsByRequest[$case['request_id']])): ?>
    <div style="margin-top:12px;">
        <div style="font-weight:700; color:#1a3a5c; margin-bottom:8px;">📅 นัดขึ้นศาล</div>
        <?php foreach ($hearingsByRequest[$case['request_id']] as $h):
            $dateStr  = $h['hearing_date'];
            $timeStr  = $h['hearing_time'] ? substr($h['hearing_time'],0,5) : '00:00';
            $dtStr    = $dateStr . 'T' . $timeStr . ':00';
            $isPast   = strtotime($dateStr) < strtotime('today');
        ?>
        <?php
            $postponeKeyH = 'hearing_' . $h['hearing_id'];
            $hPostpone = $postponeMap[$postponeKeyH][0] ?? null;
        ?>
        <div class="hearing-card <?= $isPast ? 'overdue' : 'upcoming' ?>">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px;">
                <div>
                    <div style="font-weight:600; font-size:0.9rem; color:<?= $isPast ? '#842029' : '#1a3a5c' ?>">
                        <?= $isPast ? '🔴' : '🔔' ?>
                        ครั้งที่ <?= $h['hearing_round'] ?>
                        <?= $h['case_number'] ? '— เลขคดี '.htmlspecialchars($h['case_number']) : '' ?>
                        — <?= htmlspecialchars($h['court_name']) ?>
                        <?= $h['court_room'] ? '| ห้อง '.htmlspecialchars($h['court_room']) : '' ?>
                    </div>
                    <div style="font-size:0.82rem; color:#555; margin-top:3px;">
                        📅 <?= date('d/m/Y', strtotime($dateStr)) ?>
                        <?= $h['hearing_time'] ? ' เวลา '.substr($h['hearing_time'],0,5) : '' ?>
                        <?= $h['notes'] ? ' | '.htmlspecialchars(mb_substr($h['notes'],0,50)) : '' ?>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                    <div class="countdown-pill <?= $isPast ? 'overdue' : '' ?>"
                         data-datetime="<?= $dtStr ?>">
                        กำลังคำนวณ...
                    </div>
                    <?php if (!$isPast): ?>
                        <?php if ($hPostpone && $hPostpone['status'] === 'pending'): ?>
                        <span style="font-size:.72rem;background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:10px;font-weight:700;">
                            ⏳ รอทนายอนุมัติคำขอเลื่อน
                        </span>
                        <?php elseif ($hPostpone && $hPostpone['status'] === 'approved'): ?>
                        <span style="font-size:.72rem;background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:10px;font-weight:700;">
                            ✅ ทนายอนุมัติการเลื่อนแล้ว
                        </span>
                        <?php elseif ($hPostpone && $hPostpone['status'] === 'rejected'): ?>
                        <span style="font-size:.72rem;background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:10px;font-weight:700;">
                            ❌ <?= htmlspecialchars(mb_substr($hPostpone['lawyer_note'] ?? 'ไม่อนุมัติ', 0, 30)) ?>
                        </span>
                        <?php else: ?>
                        <button class="btn btn-sm" style="background:#f97316;color:#fff;font-size:.72rem;padding:3px 10px;"
                            onclick="openPostponeModal('hearing', <?= $h['hearing_id'] ?>, '<?= $h['hearing_date'] ?>')">
                            📆 ขอเลื่อนนัดขึ้นศาล
                        </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- นัดพิพากษา -->
    <?php if ($case['verdict_appt_date'] && !$case['verdict_result']): ?>
    <?php
        $vaDtStr = $case['verdict_appt_date'] . 'T' . ($case['verdict_appt_time'] ? substr($case['verdict_appt_time'],0,5) : '00:00') . ':00';
        $vaIsPast = strtotime($case['verdict_appt_date']) < strtotime('today');
    ?>
    <div style="margin-top:12px;">
        <div style="padding:12px;background:#fef9c3;border:1px solid #facc15;border-radius:8px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
                <div>
                    <div style="font-weight:700;color:#854d0e;font-size:.9rem;">⚖️ นัดฟังคำพิพากษา</div>
                    <div style="font-size:.85rem;color:#713f12;margin-top:4px;">
                        📅 <?= date('d/m/Y', strtotime($case['verdict_appt_date'])) ?>
                        <?= $case['verdict_appt_time'] ? ' เวลา '.substr($case['verdict_appt_time'],0,5).' น.' : '' ?>
                    </div>
                    <?php if ($case['verdict_appt_note']): ?>
                    <div style="font-size:.8rem;color:#92400e;margin-top:3px;">
                        📝 <?= htmlspecialchars(mb_substr($case['verdict_appt_note'], 0, 100)) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="countdown-pill <?= $vaIsPast ? 'overdue' : '' ?>"
                     data-datetime="<?= $vaDtStr ?>">กำลังคำนวณ...</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Verdict -->
    <?php if ($case['verdict_result']): ?>
    <div style="margin-top:10px;padding:12px;background:#e8f0fe;border-radius:6px;">
        <strong>⚖️ ผลคำพิพากษา (<?= $case['verdict_date'] ?>):</strong>
        <p style="margin-top:4px;"><?= nl2br(htmlspecialchars($case['verdict_result'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- Rejected reason -->
    <?php if ($case['status'] === 'rejected' && $case['reject_reason']): ?>
    <div style="margin-top:10px;" class="alert alert-error">
        <strong>เหตุผลที่ปฏิเสธ:</strong> <?= htmlspecialchars($case['reject_reason']) ?>
    </div>
    <?php endif; ?>

    </div><!-- end collapsible body -->
</div>
<?php endforeach; ?>

<!-- Modal ตอบกลับทนาย -->
<div class="modal-backdrop" id="modal-reply">
    <div class="modal-box">
        <h3 id="reply-title"></h3>
        <div id="reply-accept-info" style="display:none; background:#d1e7dd; border-radius:6px; padding:12px; margin-bottom:14px; font-size:0.9rem; color:#0f5132;"></div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="contract_id" id="reply-contract-id">
            <input type="hidden" name="action" id="reply-action">
            <div class="form-group" id="reply-msg-group" style="display:none;">
                <label id="reply-msg-label">ข้อความ</label>
                <textarea name="client_response" rows="4"
                    placeholder="ระบุข้อเสนอหรือเหตุผล..."></textarea>
            </div>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:16px;">
                <button type="button" onclick="closeReply()" class="btn btn-sm" style="background:#e2e8f0;color:#333;">ยกเลิก</button>
                <button type="submit" id="reply-submit" class="btn btn-primary btn-sm">ยืนยัน</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReply(contractId, type, proposedFee, currentFee) {
    document.getElementById('reply-contract-id').value = contractId;
    document.getElementById('reply-action').value      = 'client_' + type;
    const titleEl  = document.getElementById('reply-title');
    const infoEl   = document.getElementById('reply-accept-info');
    const msgGrp   = document.getElementById('reply-msg-group');
    const submitEl = document.getElementById('reply-submit');
    const labelEl  = document.getElementById('reply-msg-label');

    infoEl.style.display  = 'none';
    msgGrp.style.display  = 'none';

    if (type === 'accept') {
        titleEl.textContent = '✅ ยืนยันการยอมรับเงื่อนไข';
        let txt = 'คุณกำลังจะ <strong>ยอมรับ</strong> เงื่อนไขที่ทนายเสนอ';
        if (proposedFee) {
            txt += `<br>ค่าดำเนินคดีจะเปลี่ยนเป็น <strong>${Number(proposedFee).toLocaleString('th-TH', {minimumFractionDigits:2})} บาท</strong>`;
        }
        infoEl.innerHTML      = txt;
        infoEl.style.display  = 'block';
        submitEl.textContent  = '✅ ยืนยันยอมรับ';
        submitEl.className    = 'btn btn-success btn-sm';

    } else if (type === 'counter') {
        titleEl.textContent  = '💬 เสนอเงื่อนไขโต้กลับ';
        labelEl.textContent  = 'ข้อเสนอของคุณ *';
        msgGrp.style.display = 'block';
        submitEl.textContent = '📨 ส่งข้อเสนอ';
        submitEl.className   = 'btn btn-primary btn-sm';

    } else if (type === 'reject') {
        titleEl.textContent  = '❌ ปฏิเสธเงื่อนไข';
        labelEl.textContent  = 'เหตุผล (ถ้ามี)';
        msgGrp.style.display = 'block';
        submitEl.textContent = '❌ ยืนยันปฏิเสธ';
        submitEl.className   = 'btn btn-danger btn-sm';
    }

    document.getElementById('modal-reply').classList.add('open');
}

function closeReply() {
    document.getElementById('modal-reply').classList.remove('open');
}

document.getElementById('modal-reply').addEventListener('click', function(e) {
    if (e.target === this) closeReply();
});

document.querySelector('#modal-reply form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('reply-submit');
    const origText = btn.textContent; const origClass = btn.className;
    btn.disabled = true; btn.textContent = '⏳ กำลังส่ง...';
    try {
        const res  = await fetch(location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(this)
        });
        const data = await res.json();
        closeReply();
        if (data.ok) {
            await Swal.fire({ icon:'success', title:'สำเร็จ!', text: data.msg,
                confirmButtonColor:'#1a3a5c', timer:2000, timerProgressBar:true, showConfirmButton:false });
            location.reload();
        } else {
            Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text: data.msg, confirmButtonColor:'#1a3a5c' });
        }
    } catch {
        Swal.fire({ icon:'error', title:'ผิดพลาด', text:'ไม่สามารถเชื่อมต่อได้', confirmButtonColor:'#1a3a5c' });
    } finally {
        btn.disabled = false; btn.textContent = origText; btn.className = origClass;
    }
});
</script>

<script>
function updateAllCountdowns() {
    document.querySelectorAll('[data-datetime]').forEach(function(el) {
        const dt     = new Date(el.dataset.datetime);
        const now    = new Date();
        const diffMs = dt - now;
        const abs    = Math.abs(diffMs);
        const days   = Math.floor(abs / 86400000);
        const hours  = Math.floor((abs % 86400000) / 3600000);
        const mins   = Math.floor((abs % 3600000) / 60000);
        const secs   = Math.floor((abs % 60000) / 1000);
        const isPast = diffMs < 0;
        let txt;

        if (isPast) {
            if (days > 0)       txt = `เลยมา ${days} วัน ${hours} ชม.`;
            else if (hours > 0) txt = `เลยมา ${hours} ชม. ${mins} นาที`;
            else                txt = `เลยมา ${mins} นาที ${secs} วิ`;
            el.classList.add('overdue');
            el.classList.remove('urgent');
            el.style.background = '#dc3545';
        } else {
            if (days > 1)       txt = `อีก ${days} วัน ${hours} ชม.`;
            else if (days === 1)txt = `อีก 1 วัน ${hours} ชม.`;
            else if (hours > 0) txt = `อีก ${hours} ชม. ${mins} นาที`;
            else if (mins > 0)  txt = `อีก ${mins} นาที ${secs} วิ`;
            else                txt = `อีก ${secs} วินาที!`;

            if (days === 0) {
                el.classList.add('urgent');
                el.style.background = '#e67e22';
            } else if (days <= 3) {
                el.style.background = '#fd7e14';
            } else {
                el.style.background = '#1a3a5c';
            }
        }
        el.textContent = txt;
    });
}
updateAllCountdowns();
setInterval(updateAllCountdowns, 1000);
</script>

<script>
function toggleCase(id) {
    const body = document.getElementById(id);
    const reqId = id.replace('case-', '');
    const icon  = document.getElementById('icon-' + id);
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : 'block';
    icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}

// auto-open คดีที่รอตอบกลับ หรือคดีแรกถ้ามีแค่คดีเดียว
document.addEventListener('DOMContentLoaded', function() {
    const allBodies = document.querySelectorAll('[id^="case-"]');
    let opened = false;
    allBodies.forEach(function(body) {
        const reqId = body.id.replace('case-', '');
        const icon  = document.getElementById('icon-case-' + reqId);
        const header = body.previousElementSibling;
        // เปิดถ้ามี badge รอตอบกลับ (มี warning badge)
        if (header && header.querySelector('[style*="ffc107"]')) {
            body.style.display = 'block';
            if (icon) icon.style.transform = 'rotate(180deg)';
            opened = true;
        }
    });
    // ถ้าไม่มีตัวไหนถูก auto-open และมีแค่คดีเดียว → เปิดอัตโนมัติ
    if (!opened && allBodies.length === 1) {
        const body = allBodies[0];
        const reqId = body.id.replace('case-', '');
        const icon  = document.getElementById('icon-case-' + reqId);
        body.style.display = 'block';
        if (icon) icon.style.transform = 'rotate(180deg)';
    }
});
</script>

<!-- ===== Modal ขอเลื่อนวันนัด ===== -->
<div class="modal-backdrop" id="modal-postpone">
    <div class="modal-box" style="max-width:480px;">
        <h3 id="postpone-title">📆 ขอเลื่อนวันนัด</h3>
        <div id="postpone-current-date" style="background:#f0f4f8;border-radius:6px;padding:9px 14px;margin-bottom:14px;font-size:.87rem;color:#374151;"></div>
        <form id="postpone-form" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_postpone">
            <input type="hidden" name="request_type" id="postpone-type">
            <input type="hidden" name="reference_id" id="postpone-ref-id">
            <div class="form-group" style="margin-bottom:14px;">
                <label>วันที่ต้องการเลื่อนไป (ถ้าทราบ)</label>
                <input type="date" name="requested_date" id="postpone-requested-date"
                    style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label>เหตุผลที่ขอเลื่อน <span style="color:red">*</span></label>
                <textarea name="reason" id="postpone-reason" rows="4" required
                    style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"
                    placeholder="เช่น ติดภารกิจ, ไม่สามารถมาได้เนื่องจาก..."></textarea>
            </div>
            <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:9px 13px;margin-bottom:14px;font-size:.8rem;color:#92400e;">
                ⚠️ คำขอนี้จะถูกส่งให้ทนายพิจารณา ทนายจะเป็นผู้ดำเนินการเลื่อนวันในระบบ
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="closePostponeModal()"
                    style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
                <button type="submit" id="postpone-submit"
                    style="padding:9px 24px;background:#f97316;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">📨 ส่งคำขอ</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPostponeModal(type, refId, currentDate) {
    document.getElementById('postpone-type').value    = type;
    document.getElementById('postpone-ref-id').value  = refId;
    document.getElementById('postpone-reason').value  = '';
    document.getElementById('postpone-requested-date').value = '';

    const typeLabel = type === 'filing' ? 'วันนัดยื่นฟ้อง' : 'วันนัดขึ้นศาล';
    document.getElementById('postpone-title').textContent = '📆 ขอเลื่อน' + typeLabel;

    // แปลงวันที่เป็น format ไทย
    const d = new Date(currentDate + 'T00:00:00');
    const dateStr = d.toLocaleDateString('th-TH', {day:'2-digit', month:'2-digit', year:'numeric'});
    document.getElementById('postpone-current-date').innerHTML =
        `วันนัดปัจจุบัน: <strong>${dateStr}</strong>`;

    // วันที่ขอเลื่อนต้องหลังจากวันนัดเดิม
    document.getElementById('postpone-requested-date').min = currentDate;

    document.getElementById('modal-postpone').classList.add('open');
    setTimeout(() => document.getElementById('postpone-reason').focus(), 100);
}

function closePostponeModal() {
    document.getElementById('modal-postpone').classList.remove('open');
}

document.getElementById('modal-postpone').addEventListener('click', function(e) {
    if (e.target === this) closePostponeModal();
});

document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('postpone-form');
    if (!form) return;
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('postpone-submit');
        const orig = btn.textContent;
        btn.disabled = true; btn.textContent = '⏳ กำลังส่ง...';
        try {
            const res  = await fetch(location.pathname, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(this)
            });
            const data = await res.json();
            closePostponeModal();
            if (data.ok) {
                await Swal.fire({ icon:'success', title:'ส่งคำขอแล้ว!', text: data.msg,
                    confirmButtonColor:'#1a3a5c', timer:2200, timerProgressBar:true, showConfirmButton:false });
                location.reload();
            } else {
                Swal.fire({ icon:'error', title:'ไม่สำเร็จ', text: data.msg, confirmButtonColor:'#1a3a5c' });
            }
        } catch {
            Swal.fire({ icon:'error', title:'ผิดพลาด', text:'เชื่อมต่อไม่ได้', confirmButtonColor:'#1a3a5c' });
        } finally { btn.disabled = false; btn.textContent = orig; }
    });
});
</script>

<?php include '../includes/footer.php'; ?>