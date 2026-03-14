<?php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
requireLogin();

$pdo        = getDB();
$role       = $_SESSION['role'];
$officeId   = $_SESSION['office_id'];
$contractId = isset($_GET['contract_id']) ? (int)$_GET['contract_id'] : null;

// Add filing (lawyer/admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['admin','lawyer'])) {
    $pdo->prepare("
        INSERT INTO filings (contract_id, court_id, case_number, charge, filing_date)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        (int)$_POST['contract_id'],
        (int)$_POST['court_id'],
        trim($_POST['case_number']),
        trim($_POST['charge']),
        $_POST['filing_date'],
    ]);
    header('Location: /pages/filings.php');
    exit;
}

// Fetch filings
$where = $contractId ? "AND f.contract_id = " . (int)$contractId : '';
$stmt  = $pdo->prepare("
    SELECT f.*, c.court_name,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           cr.detail AS case_detail,
           con.fee_amount, con.contract_date,
           cr.office_id
    FROM filings f
    JOIN courts c         ON f.court_id    = c.court_id
    JOIN contracts con    ON f.contract_id = con.contract_id
    JOIN case_requests cr ON con.request_id = cr.request_id
    JOIN client_profiles cp ON cr.client_id = cp.client_id
    JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
    WHERE cr.office_id = ? $where
    ORDER BY f.created_at DESC
");
$stmt->execute([$officeId]);
$filings = $stmt->fetchAll();

// Courts for form
$courts = $pdo->query("SELECT * FROM courts ORDER BY court_name")->fetchAll();

// Contracts for form — ดึงรายละเอียดครบสำหรับ info card
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

// สร้าง JS map ของ contract details
$contractMap = [];
foreach ($contracts as $con) {
    $contractMap[$con['contract_id']] = [
        'client'      => $con['client_name'],
        'lawyer'      => $con['lawyer_name'],
        'spec'        => $con['specialization'] ?? '',
        'detail'      => mb_substr($con['case_detail'] ?? '—', 0, 120) . (mb_strlen($con['case_detail']??'') > 120 ? '...' : ''),
        'fee'         => $con['fee_amount'] ? number_format($con['fee_amount'], 2) : '—',
        'date'        => $con['contract_date'] ?? '—',
        'status'      => $con['contract_review_status'] ?? '—',
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
</style>

<?php if (in_array($role, ['admin','lawyer'])): ?>
<div class="card">
    <h2>➕ เพิ่มการยื่นฟ้องใหม่</h2>
    <form method="POST">
        <div class="form-group">
            <label>สัญญา <span style="color:red">*</span></label>
            <select name="contract_id" id="contract-select" required onchange="showContractInfo(this.value)">
                <option value="">-- เลือกสัญญา --</option>
                <?php foreach ($contracts as $con): ?>
                <?php
                    $statusTxt = $statusLabel[$con['contract_review_status']] ?? $con['contract_review_status'];
                    $shortDetail = mb_substr($con['case_detail'] ?? '', 0, 30);
                ?>
                <option value="<?= $con['contract_id'] ?>"
                    <?= $contractId == $con['contract_id'] ? 'selected' : '' ?>>
                    #<?= $con['contract_id'] ?>
                    — <?= htmlspecialchars($con['client_name']) ?>
                    <?= $shortDetail ? '('. htmlspecialchars($shortDetail) .')' : '' ?>
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
            <div class="form-group">
                <label>ศาล <span style="color:red">*</span></label>
                <select name="court_id" required>
                    <option value="">-- เลือกศาล --</option>
                    <?php foreach ($courts as $court): ?>
                    <option value="<?= $court['court_id'] ?>"><?= htmlspecialchars($court['court_name']) ?></option>
                    <?php endforeach; ?>
                </select>
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
                <td><?= htmlspecialchars($f['court_name']) ?></td>
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

function showContractInfo(contractId) {
    const card = document.getElementById('contract-info-card');
    if (!contractId || !contractData[contractId]) {
        card.classList.remove('show');
        return;
    }
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

// แสดง info card ทันทีถ้า pre-selected
window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('contract-select');
    if (sel && sel.value) showContractInfo(sel.value);
});
</script>

<?php include '../includes/footer.php'; ?>