<?php
// ============================================================
// csrf_helper.php
// วางไว้ที่: src/config/csrf_helper.php
// ใช้ร่วมกับทุกไฟล์ที่มีฟอร์ม POST
// ============================================================

/**
 * สร้าง CSRF token ใหม่ (ถ้ายังไม่มี)
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * คืนค่า HTML hidden input สำหรับใส่ในทุกฟอร์ม
 * ใช้: <?= csrf_field() ?>
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * ตรวจสอบ CSRF token — เรียกต้น POST handler ทุกอัน
 * ถ้า token ไม่ตรงจะหยุดทำงานทันที
 */
function csrf_verify(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals(csrf_token(), $token)) {
        // AJAX → JSON error, ปกติ → redirect กลับหน้าเดิม
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            http_response_code(403);
            header('Content-Type: application/json');
            die(json_encode(['ok' => false, 'msg' => 'CSRF token ไม่ถูกต้อง กรุณาโหลดหน้าใหม่']));
        }
        // regenerate token แล้ว redirect กลับ
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $back = $_SERVER['REQUEST_URI'] ?? '/pages/dashboard.php';
        header('Location: ' . $back);
        exit;
    }
}
