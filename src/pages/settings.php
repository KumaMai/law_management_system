<?php
// settings.php — Admin: ตั้งค่าสำนักงาน
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/file_upload_helper.php';
require_once '../config/activity_log_helper.php';
requireRole('admin');

$pdo      = getDB();
$officeId = $_SESSION['office_id'];
$userId   = $_SESSION['user_id'];

// ==============================
// Handle POST
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---- อัปเดตข้อมูลสำนักงาน ----
    if ($action === 'update_office') {
        $officeName = trim($_POST['office_name'] ?? '');
        $officeCode = trim($_POST['office_code'] ?? '');
        $address    = trim($_POST['address'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $email      = trim($_POST['email'] ?? '');

        if (!$officeName) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'กรุณากรอกชื่อสำนักงาน']); exit; }
        } else {
            $pdo->prepare("UPDATE offices SET office_name=?, office_code=?, address=?, phone=?, email=? WHERE office_id=?")
                ->execute([$officeName, $officeCode ?: null, $address ?: null, $phone ?: null, $email ?: null, $officeId]);
            audit_log($pdo, $officeId, $userId, 'update', 'office', $officeId, 'อัปเดตข้อมูลสำนักงาน');
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'อัปเดตข้อมูลสำนักงานเรียบร้อยแล้ว']); exit; }
        }
    }

    // ---- อัปโหลด logo ----
    if ($action === 'upload_logo' && !empty($_FILES['logo']['name'])) {
        $up = validateUpload($_FILES['logo'], MIME_IMAGES, 2 * 1024 * 1024);
        if (!$up['ok']) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$up['msg']]); exit; }
        } else {
            // ลบ logo เดิม
            $oldLogo = $pdo->prepare("SELECT logo_path FROM offices WHERE office_id=?");
            $oldLogo->execute([$officeId]);
            $oldPath = $oldLogo->fetchColumn();
            if ($oldPath) {
                $absOld = '/var/www/html' . $oldPath;
                if (file_exists($absOld)) @unlink($absOld);
            }

            $uploadDir = '/var/www/html/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = 'logo_' . $officeId . '_' . time() . '.' . $up['ext'];
            move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $filename);
            $logoPath = '/uploads/' . $filename;

            $pdo->prepare("UPDATE offices SET logo_path=? WHERE office_id=?")->execute([$logoPath, $officeId]);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'อัปโหลดโลโก้เรียบร้อยแล้ว','logo'=>$logoPath]); exit; }
        }
    }

    // ---- เปลี่ยนรหัสผ่าน Admin ----
    if ($action === 'change_password') {
        $currentPw  = $_POST['current_password'] ?? '';
        $newPw      = $_POST['new_password'] ?? '';
        $confirmPw  = $_POST['confirm_password'] ?? '';
        $error      = '';

        if (!$currentPw || !$newPw || !$confirmPw) {
            $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        } elseif ($newPw !== $confirmPw) {
            $error = 'รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน';
        } elseif (strlen($newPw) < 8 || !preg_match('/[A-Z]/', $newPw) || !preg_match('/[a-z]/', $newPw) || !preg_match('/[0-9]/', $newPw)) {
            $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัว มีตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก และตัวเลข';
        } else {
            $row = $pdo->prepare("SELECT password_hash FROM users WHERE user_id=?");
            $row->execute([$userId]);
            $hash = $row->fetchColumn();
            if (!password_verify($currentPw, $hash)) {
                $error = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
            } else {
                $newHash = password_hash($newPw, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE users SET password_hash=? WHERE user_id=?")->execute([$newHash, $userId]);
                audit_log($pdo, $officeId, $userId, 'update', 'user', $userId, 'Admin เปลี่ยนรหัสผ่านตัวเอง');
            }
        }
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>empty($error),'msg'=>$error ?: '✅ เปลี่ยนรหัสผ่านเรียบร้อยแล้ว']); exit; }
    }

    header('Location: /pages/settings.php'); exit;
}

// ==============================
// Fetch office info
// ==============================
$office = $pdo->prepare("SELECT * FROM offices WHERE office_id=?");
$office->execute([$officeId]);
$office = $office->fetch();

// Fetch system stats for info panel
$sysStats = [];
$q = $pdo->prepare("SELECT COUNT(*) FROM users WHERE office_id=?"); $q->execute([$officeId]); $sysStats['users'] = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM lawyer_profiles lp JOIN users u ON lp.user_id=u.user_id WHERE u.office_id=?"); $q->execute([$officeId]); $sysStats['lawyers'] = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM client_profiles cp JOIN users u ON cp.user_id=u.user_id WHERE u.office_id=?"); $q->execute([$officeId]); $sysStats['clients'] = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE office_id=?"); $q->execute([$officeId]); $sysStats['cases'] = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN case_requests cr ON c.request_id=cr.request_id WHERE cr.office_id=?"); $q->execute([$officeId]); $sysStats['contracts'] = (int)$q->fetchColumn();
$sysStats['courts'] = (int)$pdo->query("SELECT COUNT(*) FROM courts")->fetchColumn();

$pageTitle = 'ตั้งค่าสำนักงาน';
include '../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--navy:#1a3a5c;--navy2:#2d5a8a;--border:#e2e8f0;--muted:#94a3b8;}
.settings-grid{display:grid;grid-template-columns:220px 1fr;gap:16px;}
.settings-menu{display:flex;flex-direction:column;gap:4px;}
.s-menu-item{padding:9px 14px;border-radius:8px;font-size:.87rem;font-weight:600;cursor:pointer;transition:.12s;border:none;text-align:left;background:transparent;color:#374151;width:100%;}
.s-menu-item:hover{background:#f1f5f9;}
.s-menu-item.active{background:#1a3a5c;color:#fff;}
.s-panel{display:none;}
.s-panel.active{display:block;}
.section-title{font-size:.73rem;font-weight:600;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid var(--border);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;}
.info-box{background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center;}
.info-num{font-size:1.6rem;font-weight:900;color:var(--navy);}
.info-lbl{font-size:.72rem;color:var(--muted);margin-top:2px;}
.logo-wrap{display:flex;align-items:center;gap:16px;margin-bottom:16px;}
.logo-preview{width:80px;height:80px;border-radius:12px;border:2px solid var(--border);object-fit:contain;background:#f8fafc;padding:4px;}
.logo-placeholder{width:80px;height:80px;border-radius:12px;border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;font-size:2rem;background:#f8fafc;color:var(--muted);}
.pw-seg-row{display:flex;gap:4px;margin-top:6px;}
.pw-seg{flex:1;height:4px;border-radius:2px;background:#e2e8f0;transition:.3s;}
.pw-seg.s1{background:#ef4444;}.pw-seg.s2{background:#f97316;}.pw-seg.s3{background:#eab308;}.pw-seg.s4{background:#22c55e;}
@media(max-width:700px){.settings-grid{grid-template-columns:1fr;}.form-row{grid-template-columns:1fr;}.info-grid{grid-template-columns:repeat(2,1fr);}}
</style>

<div class="card">
    <h2 style="margin:0 0 18px;">⚙️ ตั้งค่าสำนักงาน</h2>

    <div class="settings-grid">
        <!-- Menu -->
        <div class="settings-menu">
            <button class="s-menu-item active" onclick="showPanel('office',this)">🏛️ ข้อมูลสำนักงาน</button>
            <button class="s-menu-item" onclick="showPanel('security',this)">🔐 ความปลอดภัย</button>
            <button class="s-menu-item" onclick="showPanel('sysinfo',this)">📊 ข้อมูลระบบ</button>
        </div>

        <!-- Panels -->
        <div>

            <!-- ===== Office Info ===== -->
            <div id="panel-office" class="s-panel active">
                <div class="section-title">ข้อมูลสำนักงาน</div>

                <!-- Logo -->
                <div class="logo-wrap">
                    <?php if (!empty($office['logo_path'])): ?>
                    <img src="<?= htmlspecialchars($office['logo_path']) ?>" class="logo-preview" id="logoPreview" alt="logo">
                    <?php else: ?>
                    <div class="logo-placeholder" id="logoPreview">🏛️</div>
                    <?php endif; ?>
                    <div>
                        <div style="font-size:.85rem;font-weight:600;margin-bottom:6px;">โลโก้สำนักงาน</div>
                        <label style="cursor:pointer;">
                            <input type="file" id="logoFile" accept="image/*" style="display:none;" onchange="uploadLogo(this)">
                            <span class="btn btn-primary btn-sm" style="display:inline-block;padding:6px 14px;font-size:.8rem;">📤 เปลี่ยนโลโก้</span>
                        </label>
                        <div style="font-size:.74rem;color:var(--muted);margin-top:4px;">PNG/JPG ขนาดไม่เกิน 2 MB</div>
                    </div>
                </div>

                <form id="officeForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_office">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label>ชื่อสำนักงาน <span style="color:red">*</span></label>
                        <input type="text" name="office_name" value="<?= htmlspecialchars($office['office_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-row" style="margin-bottom:14px;">
                        <div class="form-group">
                            <label>รหัสสำนักงาน</label>
                            <input type="text" name="office_code" value="<?= htmlspecialchars($office['office_code'] ?? '') ?>" placeholder="เช่น LAW-001">
                        </div>
                        <div class="form-group">
                            <label>เบอร์โทร</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($office['phone'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label>อีเมลสำนักงาน</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($office['email'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:18px;">
                        <label>ที่อยู่</label>
                        <textarea name="address" rows="3" style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"><?= htmlspecialchars($office['address'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" id="officeSubmitBtn" style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">💾 บันทึกการเปลี่ยนแปลง</button>
                </form>
            </div>

            <!-- ===== Security ===== -->
            <div id="panel-security" class="s-panel">
                <div class="section-title">เปลี่ยนรหัสผ่าน Admin</div>
                <form id="pwForm" style="max-width:420px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label>รหัสผ่านปัจจุบัน <span style="color:red">*</span></label>
                        <div style="position:relative;">
                            <input type="password" name="current_password" id="pw-current" required
                                style="width:100%;padding:9px 38px 9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;outline:none;">
                            <span onclick="togglePw('pw-current',this)" style="position:absolute;right:11px;top:50%;transform:translateY(-50%);cursor:pointer;color:#94a3b8;font-size:.85rem;user-select:none;">👁</span>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label>รหัสผ่านใหม่ <span style="color:red">*</span></label>
                        <div style="position:relative;">
                            <input type="password" name="new_password" id="pw-new" oninput="checkStrength(this.value)" required
                                style="width:100%;padding:9px 38px 9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;outline:none;">
                            <span onclick="togglePw('pw-new',this)" style="position:absolute;right:11px;top:50%;transform:translateY(-50%);cursor:pointer;color:#94a3b8;font-size:.85rem;user-select:none;">👁</span>
                        </div>
                        <div class="pw-seg-row">
                            <div class="pw-seg" id="seg0"></div>
                            <div class="pw-seg" id="seg1"></div>
                            <div class="pw-seg" id="seg2"></div>
                            <div class="pw-seg" id="seg3"></div>
                        </div>
                        <small id="pw-hint" style="font-size:.76rem;color:#94a3b8;margin-top:4px;display:block;">ต้องมีอย่างน้อย 8 ตัว มีตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก และตัวเลข</small>
                    </div>
                    <div class="form-group" style="margin-bottom:18px;">
                        <label>ยืนยันรหัสผ่านใหม่ <span style="color:red">*</span></label>
                        <div style="position:relative;">
                            <input type="password" name="confirm_password" id="pw-confirm" oninput="checkMatch()" required
                                style="width:100%;padding:9px 38px 9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;outline:none;">
                            <span onclick="togglePw('pw-confirm',this)" style="position:absolute;right:11px;top:50%;transform:translateY(-50%);cursor:pointer;color:#94a3b8;font-size:.85rem;user-select:none;">👁</span>
                        </div>
                        <small id="match-hint" style="font-size:.76rem;display:none;margin-top:3px;"></small>
                    </div>
                    <button type="submit" id="pwSubmitBtn" style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">🔐 เปลี่ยนรหัสผ่าน</button>
                </form>
            </div>

            <!-- ===== System Info ===== -->
            <div id="panel-sysinfo" class="s-panel">
                <div class="section-title">ข้อมูลระบบ</div>
                <div class="info-grid">
                    <div class="info-box"><div class="info-num"><?= $sysStats['lawyers'] ?></div><div class="info-lbl">ทนายความ</div></div>
                    <div class="info-box"><div class="info-num"><?= $sysStats['clients'] ?></div><div class="info-lbl">ลูกความ</div></div>
                    <div class="info-box"><div class="info-num"><?= $sysStats['users'] ?></div><div class="info-lbl">ผู้ใช้ทั้งหมด</div></div>
                    <div class="info-box"><div class="info-num"><?= $sysStats['cases'] ?></div><div class="info-lbl">คำขอว่าจ้าง</div></div>
                    <div class="info-box"><div class="info-num"><?= $sysStats['contracts'] ?></div><div class="info-lbl">สัญญา</div></div>
                    <div class="info-box"><div class="info-num"><?= $sysStats['courts'] ?></div><div class="info-lbl">ศาลในระบบ</div></div>
                </div>
                <div style="background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:14px 16px;font-size:.84rem;color:#374151;">
                    <div style="font-weight:700;margin-bottom:10px;">📋 ข้อมูลสำนักงาน</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div><span style="color:var(--muted);">ชื่อ:</span> <?= htmlspecialchars($office['office_name'] ?? '—') ?></div>
                        <div><span style="color:var(--muted);">รหัส:</span> <?= htmlspecialchars($office['office_code'] ?? '—') ?></div>
                        <div><span style="color:var(--muted);">โทร:</span> <?= htmlspecialchars($office['phone'] ?? '—') ?></div>
                        <div><span style="color:var(--muted);">อีเมล:</span> <?= htmlspecialchars($office['email'] ?? '—') ?></div>
                        <div style="grid-column:span 2;"><span style="color:var(--muted);">ที่อยู่:</span> <?= htmlspecialchars($office['address'] ?? '—') ?></div>
                    </div>
                </div>
                <div style="margin-top:14px;font-size:.82rem;color:var(--muted);">
                    สร้างสำนักงานเมื่อ: <?= date('d/m/Y', strtotime($office['created_at'] ?? 'now')) ?>
                    &nbsp;|&nbsp; PHP <?= phpversion() ?>
                    &nbsp;|&nbsp; Law CMS v4.3
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function showPanel(id, btn) {
    document.querySelectorAll('.s-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.s-menu-item').forEach(b => b.classList.remove('active'));
    document.getElementById('panel-'+id).classList.add('active');
    btn.classList.add('active');
}

function togglePw(id, icon) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
    icon.textContent = el.type === 'password' ? '👁' : '🙈';
}

function checkStrength(val) {
    let score = 0;
    if (val.length >= 8)          score++;
    if (/[A-Z]/.test(val))        score++;
    if (/[a-z]/.test(val))        score++;
    if (/[0-9]/.test(val))        score++;
    const classes = ['s1','s2','s3','s4'];
    for (let i = 0; i < 4; i++) {
        const seg = document.getElementById('seg'+i);
        seg.className = 'pw-seg ' + (i < score ? classes[score-1] : '');
    }
    const hints = ['','อ่อนมาก','อ่อน','ปานกลาง','แข็งแกร่ง'];
    const hint = document.getElementById('pw-hint');
    hint.textContent = val ? (hints[score] || '') : 'ต้องมีอย่างน้อย 8 ตัว มีตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก และตัวเลข';
    hint.style.color = ['','#ef4444','#f97316','#eab308','#22c55e'][score] || '#94a3b8';
}

function checkMatch() {
    const pw1 = document.getElementById('pw-new').value;
    const pw2 = document.getElementById('pw-confirm').value;
    const hint = document.getElementById('match-hint');
    if (!pw2) { hint.style.display='none'; return; }
    hint.style.display = 'block';
    if (pw1 === pw2) { hint.textContent='✅ รหัสผ่านตรงกัน'; hint.style.color='#22c55e'; }
    else             { hint.textContent='❌ รหัสผ่านไม่ตรงกัน'; hint.style.color='#ef4444'; }
}

async function handleFormSubmit(formId, btnId) {
    const btn = document.getElementById(btnId), orig = btn.textContent;
    btn.disabled=true; btn.textContent='⏳...';
    try {
        const res  = await fetch(location.pathname, {
            method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'},
            body: new FormData(document.getElementById(formId))
        });
        const data = await res.json();
        if (data.ok) { Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1800,timerProgressBar:true,showConfirmButton:false}); }
        else          { Swal.fire({icon:'error',title:'ผิดพลาด',text:data.msg,confirmButtonColor:'#1a3a5c'}); }
    } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
    finally { btn.disabled=false; btn.textContent=orig; }
}

document.getElementById('officeForm').addEventListener('submit', e => { e.preventDefault(); handleFormSubmit('officeForm','officeSubmitBtn'); });
document.getElementById('pwForm').addEventListener('submit', e => {
    e.preventDefault();
    const pw1 = document.getElementById('pw-new').value;
    const pw2 = document.getElementById('pw-confirm').value;
    if (pw1 !== pw2) { Swal.fire({icon:'error',title:'รหัสผ่านไม่ตรงกัน',confirmButtonColor:'#1a3a5c'}); return; }
    handleFormSubmit('pwForm','pwSubmitBtn');
});

async function uploadLogo(input) {
    if (!input.files[0]) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fd.append('action', 'upload_logo');
    fd.append('logo', input.files[0]);
    try {
        const res  = await fetch(location.pathname, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
        const data = await res.json();
        if (data.ok) {
            const prev = document.getElementById('logoPreview');
            const img  = document.createElement('img');
            img.src       = data.logo + '?t=' + Date.now();
            img.className = 'logo-preview';
            img.id        = 'logoPreview';
            img.alt       = 'logo';
            prev.replaceWith(img);
            Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1600,timerProgressBar:true,showConfirmButton:false});
        } else {
            Swal.fire({icon:'error',title:'ผิดพลาด',text:data.msg,confirmButtonColor:'#1a3a5c'});
        }
    } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
    input.value = '';
}
</script>

<?php include '../includes/footer.php'; ?>