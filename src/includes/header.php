<?php require_once __DIR__ . '/../config/csrf_helper.php'; ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'ระบบจัดการคดีความ' ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
    // กัน FOUC: set margin-left ก่อน render
    (function(){
        var col = localStorage.getItem('sb_collapsed') === '1';
        var w = col ? '60px' : '220px';
        document.documentElement.style.setProperty('--sb-init-ml', w);
    })();
    </script>
</head>
<body style="--sb-init-ml:220px;">

<?php
// ===== ดึงข้อมูล profile สำหรับ sidebar =====
$_sb_photo = null;
$_sb_name  = 'ผู้ใช้งาน';
$_sb_role_label = '';
$_sb_sign_badge = 0;

$_sb_notif_count = 0;
$_sb_chat_count  = 0;

if (isLoggedIn()) {
    $_sb_pdo    = getDB();
    $_sb_uid    = $_SESSION['user_id'];
    $_sb_role   = $_SESSION['role'];
    $_sb_oid    = $_SESSION['office_id'];

    // นับแจ้งเตือนที่ยังไม่อ่าน
    try {
        require_once __DIR__ . '/../config/notification_helper.php';
        $_sb_notif_count = notif_count_unread($_sb_pdo, $_sb_uid);
    } catch (Exception $e) {}

    // นับแชทที่ยังไม่อ่าน (lawyer/client เท่านั้น)
    try {
        if ($_sb_role !== 'admin') {
            require_once __DIR__ . '/../config/chat_helper.php';
            $_sb_chat_count = chat_count_unread($_sb_pdo, $_sb_uid, $_sb_oid);
        }
    } catch (Exception $e) {}

    // ดึง profile photo + ชื่อ
    try {
        if ($_sb_role === 'lawyer') {
            $_sb_row = $_sb_pdo->prepare("SELECT fname, lname, profile_photo FROM lawyer_profiles WHERE user_id=?");
            $_sb_row->execute([$_sb_uid]);
            $_sb_row = $_sb_row->fetch();
            if ($_sb_row) {
                $_sb_name  = $_sb_row['fname'].' '.$_sb_row['lname'];
                $_sb_photo = $_sb_row['profile_photo'] ? '/uploads/lawyer_photos/'.$_sb_row['profile_photo'] : null;
            }
            $_sb_role_label = 'ทนายความ';
        } elseif ($_sb_role === 'client') {
            $_sb_row = $_sb_pdo->prepare("SELECT fname, lname, profile_photo FROM client_profiles WHERE user_id=?");
            $_sb_row->execute([$_sb_uid]);
            $_sb_row = $_sb_row->fetch();
            if ($_sb_row) {
                $_sb_name  = $_sb_row['fname'].' '.$_sb_row['lname'];
                $_sb_photo = $_sb_row['profile_photo'] ? '/uploads/client_photos/'.$_sb_row['profile_photo'] : null;
            }
            $_sb_role_label = 'ลูกความ';
        } elseif ($_sb_role === 'admin') {
            $_sb_name = 'ผู้ดูแลระบบ';
            $_sb_role_label = 'Administrator';
        }
    } catch (Exception $e) {}

    // นับ sign docs badge
    try {
        if ($_sb_role === 'client') {
            $_sb_cid = $_sb_pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
            $_sb_cid->execute([$_sb_uid]); $_sb_cid = $_sb_cid->fetchColumn();
            if ($_sb_cid) {
                $_sb_q = $_sb_pdo->prepare("SELECT COUNT(*) FROM client_sign_docs d JOIN case_requests cr ON d.request_id=cr.request_id WHERE cr.client_id=? AND d.office_id=? AND d.status='pending'");
                $_sb_q->execute([$_sb_cid, $_sb_oid]);
                $_sb_sign_badge = (int)$_sb_q->fetchColumn();
            }
        } elseif ($_sb_role === 'lawyer') {
            $_sb_lid = $_sb_pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
            $_sb_lid->execute([$_sb_uid]); $_sb_lid = $_sb_lid->fetchColumn();
            if ($_sb_lid) {
                $_sb_q = $_sb_pdo->prepare("SELECT COUNT(*) FROM client_sign_docs d JOIN case_requests cr ON d.request_id=cr.request_id WHERE cr.lawyer_id=? AND d.office_id=? AND d.status='pending'");
                $_sb_q->execute([$_sb_lid, $_sb_oid]);
                $_sb_sign_badge = (int)$_sb_q->fetchColumn();
            }
        } elseif ($_sb_role === 'admin') {
            $_sb_q = $_sb_pdo->prepare("SELECT COUNT(*) FROM client_sign_docs WHERE office_id=? AND status='pending'");
            $_sb_q->execute([$_sb_oid]);
            $_sb_sign_badge = (int)$_sb_q->fetchColumn();
        }
    } catch (Exception $e) {}
}

$_sb_cur = basename($_SERVER['PHP_SELF']);
?>

<?php if (isLoggedIn()): ?>
<!-- Mobile hamburger -->
<button class="sb-hamburger" onclick="toggleMobileSidebar()">☰</button>
<div class="sb-overlay" id="sbOverlay" onclick="toggleMobileSidebar()"></div>

<!-- Topbar with global search + bell notification -->
<div class="topbar" id="topbar">
    <div class="topbar-left">
        <!-- Global Search Bar -->
        <div class="gs-wrap" id="gsWrap">
            <div class="gs-input-wrap">
                <span class="gs-icon">🔍</span>
                <input type="text" id="gsInput" class="gs-input"
                       placeholder="ค้นหาทั่วระบบ... (ลูกความ, สัญญา, ศาล)"
                       autocomplete="off" spellcheck="false">
                <kbd class="gs-kbd" id="gsKbd">Ctrl+K</kbd>
            </div>
            <div class="gs-dropdown" id="gsDropdown">
                <div class="gs-dd-empty" id="gsDdEmpty" style="display:none;">
                    <span style="font-size:1.3rem;">🔍</span>
                    <span>ไม่พบผลลัพธ์</span>
                </div>
                <div class="gs-dd-loading" id="gsDdLoading" style="display:none;">
                    <span>กำลังค้นหา...</span>
                </div>
                <div class="gs-dd-results" id="gsDdResults"></div>
            </div>
        </div>
    </div>
    <div class="topbar-right">
        <div class="notif-bell-wrap" id="notifBellWrap">
            <button class="notif-bell-btn" id="notifBellBtn" onclick="toggleNotifDropdown()">
                🔔
                <?php if ($_sb_notif_count > 0): ?>
                <span class="notif-bell-badge" id="notifBellBadge"><?= $_sb_notif_count > 99 ? '99+' : $_sb_notif_count ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dd-header">
                    <strong>การแจ้งเตือน</strong>
                    <a href="/pages/notifications.php?mark_all=1" onclick="markAllNotifRead(event)">อ่านทั้งหมด</a>
                </div>
                <div class="notif-dd-list" id="notifDdList">
                    <div style="text-align:center;padding:20px;color:#94a3b8;">กำลังโหลด...</div>
                </div>
                <a href="/pages/notifications.php" class="notif-dd-footer">ดูทั้งหมด</a>
            </div>
        </div>
    </div>
</div>

<nav class="sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sb-brand">
        <span class="sb-brand-icon">⚖️</span>
        <div class="sb-brand-text sb-hide-collapsed">
            ระบบจัดการคดีความ
            <span><?= htmlspecialchars($_SESSION['office_name'] ?? '') ?></span>
        </div>
    </div>

    <!-- Nav links -->
    <div class="sb-nav">

        <div class="sb-section">เมนูหลัก</div>
        <a href="/pages/dashboard.php" class="sb-link <?= $_sb_cur==='dashboard.php'?'active':'' ?>">
            <span class="sb-link-icon">🏠</span><span class="sb-link-text sb-hide-collapsed">หน้าหลัก</span>
        </a>

        <!-- ลิงก์กลาง (ทุก role) -->
        <a href="/pages/notifications.php" class="sb-link <?= $_sb_cur==='notifications.php'?'active':'' ?>">
            <span class="sb-link-icon">🔔</span><span class="sb-link-text sb-hide-collapsed">การแจ้งเตือน</span>
            <?php if ($_sb_notif_count > 0): ?><span class="sb-badge"><?= $_sb_notif_count > 99 ? '99+' : $_sb_notif_count ?></span><?php endif; ?>
        </a>
        <a href="/pages/calendar.php" class="sb-link <?= $_sb_cur==='calendar.php'?'active':'' ?>">
            <span class="sb-link-icon">📅</span><span class="sb-link-text sb-hide-collapsed">ปฏิทิน</span>
        </a>
        <?php if ($_SESSION['role'] !== 'admin'): ?>
        <a href="/pages/chat.php" class="sb-link <?= $_sb_cur==='chat.php'?'active':'' ?>">
            <span class="sb-link-icon">💬</span><span class="sb-link-text sb-hide-collapsed">ข้อความ</span>
            <?php if ($_sb_chat_count > 0): ?><span class="sb-badge"><?= $_sb_chat_count > 99 ? '99+' : $_sb_chat_count ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if ($_SESSION['role'] === 'admin'): ?>
        <div class="sb-section">จัดการระบบ</div>
        <a href="/pages/lawyers.php"           class="sb-link <?= $_sb_cur==='lawyers.php'?'active':'' ?>"><span class="sb-link-icon">👨‍⚖️</span><span class="sb-link-text sb-hide-collapsed">ทนายความ</span></a>
        <a href="/pages/clients.php"           class="sb-link <?= $_sb_cur==='clients.php'?'active':'' ?>"><span class="sb-link-icon">👤</span><span class="sb-link-text sb-hide-collapsed">ลูกความ</span></a>
        <a href="/pages/users.php"             class="sb-link <?= $_sb_cur==='users.php'?'active':'' ?>"><span class="sb-link-icon">👥</span><span class="sb-link-text sb-hide-collapsed">จัดการผู้ใช้</span></a>
        <a href="/pages/courts.php"            class="sb-link <?= $_sb_cur==='courts.php'?'active':'' ?>"><span class="sb-link-icon">🏛️</span><span class="sb-link-text sb-hide-collapsed">ข้อมูลศาล</span></a>
        <a href="/pages/reports.php"           class="sb-link <?= $_sb_cur==='reports.php'?'active':'' ?>"><span class="sb-link-icon">📊</span><span class="sb-link-text sb-hide-collapsed">รายงาน</span></a>
        <a href="/pages/settings.php"          class="sb-link <?= $_sb_cur==='settings.php'?'active':'' ?>"><span class="sb-link-icon">⚙️</span><span class="sb-link-text sb-hide-collapsed">ตั้งค่า</span></a>
        <a href="/pages/activity_log.php"      class="sb-link <?= $_sb_cur==='activity_log.php'?'active':'' ?>"><span class="sb-link-icon">📜</span><span class="sb-link-text sb-hide-collapsed">ประวัติกิจกรรม</span></a>
        <div class="sb-section">คดีความ</div>
        <a href="/pages/case_requests.php"     class="sb-link <?= $_sb_cur==='case_requests.php'?'active':'' ?>"><span class="sb-link-icon">📋</span><span class="sb-link-text sb-hide-collapsed">คำขอว่าจ้าง</span></a>
        <a href="/pages/contracts.php"         class="sb-link <?= $_sb_cur==='contracts.php'?'active':'' ?>"><span class="sb-link-icon">📄</span><span class="sb-link-text sb-hide-collapsed">สัญญา</span></a>
        <a href="/pages/filings.php"           class="sb-link <?= $_sb_cur==='filings.php'?'active':'' ?>"><span class="sb-link-icon">⚖️</span><span class="sb-link-text sb-hide-collapsed">นัดยื่นฟ้อง</span></a>
        <a href="/pages/hearings.php"          class="sb-link <?= $_sb_cur==='hearings.php'?'active':'' ?>"><span class="sb-link-icon">📅</span><span class="sb-link-text sb-hide-collapsed">นัดขึ้นศาล</span></a>
        <a href="/pages/verdict_appointments.php" class="sb-link <?= $_sb_cur==='verdict_appointments.php'?'active':'' ?>"><span class="sb-link-icon">🗓️</span><span class="sb-link-text sb-hide-collapsed">นัดวันพิพากษา</span></a>
        <a href="/pages/verdicts.php"          class="sb-link <?= $_sb_cur==='verdicts.php'?'active':'' ?>"><span class="sb-link-icon">🔨</span><span class="sb-link-text sb-hide-collapsed">คำพิพากษา</span></a>
        <div class="sb-section">เอกสาร & การเงิน</div>
        <a href="/pages/case_summary.php"      class="sb-link <?= $_sb_cur==='case_summary.php'?'active':'' ?>"><span class="sb-link-icon">📂</span><span class="sb-link-text sb-hide-collapsed">สำนวนคดี</span></a>
        <a href="/pages/case_documents_ext.php" class="sb-link <?= $_sb_cur==='case_documents_ext.php'?'active':'' ?>"><span class="sb-link-icon">🗂️</span><span class="sb-link-text sb-hide-collapsed">เอกสารภายนอก</span></a>
        <a href="/pages/payments.php"          class="sb-link <?= $_sb_cur==='payments.php'?'active':'' ?>"><span class="sb-link-icon">💳</span><span class="sb-link-text sb-hide-collapsed">การชำระเงิน</span></a>
        <a href="/pages/client_sign_docs.php"  class="sb-link <?= $_sb_cur==='client_sign_docs.php'?'active':'' ?>">
            <span class="sb-link-icon">📝</span><span class="sb-link-text sb-hide-collapsed">เอกสารเซ็น</span>
            <?php if ($_sb_sign_badge > 0): ?><span class="sb-badge"><?= $_sb_sign_badge ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if ($_SESSION['role'] === 'lawyer'): ?>
        <div class="sb-section">คดีความ</div>
        <a href="/pages/case_requests.php"     class="sb-link <?= $_sb_cur==='case_requests.php'?'active':'' ?>"><span class="sb-link-icon">📋</span><span class="sb-link-text sb-hide-collapsed">คำขอว่าจ้าง</span></a>
        <a href="/pages/contracts.php"         class="sb-link <?= $_sb_cur==='contracts.php'?'active':'' ?>"><span class="sb-link-icon">📄</span><span class="sb-link-text sb-hide-collapsed">สัญญาของฉัน</span></a>
        <a href="/pages/filings.php"           class="sb-link <?= $_sb_cur==='filings.php'?'active':'' ?>"><span class="sb-link-icon">⚖️</span><span class="sb-link-text sb-hide-collapsed">นัดยื่นฟ้อง</span></a>
        <a href="/pages/hearings.php"          class="sb-link <?= $_sb_cur==='hearings.php'?'active':'' ?>"><span class="sb-link-icon">📅</span><span class="sb-link-text sb-hide-collapsed">นัดขึ้นศาล</span></a>
        <a href="/pages/verdict_appointments.php" class="sb-link <?= $_sb_cur==='verdict_appointments.php'?'active':'' ?>"><span class="sb-link-icon">🗓️</span><span class="sb-link-text sb-hide-collapsed">นัดวันพิพากษา</span></a>
        <a href="/pages/verdicts.php"          class="sb-link <?= $_sb_cur==='verdicts.php'?'active':'' ?>"><span class="sb-link-icon">🔨</span><span class="sb-link-text sb-hide-collapsed">คำพิพากษา</span></a>
        <div class="sb-section">เอกสาร & การเงิน</div>
        <a href="/pages/case_summary.php"      class="sb-link <?= $_sb_cur==='case_summary.php'?'active':'' ?>"><span class="sb-link-icon">📂</span><span class="sb-link-text sb-hide-collapsed">สำนวนคดี</span></a>
        <a href="/pages/case_documents_ext.php" class="sb-link <?= $_sb_cur==='case_documents_ext.php'?'active':'' ?>"><span class="sb-link-icon">🗂️</span><span class="sb-link-text sb-hide-collapsed">เอกสารภายนอก</span></a>
        <a href="/pages/payments.php"          class="sb-link <?= $_sb_cur==='payments.php'?'active':'' ?>"><span class="sb-link-icon">💳</span><span class="sb-link-text sb-hide-collapsed">การชำระเงิน</span></a>
        <a href="/pages/client_sign_docs.php"  class="sb-link <?= $_sb_cur==='client_sign_docs.php'?'active':'' ?>">
            <span class="sb-link-icon">📝</span><span class="sb-link-text sb-hide-collapsed">เอกสารเซ็น</span>
            <?php if ($_sb_sign_badge > 0): ?><span class="sb-badge"><?= $_sb_sign_badge ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if ($_SESSION['role'] === 'client'): ?>
        <div class="sb-section">บริการ</div>
        <a href="/pages/send_request.php"      class="sb-link <?= $_sb_cur==='send_request.php'?'active':'' ?>"><span class="sb-link-icon">📨</span><span class="sb-link-text sb-hide-collapsed">ส่งคำขอว่าจ้าง</span></a>
        <a href="/pages/contract_documents.php" class="sb-link <?= $_sb_cur==='contract_documents.php'?'active':'' ?>"><span class="sb-link-icon">📤</span><span class="sb-link-text sb-hide-collapsed">ส่งเอกสารสัญญา</span></a>
        <a href="/pages/my_cases.php"          class="sb-link <?= $_sb_cur==='my_cases.php'?'active':'' ?>"><span class="sb-link-icon">📁</span><span class="sb-link-text sb-hide-collapsed">คดีของฉัน</span></a>
        <a href="/pages/payments.php"          class="sb-link <?= $_sb_cur==='payments.php'?'active':'' ?>"><span class="sb-link-icon">💳</span><span class="sb-link-text sb-hide-collapsed">ชำระเงิน</span></a>
        <a href="/pages/client_sign_docs.php"  class="sb-link <?= $_sb_cur==='client_sign_docs.php'?'active':'' ?>">
            <span class="sb-link-icon">📝</span><span class="sb-link-text sb-hide-collapsed">เอกสารสำคัญ</span>
            <?php if ($_sb_sign_badge > 0): ?><span class="sb-badge"><?= $_sb_sign_badge ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

    </div>

    </div><!-- end sb-nav -->

    <!-- Profile section (bottom) -->
    <div class="sb-profile-bottom" id="sbProfileBottom">
        <button class="sb-profile-btn" id="sbProfileBtn" onclick="toggleProfileDD()">
            <?php if ($_sb_photo): ?>
            <img src="<?= htmlspecialchars($_sb_photo) ?>" class="sb-avatar" alt="avatar">
            <?php else: ?>
            <div class="sb-avatar-ph"><?= $_SESSION['role']==="admin" ? "🏛️" : ($_SESSION['role']==="lawyer" ? "⚖️" : "👤") ?></div>
            <?php endif; ?>
            <div class="sb-profile-info sb-hide-collapsed">
                <div class="sb-profile-name"><?= htmlspecialchars($_sb_name) ?></div>
                <div class="sb-profile-role"><?= htmlspecialchars($_sb_role_label) ?></div>
            </div>
            <span class="sb-profile-arrow sb-hide-collapsed">▾</span>
        </button>
        <!-- Dropdown (opens upward) -->
        <div class="sb-profile-dd" id="sbProfileDD">
            <a href="/pages/profile.php">👤 โปรไฟล์ของฉัน</a>
            <?php if ($_SESSION['role'] !== 'admin'): ?>
            <button type="button" onclick="openEditProfileFromSidebar()">✏️ แก้ไขข้อมูลส่วนตัว</button>
            <?php endif; ?>
            <div class="sb-dd-divider"></div>
            <a href="/pages/logout.php" style="color:#fca5a5;">🚪 ออกจากระบบ</a>
        </div>
    </div>

    <!-- Collapse toggle -->
    <button class="sb-toggle" onclick="toggleSidebar()" id="sbToggle">
        <span class="sb-toggle-icon" id="sbToggleIcon">◀</span>
        <span class="sb-hide-collapsed" id="sbToggleText">ย่อเมนู</span>
    </button>

</nav>

<script>
var sbCollapsed = (localStorage.getItem('sb_collapsed') === '1');

var SB_W     = 220;   // px ตอนขยาย
var SB_W_COL = 60;    // px ตอนย่อ

function applySidebarState() {
    var sb   = document.getElementById('sidebar');
    var main = document.getElementById('sbMain');
    var icon = document.getElementById('sbToggleIcon');
    var tb   = document.getElementById('topbar');
    if (!sb) return;
    if (sbCollapsed) {
        sb.style.width      = SB_W_COL + 'px';
        sb.style.minWidth   = SB_W_COL + 'px';
        sb.classList.add('collapsed');
        if (main) main.style.marginLeft = SB_W_COL + 'px';
        if (tb)   tb.style.left = SB_W_COL + 'px';
        if (icon) icon.textContent = '▶';
    } else {
        sb.style.width      = SB_W + 'px';
        sb.style.minWidth   = SB_W + 'px';
        sb.classList.remove('collapsed');
        if (main) main.style.marginLeft = SB_W + 'px';
        if (tb)   tb.style.left = SB_W + 'px';
        if (icon) icon.textContent = '◀';
    }
}

function toggleSidebar() {
    sbCollapsed = !sbCollapsed;
    localStorage.setItem('sb_collapsed', sbCollapsed ? '1' : '0');
    applySidebarState();
    // ปิด dropdown ถ้าเปิดอยู่
    document.getElementById('sbProfileDD').classList.remove('show');
    document.getElementById('sbProfileBtn').classList.remove('open');
}

function toggleProfileDD() {
    var dd  = document.getElementById('sbProfileDD');
    var btn = document.getElementById('sbProfileBtn');
    // ถ้าย่อ sidebar อยู่ ให้ขยายก่อน
    if (sbCollapsed) { sbCollapsed=false; localStorage.setItem('sb_collapsed','0'); applySidebarState(); }
    var show = !dd.classList.contains('show');
    dd.classList.toggle('show', show);
    btn.classList.toggle('open', show);
}

document.addEventListener('click', function(e) {
    var pb = document.getElementById('sbProfileBottom');
    if (pb && !pb.contains(e.target)) {
        var dd = document.getElementById('sbProfileDD');
        var btn = document.getElementById('sbProfileBtn');
        if (dd) dd.classList.remove('show');
        if (btn) btn.classList.remove('open');
    }
});

function toggleMobileSidebar() {
    document.getElementById('sidebar').classList.toggle('mobile-open');
    document.getElementById('sbOverlay').classList.toggle('show');
}

function openEditProfileFromSidebar() {
    var dd = document.getElementById('sbProfileDD');
    if (dd) dd.classList.remove('show');
    var role = <?= json_encode($_SESSION['role'] ?? '') ?>;
    if (window.location.pathname.includes('dashboard.php') || window.location.pathname.includes('profile.php')) {
        if (role === 'lawyer' && typeof openModal==='function') openModal();
        else if (role === 'client' && typeof openClientModal==='function') openClientModal();
    } else {
        window.location.href = '/pages/dashboard.php?edit=1';
    }
}

// apply state on load
applySidebarState();
document.addEventListener('DOMContentLoaded', applySidebarState);
window.addEventListener('load', applySidebarState);

// --- Notification Bell Dropdown ---
function toggleNotifDropdown() {
    var dd = document.getElementById('notifDropdown');
    if (!dd) return;
    var show = !dd.classList.contains('show');
    dd.classList.toggle('show', show);
    if (show) loadNotifDropdown();
}

function loadNotifDropdown() {
    fetch('/pages/notifications.php?ajax=recent')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                var list = document.getElementById('notifDdList');
                if (list) list.innerHTML = data.html || '<div style="text-align:center;padding:20px;color:#94a3b8;">ไม่มีการแจ้งเตือน</div>';
                var badge = document.getElementById('notifBellBadge');
                if (badge) {
                    if (data.count > 0) { badge.textContent = data.count > 99 ? '99+' : data.count; badge.style.display = ''; }
                    else { badge.style.display = 'none'; }
                }
            }
        }).catch(function(){});
}

function markAllNotifRead(e) {
    e.preventDefault();
    fetch('/pages/notifications.php?ajax=mark_all_read', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= csrf_token() ?>'
    }).then(function() { loadNotifDropdown(); });
}

// ปิด dropdown เมื่อคลิกข้างนอก
document.addEventListener('click', function(e) {
    var wrap = document.getElementById('notifBellWrap');
    if (wrap && !wrap.contains(e.target)) {
        var dd = document.getElementById('notifDropdown');
        if (dd) dd.classList.remove('show');
    }
});

<?php if (isset($_GET['edit'])): ?>
window.addEventListener('load', function(){
    var role = <?= json_encode($_SESSION['role'] ?? '') ?>;
    if (role === 'lawyer' && typeof openModal==='function') openModal();
    else if (role === 'client' && typeof openClientModal==='function') openClientModal();
});
<?php endif; ?>

// ======== Global Search ========
(function(){
    var input    = document.getElementById('gsInput');
    var wrap     = document.getElementById('gsWrap');
    var dropdown = document.getElementById('gsDropdown');
    var results  = document.getElementById('gsDdResults');
    var loading  = document.getElementById('gsDdLoading');
    var empty    = document.getElementById('gsDdEmpty');
    var timer    = null;

    if (!input) return;

    function showDD() { dropdown.classList.add('show'); }
    function hideDD() { dropdown.classList.remove('show'); }

    input.addEventListener('input', function() {
        var q = this.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { hideDD(); return; }
        loading.style.display = 'block';
        empty.style.display   = 'none';
        results.innerHTML     = '';
        showDD();
        timer = setTimeout(function(){ doSearch(q); }, 300);
    });

    input.addEventListener('focus', function() {
        if (this.value.trim().length >= 2 && results.innerHTML) showDD();
    });

    function doSearch(q) {
        fetch('/api/global_search.php?q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(data){
            loading.style.display = 'none';
            if (!data.ok || !data.grouped || Object.keys(data.grouped).length === 0) {
                empty.style.display = 'block';
                results.innerHTML   = '';
                return;
            }
            empty.style.display = 'none';
            var html = '';
            for (var type in data.grouped) {
                var g = data.grouped[type];
                html += '<div class="gs-group">';
                html += '<div class="gs-group-label">' + g.icon + ' ' + g.label + '</div>';
                for (var i = 0; i < g.items.length; i++) {
                    var it = g.items[i];
                    html += '<a href="' + escHtml(it.link) + '" class="gs-item">';
                    html += '<div class="gs-item-title">' + escHtml(it.title) + '</div>';
                    if (it.subtitle) html += '<div class="gs-item-sub">' + escHtml(it.subtitle) + '</div>';
                    html += '</a>';
                }
                html += '</div>';
            }
            html += '<div class="gs-dd-footer">พบ ' + data.count + ' ผลลัพธ์</div>';
            results.innerHTML = html;
        })
        .catch(function(){
            loading.style.display = 'none';
            results.innerHTML = '<div style="padding:12px;color:#dc2626;text-align:center;">เกิดข้อผิดพลาด</div>';
        });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // ปิด dropdown เมื่อคลิกข้างนอก
    document.addEventListener('click', function(e) {
        if (wrap && !wrap.contains(e.target)) hideDD();
    });

    // Ctrl+K shortcut
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            input.focus();
            input.select();
        }
        if (e.key === 'Escape' && document.activeElement === input) {
            input.blur();
            hideDD();
        }
    });
})();
</script>

<style>
/* ===== Global Search Styles ===== */
.topbar-left { display:flex; align-items:center; flex:1; min-width:0; }

.gs-wrap { position:relative; width:100%; max-width:420px; }
.gs-input-wrap {
    position:relative; display:flex; align-items:center;
    background:#f1f5f9; border:1px solid transparent; border-radius:10px;
    transition: all .2s;
}
.gs-input-wrap:focus-within {
    background:#fff; border-color:#2d5a8a; box-shadow:0 0 0 3px rgba(45,90,138,.12);
}
.gs-icon { position:absolute; left:12px; color:#94a3b8; font-size:.9rem; pointer-events:none; }
.gs-input {
    width:100%; padding:8px 70px 8px 36px; border:none; background:transparent;
    font-size:.87rem; outline:none; color:#1e293b; font-family:inherit;
}
.gs-input::placeholder { color:#94a3b8; }
.gs-kbd {
    position:absolute; right:8px;
    padding:2px 7px; background:#e2e8f0; color:#64748b; border-radius:4px;
    font-size:.68rem; font-family:monospace; font-weight:600; pointer-events:none;
    border:1px solid #cbd5e1;
}

/* Dropdown */
.gs-dropdown {
    display:none; position:absolute; top:calc(100% + 6px); left:0; right:0;
    background:#fff; border:1px solid #e2e8f0; border-radius:12px;
    box-shadow:0 12px 40px rgba(0,0,0,.15); z-index:10001;
    max-height:420px; overflow-y:auto;
}
.gs-dropdown.show { display:block; }
.gs-dd-loading, .gs-dd-empty {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    padding:24px; color:#94a3b8; font-size:.85rem; gap:6px;
}

/* Groups */
.gs-group { border-bottom:1px solid #f1f5f9; }
.gs-group:last-child { border-bottom:none; }
.gs-group-label {
    padding:8px 14px 4px; font-size:.72rem; font-weight:700;
    color:#64748b; text-transform:uppercase; letter-spacing:.3px;
}
.gs-item {
    display:block; padding:8px 14px; text-decoration:none; color:#1e293b;
    transition:background .1s; cursor:pointer;
}
.gs-item:hover { background:#f8fafc; }
.gs-item-title { font-size:.85rem; font-weight:600; }
.gs-item-sub { font-size:.75rem; color:#94a3b8; margin-top:1px; }

.gs-dd-footer {
    padding:8px 14px; text-align:center; font-size:.75rem; color:#94a3b8;
    border-top:1px solid #f1f5f9;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .gs-wrap { max-width:200px; }
    .gs-kbd { display:none; }
    .gs-input { padding-right:10px; }
    .gs-dropdown { left:-40px; right:-80px; min-width:300px; }
}
@media (max-width: 480px) {
    .gs-wrap { max-width:140px; }
    .gs-dropdown { left:-60px; right:-100px; min-width:280px; }
}
</style>

<?php endif; ?>
<div class="sb-main" id="sbMain">
<div class="sb-main-content">