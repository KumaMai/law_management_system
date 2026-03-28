<?php
// ============================================================
// notification_helper.php — ระบบแจ้งเตือน
// ============================================================

/**
 * สร้างแจ้งเตือนให้ user คนเดียว
 */
function notif_create(PDO $pdo, int $officeId, int $userId, string $type, string $title, ?string $body = null, ?string $link = null, ?string $refType = null, ?int $refId = null): void {
    $sql = "INSERT INTO notifications (office_id, user_id, type, title, body, link, ref_type, ref_id) VALUES (?,?,?,?,?,?,?,?)";
    $pdo->prepare($sql)->execute([$officeId, $userId, $type, $title, $body, $link, $refType, $refId]);
}

/**
 * สร้างแจ้งเตือนให้หลาย user พร้อมกัน
 */
function notif_create_multi(PDO $pdo, int $officeId, array $userIds, string $type, string $title, ?string $body = null, ?string $link = null, ?string $refType = null, ?int $refId = null): void {
    if (empty($userIds)) return;
    $sql = "INSERT INTO notifications (office_id, user_id, type, title, body, link, ref_type, ref_id) VALUES (?,?,?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    foreach ($userIds as $uid) {
        $stmt->execute([$officeId, (int)$uid, $type, $title, $body, $link, $refType, $refId]);
    }
}

/**
 * นับแจ้งเตือนที่ยังไม่อ่าน
 */
function notif_count_unread(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * ดึงแจ้งเตือนล่าสุด (สำหรับ dropdown)
 */
function notif_fetch_recent(PDO $pdo, int $userId, int $limit = 10): array {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * ดึงแจ้งเตือนทั้งหมด (สำหรับหน้า notifications.php) + pagination
 */
function notif_fetch_all(PDO $pdo, int $userId, int $offset = 0, int $limit = 20): array {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function notif_count_all(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * อ่านแจ้งเตือนทีละอัน
 */
function notif_mark_read(PDO $pdo, int $notifId, int $userId): void {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE notif_id=? AND user_id=?")->execute([$notifId, $userId]);
}

/**
 * อ่านทั้งหมด
 */
function notif_mark_all_read(PDO $pdo, int $userId): void {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0")->execute([$userId]);
}

/**
 * หา user_id ของ admin ทั้งหมดใน office
 */
function notif_get_admin_ids(PDO $pdo, int $officeId): array {
    $stmt = $pdo->prepare("SELECT u.user_id FROM users u JOIN roles r ON u.role_id=r.role_id WHERE u.office_id=? AND r.role_name='admin' AND u.status='active'");
    $stmt->execute([$officeId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * หา user_id จาก lawyer_id
 */
function notif_get_user_id_by_lawyer(PDO $pdo, int $lawyerId): ?int {
    $stmt = $pdo->prepare("SELECT user_id FROM lawyer_profiles WHERE lawyer_id=?");
    $stmt->execute([$lawyerId]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int)$val : null;
}

/**
 * หา user_id จาก client_id
 */
function notif_get_user_id_by_client(PDO $pdo, int $clientId): ?int {
    $stmt = $pdo->prepare("SELECT user_id FROM client_profiles WHERE client_id=?");
    $stmt->execute([$clientId]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int)$val : null;
}

/**
 * icon สำหรับแต่ละ type
 */
function notif_icon(string $type): string {
    $map = [
        'case_request'   => '📋',
        'contract'       => '📄',
        'payment'        => '💳',
        'hearing'        => '📅',
        'filing'         => '⚖️',
        'verdict'        => '🔨',
        'chat'           => '💬',
        'profile_change' => '👤',
        'system'         => '🔔',
    ];
    return $map[$type] ?? '🔔';
}

/**
 * แปลงเวลาเป็น "X นาทีที่แล้ว" สำหรับ dropdown
 */
function notif_time_ago(string $datetime): string {
    $now  = time();
    $then = strtotime($datetime);
    $diff = $now - $then;

    if ($diff < 60)    return 'เมื่อสักครู่';
    if ($diff < 3600)  return floor($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff / 3600) . ' ชั่วโมงที่แล้ว';
    if ($diff < 604800) return floor($diff / 86400) . ' วันที่แล้ว';
    return date('d/m/Y H:i', $then);
}
