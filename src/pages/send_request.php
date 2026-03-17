<?php
// send_request.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireLogin();

if ($_SESSION['role'] !== 'client') {
    header('Location: /pages/dashboard.php');
    exit;
}

$pdo      = getDB();
$officeId = $_SESSION['office_id'];

// ดึง client_id
$cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id = ?");
$cp->execute([$_SESSION['user_id']]);
$clientId = $cp->fetchColumn();

if (!$clientId) {
    die('<div class="container"><div class="alert alert-error">ไม่พบข้อมูลโปรไฟล์ลูกความ กรุณาติดต่อผู้ดูแลระบบ</div></div>');
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $isAjax   = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $lawyerId = (int)($_POST['lawyer_id'] ?? 0);
    $detail   = trim($_POST['detail'] ?? '');
    $error    = '';

    if (!$lawyerId || !$detail) {
        $error = 'กรุณาเลือกทนายและกรอกรายละเอียดคดี';
    } else {
        $chk = $pdo->prepare("
            SELECT request_id FROM case_requests
            WHERE client_id = ? AND lawyer_id = ? AND status IN ('pending','approved')
        ");
        $chk->execute([$clientId, $lawyerId]);
        if ($chk->fetch()) {
            $error = 'คุณมีคำขอหรือคดีที่กำลังดำเนินการกับทนายคนนี้อยู่แล้ว';
        } else {
            $requestDate = date('Y-m-d');
            $expireDate  = date('Y-m-d', strtotime('+14 days'));
            $pdo->prepare("
                INSERT INTO case_requests
                    (office_id, client_id, lawyer_id, detail, request_date, expire_date, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ")->execute([$officeId, $clientId, $lawyerId, $detail, $requestDate, $expireDate]);

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'msg' => 'ส่งคำขอสำเร็จ! ทนายจะตอบกลับภายใน 14 วัน']);
                exit;
            }
        }
    }

    if ($error && $isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => $error]);
        exit;
    }
}

// ดึงรายชื่อทนาย
$lawyersStmt = $pdo->prepare("
    SELECT lp.lawyer_id, lp.fname, lp.lname, lp.specialization, lp.phone, lp.license_no
    FROM lawyer_profiles lp
    JOIN users u ON lp.user_id = u.user_id
    WHERE u.office_id = ? AND lp.status = 'active'
    ORDER BY lp.fname
");
$lawyersStmt->execute([$officeId]);
$lawyers = $lawyersStmt->fetchAll();

// ดึงประวัติคำขอ
$historyStmt = $pdo->prepare("
    SELECT cr.*,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           lp.specialization
    FROM case_requests cr
    JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
    WHERE cr.client_id = ?
    ORDER BY cr.created_at DESC
");
$historyStmt->execute([$clientId]);
$history = $historyStmt->fetchAll();

$pageTitle = 'ส่งคำขอว่าจ้างทนาย';
include '../includes/header.php';

$badgeMap = ['pending'=>'badge-pending','approved'=>'badge-approved','rejected'=>'badge-rejected','expired'=>'badge-expired'];
$statusTH = ['pending'=>'⏳ รอทนายตอบรับ','approved'=>'✅ อนุมัติแล้ว','rejected'=>'❌ ถูกปฏิเสธ','expired'=>'⌛ หมดอายุ'];
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Header + ปุ่มเพิ่ม -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h2 style="margin:0;">📨 คำขอว่าจ้างทนาย</h2>
        <?php if (!empty($lawyers)): ?>
        <button onclick="openRequestModal()" class="btn btn-primary">➕ ส่งคำขอใหม่</button>
        <?php endif; ?>
    </div>

    <?php if (empty($lawyers)): ?>
    <p style="color:#888;">ยังไม่มีทนายความในสำนักงาน กรุณาติดต่อผู้ดูแลระบบ</p>
    <?php endif; ?>

    <!-- ตารางประวัติ -->
    <?php if (empty($history)): ?>
    <p style="color:#888;">ยังไม่มีคำขอ</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>ทนาย</th><th>ความเชี่ยวชาญ</th>
                <th>รายละเอียดคดี</th><th>วันที่ส่ง</th><th>หมดอายุ</th>
                <th>สถานะ</th><th>เหตุผล (ปฏิเสธ)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td><?= $h['request_id'] ?></td>
                <td><?= htmlspecialchars($h['lawyer_name']) ?></td>
                <td><?= htmlspecialchars($h['specialization'] ?? '—') ?></td>
                <td style="max-width:220px;">
                    <span title="<?= htmlspecialchars($h['detail']) ?>">
                        <?= htmlspecialchars(mb_substr($h['detail'], 0, 60)) ?><?= mb_strlen($h['detail']) > 60 ? '...' : '' ?>
                    </span>
                </td>
                <td><?= $h['request_date'] ?></td>
                <td><?= $h['expire_date'] ?></td>
                <td><span class="badge <?= $badgeMap[$h['status']] ?? '' ?>"><?= $statusTH[$h['status']] ?? $h['status'] ?></span></td>
                <td><?= $h['reject_reason'] ? htmlspecialchars($h['reject_reason']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal ส่งคำขอ -->
<div id="requestModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;color:#1a3a5c;">📨 ส่งคำขอว่าจ้างทนาย</h3>
            <button onclick="closeRequestModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;line-height:1;">✕</button>
        </div>
        <form id="requestForm">
            <?= csrf_field() ?>
            <!-- เลือกทนาย -->
            <div class="form-group">
                <label>เลือกทนายที่ต้องการ <span style="color:red">*</span></label>
                <select name="lawyer_id" id="modal-lawyer-select" required onchange="showLawyerInfo(this)">
                    <option value="">-- เลือกทนายความ --</option>
                    <?php foreach ($lawyers as $l): ?>
                    <option value="<?= $l['lawyer_id'] ?>"
                        data-spec="<?= htmlspecialchars($l['specialization'] ?? '—') ?>"
                        data-phone="<?= htmlspecialchars($l['phone'] ?? '—') ?>"
                        data-license="<?= htmlspecialchars($l['license_no'] ?? '—') ?>">
                        <?= htmlspecialchars($l['fname'].' '.$l['lname']) ?>
                        <?php if ($l['specialization']): ?> — <?= htmlspecialchars($l['specialization']) ?><?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Info card ทนาย -->
            <div id="modal-lawyer-info" style="display:none;background:#f0f4f8;border-radius:8px;padding:14px;margin-bottom:16px;font-size:0.9rem;">
                <strong>📋 ข้อมูลทนาย</strong><br>
                ความเชี่ยวชาญ: <span id="modal-info-spec"></span> |
                เบอร์โทร: <span id="modal-info-phone"></span> |
                เลขใบอนุญาต: <span id="modal-info-license"></span>
            </div>

            <!-- รายละเอียดคดี -->
            <div class="form-group">
                <label>รายละเอียดคดี <span style="color:red">*</span></label>
                <textarea name="detail" rows="5"
                    placeholder="อธิบายรายละเอียดของคดี เช่น ประเภทคดี, เหตุการณ์ที่เกิดขึ้น, ความต้องการ..."
                    required></textarea>
            </div>

            <div style="background:#fff8e1;border-left:4px solid #ffc107;padding:12px;border-radius:4px;margin-bottom:16px;font-size:0.88rem;color:#555;">
                ⚠️ คำขอจะหมดอายุภายใน <strong>14 วัน</strong> หากทนายไม่ตอบกลับ
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" onclick="closeRequestModal()"
                        style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">
                    ยกเลิก
                </button>
                <button type="submit" id="requestSubmitBtn"
                        style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">
                    📨 ส่งคำขอ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRequestModal() {
    document.getElementById('requestModal').style.display = 'flex';
}
function closeRequestModal() {
    document.getElementById('requestModal').style.display = 'none';
    document.getElementById('requestForm').reset();
    document.getElementById('modal-lawyer-info').style.display = 'none';
}
document.getElementById('requestModal').addEventListener('click', function(e) {
    if (e.target === this) closeRequestModal();
});

function showLawyerInfo(select) {
    const opt  = select.options[select.selectedIndex];
    const info = document.getElementById('modal-lawyer-info');
    if (select.value) {
        document.getElementById('modal-info-spec').textContent    = opt.dataset.spec;
        document.getElementById('modal-info-phone').textContent   = opt.dataset.phone;
        document.getElementById('modal-info-license').textContent = opt.dataset.license;
        info.style.display = 'block';
    } else {
        info.style.display = 'none';
    }
}

document.getElementById('requestForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('requestSubmitBtn');
    btn.disabled = true;
    btn.textContent = '⏳ กำลังส่ง...';
    try {
        const res  = await fetch(location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(this)
        });
        const data = await res.json();
        closeRequestModal();
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
        btn.textContent = '📨 ส่งคำขอ';
    }
});
</script>

<?php include '../includes/footer.php'; ?>