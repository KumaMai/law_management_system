<?php
/**
 * API: Global Search
 * เรียกผ่าน AJAX จาก header search bar
 * GET /api/global_search.php?q=keyword
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

$keyword = trim($_GET['q'] ?? '');
if ($keyword === '' || mb_strlen($keyword) < 2) {
    echo json_encode(['ok' => true, 'results' => [], 'grouped' => []]);
    exit;
}

$pdo      = getDB();
$officeId = $_SESSION['office_id'];
$userId   = $_SESSION['user_id'];
$role     = $_SESSION['role'];

try {
    $stmt = $pdo->prepare("CALL global_search(?, ?, ?, ?, ?)");
    $stmt->execute([$officeId, $userId, $role, $keyword, 30]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor(); // สำคัญ: ต้อง closeCursor หลัง CALL

    // จัดกลุ่มตาม entity_type
    $grouped = [];
    $typeLabels = [
        'client'       => ['label' => 'ลูกความ',        'icon' => '👤'],
        'lawyer'       => ['label' => 'ทนายความ',       'icon' => '👨‍⚖️'],
        'contract'     => ['label' => 'สัญญา/คดี',      'icon' => '📄'],
        'filing'       => ['label' => 'นัดยื่นฟ้อง',     'icon' => '⚖️'],
        'hearing'      => ['label' => 'นัดขึ้นศาล',      'icon' => '📅'],
        'court'        => ['label' => 'ศาล',            'icon' => '🏛️'],
        'announcement' => ['label' => 'ประกาศ',         'icon' => '📢'],
    ];

    foreach ($results as $row) {
        $type = $row['entity_type'];
        if (!isset($grouped[$type])) {
            $info = $typeLabels[$type] ?? ['label' => $type, 'icon' => '📋'];
            $grouped[$type] = [
                'label' => $info['label'],
                'icon'  => $info['icon'],
                'items' => [],
            ];
        }
        $grouped[$type]['items'][] = [
            'id'       => $row['entity_id'],
            'title'    => $row['title'],
            'subtitle' => $row['subtitle'],
            'link'     => $row['link'],
        ];
    }

    echo json_encode([
        'ok'      => true,
        'count'   => count($results),
        'grouped' => $grouped,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'ok'  => false,
        'msg' => 'เกิดข้อผิดพลาดในการค้นหา',
    ], JSON_UNESCAPED_UNICODE);
}
