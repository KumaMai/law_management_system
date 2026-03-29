<?php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/notification_helper.php';
requireLogin();

$pdo      = getDB();
$userId   = $_SESSION['user_id'];
$officeId = $_SESSION['office_id'];

// === AJAX Endpoints ===
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    // dropdown: ดึงแจ้งเตือนล่าสุด
    if ($_GET['ajax'] === 'recent') {
        $items = notif_fetch_recent($pdo, $userId, 10);
        $count = notif_count_unread($pdo, $userId);
        $html  = '';
        foreach ($items as $n) {
            $icon    = notif_icon($n['type']);
            $timeAgo = notif_time_ago($n['created_at']);
            $unread  = $n['is_read'] ? '' : ' style="background:#f0f9ff;"';
            $link    = $n['link'] ? htmlspecialchars($n['link']) : '#';
            $html .= '<a href="' . $link . '" class="notif-item"' . $unread . ' data-id="' . (int)$n['notif_id'] . '">'
                   . '<span class="notif-icon">' . $icon . '</span>'
                   . '<div class="notif-body">'
                   . '<div class="notif-title">' . htmlspecialchars($n['title']) . '</div>'
                   . '<div class="notif-time">' . htmlspecialchars($timeAgo) . '</div>'
                   . '</div>'
                   . '</a>';
        }
        echo json_encode(['ok' => true, 'count' => $count, 'html' => $html]);
        exit;
    }

    // mark one as read
    if ($_GET['ajax'] === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $nid = (int)($_POST['notif_id'] ?? 0);
        if ($nid > 0) notif_mark_read($pdo, $nid, $userId);
        echo json_encode(['ok' => true]);
        exit;
    }

    // mark all as read
    if ($_GET['ajax'] === 'mark_all_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        notif_mark_all_read($pdo, $userId);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'unknown action']);
    exit;
}

// === Full page view ===
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$total      = notif_count_all($pdo, $userId);
$notifs     = notif_fetch_all($pdo, $userId, $offset, $perPage);
$totalPages = max(1, ceil($total / $perPage));

$pageTitle = 'การแจ้งเตือน';
include '../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
    <h2 style="color: #1a3a5c;">🔔 การแจ้งเตือน</h2>
    <?php if ($total > 0): ?>
    <button onclick="markAllRead()" class="btn btn-sm" style="background: #e2e8f0; color: #475569;">
        ✓ อ่านทั้งหมด
    </button>
    <?php endif; ?>
</div>

<?php if (empty($notifs)): ?>
    <div class="card" style="text-align: center; color: #94a3b8; padding: 60px 0;">
        <div style="font-size: 2.5rem; margin-bottom: 10px;">🔔</div>
        <p>ยังไม่มีการแจ้งเตือน</p>
    </div>
<?php else: ?>
<div class="card" style="padding: 0; overflow: hidden;">
    <?php foreach ($notifs as $n):
        $icon    = notif_icon($n['type']);
        $timeAgo = notif_time_ago($n['created_at']);
        $unread  = !$n['is_read'];
        $link    = $n['link'] ?: '#';
    ?>
    <a href="<?= htmlspecialchars($link) ?>"
       class="notif-row <?= $unread ? 'notif-unread' : '' ?>"
       data-id="<?= (int)$n['notif_id'] ?>"
       onclick="markOne(<?= (int)$n['notif_id'] ?>)">
        <span class="notif-row-icon"><?= $icon ?></span>
        <div class="notif-row-content">
            <div class="notif-row-title"><?= htmlspecialchars($n['title']) ?></div>
            <?php if ($n['body']): ?>
            <div class="notif-row-body"><?= htmlspecialchars($n['body']) ?></div>
            <?php endif; ?>
        </div>
        <div class="notif-row-time"><?= htmlspecialchars($timeAgo) ?></div>
        <?php if ($unread): ?><span class="notif-dot"></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="display: flex; justify-content: center; gap: 4px; margin-top: 16px;">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <a href="?page=<?= $p ?>"
       style="padding: 6px 12px; border-radius: 6px; font-size: .82rem; text-decoration: none;
              <?= $p === $page ? 'background: #1a3a5c; color: #fff;' : 'background: #f1f5f9; color: #475569;' ?>">
        <?= $p ?>
    </a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // mark single notification as read
    window.markOne = function(nid) {
        fetch('/pages/notifications.php?ajax=mark_read', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'notif_id=' + nid + '&csrf_token=<?= csrf_token() ?>'
        });
    };

    // mark all as read
    window.markAllRead = function() {
        fetch('/pages/notifications.php?ajax=mark_all_read', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'csrf_token=<?= csrf_token() ?>'
        }).then(function() { location.reload(); });
    };
});
</script>

<?php include '../includes/footer.php'; ?>
