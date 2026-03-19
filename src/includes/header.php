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

if (isLoggedIn()) {
    $_sb_pdo    = getDB();
    $_sb_uid    = $_SESSION['user_id'];
    $_sb_role   = $_SESSION['role'];
    $_sb_oid    = $_SESSION['office_id'];

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

        <?php if ($_SESSION['role'] === 'admin'): ?>
        <div class="sb-section">จัดการระบบ</div>
        <a href="/pages/lawyers.php"           class="sb-link <?= $_sb_cur==='lawyers.php'?'active':'' ?>"><span class="sb-link-icon">👨‍⚖️</span><span class="sb-link-text sb-hide-collapsed">ทนายความ</span></a>
        <a href="/pages/clients.php"           class="sb-link <?= $_sb_cur==='clients.php'?'active':'' ?>"><span class="sb-link-icon">👤</span><span class="sb-link-text sb-hide-collapsed">ลูกความ</span></a>
        <a href="/pages/users.php"             class="sb-link <?= $_sb_cur==='users.php'?'active':'' ?>"><span class="sb-link-icon">👥</span><span class="sb-link-text sb-hide-collapsed">จัดการผู้ใช้</span></a>
        <a href="/pages/courts.php"            class="sb-link <?= $_sb_cur==='courts.php'?'active':'' ?>"><span class="sb-link-icon">🏛️</span><span class="sb-link-text sb-hide-collapsed">ข้อมูลศาล</span></a>
        <a href="/pages/reports.php"           class="sb-link <?= $_sb_cur==='reports.php'?'active':'' ?>"><span class="sb-link-icon">📊</span><span class="sb-link-text sb-hide-collapsed">รายงาน</span></a>
        <a href="/pages/settings.php"          class="sb-link <?= $_sb_cur==='settings.php'?'active':'' ?>"><span class="sb-link-icon">⚙️</span><span class="sb-link-text sb-hide-collapsed">ตั้งค่า</span></a>
        <div class="sb-section">คดีความ</div>
        <a href="/pages/case_requests.php"     class="sb-link <?= $_sb_cur==='case_requests.php'?'active':'' ?>"><span class="sb-link-icon">📋</span><span class="sb-link-text sb-hide-collapsed">คำขอว่าจ้าง</span></a>
        <a href="/pages/contracts.php"         class="sb-link <?= $_sb_cur==='contracts.php'?'active':'' ?>"><span class="sb-link-icon">📄</span><span class="sb-link-text sb-hide-collapsed">สัญญา</span></a>
        <a href="/pages/filings.php"           class="sb-link <?= $_sb_cur==='filings.php'?'active':'' ?>"><span class="sb-link-icon">⚖️</span><span class="sb-link-text sb-hide-collapsed">การยื่นฟ้อง</span></a>
        <a href="/pages/hearings.php"          class="sb-link <?= $_sb_cur==='hearings.php'?'active':'' ?>"><span class="sb-link-icon">📅</span><span class="sb-link-text sb-hide-collapsed">นัดขึ้นศาล</span></a>
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
        <a href="/pages/filings.php"           class="sb-link <?= $_sb_cur==='filings.php'?'active':'' ?>"><span class="sb-link-icon">⚖️</span><span class="sb-link-text sb-hide-collapsed">การยื่นฟ้อง</span></a>
        <a href="/pages/hearings.php"          class="sb-link <?= $_sb_cur==='hearings.php'?'active':'' ?>"><span class="sb-link-icon">📅</span><span class="sb-link-text sb-hide-collapsed">นัดขึ้นศาล</span></a>
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
    if (!sb) return;
    if (sbCollapsed) {
        sb.style.width      = SB_W_COL + 'px';
        sb.style.minWidth   = SB_W_COL + 'px';
        sb.classList.add('collapsed');
        if (main) main.style.marginLeft = SB_W_COL + 'px';
        if (icon) icon.textContent = '▶';
    } else {
        sb.style.width      = SB_W + 'px';
        sb.style.minWidth   = SB_W + 'px';
        sb.classList.remove('collapsed');
        if (main) main.style.marginLeft = SB_W + 'px';
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

<?php if (isset($_GET['edit'])): ?>
window.addEventListener('load', function(){
    var role = <?= json_encode($_SESSION['role'] ?? '') ?>;
    if (role === 'lawyer' && typeof openModal==='function') openModal();
    else if (role === 'client' && typeof openClientModal==='function') openClientModal();
});
<?php endif; ?>
</script>

<?php endif; ?>
<div class="sb-main" id="sbMain">
<div class="sb-main-content">