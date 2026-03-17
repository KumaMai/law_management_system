<?php
// register.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';

if (isLoggedIn()) {
    header('Location: /pages/dashboard.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
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
    } elseif (strlen($password) < 8) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = 'รหัสผ่านต้องมีตัวอักษรพิมพ์ใหญ่อย่างน้อย 1 ตัว';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = 'รหัสผ่านต้องมีตัวอักษรพิมพ์เล็กอย่างน้อย 1 ตัว';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = 'รหัสผ่านต้องมีตัวเลขอย่างน้อย 1 ตัว';
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
            $error = 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง';
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
        /* ── reset ── */
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            min-height: 100vh;
            background: linear-gradient(135deg, #0f2744 0%, #1a3a5c 50%, #2d5a8a 100%) !important;
            font-family: 'Sarabun', sans-serif;
        }

        /* ── centerer ── */
        .register-wrap {
            width: 100%;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }

        /* ── card ── */
        .register-box {
            background: #fff;
            padding: 36px 32px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
            width: 100%;
            max-width: 520px;
        }

        /* ── header ── */
        .reg-logo { text-align: center; font-size: 2.2rem; margin-bottom: 6px; }
        .reg-title {
            text-align: center;
            color: #1a3a5c;
            font-size: 1.4rem;
            font-weight: 800;
            margin: 0 0 4px;
        }
        .reg-sub {
            text-align: center;
            color: #94a3b8;
            font-size: .83rem;
            margin: 0 0 24px;
        }

        /* ── section label ── */
        .sec-label {
            font-weight: 700;
            color: #1a3a5c;
            font-size: .85rem;
            margin: 0 0 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ── form elements (override style.css) ── */
        .form-group { margin-bottom: 14px; }
        .form-group label {
            display: block;
            font-size: .78rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 5px;
        }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: .88rem;
            background: #f8fafc;
            color: #1e293b;
            outline: none;
            transition: border-color .18s, box-shadow .18s;
            font-family: inherit;
        }
        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #1a3a5c;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(26,58,92,.1);
        }
        .form-group textarea { resize: vertical; min-height: 80px; }

        /* ── 2-col grid ── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        /* ── divider ── */
        .reg-divider {
            border: none;
            border-top: 1px solid #e2e8f0;
            margin: 20px 0;
        }

        /* ── submit btn ── */
        .btn-register {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #1a3a5c, #2d5a8a);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: .95rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
            letter-spacing: .3px;
            transition: opacity .18s, transform .18s;
            font-family: inherit;
        }
        .btn-register:hover {
            opacity: .9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(26,58,92,.4);
        }

        /* ── alerts ── */
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: .84rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 16px;
            font-size: .87rem;
            font-weight: 600;
            text-align: center;
            line-height: 1.6;
        }
        .alert-success a { color: #1a3a5c; font-weight: 700; }

        /* ── footer link ── */
        .login-link {
            text-align: center;
            margin-top: 18px;
            font-size: .82rem;
            color: #94a3b8;
        }
        .login-link a { color: #1a3a5c; font-weight: 700; text-decoration: none; }
        .login-link a:hover { text-decoration: underline; }

        /* ── required star ── */
        .req { color: #ef4444; }

        /* ── hint text ── */
        .hint { font-weight: 400; color: #94a3b8; font-size: .72rem; }
    </style>
</head>
<body>
<div class="register-wrap">
    <div class="register-box">

        <div class="reg-logo">⚖️</div>
        <h1 class="reg-title">สมัครสมาชิก</h1>
        <p class="reg-sub">สำหรับลูกความที่ต้องการใช้บริการ</p>

        <?php if ($error): ?>
        <div class="alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" stroke="#991b1b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert-success">
            ✅ <?= htmlspecialchars($success) ?><br>
            <a href="/pages/login.php">→ คลิกที่นี่เพื่อเข้าสู่ระบบ</a>
        </div>
        <?php else: ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <!-- ── ข้อมูลบัญชี ── -->
            <div class="sec-label">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="#1a3a5c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                ข้อมูลบัญชี
            </div>

            <div class="form-group">
                <label>
                    Username <span class="req">*</span>
                    <span class="hint">(ใช้สำหรับ login — ตัวอักษรภาษาอังกฤษ, ตัวเลข, _ เท่านั้น สูงสุด 30 ตัว)</span>
                </label>
                <input type="text" name="username" placeholder="เช่น john_doe99"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       pattern="[a-zA-Z0-9_]{3,30}" maxlength="30" required autofocus>
            </div>

            <div class="form-group">
                <label>อีเมล <span class="req">*</span></label>
                <input type="email" name="email" placeholder="example@email.com"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>รหัสผ่าน <span class="req">*</span></label>
                    <input type="password" name="password" placeholder="อย่างน้อย 8 ตัว (ต้องมีA-Z, a-z, 0-9)" required>
                    <small style="color:#94a3b8;font-size:.75rem;">ต้องมีตัวพิมพ์ใหญ่ (A-Z), ตัวพิมพ์เล็ก (a-z) และตัวเลข (0-9) อย่างน้อยอย่างละ 1 ตัว</small>
                </div>
                <div class="form-group">
                    <label>ยืนยันรหัสผ่าน <span class="req">*</span></label>
                    <input type="password" name="confirm_password" placeholder="พิมพ์อีกครั้ง" required>
                </div>
            </div>

            <hr class="reg-divider">

            <!-- ── ข้อมูลส่วนตัว ── -->
            <div class="sec-label">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="#1a3a5c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                ข้อมูลส่วนตัว
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>ชื่อ <span class="req">*</span></label>
                    <input type="text" name="fname" placeholder="ชื่อจริง"
                           value="<?= htmlspecialchars($_POST['fname'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>นามสกุล <span class="req">*</span></label>
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

            <button type="submit" class="btn-register">สมัครสมาชิก →</button>
        </form>

        <?php endif; ?>

        <div class="login-link">
            มีบัญชีแล้ว? <a href="/pages/login.php">เข้าสู่ระบบ</a>
        </div>

    </div>
</div>
</body>
</html>