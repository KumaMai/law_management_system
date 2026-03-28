<?php
// users.php — Admin: จัดการผู้ใช้ทุก role + Reset Password
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/activity_log_helper.php';
requireRole('admin');

$pdo      = getDB();
$officeId = $_SESSION['office_id'];
$myUserId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    // ตรวจว่า user อยู่ใน office เดียวกัน
    $chk = $pdo->prepare("SELECT user_id, role_id FROM users u JOIN roles r ON u.role_id=r.role_id WHERE u.user_id=? AND u.office_id=?");
    $chk->execute([$userId, $officeId]);
    $targetUser = $chk->fetch();

    if (!$targetUser && $action !== '') {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบผู้ใช้หรือไม่มีสิทธิ์']); exit; }
        header('Location: /pages/users.php'); exit;
    }

    // ---- Reset Password ----
    if ($action === 'reset_password') {
        $newPw  = $_POST['new_password'] ?? '';
        $error  = '';

        if ($userId === $myUserId) {
            $error = 'ไม่สามารถรีเซ็ตรหัสผ่านของตัวเองในหน้านี้ได้';
        } elseif (strlen($newPw) < 8 || !preg_match('/[A-Z]/', $newPw) || !preg_match('/[a-z]/', $newPw) || !preg_match('/[0-9]/', $newPw)) {
            $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัว มีตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก และตัวเลข';
        }

        if ($error) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$error]); exit; }
        } else {
            $hash = password_hash($newPw, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE user_id=?")->execute([$hash, $userId]);
            audit_log($pdo, $officeId, $myUserId, 'update', 'user', $userId, 'รีเซ็ตรหัสผ่านผู้ใช้ #'.$userId);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'รีเซ็ตรหัสผ่านเรียบร้อยแล้ว']); exit; }
        }
    }

    // ---- Toggle Status ----
    if ($action === 'toggle_status') {
        if ($userId === $myUserId) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถระงับบัญชีของตัวเองได้']); exit; }
        } else {
            $newStatus = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
            $pdo->prepare("UPDATE users SET status=? WHERE user_id=?")->execute([$newStatus, $userId]);
            // sync lawyer/client profile status ด้วย
            $pdo->prepare("UPDATE lawyer_profiles SET status=? WHERE user_id=?")->execute([$newStatus, $userId]);
            audit_log($pdo, $officeId, $myUserId, 'update', 'user', $userId, ($newStatus==='active'?'เปิดใช้งาน':'ระงับ').'บัญชีผู้ใช้ #'.$userId);
            $msg = $newStatus === 'active' ? '✅ เปิดใช้งานบัญชีแล้ว' : '🔒 ระงับบัญชีแล้ว';
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>$msg]); exit; }
        }
    }

    // ---- Delete User ----
    if ($action === 'delete_user') {
        if ($userId === $myUserId) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถลบบัญชีของตัวเองได้']); exit; }
        } elseif (!$targetUser) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบผู้ใช้หรือไม่มีสิทธิ์']); exit; }
        } else {
            // ตรวจว่ามีคดี active ผ่าน lawyer_profiles หรือ client_profiles
            $hasActiveLawyer = $pdo->prepare("SELECT COUNT(*) FROM case_requests cr JOIN contracts c ON c.request_id=cr.request_id JOIN lawyer_profiles lp ON cr.lawyer_id=lp.lawyer_id WHERE lp.user_id=? AND c.status='active'");
            $hasActiveLawyer->execute([$userId]);
            $hasActiveClient = $pdo->prepare("SELECT COUNT(*) FROM case_requests cr JOIN contracts c ON c.request_id=cr.request_id JOIN client_profiles cp ON cr.client_id=cp.client_id WHERE cp.user_id=? AND c.status='active'");
            $hasActiveClient->execute([$userId]);
            if ((int)$hasActiveLawyer->fetchColumn() > 0 || (int)$hasActiveClient->fetchColumn() > 0) {
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถลบได้ ผู้ใช้มีคดีที่กำลังดำเนินการอยู่']); exit; }
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM lawyer_profiles WHERE user_id=?")->execute([$userId]);
                    $pdo->prepare("DELETE FROM client_profiles WHERE user_id=?")->execute([$userId]);
                    $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$userId]);
                    $pdo->commit();
                    audit_log($pdo, $officeId, $myUserId, 'delete', 'user', $userId, 'ลบบัญชีผู้ใช้ #'.$userId);
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'ลบบัญชีผู้ใช้เรียบร้อยแล้ว']); exit; }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถลบได้ อาจมีข้อมูลที่เชื่อมโยงอยู่']); exit; }
                }
            }
        }
    }

    header('Location: /pages/users.php'); exit;
}

// ==============================
// Fetch all users in this office
// ==============================
$users = $pdo->prepare("
    SELECT u.user_id, u.username, u.email, u.status, u.created_at,
           r.role_name,
           COALESCE(
               CONCAT(lp.fname,' ',lp.lname),
               CONCAT(cp.fname,' ',cp.lname),
               'ผู้ดูแลระบบ'
           ) AS full_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN lawyer_profiles lp ON lp.user_id = u.user_id
    LEFT JOIN client_profiles cp ON cp.user_id = u.user_id
    WHERE u.office_id = ?
    ORDER BY FIELD(r.role_name,'admin','lawyer','client'), u.created_at DESC
");
$users->execute([$officeId]);
$allUsers  = $users->fetchAll();
$pageTitle = 'จัดการผู้ใช้';
include '../includes/header.php';

$roleTH    = ['admin'=>'ผู้ดูแลระบบ','lawyer'=>'ทนายความ','client'=>'ลูกความ'];
$roleEmoji = ['admin'=>'🏛️','lawyer'=>'⚖️','client'=>'👤'];
$totalActive   = count(array_filter($allUsers, fn($u) => $u['status']==='active'));
$totalInactive = count($allUsers) - $totalActive;
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--navy:#1a3a5c;--navy2:#2d5a8a;--border:#e2e8f0;--muted:#94a3b8;}
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px;}
.stat-box{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center;}
.stat-num{font-size:1.7rem;font-weight:900;color:var(--navy);line-height:1;}
.stat-lbl{font-size:.75rem;color:var(--muted);margin-top:3px;}
.toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px;}
.action-btn{padding:4px 10px;border-radius:6px;font-size:.74rem;font-weight:700;border:none;cursor:pointer;transition:.15s;}
.btn-reset{background:#eff6ff;color:#1d4ed8;}.btn-reset:hover{background:#1d4ed8;color:#fff;}
.btn-suspend{background:#fef3c7;color:#92400e;}.btn-suspend:hover{background:#d97706;color:#fff;}
.btn-activate{background:#d1fae5;color:#065f46;}.btn-activate:hover{background:#059669;color:#fff;}
.badge-active{background:#d1fae5;color:#065f46;padding:2px 9px;border-radius:20px;font-size:.71rem;font-weight:700;}
.badge-inactive{background:#fee2e2;color:#991b1b;padding:2px 9px;border-radius:20px;font-size:.71rem;font-weight:700;}
.role-chip{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.71rem;font-weight:700;}
.role-admin{background:#ede9fe;color:#5b21b6;}
.role-lawyer{background:#dbeafe;color:#1d4ed8;}
.role-client{background:#f0fdf4;color:#15803d;}
.s-wrap{position:relative;flex:1;max-width:360px;}
.s-wrap input{width:100%;padding:8px 12px 8px 34px;border:1px solid var(--border);border-radius:8px;font-size:.87rem;outline:none;}
.s-wrap input:focus{border-color:var(--navy2);}
.s-wrap::before{content:"🔍";position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:.85rem;}
.filter-btn{padding:6px 14px;border-radius:7px;font-size:.8rem;font-weight:600;border:1px solid var(--border);background:#fff;cursor:pointer;transition:.15s;color:#374151;}
.filter-btn.active{background:var(--navy);color:#fff;border-color:var(--navy);}
.me-row{background:#f0f9ff !important;}
</style>

<div class="card">
    <h2 style="margin:0 0 16px;">👥 จัดการผู้ใช้ทั้งหมด</h2>

    <!-- Stats -->
    <div class="stat-row">
        <div class="stat-box">
            <div class="stat-num"><?= count($allUsers) ?></div>
            <div class="stat-lbl">ผู้ใช้ทั้งหมด</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" style="color:#059669;"><?= $totalActive ?></div>
            <div class="stat-lbl">ใช้งานได้</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" style="color:#dc2626;"><?= $totalInactive ?></div>
            <div class="stat-lbl">ระงับแล้ว</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" style="color:#7c3aed;"><?= count(array_filter($allUsers, fn($u) => $u['role_name']==='lawyer')) ?></div>
            <div class="stat-lbl">ทนายความ</div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div style="display:flex;gap:7px;flex-wrap:wrap;">
            <button class="filter-btn active" id="filter-all"    onclick="setFilter('all',this)">ทั้งหมด</button>
            <button class="filter-btn" id="filter-admin"  onclick="setFilter('admin',this)">🏛️ Admin</button>
            <button class="filter-btn" id="filter-lawyer" onclick="setFilter('lawyer',this)">⚖️ ทนาย</button>
            <button class="filter-btn" id="filter-client" onclick="setFilter('client',this)">👤 ลูกความ</button>
            <button class="filter-btn" id="filter-inactive" onclick="setFilter('inactive',this)">🔒 ระงับแล้ว</button>
        </div>
        <div class="s-wrap"><input type="text" placeholder="ค้นหาชื่อ, username, อีเมล..." oninput="filterTable(this.value)"></div>
    </div>

    <div class="table-wrap">
    <table id="usersTable">
        <thead>
            <tr><th>#</th><th>ชื่อ-นามสกุล</th><th>Username</th><th>อีเมล</th>
            <th>บทบาท</th><th>สถานะ</th><th>สมัครเมื่อ</th><th>จัดการ</th></tr>
        </thead>
        <tbody>
        <?php foreach ($allUsers as $u):
            $isMe     = ($u['user_id'] === $myUserId);
            $isActive = ($u['status'] === 'active');
        ?>
        <tr data-search="<?= strtolower(htmlspecialchars($u['full_name'].' '.($u['username']??'').' '.$u['email'])) ?>"
            data-role="<?= $u['role_name'] ?>"
            data-status="<?= $u['status'] ?>"
            class="<?= $isMe ? 'me-row' : '' ?>"
            style="<?= !$isActive ? 'opacity:.55;' : '' ?>">
            <td><?= $u['user_id'] ?></td>
            <td style="font-weight:600;">
                <?= htmlspecialchars($u['full_name']) ?>
                <?php if ($isMe): ?><span style="font-size:.7rem;background:#dbeafe;color:#1d4ed8;padding:1px 7px;border-radius:10px;margin-left:4px;font-weight:700;">คุณ</span><?php endif; ?>
            </td>
            <td><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:.83rem;"><?= htmlspecialchars($u['username'] ?? '—') ?></code></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td>
                <span class="role-chip role-<?= $u['role_name'] ?>">
                    <?= $roleEmoji[$u['role_name']] ?> <?= $roleTH[$u['role_name']] ?? $u['role_name'] ?>
                </span>
            </td>
            <td><span class="badge-<?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? 'ใช้งานได้' : 'ระงับแล้ว' ?></span></td>
            <td style="font-size:.8rem;color:#94a3b8;"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
            <td style="white-space:nowrap;">
                <?php if (!$isMe): ?>
                <button class="action-btn btn-reset"
                    onclick='openResetModal(<?= $u["user_id"] ?>, <?= json_encode($u["full_name"], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($u["username"] ?? "", JSON_UNESCAPED_UNICODE) ?>)'>
                    🔑 Reset PW
                </button>
                <?php if ($isActive): ?>
                <button class="action-btn btn-suspend"
                    onclick='toggleStatus(<?= $u["user_id"] ?>, "inactive", <?= json_encode($u["full_name"], JSON_UNESCAPED_UNICODE) ?>)'>🔒 ระงับ</button>
                <?php else: ?>
                <button class="action-btn btn-activate"
                    onclick='toggleStatus(<?= $u["user_id"] ?>, "active", <?= json_encode($u["full_name"], JSON_UNESCAPED_UNICODE) ?>)'>✅ เปิดใช้</button>
                <?php endif; ?>
                <?php if ($u['role_name'] !== 'admin'): ?>
                <button class="action-btn" style="background:#fee2e2;color:#991b1b;"
                    onclick='deleteUser(<?= $u["user_id"] ?>, <?= json_encode($u["full_name"], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($u["role_name"], JSON_UNESCAPED_UNICODE) ?>)'>🗑️ ลบ</button>
                <?php endif; ?>
                <?php else: ?>
                <span style="font-size:.75rem;color:#94a3b8;">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Modal: Reset Password -->
<div id="resetModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3 style="margin:0;color:#1a3a5c;">🔑 รีเซ็ตรหัสผ่าน</h3>
        <button onclick="closeModal('resetModal')" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">✕</button>
    </div>
    <div id="reset-user-info" style="background:#f0f4f8;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.87rem;color:#1a3a5c;font-weight:600;"></div>
    <form id="resetForm">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" id="reset-uid">
        <div class="form-group" style="margin-bottom:14px;">
            <label>รหัสผ่านใหม่ <span style="color:red">*</span></label>
            <input type="password" name="new_password" id="reset-pw" placeholder="≥8 ตัว มี A-Z a-z 0-9" required
                style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;outline:none;">
        </div>
        <div class="form-group" style="margin-bottom:14px;">
            <label>ยืนยันรหัสผ่านใหม่ <span style="color:red">*</span></label>
            <input type="password" id="reset-pw2" placeholder="พิมพ์รหัสผ่านอีกครั้ง" required
                style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;outline:none;">
            <small id="pw-match-hint" style="display:none;font-size:.78rem;margin-top:4px;"></small>
        </div>
        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:9px 13px;margin-bottom:14px;font-size:.8rem;color:#92400e;">
            ⚠️ ผู้ใช้จะต้องใช้รหัสผ่านใหม่นี้ในการเข้าสู่ระบบครั้งถัดไป
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="closeModal('resetModal')" style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
            <button type="submit" id="resetBtn" style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">🔑 รีเซ็ตรหัสผ่าน</button>
        </div>
    </form>
</div></div>

<script>
let _curFilter = 'all';
function setFilter(f, btn) {
    _curFilter = f;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilter();
}
function applyFilter() {
    const q = document.querySelector('.s-wrap input').value.toLowerCase().trim();
    document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
        const matchRole   = _curFilter === 'all' || (_curFilter === 'inactive' ? tr.dataset.status === 'inactive' : tr.dataset.role === _curFilter);
        const matchSearch = !q || tr.dataset.search.includes(q);
        tr.style.display = (matchRole && matchSearch) ? '' : 'none';
    });
}
function filterTable(q) { applyFilter(); }

function closeModal(id) { document.getElementById(id).style.display = 'none'; }
document.getElementById('resetModal').addEventListener('click', function(e) { if(e.target===this) closeModal('resetModal'); });

function openResetModal(uid, name, username) {
    document.getElementById('reset-uid').value = uid;
    document.getElementById('reset-user-info').innerHTML = `👤 ${name}` + (username ? ` <span style="color:#94a3b8;font-weight:400;">(@${username})</span>` : '');
    document.getElementById('resetForm').querySelector('[name="new_password"]').value = '';
    document.getElementById('reset-pw2').value = '';
    document.getElementById('pw-match-hint').style.display = 'none';
    document.getElementById('resetModal').style.display = 'flex';
    setTimeout(() => document.getElementById('reset-pw').focus(), 100);
}

// Password match check
document.getElementById('reset-pw2').addEventListener('input', function() {
    const hint = document.getElementById('pw-match-hint');
    const pw1  = document.getElementById('reset-pw').value;
    if (!this.value) { hint.style.display = 'none'; return; }
    hint.style.display = 'block';
    if (this.value === pw1) {
        hint.textContent = '✅ รหัสผ่านตรงกัน';
        hint.style.color = '#059669';
    } else {
        hint.textContent = '❌ รหัสผ่านไม่ตรงกัน';
        hint.style.color = '#dc2626';
    }
});

document.getElementById('resetForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const pw1 = document.getElementById('reset-pw').value;
    const pw2 = document.getElementById('reset-pw2').value;
    if (pw1 !== pw2) {
        Swal.fire({ icon:'error', title:'รหัสผ่านไม่ตรงกัน', text:'กรุณากรอกรหัสผ่านให้ตรงกันทั้งสองช่อง', confirmButtonColor:'#1a3a5c' });
        return;
    }
    const btn = document.getElementById('resetBtn'), orig = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳...';
    try {
        const res  = await fetch(location.pathname, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: new FormData(this) });
        const data = await res.json();
        closeModal('resetModal');
        if (data.ok) {
            await Swal.fire({ icon:'success', title:'สำเร็จ!', text:data.msg, confirmButtonColor:'#1a3a5c', timer:1800, timerProgressBar:true, showConfirmButton:false });
        } else {
            Swal.fire({ icon:'error', title:'ผิดพลาด', text:data.msg, confirmButtonColor:'#1a3a5c' });
        }
    } catch { Swal.fire({ icon:'error', title:'ผิดพลาด', text:'เชื่อมต่อไม่ได้', confirmButtonColor:'#1a3a5c' }); }
    finally { btn.disabled = false; btn.textContent = orig; }
});

async function toggleStatus(uid, status, name) {
    const on = status === 'active';
    const r = await Swal.fire({
        icon: on ? 'question' : 'warning',
        title: on ? 'เปิดใช้งานบัญชี?' : 'ระงับบัญชีผู้ใช้?',
        html: on ? `เปิดใช้งาน <b>"${name}"</b>` : `ระงับบัญชี <b>"${name}"</b><br><small style="color:#94a3b8">ผู้ใช้จะเข้าสู่ระบบไม่ได้</small>`,
        showCancelButton: true,
        confirmButtonText: on ? '✅ เปิดใช้งาน' : '🔒 ระงับ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: on ? '#059669' : '#d97706',
        cancelButtonColor: '#94a3b8',
    });
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fd.append('action', 'toggle_status');
    fd.append('user_id', uid);
    fd.append('new_status', status);
    try {
        const res  = await fetch(location.pathname, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
        const data = await res.json();
        if (data.ok) {
            await Swal.fire({ icon:'success', title:'สำเร็จ!', text:data.msg, confirmButtonColor:'#1a3a5c', timer:1800, timerProgressBar:true, showConfirmButton:false });
            location.reload();
        } else {
            Swal.fire({ icon:'error', title:'ผิดพลาด', text:data.msg, confirmButtonColor:'#1a3a5c' });
        }
    } catch { Swal.fire({ icon:'error', title:'ผิดพลาด', text:'เชื่อมต่อไม่ได้', confirmButtonColor:'#1a3a5c' }); }
}

async function deleteUser(uid, name, role) {
    const roleTH = { lawyer: 'ทนายความ', client: 'ลูกความ' };
    const r = await Swal.fire({
        icon: 'warning',
        title: `ลบ${roleTH[role] || 'ผู้ใช้'}?`,
        html: `ลบบัญชี <b>"${name}"</b> ออกจากระบบ?<br><small style="color:#dc2626">จะลบได้เฉพาะผู้ใช้ที่ไม่มีคดี active อยู่</small>`,
        showCancelButton: true,
        confirmButtonText: '🗑️ ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#94a3b8',
    });
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fd.append('action', 'delete_user');
    fd.append('user_id', uid);
    try {
        const res  = await fetch(location.pathname, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
        const data = await res.json();
        if (data.ok) {
            await Swal.fire({ icon:'success', title:'สำเร็จ!', text:data.msg, confirmButtonColor:'#1a3a5c', timer:1800, timerProgressBar:true, showConfirmButton:false });
            location.reload();
        } else {
            Swal.fire({ icon:'error', title:'ลบไม่ได้', text:data.msg, confirmButtonColor:'#1a3a5c' });
        }
    } catch { Swal.fire({ icon:'error', title:'ผิดพลาด', text:'เชื่อมต่อไม่ได้', confirmButtonColor:'#1a3a5c' }); }
}
</script>
<?php include '../includes/footer.php'; ?>