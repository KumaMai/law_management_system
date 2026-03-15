<?php
// ============================================================
// แทนที่ไฟล์: src/pages/case_requests.php
// ช่องโหว่ที่แก้:
//   - Missing Authorization → เพิ่ม AND lawyer_id=? ใน UPDATE
//   - CSRF                  → csrf_verify() + csrf_field()
// ============================================================

session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$officeId = $_SESSION['office_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'lawyer') {

    // ── ตรวจ CSRF ──
    csrf_verify();

    $requestId = (int)($_POST['request_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    // ── ดึง lawyer_id ของตัวเองก่อน ──
    $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lp->execute([$_SESSION['user_id']]);
    $lawyerId = $lp->fetchColumn();

    if ($action === 'approve') {
        // ── แก้ Missing Authorization: เพิ่ม AND lawyer_id=? ──
        // ทำให้ทนายอนุมัติได้เฉพาะคำขอที่ส่งมาหาตัวเองเท่านั้น
        $stmt = $pdo->prepare("
            UPDATE case_requests
            SET status = 'approved'
            WHERE request_id = ?
              AND lawyer_id  = ?
              AND status     = 'pending'
        ");
        $stmt->execute([$requestId, $lawyerId]);

        // ถ้า update สำเร็จ (affectedRows > 0) จึง insert contract
        if ($stmt->rowCount() > 0) {
            $pdo->prepare("
                INSERT INTO contracts
                    (request_id, contract_date, status, payment_status, contract_review_status)
                VALUES (?, CURDATE(), 'active', 'pending', 'pending_lawyer_review')
            ")->execute([$requestId]);
        }

    } elseif ($action === 'reject') {
        $reason = trim($_POST['reject_reason'] ?? '');

        // ── แก้ Missing Authorization: เพิ่ม AND lawyer_id=? ──
        $pdo->prepare("
            UPDATE case_requests
            SET status = 'rejected', reject_reason = ?
            WHERE request_id = ?
              AND lawyer_id  = ?
              AND status     = 'pending'
        ")->execute([$reason, $requestId, $lawyerId]);
    }

    header('Location: /pages/case_requests.php');
    exit;
}

// ── Fetch requests (เหมือนเดิม) ──
if ($role === 'admin') {
    $stmt = $pdo->prepare("
        SELECT cr.*,
               CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
        FROM case_requests cr
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.office_id = ?
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([$officeId]);

} elseif ($role === 'lawyer') {
    $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lp->execute([$_SESSION['user_id']]);
    $lawyerId = $lp->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT cr.*, CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
        FROM case_requests cr
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.lawyer_id = ?
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([$lawyerId]);

} else {
    $cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
    $cp->execute([$_SESSION['user_id']]);
    $clientId = $cp->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT cr.*, CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
        FROM case_requests cr
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.client_id = ?
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([$clientId]);
}

$requests  = $stmt->fetchAll();
$pageTitle = 'คำขอว่าจ้างทนาย';
include '../includes/header.php';

$badgeMap = ['pending'=>'badge-pending','approved'=>'badge-approved','rejected'=>'badge-rejected','expired'=>'badge-expired'];
$statusTH = ['pending'=>'รอดำเนินการ','approved'=>'อนุมัติแล้ว','rejected'=>'ปฏิเสธแล้ว','expired'=>'หมดอายุ'];
?>

<div class="card">
    <h2>📋 คำขอว่าจ้างทนาย</h2>

    <?php if (empty($requests)): ?>
    <p style="color:#888;">ยังไม่มีคำขอในระบบ</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>ลูกความ</th><th>ทนาย</th><th>รายละเอียด</th>
                <th>วันที่ส่งคำขอ</th><th>วันหมดอายุ</th><th>สถานะ</th>
                <?php if ($role === 'lawyer'): ?><th>การดำเนินการ</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td><?= $r['request_id'] ?></td>
                <td><?= htmlspecialchars($r['client_name']) ?></td>
                <td><?= htmlspecialchars($r['lawyer_name']) ?></td>
                <td><?= htmlspecialchars(mb_substr($r['detail'] ?? '', 0, 60)) ?>...</td>
                <td><?= $r['request_date'] ?></td>
                <td><?= $r['expire_date'] ?></td>
                <td>
                    <span class="badge <?= $badgeMap[$r['status']] ?? '' ?>">
                        <?= $statusTH[$r['status']] ?? $r['status'] ?>
                    </span>
                </td>
                <?php if ($role === 'lawyer' && $r['status'] === 'pending'): ?>
                <td>
                    <form method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button class="btn btn-success btn-sm">✔ รับ</button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return promptReject(this)">
                        <?= csrf_field() ?>
                        <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="reject_reason" class="reject-reason" value="">
                        <button class="btn btn-danger btn-sm">✘ ปฏิเสธ</button>
                    </form>
                </td>
                <?php elseif ($role === 'lawyer'): ?>
                <td><span style="color:#aaa">—</span></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script>
function promptReject(form) {
    const reason = prompt('กรุณาระบุเหตุผลในการปฏิเสธ:');
    if (reason === null) return false;
    form.querySelector('.reject-reason').value = reason;
    return true;
}
</script>

<?php include '../includes/footer.php'; ?>