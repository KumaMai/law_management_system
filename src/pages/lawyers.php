<?php
// lawyers.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireRole('admin');

$pdo      = getDB();
$officeId = $_SESSION['office_id'];
$error    = '';
$success  = '';

// Add lawyer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email          = trim($_POST['email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $fname          = trim($_POST['fname'] ?? '');
    $lname          = trim($_POST['lname'] ?? '');
    $licenseNo      = trim($_POST['license_no'] ?? '');
    $licenseExp     = $_POST['license_exp'] ?? null;
    $specialization = trim($_POST['specialization'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');

    if (!$email || !$password || !$fname || !$lname) {
        $error = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบ (ชื่อ, นามสกุล, อีเมล, รหัสผ่าน)';
    } else {
        // เช็คอีเมลซ้ำก่อน
        $chk = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $error = 'อีเมลนี้ถูกใช้งานแล้ว';
        }

        // เช็คเลขใบอนุญาตซ้ำ (ถ้ากรอก)
        if (!$error && $licenseNo) {
            $chk2 = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE license_no = ?");
            $chk2->execute([$licenseNo]);
            if ($chk2->fetch()) {
                $error = 'เลขใบอนุญาตนี้ถูกใช้งานแล้ว';
            }
        }

        if (!$error) {
            try {
                $pdo->beginTransaction();

                $hash   = password_hash($password, PASSWORD_BCRYPT);
                $roleId = $pdo->query("SELECT role_id FROM roles WHERE role_name='lawyer'")->fetchColumn();

                $pdo->prepare("
                    INSERT INTO users (office_id, role_id, email, password_hash, status)
                    VALUES (?, ?, ?, ?, 'active')
                ")->execute([$officeId, $roleId, $email, $hash]);

                $userId = $pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO lawyer_profiles (user_id, fname, lname, license_no, license_exp, specialization, phone, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                ")->execute([
                    $userId,
                    $fname,
                    $lname,
                    $licenseNo   ?: null,
                    $licenseExp  ?: null,
                    $specialization,
                    $phone,
                ]);

                $pdo->commit();
                $success = 'เพิ่มทนายความสำเร็จ';

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง';
            }
        }
    }
}

$stmt = $pdo->prepare("
    SELECT lp.*, u.email
    FROM lawyer_profiles lp
    JOIN users u ON lp.user_id = u.user_id
    WHERE u.office_id = ?
    ORDER BY lp.created_at DESC
");
$stmt->execute([$officeId]);
$lawyers   = $stmt->fetchAll();
$pageTitle = 'จัดการทนายความ';
include '../includes/header.php';
?>

<div class="card">
    <h2>➕ เพิ่มทนายความ</h2>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <div class="form-group">
                <label>ชื่อ *</label>
                <input type="text" name="fname" value="<?= htmlspecialchars($_POST['fname'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>นามสกุล *</label>
                <input type="text" name="lname" value="<?= htmlspecialchars($_POST['lname'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>อีเมล *</label>
                <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>รหัสผ่าน *</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>เลขใบอนุญาต</label>
                <input type="text" name="license_no" value="<?= htmlspecialchars($_POST['license_no'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>วันหมดอายุใบอนุญาต</label>
                <input type="date" name="license_exp" value="<?= htmlspecialchars($_POST['license_exp'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>ความเชี่ยวชาญ</label>
                <input type="text" name="specialization" placeholder="เช่น คดีแพ่ง, คดีอาญา"
                       value="<?= htmlspecialchars($_POST['specialization'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>เบอร์โทร</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">บันทึก</button>
    </form>
</div>

<div class="card">
    <h2>👨‍⚖️ รายชื่อทนายความ</h2>
    <?php if (empty($lawyers)): ?>
    <p style="color:#888;">ยังไม่มีทนายความในระบบ</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>ชื่อ-นามสกุล</th><th>อีเมล</th>
                <th>เลขใบอนุญาต</th><th>ความเชี่ยวชาญ</th>
                <th>เบอร์โทร</th><th>สถานะ</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lawyers as $l): ?>
            <tr>
                <td><?= $l['lawyer_id'] ?></td>
                <td><?= htmlspecialchars($l['fname'].' '.$l['lname']) ?></td>
                <td><?= htmlspecialchars($l['email']) ?></td>
                <td><?= htmlspecialchars($l['license_no'] ?? '—') ?></td>
                <td><?= htmlspecialchars($l['specialization'] ?? '—') ?></td>
                <td><?= htmlspecialchars($l['phone'] ?? '—') ?></td>
                <td><span class="badge badge-active"><?= htmlspecialchars($l['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>