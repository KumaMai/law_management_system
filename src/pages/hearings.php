<?php
// hearings.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$officeId = $_SESSION['office_id'];
$filingId = isset($_GET['filing_id']) ? (int)$_GET['filing_id'] : null;
$error    = '';
$success  = '';

// ==============================
// Handle POST actions
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['admin','lawyer'])) {
    csrf_verify(); // ← แก้ช่องโหว่ CSRF
    $action = $_POST['action'] ?? '';

    // ---- ลบนัด ----
    if ($action === 'delete') {
        $delId = (int)$_POST['hearing_id'];
        $check = $pdo->prepare("
            SELECT ch.hearing_id FROM court_hearings ch
            JOIN filings f ON ch.filing_id = f.filing_id
            JOIN contracts con ON f.contract_id = con.contract_id
            JOIN case_requests cr ON con.request_id = cr.request_id
            WHERE ch.hearing_id = ? AND cr.office_id = ?
        ");
        $check->execute([$delId, $officeId]);
        if ($check->fetch()) {
            $pdo->prepare("DELETE FROM court_hearings WHERE hearing_id = ?")->execute([$delId]);
        }
        header("Location: /pages/hearings.php" . ($filingId ? "?filing_id=$filingId" : ''));
        exit;
    }

    // ---- อัปเดตสถานะ (เสร็จ/เลื่อน/ยกเลิก) ----
    if ($action === 'update_status') {
        $hId       = (int)$_POST['hearing_id'];
        $newStatus = $_POST['new_status'] ?? '';
        $note      = trim($_POST['status_note'] ?? '');
        $allowed   = ['completed','postponed','cancelled','defendant_absent','plaintiff_absent','defendant_guilty_verdict'];
        if (in_array($newStatus, $allowed)) {

            // ดึงข้อมูล hearing + filing_id ก่อน
            $orig = $pdo->prepare("SELECT ch.*, f.filing_id AS fid FROM court_hearings ch JOIN filings f ON ch.filing_id = f.filing_id WHERE ch.hearing_id = ?");
            $orig->execute([$hId]);
            $orig = $orig->fetch();

            $pdo->prepare("
                UPDATE court_hearings SET
                    status = ?,
                    notes  = CASE WHEN ? != '' THEN CONCAT(COALESCE(notes,''), IF(notes IS NULL OR notes='', '', ' | '), ?) ELSE notes END
                WHERE hearing_id = ?
            ")->execute([$newStatus, $note, $note, $hId]);

            // ===== จำเลยหลบหนี → ตัดสินให้โจทก์ชนะ =====
            if ($newStatus === 'defendant_guilty_verdict') {
                $verdictNote = 'ตัดสินให้โจทก์ชนะคดี เนื่องจากจำเลยจงใจหลบหนีไม่มาศาล'
                             . ($note ? " — $note" : '');

                // บันทึกคำพิพากษาใน verdicts
                $chkVerdict = $pdo->prepare("SELECT verdict_id FROM verdicts WHERE filing_id = ?");
                $chkVerdict->execute([$orig['fid']]);
                if ($chkVerdict->fetch()) {
                    // มีอยู่แล้ว → update
                    $pdo->prepare("
                        UPDATE verdicts SET
                            result       = ?,
                            verdict_date = CURDATE()
                        WHERE filing_id = ?
                    ")->execute([$verdictNote, $orig['fid']]);
                } else {
                    // ยังไม่มี → insert
                    $pdo->prepare("
                        INSERT INTO verdicts (filing_id, result, verdict_date)
                        VALUES (?, ?, CURDATE())
                    ")->execute([$orig['fid'], $verdictNote]);
                }

                // อัปเดต contract status → completed
                $pdo->prepare("
                    UPDATE contracts SET status = 'completed', payment_status = 'pending'
                    WHERE contract_id = (
                        SELECT contract_id FROM filings WHERE filing_id = ?
                    )
                ")->execute([$orig['fid']]);

                $success = '⚖️ บันทึกคำพิพากษาแล้ว โจทก์ชนะคดี — คดีปิดแล้ว';

            // ===== จำเลย/โจทก์ไม่มา → นัดใหม่ (ถ้ากรอก) =====
            } elseif (in_array($newStatus, ['defendant_absent','plaintiff_absent']) && !empty($_POST['reschedule_date'])) {
                $reschedDate = $_POST['reschedule_date'];
                $reschedTime = $_POST['reschedule_time'] ?? null;
                $nextRound   = ($orig['hearing_round'] ?? 1) + 1;
                $absentNote  = ($newStatus === 'defendant_absent' ? 'นัดใหม่ (จำเลยไม่มา)' : 'นัดใหม่ (โจทก์ไม่มา)')
                             . ($note ? " — $note" : '');

                $pdo->prepare("
                    INSERT INTO court_hearings
                        (filing_id, hearing_date, hearing_time, court_room, hearing_round, status, notes)
                    VALUES (?, ?, ?, ?, ?, 'scheduled', ?)
                ")->execute([
                    $orig['filing_id'],
                    $reschedDate,
                    $reschedTime ?: null,
                    $orig['court_room'],
                    $nextRound,
                    $absentNote,
                ]);
                $success = 'บันทึกสถานะและสร้างนัดใหม่ครั้งที่ '.$nextRound.' แล้ว';
            } else {
                $success = 'อัปเดตสถานะเรียบร้อย';
            }
        }
        if (!$success) $error = 'สถานะไม่ถูกต้อง';
    }

    // ---- เพิ่มนัดใหม่ ----
    if ($action === '' || $action === 'add') {
        $pdo->prepare("
            INSERT INTO court_hearings
                (filing_id, hearing_date, hearing_time, court_room, hearing_round, status, notes)
            VALUES (?, ?, ?, ?, ?, 'scheduled', ?)
        ")->execute([
            (int)$_POST['filing_id'],
            $_POST['hearing_date'],
            $_POST['hearing_time'] ?: null,
            trim($_POST['court_room']),
            (int)$_POST['hearing_round'],
            trim($_POST['notes'] ?? ''),
        ]);
        header("Location: /pages/hearings.php?filing_id=" . (int)$_POST['filing_id']);
        exit;
    }
}

// ==============================
// Fetch hearings
// ==============================
$where = $filingId ? "AND ch.filing_id = " . (int)$filingId : '';
$stmt  = $pdo->prepare("
    SELECT ch.*, f.case_number,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           ct.court_name, cr.office_id
    FROM court_hearings ch
    JOIN filings f       ON ch.filing_id = f.filing_id
    JOIN contracts con   ON f.contract_id = con.contract_id
    JOIN case_requests cr ON con.request_id = cr.request_id
    JOIN client_profiles cp ON cr.client_id = cp.client_id
    JOIN courts ct       ON f.court_id = ct.court_id
    WHERE cr.office_id = ? $where
    ORDER BY ch.hearing_date ASC, ch.hearing_time ASC
");
$stmt->execute([$officeId]);
$hearings = $stmt->fetchAll();

// Filings dropdown — ดึงรายละเอียดครบสำหรับ info card
$filings = $pdo->prepare("
    SELECT f.filing_id, f.case_number, f.charge, f.filing_date,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           lp.specialization,
           ct.court_name,
           cr.detail AS case_detail,
           con.fee_amount, con.contract_date
    FROM filings f
    JOIN contracts con    ON f.contract_id  = con.contract_id
    JOIN case_requests cr ON con.request_id = cr.request_id
    JOIN client_profiles cp ON cr.client_id = cp.client_id
    JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
    JOIN courts ct        ON f.court_id     = ct.court_id
    WHERE cr.office_id = ?
    ORDER BY f.created_at DESC
");
$filings->execute([$officeId]);
$filings = $filings->fetchAll();

// สร้าง map สำหรับ JS info card
$filingMap = [];
foreach ($filings as $fi) {
    $filingMap[$fi['filing_id']] = [
        'case_number' => $fi['case_number'] ?? '—',
        'client'      => $fi['client_name'],
        'lawyer'      => $fi['lawyer_name'],
        'spec'        => $fi['specialization'] ?? '',
        'court'       => $fi['court_name'] ?? '—',
        'charge'      => $fi['charge'] ?? '—',
        'filing_date' => $fi['filing_date'] ?? '—',
        'detail'      => mb_substr($fi['case_detail'] ?? '—', 0, 120) . (mb_strlen($fi['case_detail'] ?? '') > 120 ? '...' : ''),
        'fee'         => $fi['fee_amount'] ? number_format($fi['fee_amount'], 2) : '—',
    ];
}

// รวมนัดที่ใกล้มา (30 วัน) + เลยกำหนดแล้วที่ยังเป็น scheduled
$upcoming = array_filter($hearings, fn($h) =>
    $h['status'] === 'scheduled' &&
    strtotime($h['hearing_date']) <= strtotime('+30 days')
);
// สร้าง JSON สำหรับ JS countdown
$upcomingForJS = [];
foreach ($upcoming as $u) {
    $dateStr = $u['hearing_date'];
    $timeStr = $u['hearing_time'] ? substr($u['hearing_time'],0,5) : '00:00';
    $upcomingForJS[] = [
        'case_number'  => $u['case_number'] ?? '—',
        'client_name'  => $u['client_name'],
        'court_name'   => $u['court_name'] ?? '—',
        'datetime'     => $dateStr . 'T' . $timeStr . ':00',
        'hearing_time' => $u['hearing_time'] ? substr($u['hearing_time'],0,5) : null,
    ];
}

$statusTH = [
    'scheduled'               => '📅 นัดไว้',
    'completed'               => '✅ เสร็จสิ้น',
    'postponed'               => '⏩ เลื่อนนัด',
    'cancelled'               => '❌ ยกเลิก',
    'defendant_absent'        => '⚠️ จำเลยไม่มา',
    'plaintiff_absent'        => '⚠️ โจทก์ไม่มา',
    'defendant_guilty_verdict'=> '⚖️ โจทก์ชนะ (จำเลยหลบหนี)',
];
$badgeColor = [
    'scheduled'               => '#1a3a5c',
    'completed'               => '#198754',
    'postponed'               => '#fd7e14',
    'cancelled'               => '#dc3545',
    'defendant_absent'        => '#856404',
    'plaintiff_absent'        => '#856404',
    'defendant_guilty_verdict'=> '#6f42c1',
];

$pageTitle = 'นัดขึ้นศาล';
include '../includes/header.php';
?>

<style>
.filing-info-card {
    display:none;
    background:#f0f7ff;
    border:1px solid #bdd7f5;
    border-radius:8px;
    padding:14px 16px;
    margin-top:10px;
    font-size:0.88rem;
    animation: fadeIn .2s ease;
}
.filing-info-card.show { display:block; }
.filing-info-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:8px; }
.fi-cell .lbl { color:#888; font-size:0.78rem; margin-bottom:2px; }
@keyframes fadeIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:translateY(0)} }
</style>

<style>
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal-box { background:#fff; border-radius:10px; padding:28px; width:100%; max-width:480px; box-shadow:0 8px 32px rgba(0,0,0,.2); }
.modal-box h3 { color:#1a3a5c; margin-bottom:16px; }
.absent-section { display:none; margin-top:14px; padding:14px; background:#fff8e1; border-radius:8px; border-left:4px solid #ffc107; }
.status-badge { display:inline-block; padding:3px 10px; border-radius:10px; font-size:0.78rem; font-weight:700; color:#fff; }
.hearing-row-absent { background:#fffbeb !important; }
.hearing-row-completed { background:#f0fff4 !important; opacity:.8; }
</style>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- แจ้งเตือนนัดขึ้นศาล + Countdown Realtime -->
<?php if (count($upcoming) > 0): ?>
<div id="hearing-alert-box" style="margin-bottom:20px;">
    <?php foreach ($upcoming as $ui => $u):
        $isPast = strtotime($u['hearing_date']) < strtotime('today');
        $dateStr = $u['hearing_date'];
        $timeStr = $u['hearing_time'] ? substr($u['hearing_time'],0,5) : null;
        $datetimeStr = $dateStr . ($timeStr ? 'T'.$timeStr.':00' : 'T00:00:00');
    ?>
    <div class="hearing-alert-item <?= $isPast ? 'overdue' : 'upcoming' ?>"
         data-datetime="<?= $datetimeStr ?>"
         style="border-radius:8px; padding:12px 18px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;
                <?= $isPast ? 'background:#fff0f0; border-left:5px solid #dc3545;' : 'background:#e8f4e8; border-left:5px solid #198754;' ?>">
        <div>
            <div style="font-weight:700; font-size:0.9rem; color:<?= $isPast ? '#842029' : '#1a3a5c' ?>">
                <?= $isPast ? '🔴 เลยวันนัดแล้ว!' : '🔔 นัดขึ้นศาลที่กำลังจะมาถึง' ?>
            </div>
            <div style="font-size:0.88rem; color:#333; margin-top:4px;">
                เลขคดี <strong><?= htmlspecialchars($u['case_number'] ?? '—') ?></strong>
                — <?= htmlspecialchars($u['client_name']) ?>
                | ศาล: <?= htmlspecialchars($u['court_name'] ?? '—') ?>
                | วันที่ <strong><?= date('d/m/Y', strtotime($u['hearing_date'])) ?></strong>
                <?= $timeStr ? 'เวลา <strong>'.$timeStr.'</strong>' : '' ?>
            </div>
        </div>
        <div class="countdown-badge" data-datetime="<?= $datetimeStr ?>"
             style="font-size:1rem; font-weight:700; padding:6px 14px; border-radius:20px; white-space:nowrap;
                    background:<?= $isPast ? '#dc3545' : '#1a3a5c' ?>; color:#fff; min-width:120px; text-align:center;">
            กำลังคำนวณ...
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function updateCountdowns() {
    document.querySelectorAll('.countdown-badge').forEach(function(badge) {
        const dt      = new Date(badge.dataset.datetime);
        const now     = new Date();
        const diffMs  = dt - now;
        const diffSec = Math.floor(Math.abs(diffMs) / 1000);
        const days    = Math.floor(diffSec / 86400);
        const hours   = Math.floor((diffSec % 86400) / 3600);
        const mins    = Math.floor((diffSec % 3600) / 60);
        const secs    = diffSec % 60;
        const isPast  = diffMs < 0;

        let txt = '';
        if (isPast) {
            if (days > 0)       txt = `เลยมาแล้ว ${days} วัน ${hours} ชม.`;
            else if (hours > 0) txt = `เลยมาแล้ว ${hours} ชม. ${mins} นาที`;
            else                txt = `เลยมาแล้ว ${mins} นาที ${secs} วิ`;
            badge.style.background = '#dc3545';
        } else {
            if (days > 0)       txt = `อีก ${days} วัน ${hours} ชม.`;
            else if (hours > 0) txt = `อีก ${hours} ชม. ${mins} นาที`;
            else if (mins > 0)  txt = `อีก ${mins} นาที ${secs} วิ`;
            else                txt = `อีก ${secs} วินาที!`;
            badge.style.background = days <= 1 ? '#e67e22' : '#1a3a5c';
        }
        badge.textContent = txt;
    });
}
updateCountdowns();
setInterval(updateCountdowns, 1000);
</script>

<?php if (in_array($role, ['admin','lawyer'])): ?>
<div class="card">
    <h2>➕ เพิ่มนัดขึ้นศาล</h2>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
            <div class="form-group" style="grid-column:span 2;">
                <label>คดี (เลขคดี) <span style="color:red">*</span></label>
                <select name="filing_id" id="filing-select" required onchange="showFilingInfo(this.value)">
                    <option value="">-- เลือกคดี --</option>
                    <?php foreach ($filings as $f): ?>
                    <option value="<?= $f['filing_id'] ?>" <?= $filingId == $f['filing_id'] ? 'selected':'' ?>>
                        <?= htmlspecialchars($f['case_number'] ?? '#'.$f['filing_id']) ?>
                        — <?= htmlspecialchars($f['client_name']) ?>
                        <?php if (!empty($f['charge'])): ?>
                        (<?= htmlspecialchars(mb_substr($f['charge'],0,25)) ?>)
                        <?php endif; ?>
                        [<?= htmlspecialchars($f['court_name'] ?? '') ?>]
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- Info Card แสดงรายละเอียดคดีที่เลือก -->
                <div class="filing-info-card" id="filing-info-card">
                    <div style="font-weight:700; color:#1a3a5c; margin-bottom:10px;">📋 รายละเอียดคดีที่เลือก</div>
                    <div class="filing-info-grid">
                        <div class="fi-cell"><div class="lbl">📁 เลขคดี</div><strong id="fi-case-no"></strong></div>
                        <div class="fi-cell"><div class="lbl">👤 ลูกความ</div><strong id="fi-client"></strong></div>
                        <div class="fi-cell"><div class="lbl">👨‍⚖️ ทนาย</div><strong id="fi-lawyer"></strong></div>
                        <div class="fi-cell"><div class="lbl">🏛️ ศาล</div><span id="fi-court"></span></div>
                        <div class="fi-cell"><div class="lbl">⚖️ ข้อหา</div><span id="fi-charge"></span></div>
                        <div class="fi-cell"><div class="lbl">💰 ค่าดำเนินคดี</div><span id="fi-fee"></span> บาท</div>
                        <div class="fi-cell"><div class="lbl">📅 วันที่ยื่นฟ้อง</div><span id="fi-filing-date"></span></div>
                        <div class="fi-cell" style="grid-column:span 2;">
                            <div class="lbl">📄 รายละเอียดคดี</div><span id="fi-detail"></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>วันนัดขึ้นศาล <span style="color:red">*</span></label>
                <input type="date" name="hearing_date" required>
            </div>
            <div class="form-group">
                <label>เวลา</label>
                <input type="time" name="hearing_time">
            </div>
            <div class="form-group">
                <label>ห้องพิจารณา</label>
                <input type="text" name="court_room" placeholder="เช่น ห้อง 5">
            </div>
            <div class="form-group">
                <label>ครั้งที่</label>
                <input type="number" name="hearing_round" min="1" value="1">
            </div>
            <div class="form-group" style="grid-column:span 2;">
                <label>หมายเหตุ</label>
                <textarea name="notes" placeholder="รายละเอียดเพิ่มเติม" rows="2"></textarea>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">บันทึก</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>📅 ตารางนัดขึ้นศาล</h2>
    <?php if (empty($hearings)): ?>
    <p style="color:#888;">ยังไม่มีนัดขึ้นศาล</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>เลขคดี</th><th>ลูกความ</th><th>ศาล</th>
                <th>วันที่</th><th>เวลา</th><th>ห้อง</th><th>ครั้งที่</th>
                <th>สถานะ</th><th>หมายเหตุ</th>
                <?php if (in_array($role, ['admin','lawyer'])): ?><th>จัดการ</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($hearings as $h):
            $isAbsent    = in_array($h['status'], ['defendant_absent','plaintiff_absent']);
            $isCompleted = $h['status'] === 'completed';
            $isPast      = strtotime($h['hearing_date']) < strtotime('today');
            $rowClass    = $isAbsent ? 'hearing-row-absent' : ($isCompleted ? 'hearing-row-completed' : '');
        ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $h['hearing_id'] ?></td>
                <td><?= htmlspecialchars($h['case_number'] ?? '—') ?></td>
                <td><?= htmlspecialchars($h['client_name']) ?></td>
                <td style="font-size:0.82rem;"><?= htmlspecialchars($h['court_name'] ?? '—') ?></td>
                <td>
                    <?= date('d/m/Y', strtotime($h['hearing_date'])) ?>
                    <?php if ($isPast && $h['status']==='scheduled'): ?>
                    <span style="color:#dc3545; font-size:0.75rem;">(เลยกำหนด)</span>
                    <?php endif; ?>
                </td>
                <td><?= $h['hearing_time'] ? substr($h['hearing_time'],0,5) : '—' ?></td>
                <td><?= htmlspecialchars($h['court_room'] ?? '—') ?></td>
                <td style="text-align:center;"><?= $h['hearing_round'] ?></td>
                <td>
                    <span class="status-badge" style="background:<?= $badgeColor[$h['status']] ?? '#888' ?>;">
                        <?= $statusTH[$h['status']] ?? $h['status'] ?>
                    </span>
                </td>
                <td style="font-size:0.82rem; max-width:160px;">
                    <?= htmlspecialchars(mb_substr($h['notes'] ?? '', 0, 60)) ?><?= mb_strlen($h['notes']??'')>60?'...':'' ?>
                </td>

                <?php if (in_array($role, ['admin','lawyer'])): ?>
                <td style="white-space:nowrap;">
                    <?php if ($h['status'] === 'scheduled'): ?>
                    <!-- ปุ่มอัปเดตสถานะ -->
                    <button class="btn btn-sm" style="background:#1a3a5c; color:#fff; margin-bottom:2px;"
                            onclick="openStatusModal(
                                <?= $h['hearing_id'] ?>,
                                '<?= addslashes($h['case_number'] ?? '') ?>',
                                '<?= addslashes($h['client_name']) ?>',
                                <?= $h['hearing_round'] ?>
                            )">
                        📋 อัปเดตสถานะ
                    </button>
                    <?php endif; ?>
                    <!-- ปุ่มลบ -->
                    <form method="POST" onsubmit="return confirm('ยืนยันการลบนัดนี้?')" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="hearing_id" value="<?= $h['hearing_id'] ?>">
                        <button type="submit" class="btn btn-sm" style="background:#e74c3c;color:#fff;">🗑️</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal อัปเดตสถานะ -->
<div class="modal-backdrop" id="modal-status">
    <div class="modal-box">
        <h3>📋 อัปเดตสถานะการขึ้นศาล</h3>
        <div style="background:#f8fafc; border-radius:6px; padding:10px 14px; margin-bottom:16px; font-size:0.88rem; color:#555;">
            เลขคดี: <strong id="modal-case-no"></strong> |
            ลูกความ: <strong id="modal-client"></strong> |
            ครั้งที่: <strong id="modal-round"></strong>
        </div>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="hearing_id" id="modal-hearing-id">

            <div class="form-group">
                <label>สถานะ <span style="color:red">*</span></label>
                <select name="new_status" id="status-select" required onchange="handleStatusChange(this)">
                    <option value="">-- เลือกสถานะ --</option>
                    <option value="completed">✅ ขึ้นศาลเสร็จสิ้น</option>
                    <option value="postponed">⏩ เลื่อนนัด (นัดใหม่ภายหลัง)</option>
                    <option value="cancelled">❌ ยกเลิกนัด</option>
                    <optgroup label="── ฝ่ายไม่มาศาล ──">
                        <option value="defendant_absent">⚠️ จำเลยไม่มาศาล (นัดใหม่)</option>
                        <option value="plaintiff_absent">⚠️ โจทก์ไม่มาศาล (นัดใหม่)</option>
                        <option value="defendant_guilty_verdict">⚖️ จำเลยหลบหนี — ตัดสินให้โจทก์ชนะ (ปิดคดี)</option>
                    </optgroup>
                </select>
            </div>

            <div class="form-group">
                <label>หมายเหตุเพิ่มเติม</label>
                <textarea name="status_note" rows="2" placeholder="บันทึกรายละเอียด เช่น เหตุผลที่ไม่มา, ผลการพิจารณา..."></textarea>
            </div>

            <!-- ส่วน "ฝ่ายไม่มาศาล" — นัดใหม่ -->
            <div class="absent-section" id="absent-section">
                <div style="font-weight:700; color:#856404; margin-bottom:10px;">
                    ⚠️ ฝ่ายไม่มาศาล — ต้องการนัดใหม่หรือไม่?
                </div>
                <p style="font-size:0.85rem; color:#555; margin-bottom:12px;">
                    กรอกวันนัดใหม่เพื่อให้ระบบสร้างนัดครั้งถัดไปอัตโนมัติ (ไม่บังคับ)
                </p>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group">
                        <label>วันนัดใหม่</label>
                        <input type="date" name="reschedule_date" id="reschedule-date"
                               min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    </div>
                    <div class="form-group">
                        <label>เวลา (ถ้ามี)</label>
                        <input type="time" name="reschedule_time">
                    </div>
                </div>
                <div style="background:#fff3cd; border-radius:6px; padding:10px; font-size:0.82rem; color:#856404;">
                    💡 ระบบจะสร้างนัดใหม่ครั้งถัดไปโดยอัตโนมัติ พร้อมระบุว่า "นัดใหม่ (จำเลย/โจทก์ไม่มา)"
                </div>
            </div>

            <!-- Section: จำเลยหลบหนี → ปิดคดี -->
            <div id="guilty-verdict-section" style="display:none; margin-top:14px; padding:16px; background:#f3e8ff; border-radius:8px; border-left:4px solid #6f42c1;">
                <div style="font-weight:700; color:#5a2d82; margin-bottom:8px; font-size:0.95rem;">
                    ⚖️ ยืนยันการตัดสินให้โจทก์ชนะคดี
                </div>
                <div style="font-size:0.85rem; color:#444; margin-bottom:10px; line-height:1.6;">
                    การดำเนินการนี้จะ:
                    <ul style="margin:6px 0 0 16px; padding:0;">
                        <li>บันทึกคำพิพากษา <strong>"โจทก์ชนะ เนื่องจากจำเลยจงใจหลบหนี"</strong></li>
                        <li>เปลี่ยนสถานะคดีเป็น <strong>เสร็จสิ้น</strong></li>
                        <li>ไม่สามารถยกเลิกได้ กรุณาตรวจสอบให้แน่ใจ</li>
                    </ul>
                </div>
                <div style="background:#fff; border-radius:6px; padding:10px; font-size:0.82rem; color:#5a2d82; border:1px solid #d8b4fe;">
                    ⚠️ หลังยืนยัน ลูกความจะเห็นผลคำพิพากษาในหน้า "คดีของฉัน" ทันที
                </div>
            </div>

            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:20px;">
                <button type="button" onclick="closeModal()" class="btn btn-sm" style="background:#e2e8f0;color:#333;">ยกเลิก</button>
                <button type="submit" class="btn btn-primary btn-sm">💾 บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
function openStatusModal(hearingId, caseNo, clientName, round) {
    document.getElementById('modal-hearing-id').value = hearingId;
    document.getElementById('modal-case-no').textContent  = caseNo || '—';
    document.getElementById('modal-client').textContent   = clientName;
    document.getElementById('modal-round').textContent    = round;
    document.getElementById('status-select').value        = '';
    document.getElementById('absent-section').style.display = 'none';

    // set default reschedule date = 30 วันถัดไป
    const next30 = new Date();
    next30.setDate(next30.getDate() + 30);
    document.getElementById('reschedule-date').value = next30.toISOString().split('T')[0];

    document.getElementById('modal-status').classList.add('open');
}

function handleStatusChange(sel) {
    const absentSection  = document.getElementById('absent-section');
    const guiltySection  = document.getElementById('guilty-verdict-section');
    const submitBtn      = document.querySelector('#modal-status [type="submit"]');
    const absentStatuses = ['defendant_absent', 'plaintiff_absent'];

    absentSection.style.display = absentStatuses.includes(sel.value) ? 'block' : 'none';
    guiltySection.style.display = sel.value === 'defendant_guilty_verdict' ? 'block' : 'none';

    if (sel.value === 'defendant_guilty_verdict') {
        submitBtn.textContent = '⚖️ ยืนยันตัดสินให้โจทก์ชนะ';
        submitBtn.style.background = '#6f42c1';
    } else {
        submitBtn.textContent = '💾 บันทึก';
        submitBtn.style.background = '';
    }
}

function closeModal() {
    document.getElementById('modal-status').classList.remove('open');
}

document.getElementById('modal-status').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<script>
const filingData = <?= json_encode($filingMap, JSON_UNESCAPED_UNICODE) ?>;

function showFilingInfo(filingId) {
    const card = document.getElementById('filing-info-card');
    if (!filingId || !filingData[filingId]) {
        card.classList.remove('show');
        return;
    }
    const d = filingData[filingId];
    document.getElementById('fi-case-no').textContent      = d.case_number;
    document.getElementById('fi-client').textContent       = d.client;
    document.getElementById('fi-lawyer').textContent       = d.lawyer + (d.spec ? ' (' + d.spec + ')' : '');
    document.getElementById('fi-court').textContent        = d.court;
    document.getElementById('fi-charge').textContent       = d.charge;
    document.getElementById('fi-fee').textContent          = d.fee;
    document.getElementById('fi-filing-date').textContent  = d.filing_date;
    document.getElementById('fi-detail').textContent       = d.detail;
    card.classList.add('show');
}

window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('filing-select');
    if (sel && sel.value) showFilingInfo(sel.value);
});
</script>

<?php include '../includes/footer.php'; ?>