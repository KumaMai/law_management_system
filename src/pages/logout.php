<?php
// ============================================================
// แทนที่ไฟล์: src/pages/logout.php
// ช่องโหว่ที่แก้:
//   - ลบ session cookie ด้วย ไม่ใช่แค่ destroy session
// ============================================================

session_start();
session_unset();
session_destroy();

// ลบ cookie ออกจาก browser ด้วย
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 3600,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

header('Location: /pages/login.php');
exit;