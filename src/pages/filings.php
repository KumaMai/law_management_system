<?php
// filings.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/activity_log_helper.php';
requireLogin();

$pdo        = getDB();
$role       = $_SESSION['role'];
$officeId   = $_SESSION['office_id'];
$contractId = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : null;

// Add filing (lawyer/admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['admin','lawyer'])) {
    csrf_verify();
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $action = $_POST['action'] ?? 'add';

    // ---- เพิ่มการยื่นฟ้อง ----
    if ($action === 'add') {
    $courtInput  = trim($_POST['court_input'] ?? '');
    $newContract = (int)$_POST['contract_id'];
    $courtId     = null;

    // ตรวจว่า contract นี้มี filing อยู่แล้วหรือยัง
    $dupCheck = $pdo->prepare("SELECT filing_id FROM filings WHERE contract_id = ? LIMIT 1");
    $dupCheck->execute([$newContract]);
    if ($dupCheck->fetch()) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'msg' => 'สัญญานี้มีการยื่นฟ้องอยู่แล้ว ไม่สามารถยื่นฟ้องซ้ำได้']);
            exit;
        }
        header('Location: /pages/filings.php?contract_id='.$newContract.'&error=duplicate');
        exit;
    }

    if ($courtInput !== '') {
        $matchStmt = $pdo->prepare("SELECT court_id FROM courts WHERE court_name = ? LIMIT 1");
        $matchStmt->execute([$courtInput]);
        $matched = $matchStmt->fetchColumn();
        if ($matched) {
            $courtId = (int)$matched;
        } else {
            $pdo->prepare("INSERT INTO courts (court_name) VALUES (?)")->execute([$courtInput]);
            $courtId = (int)$pdo->lastInsertId();
        }
    }

    $pdo->prepare("
        INSERT INTO filings (contract_id, court_id, charge, scheduled_filing_date)
        VALUES (?, ?, ?, ?)
    ")->execute([
        $newContract,
        $courtId,
        trim($_POST['charge']),
        $_POST['scheduled_filing_date'] ?: null,
    ]);

    $newFilingId = (int)$pdo->lastInsertId();
    audit_log($pdo, $officeId, $_SESSION['user_id'], 'create', 'filing', $newFilingId, 'เพิ่มนัดยื่นฟ้อง สัญญา #'.$newContract);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'msg' => 'เพิ่มนัดยื่นฟ้องสำเร็จ']);
        exit;
    }
    header('Location: /pages/filings.php');
    exit;
    } // end add

    // ---- แก้ไขการยื่นฟ้อง ----
    if ($action === 'edit') {
        $filingId   = (int)$_POST['filing_id'];
        $courtInput = trim($_POST['court_input'] ?? '');
        $charge     = trim($_POST['charge'] ?? '');
        $filingDate = $_POST['scheduled_filing_date'] ?? '';

        // ตรวจ ownership — filing ต้องอยู่ใน office เดียวกัน
        $chk = $pdo->prepare("
            SELECT f.filing_id FROM filings f
            JOIN contracts con ON f.contract_id = con.contract_id
            JOIN case_requests cr ON con.request_id = cr.request_id
            WHERE f.filing_id = ? AND cr.office_id = ?
        ");
        $chk->execute([$filingId, $officeId]);
        if (!$chk->fetch()) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบข้อมูลหรือไม่มีสิทธิ์']); exit; }
            header('Location: /pages/filings.php'); exit;
        }

        // หา/สร้าง court
        $courtId = null;
        if ($courtInput !== '') {
            $matchStmt = $pdo->prepare("SELECT court_id FROM courts WHERE court_name = ? LIMIT 1");
            $matchStmt->execute([$courtInput]);
            $matched = $matchStmt->fetchColumn();
            if ($matched) {
                $courtId = (int)$matched;
            } else {
                $pdo->prepare("INSERT INTO courts (court_name) VALUES (?)")->execute([$courtInput]);
                $courtId = (int)$pdo->lastInsertId();
            }
        }

        $pdo->prepare("
            UPDATE filings SET court_id=?, charge=?, scheduled_filing_date=?
            WHERE filing_id=?
        ")->execute([$courtId, $charge ?: null, $filingDate ?: null, $filingId]);

        audit_log($pdo, $officeId, $_SESSION['user_id'], 'update', 'filing', $filingId, 'แก้ไขนัดยื่นฟ้อง #'.$filingId);
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'แก้ไขข้อมูลนัดยื่นฟ้องเรียบร้อยแล้ว']); exit; }
        header('Location: /pages/filings.php'); exit;
    }

    // ---- ลบการยื่นฟ้อง (admin only) ----
    if ($action === 'delete' && $role === 'admin') {
        $filingId = (int)$_POST['filing_id'];

        // ตรวจ ownership
        $chk = $pdo->prepare("
            SELECT f.filing_id FROM filings f
            JOIN contracts con ON f.contract_id = con.contract_id
            JOIN case_requests cr ON con.request_id = cr.request_id
            WHERE f.filing_id = ? AND cr.office_id = ?
        ");
        $chk->execute([$filingId, $officeId]);
        if (!$chk->fetch()) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบข้อมูลหรือไม่มีสิทธิ์']); exit; }
        }

        // ตรวจว่ามีนัดขึ้นศาลหรือคำพิพากษาผูกอยู่
        $hasHearings = $pdo->prepare("SELECT COUNT(*) FROM court_hearings WHERE filing_id=?");
        $hasHearings->execute([$filingId]);
        $hasVerdicts = $pdo->prepare("SELECT COUNT(*) FROM verdicts WHERE filing_id=?");
        $hasVerdicts->execute([$filingId]);

        if ((int)$hasHearings->fetchColumn() > 0 || (int)$hasVerdicts->fetchColumn() > 0) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถลบได้ มีนัดขึ้นศาลหรือคำพิพากษาผูกอยู่กับการยื่นฟ้องนี้']); exit; }
        } else {
            $pdo->prepare("DELETE FROM filings WHERE filing_id=?")->execute([$filingId]);
            audit_log($pdo, $officeId, $_SESSION['user_id'], 'delete', 'filing', $filingId, 'ลบนัดยื่นฟ้อง #'.$filingId);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'ลบการยื่นฟ้องเรียบร้อยแล้ว']); exit; }
        }
        header('Location: /pages/filings.php'); exit;
    }
}

// Fetch filings
$where = $contractId ? "AND f.contract_id = " . (int)$contractId : '';
$stmt  = $pdo->prepare("
    SELECT f.filing_id, f.contract_id, f.court_id, f.charge, f.scheduled_filing_date, f.created_at,
           COALESCE(c.court_name, '—') AS court_display,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           cr.detail AS case_detail,
           con.fee_amount, con.contract_date,
           cr.office_id
    FROM filings f
    LEFT JOIN courts c    ON f.court_id    = c.court_id
    JOIN contracts con    ON f.contract_id = con.contract_id
    JOIN case_requests cr ON con.request_id = cr.request_id
    JOIN client_profiles cp ON cr.client_id = cp.client_id
    JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
    WHERE cr.office_id = ? $where
    ORDER BY f.created_at DESC
");
$stmt->execute([$officeId]);
$filings = $stmt->fetchAll();

// Courts for dropdown
$courts = $pdo->query("SELECT * FROM courts ORDER BY court_name")->fetchAll();

// Contracts for form
$contractsStmt = $pdo->prepare("
    SELECT con.contract_id, con.fee_amount, con.contract_date, con.contract_review_status,
           cr.detail AS case_detail,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           lp.specialization
    FROM contracts con
    JOIN case_requests cr ON con.request_id = cr.request_id
    JOIN client_profiles cp ON cr.client_id = cp.client_id
    JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
    WHERE cr.office_id = ?
      AND con.status NOT IN ('completed','terminated','rejected','cancelled')
      AND cr.status NOT IN ('rejected','cancelled')
    ORDER BY con.contract_id DESC
");
$contractsStmt->execute([$officeId]);
$contracts = $contractsStmt->fetchAll();

$contractMap = [];
foreach ($contracts as $con) {
    $contractMap[$con['contract_id']] = [
        'client' => $con['client_name'],
        'lawyer' => $con['lawyer_name'],
        'spec'   => $con['specialization'] ?? '',
        'detail' => mb_substr($con['case_detail'] ?? '—', 0, 120) . (mb_strlen($con['case_detail'] ?? '') > 120 ? '...' : ''),
        'fee'    => $con['fee_amount'] ? number_format($con['fee_amount'], 2) : '—',
        'date'   => $con['contract_date'] ?? '—',
        'status' => $con['contract_review_status'] ?? '—',
    ];
}

$statusLabel = [
    'pending_lawyer_review' => '⏳ รอทนายพิจารณา',
    'lawyer_accepted'       => '✅ ทนายยืนยัน',
    'lawyer_rejected'       => '❌ ทนายปฏิเสธ',
    'revision_requested'    => '🔄 รอลูกความแก้ไข',
    'negotiating'           => '💬 กำลังต่อรอง',
    'finalized'             => '🔒 ยืนยันแล้ว',
];

$pageTitle = 'นัดยื่นฟ้องคดี';
include '../includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* Court input */
.court-input-wrap { position:relative; }
.court-input-wrap input {
    width:100%; padding:10px 36px 10px 13px; border:1.5px solid #e2e8f0; border-radius:10px;
    font-size:.88rem; background:#f8fafc; color:#1e293b; outline:none; transition:.18s;
    font-family:inherit; box-sizing:border-box;
}
.court-input-wrap input:focus { border-color:#1a3a5c; background:#fff; box-shadow:0 0 0 3px rgba(26,58,92,.1); }
.court-arrow { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#94a3b8; pointer-events:none; font-size:.8rem; }
.court-dropdown {
    display:none; position:absolute; top:calc(100% + 4px); left:0; right:0;
    background:#fff; border:1.5px solid #1a3a5c; border-radius:10px;
    box-shadow:0 8px 24px rgba(0,0,0,.12); z-index:10000; max-height:220px; overflow-y:auto;
}
.court-dropdown.open { display:block; }
.court-option { padding:9px 14px; font-size:.87rem; color:#1e293b; cursor:pointer; border-bottom:1px solid #f1f5f9; transition:.1s; }
.court-option:last-child { border-bottom:none; }
.court-option:hover, .court-option.active { background:#eff6ff; color:#1a3a5c; font-weight:600; }
.court-option.new-entry { color:#854d0e; background:#fefce8; font-style:italic; }
.court-option.new-entry:hover { background:#fef9c3; }
.court-match-badge { display:none; margin-top:5px; font-size:.76rem; padding:3px 10px; border-radius:12px; font-weight:600; }
.court-match-badge.found { display:inline-block; background:#dcfce7; color:#166534; }
.court-match-badge.new   { display:inline-block; background:#fef9c3; color:#854d0e; }
/* Contract info inside modal */
.contract-info-card { display:none; background:#f0f7ff; border:1px solid #bdd7f5; border-radius:8px; padding:14px 16px; margin-top:10px; font-size:0.88rem; }
.contract-info-card.show { display:block; }
.contract-info-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:8px; }
.info-cell .lbl { color:#888; font-size:0.78rem; margin-bottom:2px; }
</style>

<!-- Header + ปุ่ม -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h2 style="margin:0;">🏛️ นัดวันยื่นฟ้อง</h2>
        <?php if (in_array($role, ['admin','lawyer'])): ?>
        <button onclick="openFilingModal()" class="btn btn-primary">➕ เพิ่มนัดยื่นฟ้อง</button>
        <?php endif; ?>
    </div>

    <?php if (empty($filings)): ?>
    <p style="color:#888;">ยังไม่มีข้อมูลการยื่นฟ้อง</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>ลูกความ</th><th>ทนาย</th>
                <th>ศาล</th><th>ข้อหา</th>
                <th>วันนัดยื่นฟ้อง</th><th>จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($filings as $f): ?>
            <tr>
                <td><?= $f['filing_id'] ?></td>
                <td><?= htmlspecialchars($f['client_name']) ?></td>
                <td><?= htmlspecialchars($f['lawyer_name']) ?></td>
                <td><?= htmlspecialchars($f['court_display']) ?></td>
                <td><?= htmlspecialchars($f['charge'] ?? '—') ?></td>
                <td><?= $f['scheduled_filing_date'] ?? '—' ?></td>
                <td>
                    <a href="/pages/hearings.php?filing_id=<?= $f['filing_id'] ?>"
                       class="btn btn-primary btn-sm">📅 นัดขึ้นศาล</a>
                    <?php if (in_array($role, ['admin','lawyer'])): ?>
                    <button class="btn btn-sm" style="background:#eff6ff;color:#1d4ed8;border:none;cursor:pointer;font-weight:700;"
                        onclick='openEditFilingModal(<?= json_encode([
                            "filing_id"   => (int)$f["filing_id"],
                            "court_name"  => $f["court_display"],
                            "charge"      => $f["charge"] ?? "",
                            "filing_date" => $f["scheduled_filing_date"] ?? "",
                        ], JSON_UNESCAPED_UNICODE) ?>)'>✏️ แก้ไข</button>
                    <?php endif; ?>
                    <?php if ($role === 'admin'): ?>
                    <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;cursor:pointer;font-weight:700;"
                        onclick='deleteFiling(<?= $f["filing_id"] ?>, <?= json_encode("#".$f["filing_id"]." (".htmlspecialchars($f["charge"]??"ไม่ระบุข้อหา").")", JSON_UNESCAPED_UNICODE) ?>)'>🗑️ ลบ</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if (in_array($role, ['admin','lawyer'])): ?>
<!-- Modal เพิ่มการยื่นฟ้อง -->
<div id="filingModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:680px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;color:#1a3a5c;">➕ เพิ่มนัดยื่นฟ้องใหม่</h3>
            <button onclick="closeFilingModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;line-height:1;">✕</button>
        </div>
        <form id="filingForm">
            <?= csrf_field() ?>

            <!-- เลือกสัญญา -->
            <div class="form-group">
                <label>สัญญา <span style="color:red">*</span></label>
                <select name="contract_id" id="modal-contract-select" required onchange="showContractInfo(this.value)">
                    <option value="">-- เลือกสัญญา --</option>
                    <?php foreach ($contracts as $con):
                        $statusTxt   = $statusLabel[$con['contract_review_status']] ?? $con['contract_review_status'];
                        $shortDetail = mb_substr($con['case_detail'] ?? '', 0, 30);
                    ?>
                    <option value="<?= $con['contract_id'] ?>"
                        <?= $contractId == $con['contract_id'] ? 'selected' : '' ?>>
                        #<?= $con['contract_id'] ?> — <?= htmlspecialchars($con['client_name']) ?>
                        <?= $shortDetail ? '(' . htmlspecialchars($shortDetail) . ')' : '' ?>
                        [<?= $statusTxt ?>]
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- Info Card -->
                <div class="contract-info-card" id="modal-contract-info-card">
                    <div style="font-weight:700;color:#1a3a5c;margin-bottom:8px;">📋 รายละเอียดสัญญาที่เลือก</div>
                    <div class="contract-info-grid">
                        <div class="info-cell"><div class="lbl">👤 ลูกความ</div><strong id="modal-info-client"></strong></div>
                        <div class="info-cell"><div class="lbl">👨‍⚖️ ทนาย</div><strong id="modal-info-lawyer"></strong></div>
                        <div class="info-cell"><div class="lbl">💰 ค่าดำเนินคดี</div><strong id="modal-info-fee"></strong> บาท</div>
                        <div class="info-cell"><div class="lbl">📅 วันทำสัญญา</div><span id="modal-info-date"></span></div>
                        <div class="info-cell"><div class="lbl">⚖️ ความเชี่ยวชาญ</div><span id="modal-info-spec"></span></div>
                        <div class="info-cell"><div class="lbl">📊 สถานะสัญญา</div><span id="modal-info-status"></span></div>
                        <div class="info-cell" style="grid-column:span 3;"><div class="lbl">📄 รายละเอียดคดี</div><span id="modal-info-detail"></span></div>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <!-- ศาล -->
                <div class="form-group">
                    <label>ศาล <span style="color:red">*</span></label>
                    <div class="court-input-wrap" id="modal-court-wrap">
                        <input type="text" name="court_input" id="modal-court-input"
                               placeholder="พิมพ์ชื่อศาล หรือคลิกเพื่อเลือก..."
                               autocomplete="off" required>
                        <span class="court-arrow">▾</span>
                        <div class="court-dropdown" id="modal-court-dropdown"></div>
                    </div>
                    <small style="color:#94a3b8;font-size:.74rem;margin-top:4px;display:block;">💡 เลือกจากรายการหรือพิมพ์ชื่อศาลใหม่ได้เลย</small>
                    <span class="court-match-badge" id="modal-court-badge"></span>
                </div>

                <div class="form-group">
                    <label>ข้อหา</label>
                    <input type="text" name="charge" placeholder="ระบุข้อหา">
                </div>
                <div class="form-group">
                    <label>วันนัดยื่นฟ้อง</label>
                    <input type="date" name="scheduled_filing_date">
                    <small style="color:#94a3b8;font-size:.74rem;">วันที่นัดหมายเพื่อยื่นเอกสาร (เลขคดีจะได้รับหลังยื่นจริง)</small>
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" onclick="closeFilingModal()"
                        style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">
                    ยกเลิก
                </button>
                <button type="submit" id="filingSubmitBtn"
                        style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">
                    💾 บันทึก
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const contractData = <?= json_encode($contractMap, JSON_UNESCAPED_UNICODE) ?>;
const knownCourts  = <?= json_encode(array_column($courts, 'court_name'), JSON_UNESCAPED_UNICODE) ?>;

// ── Modal open/close ──
function openFilingModal() {
    document.getElementById('filingModal').style.display = 'flex';
    const sel = document.getElementById('modal-contract-select');
    if (sel && sel.value) showContractInfo(sel.value);
}
function closeFilingModal() {
    document.getElementById('filingModal').style.display = 'none';
    document.getElementById('filingForm').reset();
    document.getElementById('modal-contract-info-card').classList.remove('show');
    document.getElementById('modal-court-badge').className = 'court-match-badge';
    document.getElementById('modal-court-badge').textContent = '';
}
document.getElementById('filingModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeFilingModal();
});

// ── Contract info card ──
function showContractInfo(contractId) {
    const card = document.getElementById('modal-contract-info-card');
    if (!contractId || !contractData[contractId]) { card.classList.remove('show'); return; }
    const d = contractData[contractId];
    document.getElementById('modal-info-client').textContent = d.client;
    document.getElementById('modal-info-lawyer').textContent = d.lawyer + (d.spec ? ' (' + d.spec + ')' : '');
    document.getElementById('modal-info-fee').textContent    = d.fee;
    document.getElementById('modal-info-date').textContent   = d.date;
    document.getElementById('modal-info-spec').textContent   = d.spec || '—';
    document.getElementById('modal-info-status').textContent = d.status;
    document.getElementById('modal-info-detail').textContent = d.detail || '—';
    card.classList.add('show');
}

// ── Custom court dropdown ──
const courtInput    = document.getElementById('modal-court-input');
const courtDropdown = document.getElementById('modal-court-dropdown');
const courtBadge    = document.getElementById('modal-court-badge');
let activeIdx = -1;

function renderOptions(filter) {
    const q = (filter || '').trim().toLowerCase();
    const matched = knownCourts.filter(c => !q || c.toLowerCase().includes(q));
    courtDropdown.innerHTML = '';
    activeIdx = -1;

    matched.forEach(name => {
        const div = document.createElement('div');
        div.className = 'court-option';
        div.textContent = name;
        div.addEventListener('mousedown', e => { e.preventDefault(); selectCourt(name); });
        courtDropdown.appendChild(div);
    });

    const exactMatch = knownCourts.some(c => c.toLowerCase() === q);
    if (q && !exactMatch) {
        const div = document.createElement('div');
        div.className = 'court-option new-entry';
        div.textContent = `✏️ ใช้ "${filter.trim()}" (ศาลใหม่)`;
        div.addEventListener('mousedown', e => { e.preventDefault(); selectCourt(filter.trim(), true); });
        courtDropdown.appendChild(div);
    }
    courtDropdown.classList.toggle('open', courtDropdown.children.length > 0);
}

function selectCourt(name, isNew = false) {
    courtInput.value = name;
    courtDropdown.classList.remove('open');
    updateBadge(name);
}

function updateBadge(val) {
    const isKnown = knownCourts.some(c => c.toLowerCase() === val.trim().toLowerCase());
    if (!val.trim()) {
        courtBadge.className = 'court-match-badge';
        courtBadge.textContent = '';
    } else if (isKnown) {
        courtBadge.className = 'court-match-badge found';
        courtBadge.textContent = '✅ พบในฐานข้อมูล';
    } else {
        courtBadge.className = 'court-match-badge new';
        courtBadge.textContent = '✏️ ศาลใหม่ — จะเพิ่มในฐานข้อมูลอัตโนมัติ';
    }
}

courtInput?.addEventListener('focus', () => renderOptions(courtInput.value));
courtInput?.addEventListener('input', () => { renderOptions(courtInput.value); updateBadge(courtInput.value); });
courtInput?.addEventListener('blur',  () => setTimeout(() => courtDropdown.classList.remove('open'), 150));
courtInput?.addEventListener('keydown', e => {
    const opts = courtDropdown.querySelectorAll('.court-option');
    if (!opts.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, opts.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); }
    else if (e.key === 'Enter' && activeIdx >= 0) { e.preventDefault(); opts[activeIdx].dispatchEvent(new MouseEvent('mousedown')); return; }
    else if (e.key === 'Escape') { courtDropdown.classList.remove('open'); return; }
    opts.forEach((o, i) => o.classList.toggle('active', i === activeIdx));
    if (activeIdx >= 0) opts[activeIdx].scrollIntoView({ block:'nearest' });
});
document.addEventListener('click', e => {
    if (!document.getElementById('modal-court-wrap')?.contains(e.target))
        courtDropdown.classList.remove('open');
});

// ── AJAX Submit ──
document.getElementById('filingForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('filingSubmitBtn');
    btn.disabled = true;
    btn.textContent = '⏳ กำลังบันทึก...';
    try {
        const res  = await fetch(location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(this)
        });
        const data = await res.json();
        closeFilingModal();
        if (data.ok) {
            await Swal.fire({ icon:'success', title:'สำเร็จ!', text:data.msg,
                confirmButtonColor:'#1a3a5c', timer:2000, timerProgressBar:true });
            location.reload();
        } else {
            Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text:data.msg, confirmButtonColor:'#1a3a5c' });
        }
    } catch {
        Swal.fire({ icon:'error', title:'ผิดพลาด', text:'ไม่สามารถเชื่อมต่อได้', confirmButtonColor:'#1a3a5c' });
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 บันทึก';
    }
});

// auto-open modal ถ้ามี contract_id จาก URL
window.addEventListener('DOMContentLoaded', () => {
    <?php if ($contractId): ?>
    openFilingModal();
    const sel = document.getElementById('modal-contract-select');
    if (sel) { sel.value = '<?= $contractId ?>'; showContractInfo('<?= $contractId ?>'); }
    <?php endif; ?>
});

// ── Edit filing ──
function openEditFilingModal(d) {
    document.getElementById('ef-filing-id').value   = d.filing_id;
    document.getElementById('ef-court-input').value = d.court_name;
    document.getElementById('ef-charge').value      = d.charge;
    document.getElementById('ef-filing-date').value = d.filing_date;
    updateEditBadge(d.court_name);
    document.getElementById('editFilingModal').style.display = 'flex';
}
function closeEditFilingModal() {
    document.getElementById('editFilingModal').style.display = 'none';
}
document.addEventListener('DOMContentLoaded',function(){var ef=document.getElementById('editFilingModal');if(ef)ef.addEventListener('click',function(e){if(e.target===this)closeEditFilingModal();});});

const efCourtInput    = document.getElementById('ef-court-input');
const efCourtDropdown = document.getElementById('ef-court-dropdown');
const efCourtBadge    = document.getElementById('ef-court-badge');
let efActiveIdx = -1;
function renderEditOptions(filter) {
    const q = (filter||'').trim().toLowerCase();
    const matched = knownCourts.filter(c => !q || c.toLowerCase().includes(q));
    efCourtDropdown.innerHTML = '';
    efActiveIdx = -1;
    matched.forEach(name => {
        const div = document.createElement('div');
        div.className = 'court-option';
        div.textContent = name;
        div.addEventListener('mousedown', e => { e.preventDefault(); efCourtInput.value = name; efCourtDropdown.classList.remove('open'); updateEditBadge(name); });
        efCourtDropdown.appendChild(div);
    });
    const exact = knownCourts.some(c => c.toLowerCase() === q);
    if (q && !exact) {
        const div = document.createElement('div');
        div.className = 'court-option new-entry';
        div.textContent = `✏️ ใช้ "${filter.trim()}" (ศาลใหม่)`;
        div.addEventListener('mousedown', e => { e.preventDefault(); efCourtInput.value = filter.trim(); efCourtDropdown.classList.remove('open'); updateEditBadge(filter.trim()); });
        efCourtDropdown.appendChild(div);
    }
    efCourtDropdown.classList.toggle('open', efCourtDropdown.children.length > 0);
}
function updateEditBadge(val) {
    const isKnown = knownCourts.some(c => c.toLowerCase() === val.trim().toLowerCase());
    if (!val.trim()) { efCourtBadge.className='court-match-badge'; efCourtBadge.textContent=''; }
    else if (isKnown) { efCourtBadge.className='court-match-badge found'; efCourtBadge.textContent='✅ พบในฐานข้อมูล'; }
    else { efCourtBadge.className='court-match-badge new'; efCourtBadge.textContent='✏️ ศาลใหม่ — จะเพิ่มอัตโนมัติ'; }
}
efCourtInput?.addEventListener('focus',  () => renderEditOptions(efCourtInput.value));
efCourtInput?.addEventListener('input',  () => { renderEditOptions(efCourtInput.value); updateEditBadge(efCourtInput.value); });
efCourtInput?.addEventListener('blur',   () => setTimeout(() => efCourtDropdown.classList.remove('open'), 150));
document.addEventListener('click', e => {
    if (!document.getElementById('ef-court-wrap')?.contains(e.target))
        efCourtDropdown.classList.remove('open');
});

document.addEventListener('DOMContentLoaded',function(){var eff=document.getElementById('editFilingForm');if(eff)eff.addEventListener('submit', async function(e) {
    e.preventDefault();
    closeEditFilingModal();
    const btn = document.getElementById('efSubmitBtn'), orig = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳...';
    try {
        const res  = await fetch(location.pathname, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: new FormData(this) });
        const data = await res.json();
        if (data.ok) { await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1800,timerProgressBar:true,showConfirmButton:false}); location.reload(); }
        else Swal.fire({icon:'error',title:'ผิดพลาด',text:data.msg,confirmButtonColor:'#1a3a5c'});
    } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
    finally { btn.disabled=false; btn.textContent=orig; }
});});

async function deleteFiling(id, caseNum) {
    const result = await Swal.fire({
        icon: 'warning', title: 'ลบการยื่นฟ้อง?',
        html: `ลบการยื่นฟ้อง <b>"${caseNum}"</b>?<br><small style="color:#94a3b8">จะลบได้เฉพาะกรณีที่ยังไม่มีนัดขึ้นศาลหรือคำพิพากษา</small>`,
        showCancelButton: true, confirmButtonText: '🗑️ ลบ', cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626', cancelButtonColor: '#94a3b8',
    });
    if (!result.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fd.append('action', 'delete'); fd.append('filing_id', id);
    try {
        const res  = await fetch(location.pathname, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
        const data = await res.json();
        if (data.ok) { await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1800,timerProgressBar:true,showConfirmButton:false}); location.reload(); }
        else Swal.fire({icon:'error',title:'ลบไม่ได้',text:data.msg,confirmButtonColor:'#1a3a5c'});
    } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
}
</script>

<?php if (in_array($role, ['admin','lawyer'])): ?>
<!-- Modal: แก้ไขการยื่นฟ้อง -->
<div id="editFilingModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;color:#1a3a5c;">✏️ แก้ไขการยื่นฟ้อง</h3>
            <button onclick="closeEditFilingModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">✕</button>
        </div>
        <form id="editFilingForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="filing_id" id="ef-filing-id">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label>ศาล <span style="color:red">*</span></label>
                    <div class="court-input-wrap" id="ef-court-wrap">
                        <input type="text" name="court_input" id="ef-court-input"
                               placeholder="พิมพ์ชื่อศาล..." autocomplete="off" required>
                        <span class="court-arrow">▾</span>
                        <div class="court-dropdown" id="ef-court-dropdown"></div>
                    </div>
                    <span class="court-match-badge" id="ef-court-badge"></span>
                </div>
                <div class="form-group">
                    <label>ข้อหา</label>
                    <input type="text" name="charge" id="ef-charge">
                </div>
                <div class="form-group">
                    <label>วันนัดยื่นฟ้อง</label>
                    <input type="date" name="scheduled_filing_date" id="ef-filing-date">
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" onclick="closeEditFilingModal()" style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
                <button type="submit" id="efSubmitBtn" style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">💾 บันทึก</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>