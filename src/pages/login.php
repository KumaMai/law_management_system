<?php
// ============================================================
// แทนที่ไฟล์: src/pages/login.php
// ช่องโหว่ที่แก้:
//   - Session Fixation       → session_regenerate_id(true)
//   - Rate Limiting          → นับครั้งที่ login ผิดใน session
//   - CSRF                   → csrf_verify() + csrf_field()
// ============================================================

session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';

if (isLoggedIn()) {
    header('Location: /pages/dashboard.php');
    exit;
}

$error = '';

// ── Rate Limit: ถ้าพยายาม login ผิดเกิน 10 ครั้ง ล็อก 15 นาที ──
$maxAttempts  = 10;
$lockDuration = 15 * 60; // วินาที

$attempts  = $_SESSION['login_attempts']  ?? 0;
$lockUntil = $_SESSION['login_lock_until'] ?? 0;

if ($lockUntil > time()) {
    $remaining = ceil(($lockUntil - time()) / 60);
    $error = "ลอง login ผิดหลายครั้งเกินไป กรุณารอ {$remaining} นาทีแล้วลองใหม่";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {

    // ── ตรวจ CSRF ──
    csrf_verify();

    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login && $password) {
        $pdo = getDB();

        $stmt = $pdo->prepare("
            SELECT u.*, r.role_name, o.office_name
            FROM users u
            JOIN roles r  ON u.role_id  = r.role_id
            JOIN offices o ON u.office_id = o.office_id
            WHERE (u.email = ? OR u.username = ?) AND u.status = 'active'
        ");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {

            // ── แก้ Session Fixation: สร้าง session ID ใหม่หลัง login สำเร็จ ──
            session_regenerate_id(true);

            // ── reset ตัวนับครั้ง ──
            unset($_SESSION['login_attempts'], $_SESSION['login_lock_until']);

            $_SESSION['user_id']     = $user['user_id'];
            $_SESSION['email']       = $user['email'];
            $_SESSION['username']    = $user['username'] ?? '';
            $_SESSION['role']        = $user['role_name'];
            $_SESSION['office_id']   = $user['office_id'];
            $_SESSION['office_name'] = $user['office_name'];

            // บันทึก audit log
            try {
                require_once '../config/activity_log_helper.php';
                audit_log($pdo, (int)$user['office_id'], (int)$user['user_id'], 'login', 'user', (int)$user['user_id'], 'เข้าสู่ระบบ ('.$user['role_name'].')');
            } catch (Exception $e) {}

            header('Location: /pages/dashboard.php');
            exit;

        } else {
            // ── เพิ่มตัวนับเมื่อ login ผิด ──
            $_SESSION['login_attempts'] = ($attempts + 1);

            if ($_SESSION['login_attempts'] >= $maxAttempts) {
                $_SESSION['login_lock_until'] = time() + $lockDuration;
                $error = "ลอง login ผิดหลายครั้งเกินไป กรุณารอ 15 นาทีแล้วลองใหม่";
            } else {
                $remaining = $maxAttempts - $_SESSION['login_attempts'];
                // ไม่แจ้งจำนวนครั้งที่เหลือเพื่อกันเดา
                $error = 'ชื่อผู้ใช้/อีเมล หรือรหัสผ่านไม่ถูกต้อง';
            }
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
        .form-group label { display: flex; align-items: center; gap: 6px; font-size: .8rem; font-weight: 700; color: #475569; margin-bottom: 6px; }
        .label-icon svg { width: 14px; height: 14px; stroke: #475569; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }
        .form-group input { width: 100%; padding: 11px 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: .9rem; outline: none; transition: .2s; box-sizing: border-box; color: #1e293b; background: #f8fafc; }
        .form-group input:focus { border-color: #1a3a5c; background: #fff; box-shadow: 0 0 0 3px rgba(26,58,92,.1); }
        .input-icon-wrap { position: relative; }
        .input-icon-wrap input { padding-left: 14px; }
        .btn-login { width: 100%; padding: 13px; background: linear-gradient(135deg, #1a3a5c, #2d5a8a); color: #fff; border: none; border-radius: 10px; font-size: .95rem; font-weight: 700; cursor: pointer; transition: .2s; margin-top: 8px; letter-spacing: .3px; }
        .btn-login:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(26,58,92,.4); }
        .btn-login:disabled { opacity: .5; cursor: not-allowed; transform: none; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; border-radius: 10px; padding: 11px 14px; margin-bottom: 16px; font-size: .84rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .alert-lock  { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; border-radius: 10px; padding: 11px 14px; margin-bottom: 16px; font-size: .84rem; font-weight: 600; }
        .login-footer { text-align: center; margin-top: 20px; font-size: .82rem; color: #94a3b8; }
        .login-footer a { color: #1a3a5c; font-weight: 700; text-decoration: none; }
        .login-footer a:hover { text-decoration: underline; }
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

        <?php if ($error && ($lockUntil > time())): ?>
        <div class="alert-lock">🔒 <?= htmlspecialchars($error) ?></div>
        <?php elseif ($error): ?>
        <div class="alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="#991b1b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <div class="hint-box">
            💡 สามารถล็อกอินด้วย <strong>Username</strong> หรือ <strong>อีเมล</strong> ก็ได้
        </div>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>
                    <span class="label-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    Username หรืออีเมล
                </label>
                <div class="input-icon-wrap">
                    <input type="text" name="login"
                           placeholder="username หรือ example@email.com"
                           value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                           autocomplete="username" required autofocus
                           <?= ($lockUntil > time()) ? 'disabled' : '' ?>>
                </div>
            </div>
            <div class="form-group">
                <label>
                    <span class="label-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    รหัสผ่าน
                </label>
                <div class="input-icon-wrap">
                    <input type="password" name="password"
                           placeholder="••••••••"
                           autocomplete="current-password" required
                           <?= ($lockUntil > time()) ? 'disabled' : '' ?>>
                </div>
            </div>
            <button type="submit" class="btn-login" <?= ($lockUntil > time()) ? 'disabled' : '' ?>>
                เข้าสู่ระบบ →
            </button>
        </form>

        <div class="login-footer">
            ยังไม่มีบัญชี? <a href="/pages/register.php">สมัครสมาชิก</a>
        </div>
    </div>
</div>
</body>
</html>