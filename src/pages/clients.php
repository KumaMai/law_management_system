<?php
// clients.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireRole('admin');

$pdo      = getDB();
$officeId = $_SESSION['office_id'];
$error    = '';
$success  = '';

// Add client
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $fname     = trim($_POST['fname'] ?? '');
    $lname     = trim($_POST['lname'] ?? '');
    $citizenId = trim($_POST['citizen_id'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');

    if ($email && $password && $fname && $lname) {
        try {
            $hash   = password_hash($password, PASSWORD_BCRYPT);
            $roleId = $pdo->query("SELECT role_id FROM roles WHERE role_name='client'")->fetchColumn();

            $pdo->prepare("INSERT INTO users (office_id, role_id, email, password_hash, status) VALUES (?,?,?,?,'active')")
                ->execute([$officeId, $roleId, $email, $hash]);
            $userId = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO client_profiles (user_id, fname, lname, citizen_id, phone, address) VALUES (?,?,?,?,?,?)")
                ->execute([$userId, $fname, $lname, $citizenId ?: null, $phone, $address]);

            $success = 'เพิ่มลูกความสำเร็จ';
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: อีเมลหรือเลขบัตรประชาชนอาจซ้ำกัน';
        }
    } else {
        $error = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบ';
    }
}

$stmt = $pdo->prepare("
    SELECT cp.*, u.email
    FROM client_profiles cp
    JOIN users u ON cp.user_id = u.user_id
    WHERE u.office_id = ?
    ORDER BY cp.created_at DESC
");
$stmt->execute([$officeId]);
$clients   = $stmt->fetchAll();
$pageTitle = 'จัดการลูกความ';
include '../includes/header.php';
?>

<div class="card">
    <h2>➕ เพิ่มลูกความ</h2>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div class="form-group">
                <label>ชื่อ *</label>
                <input type="text" name="fname" required>
            </div>
            <div class="form-group">
                <label>นามสกุล *</label>
                <input type="text" name="lname" required>
            </div>
            <div class="form-group">
                <label>อีเมล *</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <label>รหัสผ่าน *</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>เลขบัตรประชาชน (13 หลัก)</label>
                <input type="text" name="citizen_id" maxlength="13" pattern="\d{13}">
            </div>
            <div class="form-group">
                <label>เบอร์โทร</label>
                <input type="text" name="phone">
            </div>
        </div>
        <div class="form-group">
            <label>ที่อยู่</label>
            <textarea name="address"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">บันทึก</button>
    </form>
</div>

<div class="card">
    <h2>👤 รายชื่อลูกความ</h2>
    <?php if (empty($clients)): ?>
    <p style="color:#888;">ยังไม่มีลูกความในระบบ</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>ชื่อ-นามสกุล</th><th>อีเมล</th>
                <th>เลขบัตรประชาชน</th><th>เบอร์โทร</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
            <tr>
                <td><?= $c['client_id'] ?></td>
                <td><?= htmlspecialchars($c['fname'].' '.$c['lname']) ?></td>
                <td><?= htmlspecialchars($c['email']) ?></td>
                <td><?= htmlspecialchars($c['citizen_id'] ?? '—') ?></td>
                <td><?= htmlspecialchars($c['phone'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>