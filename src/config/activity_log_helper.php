<?php
// ============================================================
// activity_log_helper.php — Audit Trail
// ============================================================

/**
 * บันทึก activity log
 */
function audit_log(PDO $pdo, int $officeId, ?int $userId, string $action, string $entityType, ?int $entityId, string $description, $oldValue = null, $newValue = null): void {
    $sql = "INSERT INTO activity_logs (office_id, user_id, action, entity_type, entity_id, description, old_value, new_value, ip_address, user_agent)
            VALUES (?,?,?,?,?,?,?,?,?,?)";
    $pdo->prepare($sql)->execute([
        $officeId,
        $userId,
        $action,
        $entityType,
        $entityId,
        $description,
        $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
        $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
}

/**
 * ดึง activity logs พร้อม filter + pagination
 */
function audit_fetch(PDO $pdo, int $officeId, array $filters = [], int $offset = 0, int $limit = 30): array {
    $where = ["al.office_id = ?"];
    $params = [$officeId];

    if (!empty($filters['action'])) {
        $where[] = "al.action = ?";
        $params[] = $filters['action'];
    }
    if (!empty($filters['entity_type'])) {
        $where[] = "al.entity_type = ?";
        $params[] = $filters['entity_type'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = "al.user_id = ?";
        $params[] = (int)$filters['user_id'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = "DATE(al.created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = "DATE(al.created_at) <= ?";
        $params[] = $filters['date_to'];
    }

    $whereStr = implode(' AND ', $where);

    $sql = "SELECT al.*,
                   COALESCE(lp.fname, cp.fname, CASE WHEN r.role_name='admin' THEN 'ผู้ดูแลระบบ' END) AS user_fname,
                   COALESCE(lp.lname, cp.lname, '') AS user_lname
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.user_id
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN lawyer_profiles lp ON u.user_id = lp.user_id
            LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
            WHERE {$whereStr}
            ORDER BY al.created_at DESC
            LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($sql);
    $i = 1;
    foreach ($params as $val) {
        $stmt->bindValue($i++, $val);
    }
    $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($i++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * นับจำนวน logs ตาม filter
 */
function audit_count(PDO $pdo, int $officeId, array $filters = []): int {
    $where = ["office_id = ?"];
    $params = [$officeId];

    if (!empty($filters['action'])) {
        $where[] = "action = ?";
        $params[] = $filters['action'];
    }
    if (!empty($filters['entity_type'])) {
        $where[] = "entity_type = ?";
        $params[] = $filters['entity_type'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = "user_id = ?";
        $params[] = (int)$filters['user_id'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = "DATE(created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = "DATE(created_at) <= ?";
        $params[] = $filters['date_to'];
    }

    $whereStr = implode(' AND ', $where);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE {$whereStr}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function audit_action_label(string $action): string {
    $map = [
        'create'  => 'สร้าง',
        'update'  => 'แก้ไข',
        'delete'  => 'ลบ',
        'approve' => 'อนุมัติ',
        'reject'  => 'ปฏิเสธ',
        'login'   => 'เข้าสู่ระบบ',
        'logout'  => 'ออกจากระบบ',
        'upload'  => 'อัปโหลด',
        'confirm' => 'ยืนยัน',
        'cancel'  => 'ยกเลิก',
        'expire'  => 'หมดอายุ',
    ];
    return $map[$action] ?? $action;
}

function audit_action_color(string $action): string {
    $map = [
        'create'  => '#059669',
        'update'  => '#2563eb',
        'delete'  => '#dc2626',
        'approve' => '#059669',
        'reject'  => '#dc2626',
        'login'   => '#6366f1',
        'logout'  => '#94a3b8',
        'upload'  => '#0891b2',
        'confirm' => '#059669',
        'cancel'  => '#d97706',
        'expire'  => '#94a3b8',
    ];
    return $map[$action] ?? '#64748b';
}

function audit_entity_label(string $entityType): string {
    $map = [
        'case_request'   => 'คำขอว่าจ้าง',
        'contract'       => 'สัญญา',
        'payment'        => 'การชำระเงิน',
        'hearing'        => 'นัดขึ้นศาล',
        'filing'         => 'นัดยื่นฟ้อง',
        'verdict'        => 'คำพิพากษา',
        'user'           => 'ผู้ใช้',
        'client'         => 'ลูกความ',
        'lawyer'         => 'ทนายความ',
        'document'       => 'เอกสาร',
        'announcement'   => 'ประกาศ',
        'profile_change' => 'แก้ไขโปรไฟล์',
        'verdict_appointment' => 'นัดพิพากษา',
        'sign_doc'       => 'เอกสารเซ็น',
    ];
    return $map[$entityType] ?? $entityType;
}
