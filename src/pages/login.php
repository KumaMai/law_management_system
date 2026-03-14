<?php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';

if (isLoggedIn()) {
    header('Location: /pages/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['login'] ?? '');   // รับทั้ง email และ username
    $password = $_POST['password'] ?? '';

    if ($login && $password) {
        $pdo = getDB();

        // ค้นหาด้วย email หรือ username อย่างใดอย่างหนึ่ง
        $stmt = $pdo->prepare("
            SELECT u.*, r.role_name, o.office_name
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            JOIN offices o ON u.office_id = o.office_id
            WHERE (u.email = ? OR u.username = ?) AND u.status = 'active'
        ");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id']     = $user['user_id'];
            $_SESSION['email']       = $user['email'];
            $_SESSION['username']    = $user['username'] ?? '';
            $_SESSION['role']        = $user['role_name'];
            $_SESSION['office_id']   = $user['office_id'];
            $_SESSION['office_name'] = $user['office_name'];
            header('Location: /pages/dashboard.php');
            exit;
        } else {
            $error = 'ชื่อผู้ใช้/อีเมล หรือรหัสผ่านไม่ถูกต้อง';
        }
    } else {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ — ระบบจัดการคดีความ</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body { background: linear-gradient(135deg, #0f2744 0%, #1a3a5c 50%, #2d5a8a 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
        .login-wrap { width: 100%; max-width: 420px; padding: 20px; }
        .login-box { background: #fff; border-radius: 20px; padding: 40px 36px; box-shadow: 0 20px 60px rgba(0,0,0,.35); }
        .login-logo { text-align: center; margin-bottom: 24px; }
        .login-logo h1 { font-size: 1.5rem; color: #1a3a5c; font-weight: 800; margin: 8px 0 4px; }
        .login-logo p { color: #94a3b8; font-size: .85rem; margin: 0; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: .8rem; font-weight: 700; color: #475569; margin-bottom: 6px; }
        .form-group input {
            width: 100%; padding: 11px 14px; border: 1.5px solid #e2e8f0;
            border-radius: 10px; font-size: .9rem; outline: none; transition: .2s;
            box-sizing: border-box; color: #1e293b; background: #f8fafc;
        }
        .form-group input:focus { border-color: #1a3a5c; background: #fff; box-shadow: 0 0 0 3px rgba(26,58,92,.1); }
        .input-icon-wrap { position: relative; }
        .input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 1rem; pointer-events: none; }
        .input-icon-wrap input { padding-left: 38px; }
        .btn-login {
            width: 100%; padding: 13px; background: linear-gradient(135deg, #1a3a5c, #2d5a8a);
            color: #fff; border: none; border-radius: 10px; font-size: .95rem;
            font-weight: 700; cursor: pointer; transition: .2s; margin-top: 8px;
            letter-spacing: .3px;
        }
        .btn-login:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(26,58,92,.4); }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; border-radius: 10px; padding: 11px 14px; margin-bottom: 16px; font-size: .84rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .login-footer { text-align: center; margin-top: 20px; font-size: .82rem; color: #94a3b8; }
        .login-footer a { color: #1a3a5c; font-weight: 700; text-decoration: none; }
        .login-footer a:hover { text-decoration: underline; }
        .divider { display: flex; align-items: center; gap: 10px; margin: 18px 0; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
        .divider span { font-size: .75rem; color: #94a3b8; white-space: nowrap; }
        .hint-box { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 8px 12px; margin-bottom: 14px; font-size: .76rem; color: #0369a1; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-box">
        <div class="login-logo">
            <div style="font-size:2.5rem;">⚖️</div>
            <h1>ระบบจัดการคดีความ</h1>
            <p>กรุณาเข้าสู่ระบบเพื่อดำเนินการต่อ</p>
        </div>

        <?php if ($error): ?>
        <div class="alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="hint-box">
            💡 สามารถล็อกอินด้วย <strong>Username</strong> หรือ <strong>อีเมล</strong> ก็ได้
        </div>

        <form method="POST">
            <div class="form-group">
                <label>👤 Username หรืออีเมล</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">👤</span>
                    <input type="text" name="login"
                           placeholder="username หรือ example@email.com"
                           value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                           autocomplete="username" required autofocus>
                </div>
            </div>
            <div class="form-group">
                <label>🔒 รหัสผ่าน</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">🔒</span>
                    <input type="password" name="password"
                           placeholder="••••••••"
                           autocomplete="current-password" required>
                </div>
            </div>
            <button type="submit" class="btn-login">เข้าสู่ระบบ →</button>
        </form>

        <div class="login-footer">
            ยังไม่มีบัญชี? <a href="/pages/register.php">สมัครสมาชิก</a>
        </div>
    </div>
</div>
</body>
</html>