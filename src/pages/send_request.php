<?php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
requireLogin();

if ($_SESSION['role'] !== 'client') {
    header('Location: /pages/dashboard.php');
    exit;
}

$pdo      = getDB();
$officeId = $_SESSION['office_id'];
$error    = '';
$success  = '';

// ดึง client_id
$cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id = ?");
$cp->execute([$_SESSION['user_id']]);
$clientId = $cp->fetchColumn();

if (!$clientId) {
    die('<div class="container"><div class="alert alert-error">ไม่พบข้อมูลโปรไฟล์ลูกความ กรุณาติดต่อผู้ดูแลระบบ</div></div>');
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lawyerId  = (int)($_POST['lawyer_id'] ?? 0);
    $detail    = trim($_POST['detail'] ?? '');

    if (!$lawyerId || !$detail) {
        $error = 'กรุณาเลือกทนายและกรอกรายละเอียดคดี';
    } else {
        // เช็คว่ามีคำขอ pending กับทนายคนนี้อยู่แล้วหรือไม่
        $chk = $pdo->prepare("
            SELECT request_id FROM case_requests
            WHERE client_id = ? AND lawyer_id = ? AND status = 'pending'
        ");
        $chk->execute([$clientId, $lawyerId]);
        if ($chk->fetch()) {
            $error = 'คุณมีคำขอที่รอการตอบรับจากทนายคนนี้อยู่แล้ว';
        } else {
            $requestDate = date('Y-m-d');
            $expireDate  = date('Y-m-d', strtotime('+14 days'));

            $pdo->prepare("
                INSERT INTO case_requests
                    (office_id, client_id, lawyer_id, detail, request_date, expire_date, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ")->execute([$officeId, $clientId, $lawyerId, $detail, $requestDate, $expireDate]);

            $success = 'ส่งคำขอสำเร็จ! ทนายจะตอบกลับภายใน 14 วัน';
        }
    }
}

// ดึงรายชื่อทนายในสำนักงานเดียวกัน
$lawyers = $pdo->prepare("
    SELECT lp.lawyer_id, lp.fname, lp.lname, lp.specialization, lp.phone, lp.license_no
    FROM lawyer_profiles lp
    JOIN users u ON lp.user_id = u.user_id
    WHERE u.office_id = ? AND lp.status = 'active'
    ORDER BY lp.fname
");
$lawyers->execute([$officeId]);
$lawyers = $lawyers->fetchAll();

// ดึงประวัติคำขอของลูกความคนนี้
$history = $pdo->prepare("
    SELECT cr.*,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           lp.specialization
    FROM case_requests cr
    JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
    WHERE cr.client_id = ?
    ORDER BY cr.created_at DESC
");
$history->execute([$clientId]);
$history = $history->fetchAll();

$pageTitle = 'ส่งคำขอว่าจ้างทนาย';
include '../includes/header.php';

$badgeMap = [
    'pending'  => 'badge-pending',
    'approved' => 'badge-approved',
    'rejected' => 'badge-rejected',
    'expired'  => 'badge-expired',
];
$statusTH = [
    'pending'  => '⏳ รอทนายตอบรับ',
    'approved' => '✅ อนุมัติแล้ว',
    'rejected' => '❌ ถูกปฏิเสธ',
    'expired'  => '⌛ หมดอายุ',
];
?>

<!-- ฟอร์มส่งคำขอ -->
<div class="card">
    <h2>📨 ส่งคำขอว่าจ้างทนาย</h2>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($success) ?>
        <a href="/pages/my_cases.php" style="margin-left:12px;">→ ดูคดีของฉัน</a>
    </div>
    <?php endif; ?>

    <?php if (empty($lawyers)): ?>
    <p style="color:#888;">ยังไม่มีทนายความในสำนักงาน กรุณาติดต่อผู้ดูแลระบบ</p>
    <?php else: ?>

    <form method="POST">
        <!-- เลือกทนาย -->
        <div class="form-group">
            <label>เลือกทนายที่ต้องการ <span style="color:red">*</span></label>
            <select name="lawyer_id" required onchange="showLawyerInfo(this)">
                <option value="">-- เลือกทนายความ --</option>
                <?php foreach ($lawyers as $l): ?>
                <option value="<?= $l['lawyer_id'] ?>"
                    data-spec="<?= htmlspecialchars($l['specialization'] ?? '—') ?>"
                    data-phone="<?= htmlspecialchars($l['phone'] ?? '—') ?>"
                    data-license="<?= htmlspecialchars($l['license_no'] ?? '—') ?>"
                    <?= (isset($_POST['lawyer_id']) && $_POST['lawyer_id'] == $l['lawyer_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($l['fname'].' '.$l['lname']) ?>
                    <?php if ($l['specialization']): ?>
                    — <?= htmlspecialchars($l['specialization']) ?>
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Info card ทนายที่เลือก -->
        <div id="lawyer-info" style="display:none; background:#f0f4f8; border-radius:8px; padding:14px; margin-bottom:16px; font-size:0.9rem;">
            <strong>📋 ข้อมูลทนาย</strong><br>
            ความเชี่ยวชาญ: <span id="info-spec"></span> |
            เบอร์โทร: <span id="info-phone"></span> |
            เลขใบอนุญาต: <span id="info-license"></span>
        </div>

        <!-- รายละเอียดคดี -->
        <div class="form-group">
            <label>รายละเอียดคดีที่ต้องการให้ดำเนินการ <span style="color:red">*</span></label>
            <textarea name="detail" rows="6"
                placeholder="อธิบายรายละเอียดของคดี เช่น ประเภทคดี, เหตุการณ์ที่เกิดขึ้น, ความต้องการ..."
                required><?= htmlspecialchars($_POST['detail'] ?? '') ?></textarea>
        </div>

        <div style="background:#fff8e1; border-left:4px solid #ffc107; padding:12px; border-radius:4px; margin-bottom:16px; font-size:0.88rem; color:#555;">
            ⚠️ คำขอจะหมดอายุภายใน <strong>14 วัน</strong> หากทนายไม่ตอบกลับ คุณสามารถส่งคำขอใหม่ได้
        </div>

        <button type="submit" class="btn btn-primary">📨 ส่งคำขอ</button>
    </form>
    <?php endif; ?>
</div>

<!-- ประวัติคำขอ -->
<div class="card">
    <h2>📋 ประวัติคำขอของฉัน</h2>

    <?php if (empty($history)): ?>
    <p style="color:#888;">ยังไม่มีคำขอ</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>ทนาย</th>
                <th>ความเชี่ยวชาญ</th>
                <th>รายละเอียดคดี</th>
                <th>วันที่ส่ง</th>
                <th>หมดอายุ</th>
                <th>สถานะ</th>
                <th>เหตุผล (ปฏิเสธ)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td><?= $h['request_id'] ?></td>
                <td><?= htmlspecialchars($h['lawyer_name']) ?></td>
                <td><?= htmlspecialchars($h['specialization'] ?? '—') ?></td>
                <td style="max-width:220px;">
                    <span title="<?= htmlspecialchars($h['detail']) ?>">
                        <?= htmlspecialchars(mb_substr($h['detail'], 0, 60)) ?><?= mb_strlen($h['detail']) > 60 ? '...' : '' ?>
                    </span>
                </td>
                <td><?= $h['request_date'] ?></td>
                <td><?= $h['expire_date'] ?></td>
                <td>
                    <span class="badge <?= $badgeMap[$h['status']] ?? '' ?>">
                        <?= $statusTH[$h['status']] ?? $h['status'] ?>
                    </span>
                </td>
                <td><?= $h['reject_reason'] ? htmlspecialchars($h['reject_reason']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script>
function showLawyerInfo(select) {
    const opt  = select.options[select.selectedIndex];
    const info = document.getElementById('lawyer-info');
    if (select.value) {
        document.getElementById('info-spec').textContent    = opt.dataset.spec;
        document.getElementById('info-phone').textContent   = opt.dataset.phone;
        document.getElementById('info-license').textContent = opt.dataset.license;
        info.style.display = 'block';
    } else {
        info.style.display = 'none';
    }
}
</script>

<?php include '../includes/footer.php'; ?>