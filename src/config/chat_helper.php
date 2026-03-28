<?php
// ============================================================
// chat_helper.php — ระบบแชท ทนาย-ลูกความ
// ============================================================

/**
 * หาหรือสร้าง conversation สำหรับ request_id
 */
function chat_get_or_create(PDO $pdo, int $officeId, int $requestId, int $lawyerUserId, int $clientUserId): int {
    $stmt = $pdo->prepare("SELECT conversation_id FROM chat_conversations WHERE request_id=?");
    $stmt->execute([$requestId]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $pdo->prepare("INSERT INTO chat_conversations (office_id, request_id, lawyer_user_id, client_user_id) VALUES (?,?,?,?)")
        ->execute([$officeId, $requestId, $lawyerUserId, $clientUserId]);
    return (int)$pdo->lastInsertId();
}

/**
 * ดึงรายการ conversations ของ user
 */
function chat_get_conversations(PDO $pdo, int $officeId, int $userId, string $role): array {
    $sql = "SELECT cc.*,
                   cr.detail AS case_detail,
                   lp.fname AS lawyer_fname, lp.lname AS lawyer_lname, lp.profile_photo AS lawyer_photo,
                   cp.fname AS client_fname, cp.lname AS client_lname, cp.profile_photo AS client_photo,
                   (SELECT COUNT(*) FROM chat_messages cm
                    WHERE cm.conversation_id = cc.conversation_id
                      AND cm.sender_user_id != ? AND cm.is_read = 0) AS unread_count,
                   (SELECT cm2.message FROM chat_messages cm2
                    WHERE cm2.conversation_id = cc.conversation_id
                    ORDER BY cm2.created_at DESC LIMIT 1) AS last_message
            FROM chat_conversations cc
            JOIN case_requests cr ON cc.request_id = cr.request_id
            JOIN lawyer_profiles lp ON lp.user_id = cc.lawyer_user_id
            JOIN client_profiles cp ON cp.user_id = cc.client_user_id
            WHERE cc.office_id = ?";

    $params = [$userId, $officeId];

    if ($role === 'lawyer') {
        $sql .= " AND cc.lawyer_user_id = ?";
        $params[] = $userId;
    } elseif ($role === 'client') {
        $sql .= " AND cc.client_user_id = ?";
        $params[] = $userId;
    }

    $sql .= " ORDER BY COALESCE(cc.last_message_at, cc.created_at) DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * ดึงข้อความใน conversation
 */
function chat_get_messages(PDO $pdo, int $conversationId, int $limit = 50, int $offset = 0): array {
    $stmt = $pdo->prepare(
        "SELECT cm.*,
                COALESCE(lp.fname, cp.fname, 'ผู้ดูแล') AS sender_fname,
                COALESCE(lp.lname, cp.lname, '') AS sender_lname,
                COALESCE(lp.profile_photo, cp.profile_photo) AS sender_photo,
                r.role_name AS sender_role
         FROM chat_messages cm
         JOIN users u ON cm.sender_user_id = u.user_id
         JOIN roles r ON u.role_id = r.role_id
         LEFT JOIN lawyer_profiles lp ON u.user_id = lp.user_id
         LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
         WHERE cm.conversation_id = ?
         ORDER BY cm.created_at ASC
         LIMIT ? OFFSET ?"
    );
    $stmt->bindValue(1, $conversationId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * ส่งข้อความ
 */
function chat_send(PDO $pdo, int $conversationId, int $senderUserId, string $message): int {
    $pdo->prepare("INSERT INTO chat_messages (conversation_id, sender_user_id, message) VALUES (?,?,?)")
        ->execute([$conversationId, $senderUserId, $message]);
    $msgId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE chat_conversations SET last_message_at = CURRENT_TIMESTAMP WHERE conversation_id = ?")
        ->execute([$conversationId]);

    return $msgId;
}

/**
 * mark ข้อความเป็นอ่านแล้ว (ทั้ง conversation ที่ไม่ใช่ของตัวเอง)
 */
function chat_mark_read(PDO $pdo, int $conversationId, int $userId): void {
    $pdo->prepare("UPDATE chat_messages SET is_read=1 WHERE conversation_id=? AND sender_user_id!=? AND is_read=0")
        ->execute([$conversationId, $userId]);
}

/**
 * นับข้อความที่ยังไม่อ่านทั้งหมดของ user
 */
function chat_count_unread(PDO $pdo, int $userId, int $officeId): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM chat_messages cm
         JOIN chat_conversations cc ON cm.conversation_id = cc.conversation_id
         WHERE cc.office_id = ?
           AND cm.sender_user_id != ?
           AND cm.is_read = 0
           AND (cc.lawyer_user_id = ? OR cc.client_user_id = ?)"
    );
    $stmt->execute([$officeId, $userId, $userId, $userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * ตรวจสอบว่า user มีสิทธิ์เข้าถึง conversation นี้หรือไม่
 */
function chat_verify_access(PDO $pdo, int $conversationId, int $userId, int $officeId): ?array {
    $stmt = $pdo->prepare(
        "SELECT * FROM chat_conversations WHERE conversation_id=? AND office_id=? AND (lawyer_user_id=? OR client_user_id=?)"
    );
    $stmt->execute([$conversationId, $officeId, $userId, $userId]);
    return $stmt->fetch() ?: null;
}
