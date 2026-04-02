<?php
// clients.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/activity_log_helper.php';
requireRole('admin');

$pdo      = getDB();
$officeId = $_SESSION['office_id'];

function maskCitizenId(?string $id): string {
    if (!$id || strlen($id) !== 13) return $id ?? '—';
    return substr($id, 0, 3) . str_repeat('•', 8) . substr($id, -2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $action = $_POST['action'] ?? 'add';

    // ---- เพิ่มลูกความ ----
    if ($action === 'add') {
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $fname     = trim($_POST['fname'] ?? '');
        $lname     = trim($_POST['lname'] ?? '');
        $citizenId = trim($_POST['citizen_id'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $address   = trim($_POST['address'] ?? '');
        $error     = '';

        if (!$username || !$email || !$password || !$fname || !$lname)
            $error = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบ';
        elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username))
            $error = 'Username ต้องเป็นตัวอักษร a-z, 0-9 หรือ _ ความยาว 3-30 ตัว';
        elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password))
            $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัว มีตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก และตัวเลข';
        elseif ($citizenId && strlen($citizenId) !== 13)
            $error = 'เลขบัตรประชาชนต้องมี 13 หลัก';

        if (!$error) {
            $chk = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR username = ?");
            $chk->execute([$email, $username]);
            if ($chk->fetch()) $error = 'อีเมลหรือ Username นี้ถูกใช้งานแล้ว';
        }

        if ($error) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$error]); exit; }
        } else {
            try {
                $pdo->beginTransaction();
                $hash   = password_hash($password, PASSWORD_BCRYPT);
                $roleId = $pdo->query("SELECT role_id FROM roles WHERE role_name='client'")->fetchColumn();
                $pdo->prepare("INSERT INTO users (office_id, role_id, username, email, password_hash, status) VALUES (?, ?, ?, ?, ?, 'active')")
                    ->execute([$officeId, $roleId, $username, $email, $hash]);
                $newUserId = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO client_profiles (user_id, fname, lname, citizen_id, phone, address) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$newUserId, $fname, $lname, $citizenId ?: null, $phone, $address]);
                $pdo->commit();
                audit_log($pdo, $officeId, $_SESSION['user_id'], 'create', 'client', (int)$newUserId, 'เพิ่มลูกความ '.$fname.' '.$lname);
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'เพิ่มลูกความเรียบร้อยแล้ว']); exit; }
            } catch (Exception $e) {
                $pdo->rollBack();
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'เกิดข้อผิดพลาดในระบบ']); exit; }
            }
        }
    }

    // ---- แก้ไขลูกความ ----
    if ($action === 'edit') {
        $clientId  = (int)$_POST['client_id'];
        $fname     = trim($_POST['fname'] ?? '');
        $lname     = trim($_POST['lname'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $citizenId = trim($_POST['citizen_id'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $address   = trim($_POST['address'] ?? '');
        $error     = '';

        $owner = $pdo->prepare("SELECT cp.user_id FROM client_profiles cp JOIN users u ON cp.user_id=u.user_id WHERE cp.client_id=? AND u.office_id=?");
        $owner->execute([$clientId, $officeId]);
        $ownerRow = $owner->fetch();
        if (!$ownerRow) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบลูกความหรือไม่มีสิทธิ์']); exit; }
        }

        if (!$fname || !$lname) $error = 'กรุณากรอกชื่อและนามสกุล';
        if (!$error && $citizenId && strlen($citizenId) !== 13) $error = 'เลขบัตรประชาชนต้องมี 13 หลัก';
        if (!$error) {
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE (email=? OR username=?) AND user_id != ?");
            $dup->execute([$email, $username, $ownerRow['user_id']]);
            if ($dup->fetch()) $error = 'อีเมลหรือ Username นี้ถูกใช้งานแล้ว';
        }
        if (!$error && $citizenId) {
            $dup2 = $pdo->prepare("SELECT client_id FROM client_profiles WHERE citizen_id=? AND client_id != ?");
            $dup2->execute([$citizenId, $clientId]);
            if ($dup2->fetch()) $error = 'เลขบัตรประชาชนนี้ถูกใช้งานแล้ว';
        }

        if ($error) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$error]); exit; }
        } else {
            $pdo->prepare("UPDATE client_profiles SET fname=?, lname=?, citizen_id=?, phone=?, address=? WHERE client_id=?")
                ->execute([$fname, $lname, $citizenId ?: null, $phone, $address, $clientId]);
            if ($email && $username) {
                $pdo->prepare("UPDATE users SET email=?, username=? WHERE user_id=?")
                    ->execute([$email, $username, $ownerRow['user_id']]);
            }
            audit_log($pdo, $officeId, $_SESSION['user_id'], 'update', 'client', $clientId, 'แก้ไขข้อมูลลูกความ #'.$clientId);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'แก้ไขข้อมูลลูกความเรียบร้อยแล้ว']); exit; }
        }
    }

    // ---- เปิด/ระงับบัญชี ----
    if ($action === 'toggle_status') {
        $clientId  = (int)$_POST['client_id'];
        $newStatus = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
        $owner = $pdo->prepare("SELECT cp.user_id FROM client_profiles cp JOIN users u ON cp.user_id=u.user_id WHERE cp.client_id=? AND u.office_id=?");
        $owner->execute([$clientId, $officeId]);
        $ownerRow = $owner->fetch();
        if (!$ownerRow) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบลูกความหรือไม่มีสิทธิ์']); exit; }
        } else {
            $pdo->prepare("UPDATE users SET status=? WHERE user_id=?")->execute([$newStatus, $ownerRow['user_id']]);
            audit_log($pdo, $officeId, $_SESSION['user_id'], 'update', 'client', $clientId, ($newStatus==='active'?'เปิดใช้งาน':'ระงับ').'บัญชีลูกความ #'.$clientId);
            $msg = $newStatus === 'active' ? '✅ เปิดใช้งานบัญชีลูกความแล้ว' : '🔒 ระงับบัญชีลูกความแล้ว';
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>$msg]); exit; }
        }
    }

    // ---- ลบบัญชีลูกความ ----
    if ($action === 'delete_client') {
        $clientId = (int)$_POST['client_id'];
        $owner = $pdo->prepare("SELECT cp.user_id FROM client_profiles cp JOIN users u ON cp.user_id=u.user_id WHERE cp.client_id=? AND u.office_id=?");
        $owner->execute([$clientId, $officeId]);
        $ownerRow = $owner->fetch();
        if (!$ownerRow) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบลูกความหรือไม่มีสิทธิ์']); exit; }
        } else {
            $hasActive = $pdo->prepare("SELECT COUNT(*) FROM case_requests cr JOIN contracts c ON c.request_id=cr.request_id WHERE cr.client_id=? AND c.status='active'");
            $hasActive->execute([$clientId]);
            if ((int)$hasActive->fetchColumn() > 0) {
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถลบได้ ลูกความมีคดีที่กำลังดำเนินการอยู่']); exit; }
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM client_profiles WHERE client_id=?")->execute([$clientId]);
                    $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$ownerRow['user_id']]);
                    $pdo->commit();
                    audit_log($pdo, $officeId, $_SESSION['user_id'], 'delete', 'client', $clientId, 'ลบบัญชีลูกความ #'.$clientId);
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'ลบบัญชีลูกความเรียบร้อยแล้ว']); exit; }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถลบได้ อาจมีข้อมูลที่เชื่อมโยงอยู่']); exit; }
                }
            }
        }
    }

    header('Location: /pages/clients.php'); exit;
}

// Fetch clients (ใช้ v_clients view + search)
require_once '../config/search_helper.php';
$search = trim($_GET['search'] ?? '');
$searchCond = search_build_where($search, ['full_name', 'fname', 'lname', 'citizen_id', 'phone', 'email', 'username']);

$params = array_merge([$officeId], $searchCond['params']);
$stmt = $pdo->prepare("
    SELECT client_id, user_id, fname, lname, citizen_id, phone,
           address, profile_photo, created_at,
           email, username, user_status, office_id, full_name
    FROM v_clients
    WHERE office_id = ?
    {$searchCond['sql']}
    ORDER BY created_at DESC
");
$stmt->execute($params);
$clients   = $stmt->fetchAll();
$pageTitle = 'จัดการลูกความ';
include '../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;}
.action-btn{padding:5px 11px;border-radius:6px;font-size:.75rem;font-weight:700;border:none;cursor:pointer;transition:.15s;}
.btn-edit{background:#eff6ff;color:#1d4ed8;}.btn-edit:hover{background:#1d4ed8;color:#fff;}
.btn-suspend{background:#fef3c7;color:#92400e;}.btn-suspend:hover{background:#d97706;color:#fff;}
.btn-activate{background:#d1fae5;color:#065f46;}.btn-activate:hover{background:#059669;color:#fff;}
.badge-active{background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;}
.badge-inactive{background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;}
.s-wrap{position:relative;flex:1;max-width:360px;}
.s-wrap input{width:100%;padding:8px 12px 8px 34px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;outline:none;}
.s-wrap input:focus{border-color:#2d5a8a;}
.s-wrap::before{content:"🔍";position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:.85rem;}
</style>
<div class="card">
    <div class="toolbar">
        <h2 style="margin:0;">👤 รายชื่อลูกความ</h2>
        <button onclick="openAddModal()" class="btn btn-primary">➕ เพิ่มลูกความ</button>
    </div>
    <div class="toolbar">
        <?= search_render_box($search, 'ค้นหาชื่อ, username, บัตรประชาชน, เบอร์โทร...') ?>
        <span style="font-size:.82rem;color:#94a3b8;">
            <?= count($clients) ?> คน
            <?= $search !== '' ? '(ค้นหา: "'.htmlspecialchars($search).'")' : '' ?>
        </span>
    </div>
    <?php if (empty($clients)): ?>
    <p style="color:#888;"><?= $search ? 'ไม่พบลูกความที่ตรงกับ "'.htmlspecialchars($search).'"' : 'ยังไม่มีลูกความในระบบ' ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table id="clientTable">
        <thead>
            <tr><th>#</th><th>ชื่อ-นามสกุล</th><th>Username</th><th>อีเมล</th>
            <th>เลขบัตรประชาชน</th><th>เบอร์โทร</th><th>สถานะ</th><th>จัดการ</th></tr>
        </thead>
        <tbody>
        <?php foreach ($clients as $c):
            $isActive = ($c['user_status'] === 'active');
        ?>
        <tr data-search="<?= strtolower(htmlspecialchars($c['fname'].' '.$c['lname'].' '.($c['username']??'').' '.($c['citizen_id']??''))) ?>"
            style="<?= !$isActive ? 'opacity:.55;' : '' ?>">
            <td><?= $c['client_id'] ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($c['fname'].' '.$c['lname']) ?></td>
            <td><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:.83rem;"><?= htmlspecialchars($c['username'] ?? '—') ?></code></td>
            <td><?= htmlspecialchars($c['email']) ?></td>
            <td style="letter-spacing:.05em;font-size:.85rem;"><?= htmlspecialchars(maskCitizenId($c['citizen_id'])) ?></td>
            <td><?= htmlspecialchars($c['phone'] ?? '—') ?></td>
            <td><span class="badge-<?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? 'ใช้งานได้' : 'ระงับแล้ว' ?></span></td>
            <td style="white-space:nowrap;">
                <button class="action-btn btn-edit" onclick='openEditModal(<?= json_encode([
                    "client_id"=>(int)$c["client_id"],"fname"=>$c["fname"],"lname"=>$c["lname"],
                    "email"=>$c["email"],"username"=>$c["username"]??"",
                    "citizen_id"=>$c["citizen_id"]??""  ,"phone"=>$c["phone"]??""  ,"address"=>$c["address"]??""
                ], JSON_UNESCAPED_UNICODE) ?>)'>✏️ แก้ไข</button>
                <?php if ($isActive): ?>
                <button class="action-btn btn-suspend" onclick='toggleStatus(<?= $c["client_id"] ?>, "inactive", <?= json_encode($c["fname"]." ".$c["lname"], JSON_UNESCAPED_UNICODE) ?>)'>🔒 ระงับ</button>
                <?php else: ?>
                <button class="action-btn btn-activate" onclick='toggleStatus(<?= $c["client_id"] ?>, "active", <?= json_encode($c["fname"]." ".$c["lname"], JSON_UNESCAPED_UNICODE) ?>)'>✅ เปิดใช้</button>
                <?php endif; ?>
                <button class="action-btn" style="background:#fee2e2;color:#991b1b;" onclick='deleteClient(<?= $c["client_id"] ?>, <?= json_encode($c["fname"]." ".$c["lname"], JSON_UNESCAPED_UNICODE) ?>)'>🗑️ ลบ</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal: เพิ่มลูกความ -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3 style="margin:0;color:#1a3a5c;">➕ เพิ่มลูกความ</h3>
        <button onclick="closeModal('addModal')" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">✕</button>
    </div>
    <form id="addForm">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="form-group"><label>ชื่อ <span style="color:red">*</span></label><input type="text" name="fname" required></div>
            <div class="form-group"><label>นามสกุล <span style="color:red">*</span></label><input type="text" name="lname" required></div>
            <div class="form-group"><label>Username <span style="color:red">*</span></label><input type="text" name="username" placeholder="a-z 0-9 _ (3-30 ตัว)" maxlength="30" required></div>
            <div class="form-group"><label>อีเมล <span style="color:red">*</span></label><input type="email" name="email" required></div>
            <div class="form-group"><label>รหัสผ่าน <span style="color:red">*</span></label><input type="password" name="password" placeholder="≥8 ตัว มี A-Z a-z 0-9" required></div>
            <div class="form-group"><label>เบอร์โทร</label><input type="text" name="phone"></div>
            <div class="form-group" style="grid-column:1/-1;"><label>เลขบัตรประชาชน (13 หลัก)</label><input type="text" name="citizen_id" maxlength="13" placeholder="xxxxxxxxxxxxxxxxx"></div>
            <div class="form-group" style="grid-column:1/-1;"><label>ที่อยู่</label><textarea name="address" rows="2" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"></textarea></div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
            <button type="button" onclick="closeModal('addModal')" style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
            <button type="submit" id="addBtn" style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">💾 บันทึก</button>
        </div>
    </form>
</div></div>

<!-- Modal: แก้ไขลูกความ -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3 style="margin:0;color:#1a3a5c;">✏️ แก้ไขข้อมูลลูกความ</h3>
        <button onclick="closeModal('editModal')" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">✕</button>
    </div>
    <form id="editForm">
        <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="client_id" id="eid">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="form-group"><label>ชื่อ <span style="color:red">*</span></label><input type="text" name="fname" id="ef-fname" required></div>
            <div class="form-group"><label>นามสกุล <span style="color:red">*</span></label><input type="text" name="lname" id="ef-lname" required></div>
            <div class="form-group"><label>Username</label><input type="text" name="username" id="ef-username" maxlength="30"></div>
            <div class="form-group"><label>อีเมล</label><input type="email" name="email" id="ef-email"></div>
            <div class="form-group"><label>เบอร์โทร</label><input type="text" name="phone" id="ef-phone"></div>
            <div class="form-group"><label>เลขบัตรประชาชน</label><input type="text" name="citizen_id" id="ef-citizen" maxlength="13"></div>
            <div class="form-group" style="grid-column:1/-1;"><label>ที่อยู่</label><textarea name="address" id="ef-address" rows="2" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"></textarea></div>
        </div>
        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:9px 13px;margin-top:8px;font-size:.8rem;color:#92400e;">
            ℹ️ การแก้ไข Username/อีเมลจะมีผลทันที
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
            <button type="button" onclick="closeModal('editModal')" style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
            <button type="submit" id="editBtn" style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">💾 บันทึก</button>
        </div>
    </form>
</div></div>

<script>
function filterTable(q){q=q.toLowerCase().trim();document.querySelectorAll('#clientTable tbody tr').forEach(tr=>{tr.style.display=(!q||tr.dataset.search.includes(q))?'':'none';});}
function closeModal(id){document.getElementById(id).style.display='none';}
['addModal','editModal'].forEach(id=>document.getElementById(id).addEventListener('click',function(e){if(e.target===this)closeModal(id);}));
function openAddModal(){document.getElementById('addForm').reset();document.getElementById('addModal').style.display='flex';}
function openEditModal(d){
    document.getElementById('eid').value=d.client_id;
    document.getElementById('ef-fname').value=d.fname;document.getElementById('ef-lname').value=d.lname;
    document.getElementById('ef-email').value=d.email;document.getElementById('ef-username').value=d.username;
    document.getElementById('ef-citizen').value=d.citizen_id;document.getElementById('ef-phone').value=d.phone;
    document.getElementById('ef-address').value=d.address;
    document.getElementById('editModal').style.display='flex';
}
async function toggleStatus(id,status,name){
    const on=status==='active';
    const r=await Swal.fire({icon:on?'question':'warning',title:on?'เปิดใช้งานบัญชี?':'ระงับบัญชีลูกความ?',
        html:on?`เปิดใช้งาน <b>"${name}"</b>`:`ระงับบัญชี <b>"${name}"</b><br><small style="color:#94a3b8">ลูกความจะเข้าสู่ระบบไม่ได้</small>`,
        showCancelButton:true,confirmButtonText:on?'✅ เปิดใช้งาน':'🔒 ระงับ',cancelButtonText:'ยกเลิก',
        confirmButtonColor:on?'#059669':'#d97706',cancelButtonColor:'#94a3b8'});
    if(!r.isConfirmed)return;
    const fd=new FormData();
    fd.append('csrf_token',document.querySelector('input[name="csrf_token"]').value);
    fd.append('action','toggle_status');fd.append('client_id',id);fd.append('new_status',status);
    try{const res=await fetch(location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
    const data=await res.json();
    if(data.ok){await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1800,timerProgressBar:true,showConfirmButton:false});location.reload();}
    else Swal.fire({icon:'error',title:'ผิดพลาด',text:data.msg,confirmButtonColor:'#1a3a5c'});}
    catch{Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'});}
}
async function handleSubmit(fid,bid){
    const btn=document.getElementById(bid),orig=btn.textContent;
    btn.disabled=true;btn.textContent='⏳...';
    try{const res=await fetch(location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new FormData(document.getElementById(fid))});
    const data=await res.json();closeModal(fid.replace('Form','Modal'));
    if(data.ok){await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1800,timerProgressBar:true,showConfirmButton:false});location.reload();}
    else Swal.fire({icon:'error',title:'ผิดพลาด',text:data.msg,confirmButtonColor:'#1a3a5c'});}
    catch{Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'});}
    finally{btn.disabled=false;btn.textContent=orig;}
}
document.addEventListener('DOMContentLoaded', function() {
    var af = document.getElementById('addForm');
    var ef = document.getElementById('editForm');
    if (af) af.addEventListener('submit', e => { e.preventDefault(); handleSubmit('addForm', 'addBtn'); });
    if (ef) ef.addEventListener('submit', e => { e.preventDefault(); handleSubmit('editForm', 'editBtn'); });
});

async function deleteClient(id, name) {
    const r = await Swal.fire({
        icon:'warning', title:'ลบบัญชีลูกความ?',
        html:`ลบ <b>"${name}"</b> ออกจากระบบ?<br><small style="color:#dc2626">จะลบได้เฉพาะลูกความที่ไม่มีคดี active อยู่</small>`,
        showCancelButton:true, confirmButtonText:'🗑️ ลบ', cancelButtonText:'ยกเลิก',
        confirmButtonColor:'#dc2626', cancelButtonColor:'#94a3b8',
    });
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fd.append('action','delete_client'); fd.append('client_id',id);
    try {
        const res = await fetch(location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
        const data = await res.json();
        if(data.ok){await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1800,timerProgressBar:true,showConfirmButton:false});location.reload();}
        else Swal.fire({icon:'error',title:'ลบไม่ได้',text:data.msg,confirmButtonColor:'#1a3a5c'});
    } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
}
</script>
<?php include '../includes/footer.php'; ?>