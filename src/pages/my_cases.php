<?php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
requireLogin();

$pdo    = getDB();
$userId = $_SESSION['user_id'];
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
    $contractId = (int)($_POST['contract_id'] ?? 0);
    $action     = $_POST['action'] ?? '';

    // ตรวจสอบว่า contract เป็นของ client คนนี้จริง
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
                    negotiation_status     = 'negotiating',
                    contract_review_status = 'negotiating',
                    fee_amount         = COALESCE(proposed_fee, fee_amount),
                    proposed_fee       = NULL,
                    client_response        = 'ยอมรับเงื่อนไข รอทนายยืนยันสัญญาสุดท้าย',
                    negotiated_at      = NOW()
                WHERE contract_id = ?
            ")->execute([$contractId]);
            $success = '✅ ยอมรับเงื่อนไขแล้ว รอทนายยืนยันสัญญาสุดท้าย';

        } elseif ($action === 'client_counter') {
            $msg = trim($_POST['client_response'] ?? '');
            if (!$msg) { $error = 'กรุณาระบุข้อเสนอของคุณ'; }
            else {
                $pdo->prepare("
                    UPDATE contracts SET
                        negotiation_status     = 'negotiating',
                        contract_review_status = 'negotiating',
                        client_response        = ?,
                        negotiated_at      = NOW()
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
                    negotiated_at      = NOW()
                WHERE contract_id = ?
            ")->execute([$msg, $contractId]);
            $success = '❌ ส่งการปฏิเสธแล้ว ทนายจะได้รับทราบ';
        }
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
           con.payment_status, con.negotiation_status,
           con.lawyer_note, con.proposed_fee, con.client_response,
           con.negotiated_at,
           f.filing_id, f.case_number, f.charge, f.filing_date,
           ct.court_name,
           v.result AS verdict_result, v.verdict_date
    FROM case_requests cr
    JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
    LEFT JOIN contracts con ON cr.request_id = con.request_id
    LEFT JOIN filings f ON con.contract_id = f.contract_id
    LEFT JOIN courts ct ON f.court_id = ct.court_id
    LEFT JOIN verdicts v ON f.filing_id = v.filing_id
    WHERE cr.client_id = ?
    ORDER BY cr.created_at DESC
");
$stmt->execute([$clientId]);
$cases = $stmt->fetchAll();

// ดึงนัดขึ้นศาลทั้งหมดของลูกความคนนี้ (เฉพาะ scheduled + ยังไม่เลยมากกว่า 30 วัน)
$hearingStmt = $pdo->prepare("
    SELECT ch.hearing_id, ch.hearing_date, ch.hearing_time, ch.court_room,
           ch.hearing_round, ch.status, ch.notes,
           f.case_number, f.charge,
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
$pendingNeg = array_filter($cases, fn($c) => ($c['negotiation_status'] ?? '') === 'revision_requested');

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

<style>
.neg-alert { border-radius:8px; padding:14px 18px; margin-bottom:16px; border-left:5px solid; font-size:0.92rem; }
.neg-alert.warning { background:#fff8e1; border-color:#ffc107; }
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal-box { background:#fff; border-radius:10px; padding:28px; width:100%; max-width:460px; box-shadow:0 8px 32px rgba(0,0,0,.18); }
.modal-box h3 { color:#1a3a5c; margin-bottom:14px; }
</style>

<h2 style="margin-bottom:16px; color:#1a3a5c;">📁 คดีของฉัน</h2>

<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

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
    $negStatus = $case['negotiation_status'] ?? 'accepted';
    $negInfo   = $negLabel[$negStatus] ?? $negLabel['accepted'];
    $isRevision = $negStatus === 'revision_requested';
?>
<div class="card" style="<?= $isRevision ? 'border:2px solid #ffc107;' : '' ?>">

    <!-- Header -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:8px;">
        <h2 style="margin:0;">
            คำขอ #<?= $case['request_id'] ?>
            <span class="badge <?= $badgeMap[$case['status']] ?? '' ?>" style="margin-left:8px;">
                <?= $statusTH[$case['status']] ?? $case['status'] ?>
            </span>
        </h2>
        <small style="color:#888;">ส่งเมื่อ <?= $case['request_date'] ?> | หมดอายุ <?= $case['expire_date'] ?></small>
    </div>

    <!-- Lawyer Info -->
    <div style="background:#f8f9fa; padding:12px; border-radius:6px; margin-bottom:16px;">
        <strong>👨‍⚖️ ทนาย:</strong> <?= htmlspecialchars($case['lawyer_name']) ?>
        <?php if ($case['specialization']): ?>
        <span style="color:#666; margin-left:6px;">(<?= htmlspecialchars($case['specialization']) ?>)</span>
        <?php endif; ?>
        <?php if ($case['lawyer_phone']): ?>
        | 📞 <?= htmlspecialchars($case['lawyer_phone']) ?>
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
            <?php if ($case['negotiated_at']): ?>
            <span style="font-weight:400; font-size:0.78rem; margin-left:8px; opacity:.75;">
                เมื่อ <?= date('d/m/Y H:i', strtotime($case['negotiated_at'])) ?>
            </span>
            <?php endif; ?>
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

    <!-- Filing Detail -->
    <?php if ($case['filing_id']): ?>
    <div style="margin-top:16px;padding:12px;background:#e8f4e8;border-radius:6px;">
        <strong>🏛️ การยื่นฟ้อง:</strong>
        เลขคดี <?= htmlspecialchars($case['case_number'] ?? '—') ?>
        | ศาล: <?= htmlspecialchars($case['court_name'] ?? '—') ?>
        | ข้อหา: <?= htmlspecialchars($case['charge'] ?? '—') ?>
        | วันที่ยื่น: <?= $case['filing_date'] ?? '—' ?>
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
        <div class="hearing-card <?= $isPast ? 'overdue' : 'upcoming' ?>">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <div>
                    <div style="font-weight:600; font-size:0.9rem; color:<?= $isPast ? '#842029' : '#1a3a5c' ?>">
                        <?= $isPast ? '🔴' : '🔔' ?>
                        ครั้งที่ <?= $h['hearing_round'] ?>
                        — <?= htmlspecialchars($h['court_name']) ?>
                        <?= $h['court_room'] ? '| ห้อง '.htmlspecialchars($h['court_room']) : '' ?>
                    </div>
                    <div style="font-size:0.82rem; color:#555; margin-top:3px;">
                        📅 <?= date('d/m/Y', strtotime($dateStr)) ?>
                        <?= $h['hearing_time'] ? ' เวลา '.substr($h['hearing_time'],0,5) : '' ?>
                        <?= $h['notes'] ? ' | '.htmlspecialchars(mb_substr($h['notes'],0,50)) : '' ?>
                    </div>
                </div>
                <div class="countdown-pill <?= $isPast ? 'overdue' : '' ?>"
                     data-datetime="<?= $dtStr ?>">
                    กำลังคำนวณ...
                </div>
            </div>
        </div>
        <?php endforeach; ?>
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

</div>
<?php endforeach; ?>

<!-- Modal ตอบกลับทนาย -->
<div class="modal-backdrop" id="modal-reply">
    <div class="modal-box">
        <h3 id="reply-title"></h3>
        <div id="reply-accept-info" style="display:none; background:#d1e7dd; border-radius:6px; padding:12px; margin-bottom:14px; font-size:0.9rem; color:#0f5132;"></div>
        <form method="POST">
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

<?php include '../includes/footer.php'; ?>