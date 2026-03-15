<?php
// Verdicts.php
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
// Handle POST — บันทึก/แก้ไขคำพิพากษา (lawyer/admin เท่านั้น)
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['admin','lawyer'])) {
    csrf_verify();
    $action    = $_POST['action'] ?? '';
    $filingId  = (int)($_POST['filing_id'] ?? 0);
    $winner    = $_POST['winner'] ?? '';           // plaintiff / defendant
    $result    = trim($_POST['result'] ?? '');
    $reasoning = trim($_POST['reasoning'] ?? '');
    $verdictDate = $_POST['verdict_date'] ?? date('Y-m-d');
    $damages   = $_POST['damages'] !== '' ? (float)$_POST['damages'] : null;
    $damagesTo = $_POST['damages_to'] ?? null;     // plaintiff / defendant / none

    // ตรวจว่า filing นี้อยู่ใน office เดียวกัน
    $chk = $pdo->prepare("
        SELECT f.filing_id FROM filings f
        JOIN contracts con   ON f.contract_id   = con.contract_id
        JOIN case_requests cr ON con.request_id = cr.request_id
        WHERE f.filing_id = ? AND cr.office_id = ?
    ");
    $chk->execute([$filingId, $officeId]);

    if (!$chk->fetch()) {
        $error = 'ไม่พบคดีหรือไม่มีสิทธิ์';
    } elseif (!$winner || !$result) {
        $error = 'กรุณาระบุผู้ชนะคดีและสรุปคำพิพากษา';
    } else {
        $winnerTH    = $winner === 'plaintiff' ? 'โจทก์' : 'จำเลย';
        $fullResult  = "ผู้ชนะ: $winnerTH\n\nสรุปคำพิพากษา:\n$result"
                     . ($reasoning ? "\n\nเหตุผลของศาล:\n$reasoning" : '')
                     . ($damages   ? "\n\nค่าเสียหาย/ค่าปรับ: " . number_format($damages,2) . " บาท"
                                    . ($damagesTo ? " (ชำระให้: " . ($damagesTo==='plaintiff'?'โจทก์':'จำเลย') . ")" : '')
                                   : '');

        // Upsert verdict
        $existStmt = $pdo->prepare("SELECT verdict_id FROM verdicts WHERE filing_id = ?");
        $existStmt->execute([$filingId]);
        $existVerdict = $existStmt->fetch();

        if ($existVerdict) {
            $pdo->prepare("
                UPDATE verdicts SET result = ?, verdict_date = ? WHERE filing_id = ?
            ")->execute([$fullResult, $verdictDate, $filingId]);
        } else {
            $pdo->prepare("
                INSERT INTO verdicts (filing_id, result, verdict_date) VALUES (?, ?, ?)
            ")->execute([$filingId, $fullResult, $verdictDate]);
        }

        // อัปเดต contract status → completed
        $pdo->prepare("
            UPDATE contracts SET status = 'completed'
            WHERE contract_id = (SELECT contract_id FROM filings WHERE filing_id = ?)
        ")->execute([$filingId]);

        $success = '⚖️ บันทึกคำพิพากษาแล้ว — ' . ($winner === 'plaintiff' ? '✅ โจทก์ชนะ' : '✅ จำเลยชนะ');
    }
}

// ==============================
// Fetch all filings + verdicts สำหรับ office
// ==============================
if ($role === 'lawyer') {
    $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lp->execute([$userId]);
    $lawyerId = $lp->fetchColumn();
    $whereExtra = "AND cr.lawyer_id = $lawyerId";
} else {
    $whereExtra = '';
}

$stmt = $pdo->prepare("
    SELECT f.filing_id, f.case_number, f.charge, f.filing_date,
           ct.court_name,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           cr.detail AS case_detail,
           con.contract_id, con.fee_amount,
           v.verdict_id, v.result AS verdict_result, v.verdict_date
    FROM filings f
    JOIN courts ct        ON f.court_id      = ct.court_id
    JOIN contracts con    ON f.contract_id   = con.contract_id
    JOIN case_requests cr ON con.request_id  = cr.request_id
    JOIN client_profiles cp ON cr.client_id  = cp.client_id
    JOIN lawyer_profiles lp ON cr.lawyer_id  = lp.lawyer_id
    LEFT JOIN verdicts v  ON f.filing_id     = v.filing_id
    WHERE cr.office_id = ? $whereExtra
    ORDER BY v.verdict_date DESC, f.filing_date DESC
");
$stmt->execute([$officeId]);
$filings = $stmt->fetchAll();

// แยก: คดีที่มีคำพิพากษาแล้ว vs รอพิพากษา
$verdicted = array_filter($filings, fn($f) => !empty($f['verdict_result']));
$pending   = array_filter($filings, fn($f) =>  empty($f['verdict_result']));

// ดึง hearing list สำหรับ pending filings (เพื่อแสดงประวัติ)
$hearingMap = [];
if (!empty($pending)) {
    $fidList = implode(',', array_column(array_values($pending), 'filing_id'));
    if ($fidList) {
        $hStmt = $pdo->query("
            SELECT filing_id, hearing_date, hearing_time, hearing_round, status, notes
            FROM court_hearings
            WHERE filing_id IN ($fidList)
            ORDER BY hearing_date DESC
        ");
        foreach ($hStmt->fetchAll() as $h) {
            $hearingMap[$h['filing_id']][] = $h;
        }
    }
}

$statusHearingTH = [
    'scheduled'               => '📅 นัดไว้',
    'completed'               => '✅ เสร็จสิ้น',
    'postponed'               => '⏩ เลื่อน',
    'cancelled'               => '❌ ยกเลิก',
    'defendant_absent'        => '⚠️ จำเลยไม่มา',
    'plaintiff_absent'        => '⚠️ โจทก์ไม่มา',
    'defendant_guilty_verdict'=> '⚖️ โจทก์ชนะ (จำเลยหลบหนี)',
];

$pageTitle = 'คำพิพากษา';
include '../includes/header.php';
?>

<style>
.verdict-card { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:20px; overflow:hidden; }
.verdict-header { background:#1a3a5c; color:#fff; padding:12px 18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px; }
.verdict-body { padding:16px 18px; }
.info-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:14px; }
.info-cell .lbl { font-size:0.75rem; color:#888; margin-bottom:2px; }
.winner-badge { display:inline-block; padding:6px 18px; border-radius:20px; font-weight:700; font-size:0.9rem; color:#fff; }
.verdict-result-box { background:#f8fafc; border-left:4px solid #1a3a5c; border-radius:0 6px 6px 0; padding:12px 16px; font-size:0.88rem; white-space:pre-wrap; line-height:1.7; }
.hearing-chip { display:inline-block; padding:2px 10px; border-radius:10px; font-size:0.75rem; background:#e8f0fe; color:#1a3a5c; margin:2px; }
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; }
.modal-backdrop.open { display:flex; }
.modal-box { background:#fff; border-radius:12px; padding:28px; width:100%; max-width:580px; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,.2); }
.winner-btn { border:2px solid transparent; border-radius:8px; padding:14px 20px; cursor:pointer; text-align:center; transition:.2s; background:#f8fafc; }
.winner-btn:hover { border-color:#1a3a5c; }
.winner-btn.selected-plaintiff { border-color:#198754; background:#d1e7dd; }
.winner-btn.selected-defendant { border-color:#dc3545; background:#f8d7da; }
</style>

<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<!-- ===== สรุปสถิติ ===== -->
<div style="display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:24px;">
    <div style="background:#1a3a5c; color:#fff; border-radius:10px; padding:16px; text-align:center;">
        <div style="font-size:2rem; font-weight:700;"><?= count($filings) ?></div>
        <div style="font-size:0.85rem; opacity:.85;">คดีทั้งหมด</div>
    </div>
    <div style="background:#198754; color:#fff; border-radius:10px; padding:16px; text-align:center;">
        <div style="font-size:2rem; font-weight:700;"><?= count($verdicted) ?></div>
        <div style="font-size:0.85rem; opacity:.85;">มีคำพิพากษาแล้ว</div>
    </div>
    <div style="background:#fd7e14; color:#fff; border-radius:10px; padding:16px; text-align:center;">
        <div style="font-size:2rem; font-weight:700;"><?= count($pending) ?></div>
        <div style="font-size:0.85rem; opacity:.85;">รอคำพิพากษา</div>
    </div>
</div>

<!-- ===== คดีรอคำพิพากษา ===== -->
<?php if (!empty($pending)): ?>
<div class="card">
    <h2>⏳ คดีที่รอคำพิพากษา (<?= count($pending) ?> คดี)</h2>

    <?php foreach ($pending as $f): ?>
    <div class="verdict-card" style="border-left:4px solid #fd7e14;">
        <div class="verdict-header" style="background:#7c4a00;">
            <div>
                <span style="font-weight:700; font-size:1rem;">📁 <?= htmlspecialchars($f['case_number'] ?? 'ไม่มีเลขคดี') ?></span>
                <span style="font-size:0.82rem; opacity:.8; margin-left:10px;"><?= htmlspecialchars($f['court_name']) ?></span>
            </div>
            <span style="background:#fd7e14; color:#fff; padding:3px 12px; border-radius:10px; font-size:0.8rem; font-weight:700;">
                ⏳ รอพิพากษา
            </span>
        </div>
        <div class="verdict-body">
            <div class="info-grid">
                <div class="info-cell"><div class="lbl">👤 ลูกความ (โจทก์)</div><strong><?= htmlspecialchars($f['client_name']) ?></strong></div>
                <div class="info-cell"><div class="lbl">👨‍⚖️ ทนาย</div><strong><?= htmlspecialchars($f['lawyer_name']) ?></strong></div>
                <div class="info-cell"><div class="lbl">⚖️ ข้อหา</div><?= htmlspecialchars($f['charge'] ?? '—') ?></div>
                <div class="info-cell"><div class="lbl">📅 วันยื่นฟ้อง</div><?= $f['filing_date'] ?? '—' ?></div>
                <div class="info-cell" style="grid-column:span 2;"><div class="lbl">📄 รายละเอียดคดี</div><?= htmlspecialchars(mb_substr($f['case_detail']??'—',0,120)) ?></div>
            </div>

            <!-- ประวัติการขึ้นศาล -->
            <?php if (!empty($hearingMap[$f['filing_id']])): ?>
            <div style="margin-bottom:12px;">
                <div style="font-size:0.8rem; color:#888; margin-bottom:6px;">ประวัติขึ้นศาล:</div>
                <?php foreach ($hearingMap[$f['filing_id']] as $h): ?>
                <span class="hearing-chip">
                    ครั้งที่<?= $h['hearing_round'] ?>
                    <?= date('d/m/Y', strtotime($h['hearing_date'])) ?>
                    — <?= $statusHearingTH[$h['status']] ?? $h['status'] ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (in_array($role, ['admin','lawyer'])): ?>
            <button class="btn btn-primary btn-sm"
                    onclick="openVerdictModal(
                        <?= $f['filing_id'] ?>,
                        '<?= addslashes($f['case_number'] ?? '') ?>',
                        '<?= addslashes($f['client_name']) ?>',
                        '<?= addslashes($f['lawyer_name']) ?>',
                        '<?= addslashes($f['court_name']) ?>'
                    )">
                ⚖️ บันทึกคำพิพากษา
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ===== คดีที่มีคำพิพากษาแล้ว ===== -->
<div class="card">
    <h2>⚖️ คำพิพากษาที่บันทึกแล้ว (<?= count($verdicted) ?> คดี)</h2>

    <?php if (empty($verdicted)): ?>
    <p style="color:#aaa; text-align:center; padding:20px;">ยังไม่มีคำพิพากษา</p>
    <?php endif; ?>

    <?php foreach ($verdicted as $f):
        // แยกผู้ชนะจาก result text
        $isPlaintiffWin = str_contains($f['verdict_result'], 'ผู้ชนะ: โจทก์')
                       || str_contains($f['verdict_result'], 'โจทก์ชนะ');
        $winnerText  = $isPlaintiffWin ? '✅ โจทก์ชนะ' : '✅ จำเลยชนะ';
        $winnerColor = $isPlaintiffWin ? '#198754' : '#0d6efd';
    ?>
    <div class="verdict-card" style="border-left:4px solid <?= $winnerColor ?>;">
        <div class="verdict-header">
            <div>
                <span style="font-weight:700;">📁 <?= htmlspecialchars($f['case_number'] ?? '—') ?></span>
                <span style="font-size:0.82rem; opacity:.8; margin-left:8px;"><?= htmlspecialchars($f['court_name']) ?></span>
            </div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span class="winner-badge" style="background:<?= $winnerColor ?>;"><?= $winnerText ?></span>
                <span style="font-size:0.8rem; opacity:.75;">วันที่: <?= $f['verdict_date'] ?></span>
            </div>
        </div>
        <div class="verdict-body">
            <div class="info-grid">
                <div class="info-cell"><div class="lbl">👤 ลูกความ (โจทก์)</div><strong><?= htmlspecialchars($f['client_name']) ?></strong></div>
                <div class="info-cell"><div class="lbl">👨‍⚖️ ทนาย</div><strong><?= htmlspecialchars($f['lawyer_name']) ?></strong></div>
                <div class="info-cell"><div class="lbl">⚖️ ข้อหา</div><?= htmlspecialchars($f['charge'] ?? '—') ?></div>
            </div>
            <div class="verdict-result-box"><?= nl2br(htmlspecialchars($f['verdict_result'])) ?></div>

            <?php if (in_array($role, ['admin','lawyer'])): ?>
            <div style="margin-top:10px;">
                <button class="btn btn-sm" style="background:#e67e22; color:#fff;"
                        onclick="openVerdictModal(
                            <?= $f['filing_id'] ?>,
                            '<?= addslashes($f['case_number'] ?? '') ?>',
                            '<?= addslashes($f['client_name']) ?>',
                            '<?= addslashes($f['lawyer_name']) ?>',
                            '<?= addslashes($f['court_name']) ?>',
                            '<?= $isPlaintiffWin ? 'plaintiff' : 'defendant' ?>',
                            <?= $f['verdict_id'] ?>
                        )">
                    ✏️ แก้ไขคำพิพากษา
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ===== Modal บันทึกคำพิพากษา ===== -->
<div class="modal-backdrop" id="modal-verdict">
    <div class="modal-box">
        <h3 style="color:#1a3a5c; margin-bottom:6px;">⚖️ บันทึกคำพิพากษา</h3>
        <div id="modal-case-info" style="background:#f8fafc; border-radius:6px; padding:10px 14px; font-size:0.85rem; color:#555; margin-bottom:18px;"></div>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_verdict">
            <input type="hidden" name="filing_id" id="v-filing-id">

            <!-- เลือกผู้ชนะ -->
            <div class="form-group">
                <label style="font-weight:700; color:#1a3a5c;">ผลการพิพากษา — ฝ่ายที่ชนะ <span style="color:red">*</span></label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:8px;">
                    <div class="winner-btn" id="btn-plaintiff" onclick="selectWinner('plaintiff')">
                        <div style="font-size:1.5rem; margin-bottom:4px;">👤</div>
                        <div style="font-weight:700; color:#198754;">โจทก์ชนะ</div>
                        <div style="font-size:0.78rem; color:#555;" id="plaintiff-name-display">ลูกความ/ผู้ฟ้อง</div>
                    </div>
                    <div class="winner-btn" id="btn-defendant" onclick="selectWinner('defendant')">
                        <div style="font-size:1.5rem; margin-bottom:4px;">🧑</div>
                        <div style="font-weight:700; color:#dc3545;">จำเลยชนะ</div>
                        <div style="font-size:0.78rem; color:#555;">ฝ่ายถูกฟ้อง</div>
                    </div>
                </div>
                <input type="hidden" name="winner" id="winner-input" required>
            </div>

            <div class="form-group">
                <label>วันที่พิพากษา <span style="color:red">*</span></label>
                <input type="date" name="verdict_date" id="v-date" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="form-group">
                <label>สรุปคำพิพากษา <span style="color:red">*</span></label>
                <textarea name="result" id="v-result" rows="4"
                    placeholder="เช่น ศาลพิพากษาให้จำเลยชำระเงินจำนวน... / ศาลยกฟ้อง..." required></textarea>
            </div>

            <div class="form-group">
                <label>เหตุผลของศาล</label>
                <textarea name="reasoning" id="v-reasoning" rows="3"
                    placeholder="อธิบายเหตุผลและข้อกฎหมายที่ศาลอ้างอิง..."></textarea>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label>ค่าเสียหาย/ค่าปรับ (บาท) — ถ้ามี</label>
                    <input type="number" name="damages" id="v-damages" step="0.01" min="0" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>ชำระให้ฝ่าย</label>
                    <select name="damages_to" id="v-damages-to">
                        <option value="none">— ไม่ระบุ —</option>
                        <option value="plaintiff">โจทก์</option>
                        <option value="defendant">จำเลย</option>
                    </select>
                </div>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
                <button type="button" onclick="closeVerdictModal()"
                        class="btn btn-sm" style="background:#e2e8f0; color:#333;">ยกเลิก</button>
                <button type="submit" id="v-submit" class="btn btn-primary btn-sm" disabled>
                    ⚖️ บันทึกคำพิพากษา
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let selectedWinner = null;

function openVerdictModal(filingId, caseNo, clientName, lawyerName, courtName, preWinner, verdictId) {
    document.getElementById('v-filing-id').value = filingId;
    document.getElementById('v-result').value    = '';
    document.getElementById('v-reasoning').value = '';
    document.getElementById('v-damages').value   = '';
    selectedWinner = null;
    resetWinnerBtns();
    document.getElementById('v-submit').disabled = true;

    document.getElementById('modal-case-info').innerHTML =
        `📁 <strong>${caseNo || '—'}</strong> | 🏛️ ${courtName} | 👤 ${clientName} | 👨‍⚖️ ${lawyerName}`;
    document.getElementById('plaintiff-name-display').textContent = clientName;
    document.getElementById('v-date').value = new Date().toISOString().split('T')[0];

    if (preWinner) selectWinner(preWinner);
    document.getElementById('modal-verdict').classList.add('open');
}

function selectWinner(side) {
    selectedWinner = side;
    document.getElementById('winner-input').value = side;
    document.getElementById('v-submit').disabled = false;
    resetWinnerBtns();
    if (side === 'plaintiff') {
        document.getElementById('btn-plaintiff').classList.add('selected-plaintiff');
    } else {
        document.getElementById('btn-defendant').classList.add('selected-defendant');
    }
}

function resetWinnerBtns() {
    document.getElementById('btn-plaintiff').className = 'winner-btn';
    document.getElementById('btn-defendant').className = 'winner-btn';
}

function closeVerdictModal() {
    document.getElementById('modal-verdict').classList.remove('open');
}

document.getElementById('modal-verdict').addEventListener('click', function(e) {
    if (e.target === this) closeVerdictModal();
});
</script>

<?php include '../includes/footer.php'; ?>