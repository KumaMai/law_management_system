<?php
// lawyers.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/file_upload_helper.php';
require_once '../config/activity_log_helper.php';
requireRole('admin');

$pdo      = getDB();
$officeId = $_SESSION['office_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $action = $_POST['action'] ?? 'add';

    // ---- เพิ่มทนายใหม่ ----
    if ($action === 'add') {
        $username       = trim($_POST['username'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $password       = $_POST['password'] ?? '';
        $fname          = trim($_POST['fname'] ?? '');
        $lname          = trim($_POST['lname'] ?? '');
        $licenseNo      = trim($_POST['license_no'] ?? '');
        $licenseExp     = $_POST['license_exp'] ?? null;
        $specialization = trim($_POST['specialization'] ?? '');
        $phone          = trim($_POST['phone'] ?? '');
        $error          = '';

        if (!$username || !$email || !$password || !$fname || !$lname)
            $error = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบ (ชื่อ, นามสกุล, Username, อีเมล, รหัสผ่าน)';
        elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username))
            $error = 'Username ต้องเป็นตัวอักษร a-z, 0-9 หรือ _ ความยาว 3-30 ตัว';
        elseif (strlen($password) < 8)
            $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
        elseif (!preg_match('/[A-Z]/', $password))
            $error = 'รหัสผ่านต้องมีตัวอักษรพิมพ์ใหญ่อย่างน้อย 1 ตัว';
        elseif (!preg_match('/[a-z]/', $password))
            $error = 'รหัสผ่านต้องมีตัวอักษรพิมพ์เล็กอย่างน้อย 1 ตัว';
        elseif (!preg_match('/[0-9]/', $password))
            $error = 'รหัสผ่านต้องมีตัวเลขอย่างน้อย 1 ตัว';

        if (!$error) {
            $chk = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR username = ?");
            $chk->execute([$email, $username]);
            if ($chk->fetch()) $error = 'อีเมลหรือ Username นี้ถูกใช้งานแล้ว';
        }
        if (!$error && $licenseNo) {
            $chk2 = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE license_no = ?");
            $chk2->execute([$licenseNo]);
            if ($chk2->fetch()) $error = 'เลขใบอนุญาตนี้ถูกใช้งานแล้ว';
        }

        if ($error) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$error]); exit; }
        } else {
            try {
                $pdo->beginTransaction();
                $hash   = password_hash($password, PASSWORD_BCRYPT);
                $roleId = $pdo->query("SELECT role_id FROM roles WHERE role_name='lawyer'")->fetchColumn();
                $pdo->prepare("INSERT INTO users (office_id, role_id, username, email, password_hash, status) VALUES (?, ?, ?, ?, ?, 'active')")
                    ->execute([$officeId, $roleId, $username, $email, $hash]);
                $newUserId = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO lawyer_profiles (user_id, fname, lname, license_no, license_exp, specialization, phone, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')")
                    ->execute([$newUserId, $fname, $lname, $licenseNo ?: null, $licenseExp ?: null, $specialization, $phone]);
                $pdo->commit();
                audit_log($pdo, $officeId, $_SESSION['user_id'], 'create', 'lawyer', (int)$newUserId, 'เพิ่มทนายความ '.$fname.' '.$lname);
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'เพิ่มทนายความเรียบร้อยแล้ว']); exit; }
            } catch (Exception $e) {
                $pdo->rollBack();
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'เกิดข้อผิดพลาดในระบบ']); exit; }
            }
        }
    }

    // ---- แก้ไขทนาย ----
    if ($action === 'edit') {
        $lawyerId       = (int)$_POST['lawyer_id'];
        $fname          = trim($_POST['fname'] ?? '');
        $lname          = trim($_POST['lname'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $username       = trim($_POST['username'] ?? '');
        $licenseNo      = trim($_POST['license_no'] ?? '');
        $licenseExp     = $_POST['license_exp'] ?: null;
        $specialization = trim($_POST['specialization'] ?? '');
        $phone          = trim($_POST['phone'] ?? '');
        $error          = '';

        $owner = $pdo->prepare("SELECT lp.user_id FROM lawyer_profiles lp JOIN users u ON lp.user_id=u.user_id WHERE lp.lawyer_id=? AND u.office_id=?");
        $owner->execute([$lawyerId, $officeId]);
        $ownerRow = $owner->fetch();
        if (!$ownerRow) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบทนายหรือไม่มีสิทธิ์']); exit; }
        }
        if (!$fname || !$lname) $error = 'กรุณากรอกชื่อและนามสกุล';
        if (!$error && ($email || $username)) {
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE (email=? OR username=?) AND user_id != ?");
            $dup->execute([$email, $username, $ownerRow['user_id']]);
            if ($dup->fetch()) $error = 'อีเมลหรือ Username นี้ถูกใช้งานแล้ว';
        }
        if (!$error && $licenseNo) {
            $dup2 = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE license_no=? AND lawyer_id != ?");
            $dup2->execute([$licenseNo, $lawyerId]);
            if ($dup2->fetch()) $error = 'เลขใบอนุญาตนี้ถูกใช้งานแล้ว';
        }

        if ($error) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$error]); exit; }
        } else {
            $pdo->prepare("UPDATE lawyer_profiles SET fname=?, lname=?, license_no=?, license_exp=?, specialization=?, phone=? WHERE lawyer_id=?")
                ->execute([$fname, $lname, $licenseNo ?: null, $licenseExp, $specialization, $phone, $lawyerId]);
            if ($email && $username) {
                $pdo->prepare("UPDATE users SET email=?, username=? WHERE user_id=?")
                    ->execute([$email, $username, $ownerRow['user_id']]);
            }
            audit_log($pdo, $officeId, $_SESSION['user_id'], 'update', 'lawyer', $lawyerId, 'แก้ไขข้อมูลทนาย #'.$lawyerId);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'แก้ไขข้อมูลทนายเรียบร้อยแล้ว']); exit; }
        }
    }

    // ---- เปิด/ระงับบัญชี ----
    if ($action === 'toggle_status') {
        $lawyerId  = (int)$_POST['lawyer_id'];
        $newStatus = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
        $owner = $pdo->prepare("SELECT lp.user_id FROM lawyer_profiles lp JOIN users u ON lp.user_id=u.user_id WHERE lp.lawyer_id=? AND u.office_id=?");
        $owner->execute([$lawyerId, $officeId]);
        $ownerRow = $owner->fetch();
        if (!$ownerRow) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบทนายหรือไม่มีสิทธิ์']); exit; }
        } else {
            $pdo->prepare("UPDATE lawyer_profiles SET status=? WHERE lawyer_id=?")->execute([$newStatus, $lawyerId]);
            $pdo->prepare("UPDATE users SET status=? WHERE user_id=?")->execute([$newStatus, $ownerRow['user_id']]);
            audit_log($pdo, $officeId, $_SESSION['user_id'], 'update', 'lawyer', $lawyerId, ($newStatus==='active'?'เปิดใช้งาน':'ระงับ').'บัญชีทนาย #'.$lawyerId);
            $msg = $newStatus === 'active' ? '✅ เปิดใช้งานบัญชีทนายแล้ว' : '🔒 ระงับบัญชีทนายแล้ว';
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>$msg]); exit; }
        }
    }

    // ---- ลบบัญชีทนาย ----
    if ($action === 'delete_lawyer') {
        $lawyerId = (int)$_POST['lawyer_id'];
        $owner = $pdo->prepare("SELECT lp.user_id FROM lawyer_profiles lp JOIN users u ON lp.user_id=u.user_id WHERE lp.lawyer_id=? AND u.office_id=?");
        $owner->execute([$lawyerId, $officeId]);
        $ownerRow = $owner->fetch();
        if (!$ownerRow) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบทนายหรือไม่มีสิทธิ์']); exit; }
        } else {
            $hasActive = $pdo->prepare("SELECT COUNT(*) FROM case_requests cr JOIN contracts c ON c.request_id=cr.request_id WHERE cr.lawyer_id=? AND c.status='active'");
            $hasActive->execute([$lawyerId]);
            if ((int)$hasActive->fetchColumn() > 0) {
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถลบได้ ทนายคนนี้มีคดีที่กำลังดำเนินการอยู่']); exit; }
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM lawyer_profiles WHERE lawyer_id=?")->execute([$lawyerId]);
                    $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$ownerRow['user_id']]);
                    $pdo->commit();
                    audit_log($pdo, $officeId, $_SESSION['user_id'], 'delete', 'lawyer', $lawyerId, 'ลบบัญชีทนาย #'.$lawyerId);
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'ลบบัญชีทนายเรียบร้อยแล้ว']); exit; }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถลบได้ อาจมีข้อมูลที่เชื่อมโยงอยู่']); exit; }
                }
            }
        }
    }

    header('Location: /pages/lawyers.php'); exit;
}

$stmt = $pdo->prepare("
    SELECT lp.*, u.email, u.username, u.status AS user_status,
           COUNT(DISTINCT cr.request_id) AS case_count
    FROM lawyer_profiles lp
    JOIN users u ON lp.user_id = u.user_id
    LEFT JOIN case_requests cr ON cr.lawyer_id = lp.lawyer_id AND cr.status = 'approved'
    WHERE u.office_id = ?
    GROUP BY lp.lawyer_id
    ORDER BY lp.created_at DESC
");
$stmt->execute([$officeId]);
$lawyers   = $stmt->fetchAll();
$pageTitle = 'จัดการทนายความ';
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
        <h2 style="margin:0;">👨‍⚖️ รายชื่อทนายความ</h2>
        <button onclick="openAddModal()" class="btn btn-primary">➕ เพิ่มทนายความ</button>
    </div>
    <div class="toolbar">
        <div class="s-wrap"><input type="text" placeholder="ค้นหาชื่อ, username, ใบอนุญาต..." oninput="filterTable(this.value)"></div>
        <span style="font-size:.82rem;color:#94a3b8;"><?= count($lawyers) ?> คน</span>
    </div>
    <?php if (empty($lawyers)): ?>
    <p style="color:#888;">ยังไม่มีทนายความในระบบ</p>
    <?php else: ?>
    <div class="table-wrap">
    <table id="lawyerTable">
        <thead>
            <tr><th>#</th><th>ชื่อ-นามสกุล</th><th>Username</th><th>อีเมล</th>
            <th>ใบอนุญาต</th><th>ความเชี่ยวชาญ</th><th>คดีที่รับ</th><th>สถานะ</th><th>จัดการ</th></tr>
        </thead>
        <tbody>
        <?php foreach ($lawyers as $l):
            $isActive = ($l['status'] === 'active');
            $expDate  = $l['license_exp'] ? strtotime($l['license_exp']) : null;
        ?>
        <tr data-search="<?= strtolower(htmlspecialchars($l['fname'].' '.$l['lname'].' '.($l['username']??'').' '.($l['license_no']??''))) ?>"
            style="<?= !$isActive ? 'opacity:.55;' : '' ?>">
            <td><?= $l['lawyer_id'] ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($l['fname'].' '.$l['lname']) ?></td>
            <td><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:.83rem;"><?= htmlspecialchars($l['username'] ?? '—') ?></code></td>
            <td><?= htmlspecialchars($l['email']) ?></td>
            <td>
                <div><?= htmlspecialchars($l['license_no'] ?? '—') ?></div>
                <?php if ($expDate): ?>
                <div style="font-size:.72rem;color:<?= $expDate < time() ? '#dc2626' : '#94a3b8' ?>;">
                    <?= $expDate < time() ? '⚠️ หมดอายุ: ' : 'ถึง: ' ?><?= date('d/m/Y', $expDate) ?>
                </div>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($l['specialization'] ?? '—') ?></td>
            <td style="text-align:center;"><?= $l['case_count'] ?: '—' ?></td>
            <td><span class="badge-<?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? 'ใช้งานได้' : 'ระงับแล้ว' ?></span></td>
            <td style="white-space:nowrap;">
                <button class="action-btn btn-edit" onclick='openEditModal(<?= json_encode([
                    "lawyer_id"=>(int)$l["lawyer_id"],"fname"=>$l["fname"],"lname"=>$l["lname"],
                    "email"=>$l["email"],"username"=>$l["username"]??"","license_no"=>$l["license_no"]??"",
                    "license_exp"=>$l["license_exp"]??""  ,"specialization"=>$l["specialization"]??""  ,"phone"=>$l["phone"]??""
                ], JSON_UNESCAPED_UNICODE) ?>)'>✏️ แก้ไข</button>
                <?php if ($isActive): ?>
                <button class="action-btn btn-suspend" onclick='toggleStatus(<?= $l["lawyer_id"] ?>, "inactive", <?= json_encode($l["fname"]." ".$l["lname"], JSON_UNESCAPED_UNICODE) ?>)'>🔒 ระงับ</button>
                <?php else: ?>
                <button class="action-btn btn-activate" onclick='toggleStatus(<?= $l["lawyer_id"] ?>, "active", <?= json_encode($l["fname"]." ".$l["lname"], JSON_UNESCAPED_UNICODE) ?>)'>✅ เปิดใช้</button>
                <?php endif; ?>
                <button class="action-btn" style="background:#fee2e2;color:#991b1b;" onclick='deleteLawyer(<?= $l["lawyer_id"] ?>, <?= json_encode($l["fname"]." ".$l["lname"], JSON_UNESCAPED_UNICODE) ?>)'>🗑️ ลบ</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal: เพิ่มทนาย -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3 style="margin:0;color:#1a3a5c;">➕ เพิ่มทนายความ</h3>
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
            <div class="form-group"><label>เลขใบอนุญาต</label><input type="text" name="license_no"></div>
            <div class="form-group"><label>วันหมดอายุใบอนุญาต</label><input type="date" name="license_exp"></div>
            <div class="form-group" style="grid-column:1/-1;"><label>ความเชี่ยวชาญ</label><input type="text" name="specialization" placeholder="เช่น คดีแพ่ง, คดีอาญา"></div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
            <button type="button" onclick="closeModal('addModal')" style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
            <button type="submit" id="addBtn" style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">💾 บันทึก</button>
        </div>
    </form>
</div></div>

<!-- Modal: แก้ไขทนาย -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3 style="margin:0;color:#1a3a5c;">✏️ แก้ไขข้อมูลทนาย</h3>
        <button onclick="closeModal('editModal')" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">✕</button>
    </div>
    <form id="editForm">
        <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="lawyer_id" id="eid">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="form-group"><label>ชื่อ <span style="color:red">*</span></label><input type="text" name="fname" id="ef-fname" required></div>
            <div class="form-group"><label>นามสกุล <span style="color:red">*</span></label><input type="text" name="lname" id="ef-lname" required></div>
            <div class="form-group"><label>Username</label><input type="text" name="username" id="ef-username" maxlength="30"></div>
            <div class="form-group"><label>อีเมล</label><input type="email" name="email" id="ef-email"></div>
            <div class="form-group"><label>เบอร์โทร</label><input type="text" name="phone" id="ef-phone"></div>
            <div class="form-group"><label>เลขใบอนุญาต</label><input type="text" name="license_no" id="ef-licno"></div>
            <div class="form-group"><label>วันหมดอายุใบอนุญาต</label><input type="date" name="license_exp" id="ef-licexp"></div>
            <div class="form-group"><label>ความเชี่ยวชาญ</label><input type="text" name="specialization" id="ef-spec"></div>
        </div>
        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:9px 13px;margin-top:8px;font-size:.8rem;color:#92400e;">
            ℹ️ การแก้ไข Username/อีเมลจะมีผลทันที ทนายต้องใช้ข้อมูลใหม่ในการเข้าสู่ระบบ
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
            <button type="button" onclick="closeModal('editModal')" style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
            <button type="submit" id="editBtn" style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">💾 บันทึก</button>
        </div>
    </form>
</div></div>

<script>
function filterTable(q){q=q.toLowerCase().trim();document.querySelectorAll('#lawyerTable tbody tr').forEach(tr=>{tr.style.display=(!q||tr.dataset.search.includes(q))?'':'none';});}
function closeModal(id){document.getElementById(id).style.display='none';}
['addModal','editModal'].forEach(id=>document.getElementById(id).addEventListener('click',function(e){if(e.target===this)closeModal(id);}));
function openAddModal(){document.getElementById('addForm').reset();document.getElementById('addModal').style.display='flex';}
function openEditModal(d){
    document.getElementById('eid').value=d.lawyer_id;
    document.getElementById('ef-fname').value=d.fname;document.getElementById('ef-lname').value=d.lname;
    document.getElementById('ef-email').value=d.email;document.getElementById('ef-username').value=d.username;
    document.getElementById('ef-licno').value=d.license_no;document.getElementById('ef-licexp').value=d.license_exp;
    document.getElementById('ef-spec').value=d.specialization;document.getElementById('ef-phone').value=d.phone;
    document.getElementById('editModal').style.display='flex';
}
async function toggleStatus(id,status,name){
    const on=status==='active';
    const r=await Swal.fire({icon:on?'question':'warning',title:on?'เปิดใช้งานบัญชี?':'ระงับบัญชีทนาย?',
        html:on?`เปิดใช้งาน <b>"${name}"</b> ทนายจะเข้าสู่ระบบได้อีกครั้ง`:`ระงับบัญชี <b>"${name}"</b><br><small style="color:#94a3b8">ทนายจะเข้าสู่ระบบไม่ได้จนกว่าจะเปิดใช้งาน</small>`,
        showCancelButton:true,confirmButtonText:on?'✅ เปิดใช้งาน':'🔒 ระงับ',cancelButtonText:'ยกเลิก',
        confirmButtonColor:on?'#059669':'#d97706',cancelButtonColor:'#94a3b8'});
    if(!r.isConfirmed)return;
    const fd=new FormData();
    fd.append('csrf_token',document.querySelector('input[name="csrf_token"]').value);
    fd.append('action','toggle_status');fd.append('lawyer_id',id);fd.append('new_status',status);
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
    const data=await res.json();
    closeModal(fid.replace('Form','Modal'));
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

async function deleteLawyer(id, name) {
    const r = await Swal.fire({
        icon:'warning', title:'ลบบัญชีทนายความ?',
        html:`ลบ <b>"${name}"</b> ออกจากระบบ?<br><small style="color:#dc2626">จะลบได้เฉพาะทนายที่ไม่มีคดี active อยู่</small>`,
        showCancelButton:true, confirmButtonText:'🗑️ ลบ', cancelButtonText:'ยกเลิก',
        confirmButtonColor:'#dc2626', cancelButtonColor:'#94a3b8',
    });
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fd.append('action','delete_lawyer'); fd.append('lawyer_id',id);
    try {
        const res = await fetch(location.pathname,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd});
        const data = await res.json();
        if(data.ok){await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1800,timerProgressBar:true,showConfirmButton:false});location.reload();}
        else Swal.fire({icon:'error',title:'ลบไม่ได้',text:data.msg,confirmButtonColor:'#1a3a5c'});
    } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
}
</script>
<?php include '../includes/footer.php'; ?>