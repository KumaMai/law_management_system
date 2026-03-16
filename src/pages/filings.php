<?php
// filings.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireLogin();

$pdo        = getDB();
$role       = $_SESSION['role'];
$officeId   = $_SESSION['office_id'];
$contractId = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : null;

// Add filing (lawyer/admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['admin','lawyer'])) {
    csrf_verify();
    $courtInput  = trim($_POST['court_input'] ?? '');
    $newContract = (int)$_POST['contract_id'];
    $courtId     = null;

    // ── ตรวจว่า contract นี้มี filing อยู่แล้วหรือยัง ──
    $dupCheck = $pdo->prepare("SELECT filing_id FROM filings WHERE contract_id = ? LIMIT 1");
    $dupCheck->execute([$newContract]);
    if ($dupCheck->fetch()) {
        header('Location: /pages/filings.php?contract_id='.$newContract.'&error=duplicate');
        exit;
    }

    if ($courtInput !== '') {
        // ค้นหาศาลที่มีอยู่แล้ว (case-insensitive)
        $matchStmt = $pdo->prepare("SELECT court_id FROM courts WHERE court_name = ? LIMIT 1");
        $matchStmt->execute([$courtInput]);
        $matched = $matchStmt->fetchColumn();

        if ($matched) {
            // ศาลมีอยู่แล้ว → ใช้ court_id เดิม
            $courtId = (int)$matched;
        } else {
            // ศาลใหม่ → insert เข้า courts table อัตโนมัติ
            $pdo->prepare("INSERT INTO courts (court_name) VALUES (?)")
                ->execute([$courtInput]);
            $courtId = (int)$pdo->lastInsertId();
        }
    }

    $pdo->prepare("
        INSERT INTO filings (contract_id, court_id, case_number, charge, filing_date)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $newContract,
        $courtId,
        trim($_POST['case_number']),
        trim($_POST['charge']),
        $_POST['filing_date'],
    ]);
    header('Location: /pages/filings.php');
    exit;
}

// Fetch filings — ดึงชื่อศาลจากทั้ง court table และ freetext
$where = $contractId ? "AND f.contract_id = " . (int)$contractId : '';
$stmt  = $pdo->prepare("
    SELECT f.*,
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

// Courts for datalist
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
      AND con.status NOT IN ('terminated','rejected','cancelled')
      AND cr.status NOT IN ('rejected','cancelled')
    ORDER BY con.contract_id DESC
");
$contractsStmt->execute([$officeId]);
$contracts = $contractsStmt->fetchAll();

// JS map
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

$pageTitle = 'การยื่นฟ้องคดี';
include '../includes/header.php';
?>

<?php if (($_GET['error'] ?? '') === 'duplicate'): ?>
<div style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;padding:11px 16px;margin-bottom:14px;font-size:.86rem;font-weight:600;">
  ❌ สัญญานี้มีการยื่นฟ้องอยู่แล้ว ไม่สามารถยื่นฟ้องซ้ำได้
</div>
<?php endif; ?>

<style>
.contract-info-card {
    display:none;
    background:#f0f7ff;
    border:1px solid #bdd7f5;
    border-radius:8px;
    padding:14px 16px;
    margin-top:10px;
    font-size:0.88rem;
    animation: fadeIn .2s ease;
}
.contract-info-card.show { display:block; }
.contract-info-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:8px; }
.info-cell .lbl { color:#888; font-size:0.78rem; margin-bottom:2px; }
@keyframes fadeIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:translateY(0)} }

/* Court input */
.court-input-wrap { position:relative; }
.court-input-wrap input {
    width:100%;
    padding:10px 36px 10px 13px;
    border:1.5px solid #e2e8f0;
    border-radius:10px;
    font-size:.88rem;
    background:#f8fafc;
    color:#1e293b;
    outline:none;
    transition:.18s;
    font-family:inherit;
    box-sizing:border-box;
}
.court-input-wrap input:focus {
    border-color:#1a3a5c;
    background:#fff;
    box-shadow:0 0 0 3px rgba(26,58,92,.1);
}
.court-arrow {
    position:absolute; right:12px; top:50%; transform:translateY(-50%);
    color:#94a3b8; pointer-events:none; font-size:.8rem;
}
.court-dropdown {
    display:none;
    position:absolute;
    top:calc(100% + 4px);
    left:0; right:0;
    background:#fff;
    border:1.5px solid #1a3a5c;
    border-radius:10px;
    box-shadow:0 8px 24px rgba(0,0,0,.12);
    z-index:999;
    max-height:220px;
    overflow-y:auto;
}
.court-dropdown.open { display:block; }
.court-option {
    padding:9px 14px;
    font-size:.87rem;
    color:#1e293b;
    cursor:pointer;
    border-bottom:1px solid #f1f5f9;
    transition:.1s;
}
.court-option:last-child { border-bottom:none; }
.court-option:hover, .court-option.active { background:#eff6ff; color:#1a3a5c; font-weight:600; }
.court-option.new-entry { color:#854d0e; background:#fefce8; font-style:italic; }
.court-option.new-entry:hover { background:#fef9c3; }
.court-hint {
    font-size:.74rem;
    color:#94a3b8;
    margin-top:4px;
    display:block;
}
.court-match-badge {
    display:none;
    margin-top:5px;
    font-size:.76rem;
    padding:3px 10px;
    border-radius:12px;
    font-weight:600;
}
.court-match-badge.found  { display:inline-block; background:#dcfce7; color:#166534; }
.court-match-badge.new    { display:inline-block; background:#fef9c3; color:#854d0e; }
</style>

<?php if (in_array($role, ['admin','lawyer'])): ?>
<div class="card">
    <h2>➕ เพิ่มการยื่นฟ้องใหม่</h2>
    <form method="POST">
        <?= csrf_field() ?>

        <!-- เลือกสัญญา -->
        <div class="form-group">
            <label>สัญญา <span style="color:red">*</span></label>
            <select name="contract_id" id="contract-select" required onchange="showContractInfo(this.value)">
                <option value="">-- เลือกสัญญา --</option>
                <?php foreach ($contracts as $con):
                    $statusTxt   = $statusLabel[$con['contract_review_status']] ?? $con['contract_review_status'];
                    $shortDetail = mb_substr($con['case_detail'] ?? '', 0, 30);
                ?>
                <option value="<?= $con['contract_id'] ?>"
                    <?= $contractId == $con['contract_id'] ? 'selected' : '' ?>>
                    #<?= $con['contract_id'] ?>
                    — <?= htmlspecialchars($con['client_name']) ?>
                    <?= $shortDetail ? '(' . htmlspecialchars($shortDetail) . ')' : '' ?>
                    [<?= $statusTxt ?>]
                </option>
                <?php endforeach; ?>
            </select>

            <!-- Info Card -->
            <div class="contract-info-card" id="contract-info-card">
                <div style="font-weight:700; color:#1a3a5c; margin-bottom:8px;">📋 รายละเอียดสัญญาที่เลือก</div>
                <div class="contract-info-grid">
                    <div class="info-cell"><div class="lbl">👤 ลูกความ</div><strong id="info-client"></strong></div>
                    <div class="info-cell"><div class="lbl">👨‍⚖️ ทนาย</div><strong id="info-lawyer"></strong></div>
                    <div class="info-cell"><div class="lbl">💰 ค่าดำเนินคดี</div><strong id="info-fee"></strong> บาท</div>
                    <div class="info-cell"><div class="lbl">📅 วันทำสัญญา</div><span id="info-date"></span></div>
                    <div class="info-cell"><div class="lbl">⚖️ ความเชี่ยวชาญ</div><span id="info-spec"></span></div>
                    <div class="info-cell"><div class="lbl">📊 สถานะสัญญา</div><span id="info-status"></span></div>
                    <div class="info-cell" style="grid-column:span 3;">
                        <div class="lbl">📄 รายละเอียดคดี</div>
                        <span id="info-detail"></span>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">

            <!-- ── ศาล: พิมพ์เองหรือเลือกจาก custom dropdown ── -->
            <div class="form-group">
                <label>ศาล <span style="color:red">*</span></label>
                <div class="court-input-wrap" id="court-wrap">
                    <input
                        type="text"
                        name="court_input"
                        id="court-input"
                        placeholder="พิมพ์ชื่อศาล หรือคลิกเพื่อเลือก..."
                        autocomplete="off"
                        required
                    >
                    <span class="court-arrow">▾</span>
                    <div class="court-dropdown" id="court-dropdown"></div>
                </div>
                <span class="court-hint">💡 เลือกจากรายการหรือพิมพ์ชื่อศาลใหม่ได้เลย</span>
                <span class="court-match-badge" id="court-badge"></span>
            </div>

            <div class="form-group">
                <label>เลขคดี <span style="color:red">*</span></label>
                <input type="text" name="case_number" placeholder="เช่น 123/2568" required>
            </div>
            <div class="form-group">
                <label>ข้อหา</label>
                <input type="text" name="charge" placeholder="ระบุข้อหา">
            </div>
            <div class="form-group">
                <label>วันที่ยื่นฟ้อง <span style="color:red">*</span></label>
                <input type="date" name="filing_date" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">บันทึก</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>🏛️ รายการยื่นฟ้อง</h2>
    <?php if (empty($filings)): ?>
    <p style="color:#888;">ยังไม่มีข้อมูลการยื่นฟ้อง</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>ลูกความ</th><th>ทนาย</th>
                <th>ศาล</th><th>เลขคดี</th><th>ข้อหา</th>
                <th>วันที่ยื่นฟ้อง</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($filings as $f): ?>
            <tr>
                <td><?= $f['filing_id'] ?></td>
                <td><?= htmlspecialchars($f['client_name']) ?></td>
                <td><?= htmlspecialchars($f['lawyer_name']) ?></td>
                <td><?= htmlspecialchars($f['court_display']) ?></td>
                <td><?= htmlspecialchars($f['case_number'] ?? '—') ?></td>
                <td><?= htmlspecialchars($f['charge'] ?? '—') ?></td>
                <td><?= $f['filing_date'] ?? '—' ?></td>
                <td>
                    <a href="/pages/hearings.php?filing_id=<?= $f['filing_id'] ?>"
                       class="btn btn-primary btn-sm">📅 นัดขึ้นศาล</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script>
const contractData = <?= json_encode($contractMap, JSON_UNESCAPED_UNICODE) ?>;
const knownCourts  = <?= json_encode(array_column($courts, 'court_name'), JSON_UNESCAPED_UNICODE) ?>;

// ── Contract info card ──
function showContractInfo(contractId) {
    const card = document.getElementById('contract-info-card');
    if (!contractId || !contractData[contractId]) { card.classList.remove('show'); return; }
    const d = contractData[contractId];
    document.getElementById('info-client').textContent = d.client;
    document.getElementById('info-lawyer').textContent = d.lawyer + (d.spec ? ' (' + d.spec + ')' : '');
    document.getElementById('info-fee').textContent    = d.fee;
    document.getElementById('info-date').textContent   = d.date;
    document.getElementById('info-spec').textContent   = d.spec || '—';
    document.getElementById('info-status').textContent = d.status;
    document.getElementById('info-detail').textContent = d.detail || '—';
    card.classList.add('show');
}

// ── Custom court dropdown ──
const courtInput    = document.getElementById('court-input');
const courtDropdown = document.getElementById('court-dropdown');
const courtBadge    = document.getElementById('court-badge');
let activeIdx = -1;

function renderOptions(filter) {
    const q = (filter || '').trim().toLowerCase();
    const matched = knownCourts.filter(c => !q || c.toLowerCase().includes(q));

    courtDropdown.innerHTML = '';
    activeIdx = -1;

    // แสดงตัวเลือกที่ตรงกัน
    matched.forEach(name => {
        const div = document.createElement('div');
        div.className = 'court-option';
        div.textContent = name;
        div.addEventListener('mousedown', e => {
            e.preventDefault();
            selectCourt(name);
        });
        courtDropdown.appendChild(div);
    });

    // ถ้าพิมพ์ชื่อใหม่ที่ไม่ตรงใน list → แสดงตัวเลือก "ใช้ชื่อนี้"
    const exactMatch = knownCourts.some(c => c.toLowerCase() === q);
    if (q && !exactMatch) {
        const div = document.createElement('div');
        div.className = 'court-option new-entry';
        div.textContent = `✏️ ใช้ "${filter.trim()}" (ศาลใหม่)`;
        div.addEventListener('mousedown', e => {
            e.preventDefault();
            selectCourt(filter.trim(), true);
        });
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

courtInput.addEventListener('focus', () => renderOptions(courtInput.value));
courtInput.addEventListener('input', () => { renderOptions(courtInput.value); updateBadge(courtInput.value); });
courtInput.addEventListener('blur',  () => setTimeout(() => courtDropdown.classList.remove('open'), 150));

// keyboard navigation
courtInput.addEventListener('keydown', e => {
    const opts = courtDropdown.querySelectorAll('.court-option');
    if (!opts.length) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIdx = Math.min(activeIdx + 1, opts.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIdx = Math.max(activeIdx - 1, 0);
    } else if (e.key === 'Enter' && activeIdx >= 0) {
        e.preventDefault();
        opts[activeIdx].dispatchEvent(new MouseEvent('mousedown'));
        return;
    } else if (e.key === 'Escape') {
        courtDropdown.classList.remove('open');
        return;
    }
    opts.forEach((o, i) => o.classList.toggle('active', i === activeIdx));
    if (activeIdx >= 0) opts[activeIdx].scrollIntoView({ block:'nearest' });
});

// ปิด dropdown ถ้าคลิกนอก
document.addEventListener('click', e => {
    if (!document.getElementById('court-wrap').contains(e.target)) {
        courtDropdown.classList.remove('open');
    }
});

window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('contract-select');
    if (sel && sel.value) showContractInfo(sel.value);
});
</script>

<?php include '../includes/footer.php'; ?>