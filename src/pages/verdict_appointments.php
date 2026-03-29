<?php
// verdict_appointments.php — นัดวันฟังคำพิพากษา
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/activity_log_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$officeId = $_SESSION['office_id'];
$userId   = $_SESSION['user_id'];

// ==============================
// Handle POST (admin/lawyer เท่านั้น)
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['admin','lawyer'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---- เพิ่ม/แก้ไขนัด ----
    if ($action === 'save') {
        $filingId  = (int)($_POST['filing_id'] ?? 0);
        $date      = $_POST['scheduled_date'] ?? '';
        $time      = $_POST['scheduled_time'] ?: null;
        $note      = trim($_POST['note'] ?? '');
        $appointId = (int)($_POST['appointment_id'] ?? 0);

        if (!$filingId || !$date) {
            $msg = ['ok'=>false,'msg'=>'กรุณาระบุคดีและวันนัด'];
        } else {
            // ตรวจ ownership
            $chk = $pdo->prepare("
                SELECT f.filing_id FROM filings f
                JOIN contracts con   ON f.contract_id  = con.contract_id
                JOIN case_requests cr ON con.request_id = cr.request_id
                WHERE f.filing_id = ? AND cr.office_id = ?
            ");
            $chk->execute([$filingId, $officeId]);
            if (!$chk->fetch()) {
                $msg = ['ok'=>false,'msg'=>'ไม่พบคดีหรือไม่มีสิทธิ์'];
            } else {
                if ($appointId) {
                    $pdo->prepare("
                        UPDATE verdict_appointments
                        SET scheduled_date=?, scheduled_time=?, note=?, status='scheduled'
                        WHERE appointment_id=? AND filing_id=?
                    ")->execute([$date, $time, $note ?: null, $appointId, $filingId]);
                    audit_log($pdo, $officeId, $userId, 'update', 'verdict_appointment', $appointId, 'แก้ไขนัดพิพากษา #'.$appointId);
                    $msg = ['ok'=>true,'msg'=>'แก้ไขนัดวันพิพากษาเรียบร้อยแล้ว'];
                } else {
                    $pdo->prepare("
                        INSERT INTO verdict_appointments (filing_id, scheduled_date, scheduled_time, note, status, created_by)
                        VALUES (?, ?, ?, ?, 'scheduled', ?)
                    ")->execute([$filingId, $date, $time, $note ?: null, $userId]);
                    $newVaId = (int)$pdo->lastInsertId();
                    audit_log($pdo, $officeId, $userId, 'create', 'verdict_appointment', $newVaId, 'เพิ่มนัดพิพากษาใหม่ filing #'.$filingId);
                    $msg = ['ok'=>true,'msg'=>'เพิ่มนัดวันพิพากษาเรียบร้อยแล้ว'];
                }
            }
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($msg); exit; }
    }

    // ---- เปลี่ยนสถานะนัด ----
    if ($action === 'update_status') {
        $appointId = (int)($_POST['appointment_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        $allowed   = ['scheduled','completed','postponed','cancelled'];

        if (!$appointId || !in_array($newStatus, $allowed)) {
            $msg = ['ok'=>false,'msg'=>'ข้อมูลไม่ถูกต้อง'];
        } else {
            // ตรวจ ownership ผ่าน filing
            $chk = $pdo->prepare("
                SELECT va.appointment_id FROM verdict_appointments va
                JOIN filings f           ON va.filing_id   = f.filing_id
                JOIN contracts con       ON f.contract_id  = con.contract_id
                JOIN case_requests cr    ON con.request_id = cr.request_id
                WHERE va.appointment_id = ? AND cr.office_id = ?
            ");
            $chk->execute([$appointId, $officeId]);
            if (!$chk->fetch()) {
                $msg = ['ok'=>false,'msg'=>'ไม่พบนัดหรือไม่มีสิทธิ์'];
            } else {
                $pdo->prepare("UPDATE verdict_appointments SET status=? WHERE appointment_id=?")
                    ->execute([$newStatus, $appointId]);
                audit_log($pdo, $officeId, $userId, 'update', 'verdict_appointment', $appointId, 'เปลี่ยนสถานะนัดพิพากษา #'.$appointId.' เป็น '.$newStatus);
                $statusTH = ['scheduled'=>'นัดไว้','completed'=>'เสร็จสิ้น','postponed'=>'เลื่อนนัด','cancelled'=>'ยกเลิก'];
                $msg = ['ok'=>true,'msg'=>'เปลี่ยนสถานะเป็น "' . ($statusTH[$newStatus] ?? $newStatus) . '" แล้ว'];
            }
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($msg); exit; }
    }

    // ---- ลบนัด ----
    if ($action === 'delete') {
        $appointId = (int)($_POST['appointment_id'] ?? 0);
        $chk = $pdo->prepare("
            SELECT va.appointment_id FROM verdict_appointments va
            JOIN filings f        ON va.filing_id   = f.filing_id
            JOIN contracts con    ON f.contract_id  = con.contract_id
            JOIN case_requests cr ON con.request_id = cr.request_id
            WHERE va.appointment_id = ? AND cr.office_id = ?
        ");
        $chk->execute([$appointId, $officeId]);
        if (!$chk->fetch()) {
            $msg = ['ok'=>false,'msg'=>'ไม่พบนัดหรือไม่มีสิทธิ์'];
        } else {
            $pdo->prepare("DELETE FROM verdict_appointments WHERE appointment_id=?")->execute([$appointId]);
            audit_log($pdo, $officeId, $userId, 'delete', 'verdict_appointment', $appointId, 'ลบนัดพิพากษา #'.$appointId);
            $msg = ['ok'=>true,'msg'=>'ลบนัดเรียบร้อยแล้ว'];
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode($msg); exit; }
    }

    header('Location: /pages/verdict_appointments.php'); exit;
}

// ==============================
// Fetch: lawyer filter
// ==============================
if ($role === 'lawyer') {
    $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lp->execute([$userId]);
    $lawyerId   = $lp->fetchColumn();
    $whereExtra = "AND cr.lawyer_id = $lawyerId";
} else {
    $whereExtra = '';
}

// ดึงนัดทั้งหมด
$appointments = $pdo->prepare("
    SELECT va.*,
           f.charge, f.scheduled_filing_date,
           (SELECT MAX(ch.case_number) FROM court_hearings ch WHERE ch.filing_id = f.filing_id) AS case_number,
           ct.court_name,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           v.verdict_id
    FROM verdict_appointments va
    JOIN filings f           ON va.filing_id    = f.filing_id
    LEFT JOIN courts ct      ON f.court_id      = ct.court_id
    JOIN contracts con       ON f.contract_id   = con.contract_id
    JOIN case_requests cr    ON con.request_id  = cr.request_id
    JOIN client_profiles cp  ON cr.client_id    = cp.client_id
    JOIN lawyer_profiles lp  ON cr.lawyer_id    = lp.lawyer_id
    LEFT JOIN verdicts v     ON f.filing_id     = v.filing_id
    WHERE cr.office_id = ? $whereExtra
    ORDER BY
        CASE va.status WHEN 'scheduled' THEN 0 ELSE 1 END,
        va.scheduled_date ASC
");
$appointments->execute([$officeId]);
$allAppointments = $appointments->fetchAll();

// ดึง filings ที่ยังไม่มีคำพิพากษา สำหรับ dropdown เพิ่มนัดใหม่
$filingStmt = $pdo->prepare("
    SELECT f.filing_id,
           (SELECT MAX(ch.case_number) FROM court_hearings ch WHERE ch.filing_id = f.filing_id) AS case_number,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           ct.court_name
    FROM filings f
    LEFT JOIN courts ct      ON f.court_id      = ct.court_id
    JOIN contracts con       ON f.contract_id   = con.contract_id
    JOIN case_requests cr    ON con.request_id  = cr.request_id
    JOIN client_profiles cp  ON cr.client_id    = cp.client_id
    JOIN lawyer_profiles lp  ON cr.lawyer_id    = lp.lawyer_id
    LEFT JOIN verdicts v     ON f.filing_id     = v.filing_id
    WHERE cr.office_id = ? $whereExtra
      AND v.verdict_id IS NULL
      AND con.status = 'active'
    ORDER BY f.scheduled_filing_date DESC
");
$filingStmt->execute([$officeId]);
$availableFilings = $filingStmt->fetchAll();

// แยก upcoming vs past
$today       = date('Y-m-d');
$upcoming    = array_filter($allAppointments, fn($a) => $a['status'] === 'scheduled' && $a['scheduled_date'] >= $today);
$pastOrDone  = array_filter($allAppointments, fn($a) => $a['status'] !== 'scheduled' || $a['scheduled_date'] < $today);

$statusConfig = [
    'scheduled'  => ['label'=>'📅 นัดไว้',    'bg'=>'#dbeafe','color'=>'#1e40af'],
    'completed'  => ['label'=>'✅ เสร็จสิ้น',  'bg'=>'#d1fae5','color'=>'#065f46'],
    'postponed'  => ['label'=>'⏩ เลื่อนนัด',  'bg'=>'#fef3c7','color'=>'#92400e'],
    'cancelled'  => ['label'=>'❌ ยกเลิก',    'bg'=>'#fee2e2','color'=>'#991b1b'],
];

$pageTitle = 'นัดวันพิพากษา';
include '../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--navy:#1a3a5c;--navy2:#2d5a8a;--border:#e2e8f0;}
.appt-card{border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:14px;transition:.15s;}
.appt-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
.appt-header{padding:13px 18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}
.appt-body{padding:14px 18px;border-top:1px solid var(--border);background:#fafafa;}
.appt-date{font-size:1.6rem;font-weight:900;color:var(--navy);line-height:1;}
.appt-date-lbl{font-size:.72rem;color:#94a3b8;margin-top:2px;}
.info-row{display:flex;flex-wrap:wrap;gap:18px;font-size:.84rem;}
.info-row span{color:#475569;}
.info-row strong{color:#1e293b;}
.stat-badge{padding:3px 12px;border-radius:20px;font-size:.73rem;font-weight:700;}
.action-btn{padding:5px 12px;border-radius:6px;font-size:.75rem;font-weight:700;border:none;cursor:pointer;transition:.15s;}
.today-flash{animation:flash 1.5s ease-in-out infinite;}
@keyframes flash{0%,100%{opacity:1}50%{opacity:.6}}
.tab-btn{padding:7px 18px;border-radius:8px;font-size:.83rem;font-weight:700;border:none;cursor:pointer;transition:.15s;background:#f1f5f9;color:#475569;}
.tab-btn.active{background:var(--navy);color:#fff;}
</style>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:0;">🗓️ นัดวันฟังคำพิพากษา</h2>
        <?php if (in_array($role,['admin','lawyer'])): ?>
        <button onclick="openAddModal()" class="btn btn-primary">➕ เพิ่มนัดใหม่</button>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
        <div style="background:#eff6ff;border-radius:10px;padding:14px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:900;color:#1d4ed8;"><?= count($allAppointments) ?></div>
            <div style="font-size:.75rem;color:#94a3b8;margin-top:2px;">นัดทั้งหมด</div>
        </div>
        <div style="background:#d1fae5;border-radius:10px;padding:14px;text-align:center;">
            <?php $sc = count(array_filter($allAppointments,fn($a)=>$a['status']==='scheduled'&&$a['scheduled_date']>=$today)); ?>
            <div style="font-size:1.8rem;font-weight:900;color:#065f46;"><?= $sc ?></div>
            <div style="font-size:.75rem;color:#94a3b8;margin-top:2px;">ที่กำลังจะมาถึง</div>
        </div>
        <div style="background:#fef3c7;border-radius:10px;padding:14px;text-align:center;">
            <?php $pc = count(array_filter($allAppointments,fn($a)=>$a['status']==='postponed')); ?>
            <div style="font-size:1.8rem;font-weight:900;color:#92400e;"><?= $pc ?></div>
            <div style="font-size:.75rem;color:#94a3b8;margin-top:2px;">เลื่อนนัด</div>
        </div>
        <div style="background:#fce7f3;border-radius:10px;padding:14px;text-align:center;">
            <?php $dc = count(array_filter($allAppointments,fn($a)=>$a['status']==='completed')); ?>
            <div style="font-size:1.8rem;font-weight:900;color:#9d174d;"><?= $dc ?></div>
            <div style="font-size:.75rem;color:#94a3b8;margin-top:2px;">เสร็จสิ้นแล้ว</div>
        </div>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:8px;margin-bottom:16px;">
        <button class="tab-btn active" id="tab-upcoming" onclick="switchTab('upcoming')">📅 กำลังจะมาถึง (<?= count($upcoming) ?>)</button>
        <button class="tab-btn" id="tab-past" onclick="switchTab('past')">📋 ประวัติ (<?= count($pastOrDone) ?>)</button>
    </div>

    <!-- Upcoming -->
    <div id="section-upcoming">
    <?php if (empty($upcoming)): ?>
    <div style="text-align:center;padding:40px;color:#94a3b8;">
        <div style="font-size:2.5rem;margin-bottom:8px;">📭</div>
        ยังไม่มีนัดที่กำลังจะมาถึง
    </div>
    <?php endif; ?>
    <?php foreach ($upcoming as $a):
        $isToday  = $a['scheduled_date'] === $today;
        $daysDiff = (int)((strtotime($a['scheduled_date']) - strtotime($today)) / 86400);
        $st       = $statusConfig[$a['status']] ?? $statusConfig['scheduled'];
        $borderColor = $isToday ? '#ef4444' : '#3b82f6';
    ?>
    <div class="appt-card" style="border-left:4px solid <?= $borderColor ?>;">
        <div class="appt-header" style="background:<?= $isToday ? '#fff7ed' : '#f8fafc' ?>;">
            <!-- วันที่ -->
            <div style="display:flex;align-items:center;gap:16px;">
                <div style="text-align:center;min-width:56px;">
                    <div class="appt-date <?= $isToday ? 'today-flash' : '' ?>" style="color:<?= $isToday ? '#ef4444' : '#1a3a5c' ?>;">
                        <?= date('d', strtotime($a['scheduled_date'])) ?>
                    </div>
                    <div class="appt-date-lbl"><?= date('M Y', strtotime($a['scheduled_date'])) ?></div>
                    <?php if ($a['scheduled_time']): ?>
                    <div style="font-size:.75rem;color:var(--navy);font-weight:700;margin-top:2px;">
                        🕐 <?= substr($a['scheduled_time'],0,5) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($isToday): ?>
                    <span style="background:#ef4444;color:#fff;padding:2px 10px;border-radius:20px;font-size:.7rem;font-weight:700;margin-bottom:4px;display:inline-block;">วันนี้!</span>
                    <?php elseif ($daysDiff <= 7): ?>
                    <span style="background:#f97316;color:#fff;padding:2px 10px;border-radius:20px;font-size:.7rem;font-weight:700;margin-bottom:4px;display:inline-block;">อีก <?= $daysDiff ?> วัน</span>
                    <?php endif; ?>
                    <div style="font-weight:700;color:#1e293b;font-size:.95rem;">
                        📁 <?= htmlspecialchars($a['case_number'] ?? 'ไม่มีเลขคดี') ?>
                    </div>
                    <div style="font-size:.8rem;color:#64748b;">
                        🏛️ <?= htmlspecialchars($a['court_name'] ?? '—') ?> &nbsp;|&nbsp;
                        👤 <?= htmlspecialchars($a['client_name']) ?> &nbsp;|&nbsp;
                        👨‍⚖️ <?= htmlspecialchars($a['lawyer_name']) ?>
                    </div>
                </div>
            </div>
            <!-- Actions -->
            <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;">
                <span class="stat-badge" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;"><?= $st['label'] ?></span>
                <?php if (in_array($role,['admin','lawyer'])): ?>
                <button class="action-btn" style="background:#eff6ff;color:#1d4ed8;"
                    onclick='openEditModal(<?= json_encode([
                        "appointment_id" => (int)$a["appointment_id"],
                        "filing_id"      => (int)$a["filing_id"],
                        "case_number"    => $a["case_number"] ?? "",
                        "scheduled_date" => $a["scheduled_date"],
                        "scheduled_time" => $a["scheduled_time"] ?? "",
                        "note"           => $a["note"] ?? "",
                    ], JSON_UNESCAPED_UNICODE) ?>)'>✏️ แก้ไข</button>
                <button class="action-btn" style="background:#fef3c7;color:#92400e;"
                    onclick="updateStatus(<?= $a['appointment_id'] ?>, 'postponed', 'เลื่อนนัด')">⏩ เลื่อน</button>
                <button class="action-btn" style="background:#fee2e2;color:#991b1b;"
                    onclick="updateStatus(<?= $a['appointment_id'] ?>, 'cancelled', 'ยกเลิกนัด')">❌ ยกเลิก</button>
                <?php if (!$a['verdict_id']): ?>
                <a href="/pages/Verdicts.php" class="action-btn" style="background:#1a3a5c;color:#fff;text-decoration:none;">
                    ⚖️ บันทึกคำพิพากษา
                </a>
                <?php else: ?>
                <a href="/pages/Verdicts.php" class="action-btn" style="background:#059669;color:#fff;text-decoration:none;">
                    ✅ ดูคำพิพากษา
                </a>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($a['note']): ?>
        <div class="appt-body">
            <span style="font-size:.78rem;color:#94a3b8;">📝 หมายเหตุ:</span>
            <span style="font-size:.84rem;color:#374151;"> <?= htmlspecialchars($a['note']) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Past/Done -->
    <div id="section-past" style="display:none;">
    <?php if (empty($pastOrDone)): ?>
    <div style="text-align:center;padding:40px;color:#94a3b8;">ยังไม่มีประวัตินัด</div>
    <?php endif; ?>
    <?php foreach ($pastOrDone as $a):
        $st = $statusConfig[$a['status']] ?? $statusConfig['scheduled'];
        $isOverdue = $a['status'] === 'scheduled' && $a['scheduled_date'] < $today;
    ?>
    <div class="appt-card" style="border-left:4px solid <?= $isOverdue ? '#f97316' : '#94a3b8' ?>;opacity:<?= $a['status']==='cancelled' ? '.6' : '1' ?>;">
        <div class="appt-header">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="text-align:center;min-width:56px;">
                    <div style="font-size:1.4rem;font-weight:900;color:#94a3b8;">
                        <?= date('d', strtotime($a['scheduled_date'])) ?>
                    </div>
                    <div class="appt-date-lbl"><?= date('M Y', strtotime($a['scheduled_date'])) ?></div>
                </div>
                <div>
                    <?php if ($isOverdue): ?>
                    <span style="background:#f97316;color:#fff;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:700;margin-bottom:2px;display:inline-block;">เกินกำหนด</span>
                    <?php endif; ?>
                    <div style="font-weight:700;color:#374151;">📁 <?= htmlspecialchars($a['case_number'] ?? '—') ?></div>
                    <div style="font-size:.8rem;color:#94a3b8;">👤 <?= htmlspecialchars($a['client_name']) ?> | 👨‍⚖️ <?= htmlspecialchars($a['lawyer_name']) ?></div>
                </div>
            </div>
            <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap;">
                <span class="stat-badge" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;"><?= $st['label'] ?></span>
                <?php if (in_array($role,['admin','lawyer']) && $a['status'] !== 'cancelled'): ?>
                <?php if (!$a['verdict_id']): ?>
                <a href="/pages/Verdicts.php" class="action-btn" style="background:#1a3a5c;color:#fff;text-decoration:none;">⚖️ บันทึกคำพิพากษา</a>
                <?php else: ?>
                <a href="/pages/Verdicts.php" class="action-btn" style="background:#059669;color:#fff;text-decoration:none;">✅ ดูคำพิพากษา</a>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (in_array($role,['admin','lawyer'])): ?>
                <button class="action-btn" style="background:#fee2e2;color:#991b1b;"
                    onclick="deleteAppt(<?= $a['appointment_id'] ?>, <?= json_encode($a['case_number'] ?? '#'.$a['filing_id'], JSON_UNESCAPED_UNICODE) ?>)">🗑️ ลบ</button>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($a['note']): ?>
        <div class="appt-body">
            <span style="font-size:.78rem;color:#94a3b8;">📝 หมายเหตุ:</span>
            <span style="font-size:.84rem;color:#374151;"> <?= htmlspecialchars($a['note']) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- ===== Modal: เพิ่ม/แก้ไขนัด ===== -->
<div id="apptModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3 id="modal-title" style="margin:0;color:#1a3a5c;">🗓️ เพิ่มนัดวันพิพากษา</h3>
        <button onclick="closeModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">✕</button>
    </div>
    <form id="apptForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="appointment_id" id="f-appt-id" value="0">

        <div class="form-group" style="margin-bottom:14px;">
            <label>คดี <span style="color:red">*</span></label>
            <select name="filing_id" id="f-filing" required
                style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;">
                <option value="">— เลือกคดี —</option>
                <?php foreach ($availableFilings as $fl): ?>
                <option value="<?= $fl['filing_id'] ?>">
                    <?= htmlspecialchars(($fl['case_number'] ?? '#'.$fl['filing_id']) . ' — ' . $fl['client_name'] . ' / ' . $fl['lawyer_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <small style="color:#94a3b8;font-size:.75rem;">แสดงเฉพาะคดีที่ยังไม่มีคำพิพากษา</small>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div class="form-group">
                <label>วันนัดฟังคำพิพากษา <span style="color:red">*</span></label>
                <input type="date" name="scheduled_date" id="f-date" required
                    style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;">
            </div>
            <div class="form-group">
                <label>เวลา</label>
                <input type="time" name="scheduled_time" id="f-time"
                    style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;">
            </div>
        </div>

        <div class="form-group" style="margin-bottom:18px;">
            <label>หมายเหตุ</label>
            <textarea name="note" id="f-note" rows="3"
                style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"
                placeholder="รายละเอียดเพิ่มเติม เช่น ห้องพิจารณา ผู้ที่ต้องมา..."></textarea>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="closeModal()"
                style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
            <button type="submit" id="apptSubmitBtn"
                style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">💾 บันทึก</button>
        </div>
    </form>
</div></div>

<script>
function switchTab(tab) {
    document.getElementById('section-upcoming').style.display = tab==='upcoming' ? '' : 'none';
    document.getElementById('section-past').style.display     = tab==='past'     ? '' : 'none';
    document.getElementById('tab-upcoming').classList.toggle('active', tab==='upcoming');
    document.getElementById('tab-past').classList.toggle('active', tab==='past');
}

function closeModal() { document.getElementById('apptModal').style.display='none'; }
document.getElementById('apptModal').addEventListener('click',function(e){if(e.target===this)closeModal();});

function openAddModal() {
    document.getElementById('modal-title').textContent = '🗓️ เพิ่มนัดวันพิพากษา';
    document.getElementById('apptForm').reset();
    document.getElementById('f-appt-id').value = '0';
    document.getElementById('f-date').min = new Date().toISOString().split('T')[0];
    document.getElementById('apptModal').style.display = 'flex';
    setTimeout(()=>document.getElementById('f-filing').focus(),100);
}

function openEditModal(d) {
    document.getElementById('modal-title').textContent = '✏️ แก้ไขนัดวันพิพากษา';
    document.getElementById('f-appt-id').value  = d.appointment_id;
    document.getElementById('f-filing').value   = d.filing_id;
    document.getElementById('f-date').value     = d.scheduled_date;
    document.getElementById('f-time').value     = d.scheduled_time;
    document.getElementById('f-note').value     = d.note;
    document.getElementById('apptModal').style.display = 'flex';
}

async function updateStatus(id, status, label) {
    const r = await Swal.fire({
        icon:'warning', title:`${label}?`,
        text:'ต้องการเปลี่ยนสถานะนัดนี้?',
        showCancelButton:true, confirmButtonText:'ยืนยัน', cancelButtonText:'ยกเลิก',
        confirmButtonColor:'#1a3a5c', cancelButtonColor:'#94a3b8'
    });
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fd.append('action','update_status');
    fd.append('appointment_id', id);
    fd.append('status', status);
    try {
        const res  = await fetch(location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
        const data = await res.json();
        if (data.ok) { await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1600,timerProgressBar:true,showConfirmButton:false}); location.reload(); }
        else Swal.fire({icon:'error',title:'ผิดพลาด',text:data.msg,confirmButtonColor:'#1a3a5c'});
    } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
}

async function deleteAppt(id, caseNo) {
    const r = await Swal.fire({
        icon:'warning', title:'ลบนัด?',
        html:`ลบนัดคดี <b>"${caseNo}"</b>?`,
        showCancelButton:true, confirmButtonText:'🗑️ ลบ', cancelButtonText:'ยกเลิก',
        confirmButtonColor:'#dc2626', cancelButtonColor:'#94a3b8'
    });
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fd.append('action','delete');
    fd.append('appointment_id', id);
    try {
        const res  = await fetch(location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
        const data = await res.json();
        if (data.ok) { await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1600,timerProgressBar:true,showConfirmButton:false}); location.reload(); }
        else Swal.fire({icon:'error',title:'ผิดพลาด',text:data.msg,confirmButtonColor:'#1a3a5c'});
    } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
}

document.addEventListener('DOMContentLoaded', function() {
    const frm = document.getElementById('apptForm');
    const btn = document.getElementById('apptSubmitBtn');
    if (frm) frm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const orig = btn.textContent;
        btn.disabled = true; btn.textContent = '⏳...';
        try {
            const res  = await fetch(location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new FormData(this)});
            const data = await res.json();
            closeModal();
            if (data.ok) { await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1800,timerProgressBar:true,showConfirmButton:false}); location.reload(); }
            else Swal.fire({icon:'error',title:'ผิดพลาด',text:data.msg,confirmButtonColor:'#1a3a5c'});
        } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
        finally { btn.disabled=false; btn.textContent=orig; }
    });
});
</script>

<?php include '../includes/footer.php'; ?>