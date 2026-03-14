<?php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';

if (isLoggedIn()) {
    header('Location: /pages/dashboard.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $fname     = trim($_POST['fname'] ?? '');
    $lname     = trim($_POST['lname'] ?? '');
    $citizenId = trim($_POST['citizen_id'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');

    // Validate
    if (!$username || !$email || !$password || !$fname || !$lname) {
        $error = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบ';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username ต้องเป็นภาษาอังกฤษ/ตัวเลข/_ ความยาว 3-30 ตัว';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'รูปแบบอีเมลไม่ถูกต้อง';
    } elseif (strlen($password) < 6) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    } elseif ($password !== $confirm) {
        $error = 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน';
    } elseif ($citizenId && strlen($citizenId) !== 13) {
        $error = 'เลขบัตรประชาชนต้องมี 13 หลัก';
    } else {
        try {
            $pdo = getDB();

            // Check username/email ซ้ำ
            $check = $pdo->prepare("SELECT user_id FROM users WHERE email=? OR username=?");
            $check->execute([$email, $username]);
            $dup = $check->fetch();
            if ($dup) {
                $error = 'Username หรืออีเมลนี้ถูกใช้งานแล้ว';
            } else {
                // ดึง office_id แรกที่ active
                $officeId = $pdo->query("SELECT office_id FROM offices WHERE status='active' ORDER BY office_id LIMIT 1")->fetchColumn();
                $roleId   = $pdo->query("SELECT role_id FROM roles WHERE role_name='client'")->fetchColumn();

                if (!$officeId || !$roleId) {
                    $error = 'ไม่พบข้อมูลสำนักงานหรือ role กรุณาติดต่อผู้ดูแลระบบ';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);

                    try {
                        $pdo->prepare("
                            INSERT INTO users (username, office_id, role_id, email, password_hash, status)
                            VALUES (?, ?, ?, ?, ?, 'active')
                        ")->execute([$username, $officeId, $roleId, $email, $hash]);
                    } catch(Exception $e) {
                        // fallback ถ้า username column ยังไม่มี
                        $pdo->prepare("
                            INSERT INTO users (office_id, role_id, email, password_hash, status)
                            VALUES (?, ?, ?, ?, 'active')
                        ")->execute([$officeId, $roleId, $email, $hash]);
                    }

                    $userId = $pdo->lastInsertId();

                    $pdo->prepare("
                        INSERT INTO client_profiles (user_id, fname, lname, citizen_id, phone, address)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $userId,
                        $fname,
                        $lname,
                        $citizenId ?: null,
                        $phone ?: null,
                        $address ?: null,
                    ]);

                    $success = 'สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ';
                }
            }
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: อีเมลหรือเลขบัตรประชาชนอาจซ้ำกัน';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .register-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }
        .register-box {
            background: #fff;
            padding: 40px 36px;
            border-radius: 10px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            width: 100%;
            max-width: 520px;
        }
        .register-box h1 {
            text-align: center;
            color: #1a3a5c;
            margin-bottom: 6px;
            font-size: 1.4rem;
        }
        .register-box p {
            text-align: center;
            color: #888;
            margin-bottom: 24px;
            font-size: 0.88rem;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .divider {
            border: none;
            border-top: 1px solid #eee;
            margin: 20px 0;
        }
        .login-link {
            text-align: center;
            margin-top: 16px;
            font-size: 0.88rem;
            color: #666;
        }
        .login-link a { color: #1a3a5c; font-weight: bold; }
    </style>
</head>
<body>
<div class="register-wrap">
    <div class="register-box">
        <h1>⚖️ สมัครสมาชิก</h1>
        <p>สำหรับลูกความที่ต้องการใช้บริการ</p>

        <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
            <br><a href="/pages/login.php">→ คลิกที่นี่เพื่อเข้าสู่ระบบ</a>
        </div>
        <?php else: ?>

        <form method="POST" novalidate>
            <!-- ข้อมูลบัญชี -->
            <p style="font-weight:bold; color:#1a3a5c; margin-bottom:12px;">📋 ข้อมูลบัญชี</p>

            <div class="form-group">
                <label>👤 Username <span style="color:red">*</span>
                    <span style="font-weight:400;color:#94a3b8;font-size:.75rem;">(ใช้สำหรับ login — a-z, 0-9, _ เท่านั้น)</span>
                </label>
                <input type="text" name="username" placeholder="เช่น john_doe99"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       pattern="[a-zA-Z0-9_]{3,30}" maxlength="30" required autofocus>
            </div>
            <div class="form-group">
                <label>📧 อีเมล <span style="color:red">*</span></label>
                <input type="email" name="email" placeholder="example@email.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>รหัสผ่าน <span style="color:red">*</span></label>
                    <input type="password" name="password" placeholder="อย่างน้อย 6 ตัว" required>
                </div>
                <div class="form-group">
                    <label>ยืนยันรหัสผ่าน <span style="color:red">*</span></label>
                    <input type="password" name="confirm_password" placeholder="พิมพ์อีกครั้ง" required>
                </div>
            </div>

            <hr class="divider">

            <!-- ข้อมูลส่วนตัว -->
            <p style="font-weight:bold; color:#1a3a5c; margin-bottom:12px;">👤 ข้อมูลส่วนตัว</p>

            <div class="form-row">
                <div class="form-group">
                    <label>ชื่อ <span style="color:red">*</span></label>
                    <input type="text" name="fname" placeholder="ชื่อจริง"
                           value="<?= htmlspecialchars($_POST['fname'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>นามสกุล <span style="color:red">*</span></label>
                    <input type="text" name="lname" placeholder="นามสกุล"
                           value="<?= htmlspecialchars($_POST['lname'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label>เลขบัตรประชาชน (13 หลัก)</label>
                <input type="text" name="citizen_id" placeholder="x-xxxx-xxxxx-xx-x"
                       maxlength="13" pattern="\d{13}"
                       value="<?= htmlspecialchars($_POST['citizen_id'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>เบอร์โทรศัพท์</label>
                <input type="text" name="phone" placeholder="08x-xxx-xxxx"
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>ที่อยู่</label>
                <textarea name="address" placeholder="บ้านเลขที่ ถนน ตำบล อำเภอ จังหวัด"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:8px;">
                สมัครสมาชิก
            </button>
        </form>

        <?php endif; ?>

        <div class="login-link">
            มีบัญชีแล้ว? <a href="/pages/login.php">เข้าสู่ระบบ</a>
        </div>
    </div>
</div>
</body>
</html>