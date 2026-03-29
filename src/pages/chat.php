<?php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/chat_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$userId   = $_SESSION['user_id'];
$officeId = $_SESSION['office_id'];

// admin ไม่มีสิทธิ์แชท
if ($role === 'admin') {
    header('Location: /pages/dashboard.php');
    exit;
}

// === AJAX Endpoints ===
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    // ส่งข้อความ
    if ($_GET['ajax'] === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $convId  = (int)($_POST['conversation_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        if (!$convId || $message === '') {
            echo json_encode(['ok' => false, 'msg' => 'ข้อมูลไม่ครบ']);
            exit;
        }

        // verify access
        $conv = chat_verify_access($pdo, $convId, $userId, $officeId);
        if (!$conv) {
            echo json_encode(['ok' => false, 'msg' => 'ไม่มีสิทธิ์']);
            exit;
        }

        $msgId = chat_send($pdo, $convId, $userId, $message);
        echo json_encode(['ok' => true, 'message_id' => $msgId]);
        exit;
    }

    // ดึงข้อความ (polling)
    if ($_GET['ajax'] === 'messages') {
        $convId = (int)($_GET['conversation_id'] ?? 0);
        if (!$convId || !chat_verify_access($pdo, $convId, $userId, $officeId)) {
            echo json_encode(['ok' => false, 'msg' => 'ไม่มีสิทธิ์']);
            exit;
        }

        chat_mark_read($pdo, $convId, $userId);
        $msgs = chat_get_messages($pdo, $convId, 100);

        $html = '';
        foreach ($msgs as $m) {
            $isMine = (int)$m['sender_user_id'] === $userId;
            $cls    = $isMine ? 'chat-msg-mine' : 'chat-msg-other';
            $name   = htmlspecialchars($m['sender_fname'] . ' ' . $m['sender_lname']);
            $time   = date('H:i', strtotime($m['created_at']));
            $text   = nl2br(htmlspecialchars($m['message']));

            $html .= '<div class="chat-msg ' . $cls . '">';
            if (!$isMine) {
                $html .= '<div class="chat-msg-name">' . $name . '</div>';
            }
            $html .= '<div class="chat-msg-bubble">' . $text . '</div>';
            $html .= '<div class="chat-msg-time">' . $time . '</div>';
            $html .= '</div>';
        }

        echo json_encode(['ok' => true, 'html' => $html, 'count' => count($msgs)]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'unknown']);
    exit;
}

// === Page View ===
$conversations = chat_get_conversations($pdo, $officeId, $userId, $role);
$activeConvId  = (int)($_GET['c'] ?? 0);

// ถ้ามี request_id มา ให้สร้าง/เปิด conversation
if (!empty($_GET['request_id'])) {
    $reqId = (int)$_GET['request_id'];
    // ดึงข้อมูล request
    $reqStmt = $pdo->prepare("SELECT cr.*, lp.user_id AS lawyer_uid, cp.user_id AS client_uid
                               FROM case_requests cr
                               JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
                               JOIN client_profiles cp ON cr.client_id = cp.client_id
                               WHERE cr.request_id = ? AND cr.office_id = ?");
    $reqStmt->execute([$reqId, $officeId]);
    $req = $reqStmt->fetch();
    if ($req && ((int)$req['lawyer_uid'] === $userId || (int)$req['client_uid'] === $userId)) {
        $activeConvId = chat_get_or_create($pdo, $officeId, $reqId, (int)$req['lawyer_uid'], (int)$req['client_uid']);
        // refresh conversations list
        $conversations = chat_get_conversations($pdo, $officeId, $userId, $role);
    }
}

$pageTitle = 'ข้อความ';
include '../includes/header.php';
?>

<h2 style="margin-bottom: 18px; color: #1a3a5c;">💬 ข้อความ</h2>

<div class="chat-container">
    <!-- Conversation List -->
    <div class="chat-sidebar" id="chatSidebar">
        <?php if (empty($conversations)): ?>
            <div style="text-align: center; color: #94a3b8; padding: 40px 16px; font-size: .85rem;">
                <div style="font-size: 2rem; margin-bottom: 8px;">💬</div>
                ยังไม่มีข้อความ
            </div>
        <?php else: ?>
            <?php foreach ($conversations as $conv):
                $isActive   = (int)$conv['conversation_id'] === $activeConvId;
                $otherName  = $role === 'lawyer'
                    ? $conv['client_fname'] . ' ' . $conv['client_lname']
                    : $conv['lawyer_fname'] . ' ' . $conv['lawyer_lname'];
                $otherPhoto = $role === 'lawyer' ? $conv['client_photo'] : $conv['lawyer_photo'];
                $photoPath  = $role === 'lawyer'
                    ? ($otherPhoto ? '/uploads/client_photos/' . $otherPhoto : '')
                    : ($otherPhoto ? '/uploads/lawyer_photos/' . $otherPhoto : '');
                $unread     = (int)$conv['unread_count'];
            ?>
            <a href="?c=<?= (int)$conv['conversation_id'] ?>"
               class="chat-conv-item <?= $isActive ? 'chat-conv-active' : '' ?>">
                <?php if ($photoPath): ?>
                <img src="<?= htmlspecialchars($photoPath) ?>" class="chat-conv-avatar" alt="">
                <?php else: ?>
                <div class="chat-conv-avatar-ph"><?= $role === 'lawyer' ? '👤' : '⚖️' ?></div>
                <?php endif; ?>
                <div class="chat-conv-info">
                    <div class="chat-conv-name"><?= htmlspecialchars($otherName) ?></div>
                    <div class="chat-conv-preview"><?= htmlspecialchars(mb_strimwidth($conv['last_message'] ?? 'เริ่มสนทนา...', 0, 40, '...')) ?></div>
                </div>
                <?php if ($unread > 0): ?>
                <span class="chat-conv-badge"><?= $unread ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Chat Area -->
    <div class="chat-main" id="chatMain">
        <?php if ($activeConvId > 0): ?>
            <?php
            $activeConv = null;
            foreach ($conversations as $c) {
                if ((int)$c['conversation_id'] === $activeConvId) { $activeConv = $c; break; }
            }
            if ($activeConv):
                $otherName = $role === 'lawyer'
                    ? $activeConv['client_fname'] . ' ' . $activeConv['client_lname']
                    : $activeConv['lawyer_fname'] . ' ' . $activeConv['lawyer_lname'];
            ?>
            <div class="chat-header">
                <button class="chat-back-btn" onclick="document.getElementById('chatSidebar').classList.toggle('chat-sidebar-show')">◀</button>
                <strong><?= htmlspecialchars($otherName) ?></strong>
                <span style="font-size: .78rem; color: #94a3b8; margin-left: 8px;">
                    คดี #<?= (int)$activeConv['request_id'] ?>
                </span>
            </div>
            <div class="chat-messages" id="chatMessages">
                <div style="text-align: center; color: #94a3b8; padding: 20px;">กำลังโหลด...</div>
            </div>
            <div class="chat-input-area">
                <form id="chatForm" onsubmit="return sendMsg(event)">
                    <input type="text" id="chatInput" placeholder="พิมพ์ข้อความ..." autocomplete="off" maxlength="2000">
                    <button type="submit" class="btn btn-primary btn-sm">ส่ง</button>
                </form>
            </div>
            <?php else: ?>
            <div class="chat-empty">
                <p>ไม่พบการสนทนานี้</p>
            </div>
            <?php endif; ?>
        <?php else: ?>
        <div class="chat-empty">
            <div style="font-size: 3rem; margin-bottom: 10px;">💬</div>
            <p>เลือกการสนทนาจากรายการด้านซ้าย</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($activeConvId > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var convId = <?= $activeConvId ?>;
    var csrfToken = <?= json_encode(csrf_token()) ?>;
    var chatMessages = document.getElementById('chatMessages');
    var chatInput = document.getElementById('chatInput');
    var pollTimer = null;
    var lastCount = -1;

    function loadMessages() {
        fetch('/pages/chat.php?ajax=messages&conversation_id=' + convId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    if (data.count !== lastCount) {
                        chatMessages.innerHTML = data.html || '<div style="text-align:center;color:#94a3b8;padding:40px;">ยังไม่มีข้อความ เริ่มสนทนาเลย!</div>';
                        lastCount = data.count;
                        chatMessages.scrollTop = chatMessages.scrollHeight;
                    }
                } else {
                    chatMessages.innerHTML = '<div style="text-align:center;color:#ef4444;padding:40px;">' + (data.msg || 'เกิดข้อผิดพลาด') + '</div>';
                }
            })
            .catch(function() {
                chatMessages.innerHTML = '<div style="text-align:center;color:#ef4444;padding:40px;">ไม่สามารถเชื่อมต่อได้</div>';
            });
    }

    window.sendMsg = function(e) {
        e.preventDefault();
        var msg = chatInput.value.trim();
        if (!msg) return false;

        chatInput.value = '';
        fetch('/pages/chat.php?ajax=send', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'conversation_id=' + convId + '&message=' + encodeURIComponent(msg) + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) loadMessages();
        });
        return false;
    };

    // initial load + polling every 3s
    loadMessages();
    pollTimer = setInterval(loadMessages, 3000);

    // cleanup on page leave
    window.addEventListener('beforeunload', function() {
        if (pollTimer) clearInterval(pollTimer);
    });

    // focus input
    if (chatInput) chatInput.focus();
});
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
