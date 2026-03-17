<?php
// clients.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireRole('admin');

$pdo      = getDB();
$officeId = $_SESSION['office_id'];

// Helper: mask เลขบัตรประชาชน → แสดงเฉพาะ 3 ตัวแรก + mask + 2 ตัวท้าย
// เช่น 1939900588461 → 193•••••••61
function maskCitizenId(?string $id): string {
    if (!$id || strlen($id) !== 13) return $id ?? '—';
    return substr($id, 0, 3) . str_repeat('•', 8) . substr($id, -2);
}
$result   = ['ok' => false, 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $isAjax    = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $fname     = trim($_POST['fname'] ?? '');
    $lname     = trim($_POST['lname'] ?? '');
    $citizenId = trim($_POST['citizen_id'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $error     = '';

    if (!$username || !$email || !$password || !$fname || !$lname) {
        $error = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบ (ชื่อ, นามสกุล, Username, อีเมล, รหัสผ่าน)';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username ต้องเป็นตัวอักษร a-z, 0-9 หรือ _ ความยาว 3-30 ตัว';
    } elseif ($citizenId && strlen($citizenId) !== 13) {
        $error = 'เลขบัตรประชาชนต้องมี 13 หลัก';
    } else {
        $chk = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR username = ?");
        $chk->execute([$email, $username]);
        if ($chk->fetch()) $error = 'อีเมลหรือ Username นี้ถูกใช้งานแล้ว';

        if (!$error) {
            try {
                $pdo->beginTransaction();
                $hash   = password_hash($password, PASSWORD_BCRYPT);
                $roleId = $pdo->query("SELECT role_id FROM roles WHERE role_name='client'")->fetchColumn();
                $pdo->prepare("
                    INSERT INTO users (office_id, role_id, username, email, password_hash, status)
                    VALUES (?, ?, ?, ?, ?, 'active')
                ")->execute([$officeId, $roleId, $username, $email, $hash]);
                $userId = $pdo->lastInsertId();
                $pdo->prepare("
                    INSERT INTO client_profiles (user_id, fname, lname, citizen_id, phone, address)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$userId, $fname, $lname, $citizenId ?: null, $phone, $address]);
                $pdo->commit();
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'เพิ่มลูกความสำเร็จ']); exit; }
                $result = ['ok'=>true,'msg'=>'เพิ่มลูกความสำเร็จ'];
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง';
            }
        }
    }
    if ($error) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$error]); exit; }
        $result = ['ok'=>false,'msg'=>$error];
    }
}

$stmt = $pdo->prepare("
    SELECT cp.*, u.email, u.username
    FROM client_profiles cp
    JOIN users u ON cp.user_id = u.user_id
    WHERE u.office_id = ?
    ORDER BY cp.created_at DESC
");
$stmt->execute([$officeId]);
$clients   = $stmt->fetchAll();
$pageTitle = 'จัดการลูกความ';
include '../includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if ($result['msg']): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        icon: '<?= $result['ok'] ? 'success' : 'error' ?>',
        title: '<?= $result['ok'] ? 'สำเร็จ!' : 'เกิดข้อผิดพลาด' ?>',
        text: '<?= addslashes($result['msg']) ?>',
        confirmButtonColor: '#1a3a5c',
        <?php if ($result['ok']): ?>timer: 2000, timerProgressBar: true,<?php endif; ?>
    });
});
</script>
<?php endif; ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h2 style="margin:0;">👤 รายชื่อลูกความ</h2>
        <button onclick="openClientModal()" class="btn btn-primary">➕ เพิ่มลูกความ</button>
    </div>

    <?php if (empty($clients)): ?>
    <p style="color:#888;">ยังไม่มีลูกความในระบบ</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>ชื่อ-นามสกุล</th><th>Username</th><th>อีเมล</th>
                <th>เลขบัตรประชาชน</th><th>เบอร์โทร</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
        <tr>
            <td><?= $c['client_id'] ?></td>
            <td><?= htmlspecialchars($c['fname'].' '.$c['lname']) ?></td>
            <td><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:.83rem;"><?= htmlspecialchars($c['username'] ?? '—') ?></code></td>
            <td><?= htmlspecialchars($c['email']) ?></td>
            <td style="letter-spacing:.05em;"><?= htmlspecialchars(maskCitizenId($c['citizen_id'])) ?></td>
            <td><?= htmlspecialchars($c['phone'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal เพิ่มลูกความ -->
<div id="clientModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;color:#1a3a5c;">➕ เพิ่มลูกความ</h3>
            <button onclick="closeClientModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;line-height:1;">✕</button>
        </div>
        <form id="clientForm">
            <?= csrf_field() ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group">
                    <label>ชื่อ <span style="color:red">*</span></label>
                    <input type="text" name="fname" required>
                </div>
                <div class="form-group">
                    <label>นามสกุล <span style="color:red">*</span></label>
                    <input type="text" name="lname" required>
                </div>
                <div class="form-group">
                    <label>Username <span style="color:red">*</span></label>
                    <input type="text" name="username" placeholder="เช่น client_john" required>
                    <small style="color:#94a3b8;font-size:.75rem;">a-z, 0-9, _ ความยาว 3-30 ตัว</small>
                </div>
                <div class="form-group">
                    <label>อีเมล <span style="color:red">*</span></label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>รหัสผ่าน <span style="color:red">*</span></label>
                    <input type="password" name="password" required>
                </div>
                <div class="form-group">
                    <label>เบอร์โทร</label>
                    <input type="text" name="phone">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>เลขบัตรประชาชน (13 หลัก)</label>
                    <input type="text" name="citizen_id" maxlength="13" pattern="\d{13}"
                           placeholder="xxxxxxxxxxxxxxxxx">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label>ที่อยู่</label>
                    <textarea name="address" rows="2" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"></textarea>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" onclick="closeClientModal()"
                        style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">
                    ยกเลิก
                </button>
                <button type="submit" id="clientSubmitBtn"
                        style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">
                    💾 บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openClientModal() {
    document.getElementById('clientModal').style.display = 'flex';
}
function closeClientModal() {
    document.getElementById('clientModal').style.display = 'none';
    document.getElementById('clientForm').reset();
}
document.getElementById('clientModal').addEventListener('click', function(e) {
    if (e.target === this) closeClientModal();
});

document.getElementById('clientForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('clientSubmitBtn');
    btn.disabled = true;
    btn.textContent = '⏳ กำลังบันทึก...';
    try {
        const res  = await fetch(location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(this)
        });
        const data = await res.json();
        closeClientModal();
        if (data.ok) {
            await Swal.fire({ icon:'success', title:'สำเร็จ!', text:data.msg,
                confirmButtonColor:'#1a3a5c', timer:2000, timerProgressBar:true });
            location.reload();
        } else {
            Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text:data.msg, confirmButtonColor:'#1a3a5c' });
        }
    } catch {
        Swal.fire({ icon:'error', title:'ผิดพลาด', text:'ไม่สามารถเชื่อมต่อได้', confirmButtonColor:'#1a3a5c' });
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 บันทึก';
    }
});
</script>

<?php include '../includes/footer.php'; ?>