<?php
// ============================================================
// แทนที่ไฟล์: src/pages/case_requests.php
// ช่องโหว่ที่แก้:
//   - Missing Authorization → เพิ่ม AND lawyer_id=? ใน UPDATE
//   - CSRF                  → csrf_verify() + csrf_field()
// ============================================================

session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/notification_helper.php';
require_once '../config/activity_log_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$officeId = $_SESSION['office_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'lawyer') {

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    csrf_verify();

    $requestId = (int)($_POST['request_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    // ── ดึง lawyer_id ของตัวเองก่อน ──
    $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lp->execute([$_SESSION['user_id']]);
    $lawyerId = $lp->fetchColumn();

    if ($action === 'approve') {
        // ── แก้ Missing Authorization: เพิ่ม AND lawyer_id=? ──
        // ทำให้ทนายอนุมัติได้เฉพาะคำขอที่ส่งมาหาตัวเองเท่านั้น
        $stmt = $pdo->prepare("
            UPDATE case_requests
            SET status = 'approved'
            WHERE request_id = ?
              AND lawyer_id  = ?
              AND status     = 'pending'
        ");
        $stmt->execute([$requestId, $lawyerId]);

        // ถ้า update สำเร็จ (affectedRows > 0) จึง insert contract
        if ($stmt->rowCount() > 0) {
            $pdo->prepare("
                INSERT INTO contracts
                    (request_id, contract_date, status, payment_status, contract_review_status)
                VALUES (?, CURDATE(), 'active', 'pending', 'pending_lawyer_review')
            ")->execute([$requestId]);

            // แจ้งเตือนลูกความ + admin
            $crRow = $pdo->prepare("SELECT client_id FROM case_requests WHERE request_id=?");
            $crRow->execute([$requestId]);
            $crClientId = $crRow->fetchColumn();
            $clientUid = notif_get_user_id_by_client($pdo, (int)$crClientId);
            if ($clientUid) {
                notif_create($pdo, $officeId, $clientUid, 'case_request', 'ทนายรับคำขอว่าจ้างของคุณแล้ว', 'คำขอ #'.$requestId.' ได้รับการอนุมัติ', '/pages/my_cases.php', 'case_request', $requestId);
            }
            notif_create_multi($pdo, $officeId, notif_get_admin_ids($pdo, $officeId), 'case_request', 'คำขอว่าจ้าง #'.$requestId.' ได้รับการอนุมัติ', null, '/pages/case_requests.php', 'case_request', $requestId);
            audit_log($pdo, $officeId, $_SESSION['user_id'], 'approve', 'case_request', $requestId, 'ทนายอนุมัติคำขอว่าจ้าง #'.$requestId);
        }

    } elseif ($action === 'reject') {
        $reason = trim($_POST['reject_reason'] ?? '');

        // ── แก้ Missing Authorization: เพิ่ม AND lawyer_id=? ──
        $stmt2 = $pdo->prepare("
            UPDATE case_requests
            SET status = 'rejected', reject_reason = ?
            WHERE request_id = ?
              AND lawyer_id  = ?
              AND status     = 'pending'
        ");
        $stmt2->execute([$reason, $requestId, $lawyerId]);

        if ($stmt2->rowCount() > 0) {
            $crRow = $pdo->prepare("SELECT client_id FROM case_requests WHERE request_id=?");
            $crRow->execute([$requestId]);
            $crClientId = $crRow->fetchColumn();
            $clientUid = notif_get_user_id_by_client($pdo, (int)$crClientId);
            if ($clientUid) {
                notif_create($pdo, $officeId, $clientUid, 'case_request', 'คำขอว่าจ้างถูกปฏิเสธ', 'คำขอ #'.$requestId.' ถูกปฏิเสธ', '/pages/my_cases.php', 'case_request', $requestId);
            }
            audit_log($pdo, $officeId, $_SESSION['user_id'], 'reject', 'case_request', $requestId, 'ทนายปฏิเสธคำขอว่าจ้าง #'.$requestId);
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        $msg = $action === 'approve' ? '✅ รับคำขอเรียบร้อยแล้ว ระบบสร้างสัญญาให้อัตโนมัติ' : '❌ ปฏิเสธคำขอเรียบร้อยแล้ว';
        echo json_encode(['ok' => true, 'msg' => $msg]);
        exit;
    }
    header('Location: /pages/case_requests.php');
    exit;
}

// ── Admin: Force Expire / Cancel คำขอ ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'admin') {
    $isAjax    = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    csrf_verify();
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action    = $_POST['action'] ?? '';

    // ตรวจ ownership
    $chk = $pdo->prepare("SELECT request_id FROM case_requests WHERE request_id=? AND office_id=?");
    $chk->execute([$requestId, $officeId]);
    if (!$chk->fetch()) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่พบคำขอหรือไม่มีสิทธิ์']); exit; }
        header('Location: /pages/case_requests.php'); exit;
    }

    if ($action === 'force_expire') {
        $pdo->prepare("UPDATE case_requests SET status='expired' WHERE request_id=? AND status='pending'")
            ->execute([$requestId]);
        audit_log($pdo, $officeId, $_SESSION['user_id'], 'expire', 'case_request', $requestId, 'Admin บังคับหมดอายุคำขอ #'.$requestId);
        $msg = '⌛ บังคับหมดอายุคำขอแล้ว';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>$msg]); exit; }
    }

    if ($action === 'admin_cancel') {
        $reason = trim($_POST['cancel_reason'] ?? 'ยกเลิกโดย Admin');
        $pdo->prepare("UPDATE case_requests SET status='rejected', reject_reason=? WHERE request_id=? AND status IN ('pending','approved')")
            ->execute([$reason, $requestId]);
        $pdo->prepare("
            UPDATE contracts SET status='terminated',
                lawyer_note=CONCAT(COALESCE(lawyer_note,''), ' | Admin ยกเลิก: ', ?)
            WHERE request_id=? AND status='active'
        ")->execute([$reason, $requestId]);
        audit_log($pdo, $officeId, $_SESSION['user_id'], 'cancel', 'case_request', $requestId, 'Admin ยกเลิกคำขอ #'.$requestId.': '.$reason);
        $msg = '❌ ยกเลิกคำขอเรียบร้อยแล้ว';
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>$msg]); exit; }
    }

    header('Location: /pages/case_requests.php');
    exit;
}

// ── Fetch requests (เหมือนเดิม) ──
if ($role === 'admin') {
    $stmt = $pdo->prepare("
        SELECT cr.*,
               CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
        FROM case_requests cr
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.office_id = ?
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([$officeId]);

} elseif ($role === 'lawyer') {
    $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lp->execute([$_SESSION['user_id']]);
    $lawyerId = $lp->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT cr.*, CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
        FROM case_requests cr
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.lawyer_id = ?
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([$lawyerId]);

} else {
    $cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
    $cp->execute([$_SESSION['user_id']]);
    $clientId = $cp->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT cr.*, CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
        FROM case_requests cr
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        WHERE cr.client_id = ?
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([$clientId]);
}

$requests  = $stmt->fetchAll();
$pageTitle = 'คำขอว่าจ้างทนาย';
include '../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php
$badgeMap = ['pending'=>'badge-pending','approved'=>'badge-approved','rejected'=>'badge-rejected','expired'=>'badge-expired'];
$statusTH = ['pending'=>'รอดำเนินการ','approved'=>'อนุมัติแล้ว','rejected'=>'ปฏิเสธแล้ว','expired'=>'หมดอายุ'];
?>

<div class="card">
    <h2>📋 คำขอว่าจ้างทนาย</h2>

    <?php if (empty($requests)): ?>
    <p style="color:#888;">ยังไม่มีคำขอในระบบ</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th><th>ลูกความ</th><th>ทนาย</th><th>รายละเอียด</th>
                <th>วันที่ส่งคำขอ</th><th>วันหมดอายุ</th><th>สถานะ</th>
                <?php if ($role === 'lawyer' || $role === 'admin'): ?><th>การดำเนินการ</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td><?= $r['request_id'] ?></td>
                <td><?= htmlspecialchars($r['client_name']) ?></td>
                <td><?= htmlspecialchars($r['lawyer_name']) ?></td>
                <td><?= htmlspecialchars(mb_substr($r['detail'] ?? '', 0, 60)) ?>...</td>
                <td><?= $r['request_date'] ?></td>
                <td><?= $r['expire_date'] ?></td>
                <td>
                    <span class="badge <?= $badgeMap[$r['status']] ?? '' ?>">
                        <?= $statusTH[$r['status']] ?? $r['status'] ?>
                    </span>
                </td>
                <?php if ($role === 'lawyer' && $r['status'] === 'pending'): ?>
                <td>
                    <form style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="button" class="btn btn-success btn-sm"
                                onclick="submitRequest(this.closest('form'))">✔ รับ</button>
                    </form>
                    <form style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="reject_reason" class="reject-reason" value="">
                        <button type="button" class="btn btn-danger btn-sm"
                                onclick="promptReject(this.closest('form'))">✘ ปฏิเสธ</button>
                    </form>
                </td>
                <?php elseif ($role === 'lawyer' && $r['status'] === 'approved'): ?>
                <td>
                    <a href="/pages/chat.php?request_id=<?= $r['request_id'] ?>"
                       class="btn btn-sm" style="background:#1e3a5f;color:#fff;border:none;font-weight:700;padding:4px 10px;border-radius:6px;text-decoration:none;">
                        💬 แชท
                    </a>
                </td>
                <?php elseif ($role === 'lawyer'): ?>
                <td><span style="color:#aaa">—</span></td>
                <?php elseif ($role === 'admin'): ?>
                <td style="white-space:nowrap;">
                    <?php if ($r['status'] === 'pending'): ?>
                    <button class="btn btn-sm" style="background:#fef3c7;color:#92400e;border:none;cursor:pointer;font-weight:700;padding:4px 10px;border-radius:6px;"
                        onclick='forceExpire(<?= $r["request_id"] ?>, <?= json_encode($r["client_name"]." → ".$r["lawyer_name"], JSON_UNESCAPED_UNICODE) ?>)'>
                        ⌛ Force Expire
                    </button>
                    <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;cursor:pointer;font-weight:700;padding:4px 10px;border-radius:6px;"
                        onclick='openAdminCancelModal(<?= $r["request_id"] ?>, <?= json_encode($r["client_name"]." → ".$r["lawyer_name"], JSON_UNESCAPED_UNICODE) ?>)'>
                        ❌ ยกเลิก
                    </button>
                    <?php elseif ($r['status'] === 'approved'): ?>
                    <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:none;cursor:pointer;font-weight:700;padding:4px 10px;border-radius:6px;"
                        onclick='openAdminCancelModal(<?= $r["request_id"] ?>, <?= json_encode($r["client_name"]." → ".$r["lawyer_name"], JSON_UNESCAPED_UNICODE) ?>)'>
                        🛑 Cancel + Terminate
                    </button>
                    <?php else: ?>
                    <span style="color:#aaa;font-size:.8rem;">—</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script>
async function submitRequest(form) {
    const btn = form.querySelector('button');
    const origText = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳...';
    try {
        const res  = await fetch(location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        });
        const data = await res.json();
        if (data.ok) {
            await Swal.fire({ icon:'success', title:'สำเร็จ!', text: data.msg,
                confirmButtonColor:'#1a3a5c', timer:2000, timerProgressBar:true, showConfirmButton:false });
            location.reload();
        } else {
            Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text: data.msg, confirmButtonColor:'#1a3a5c' });
            btn.disabled = false; btn.textContent = origText;
        }
    } catch {
        Swal.fire({ icon:'error', title:'ผิดพลาด', text:'ไม่สามารถเชื่อมต่อได้', confirmButtonColor:'#1a3a5c' });
        btn.disabled = false; btn.textContent = origText;
    }
}

async function promptReject(form) {
    const { value: reason, isConfirmed } = await Swal.fire({
        title: 'ระบุเหตุผลในการปฏิเสธ',
        input: 'textarea',
        inputPlaceholder: 'เหตุผล...',
        showCancelButton: true,
        confirmButtonText: '✘ ยืนยันปฏิเสธ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#94a3b8',
        inputValidator: v => !v && 'กรุณาระบุเหตุผล'
    });
    if (!isConfirmed) return;
    form.querySelector('.reject-reason').value = reason;
    submitRequest(form);
}

// ── Admin functions ──
async function forceExpire(requestId, label) {
    const result = await Swal.fire({
        icon: 'warning', title: 'Force Expire คำขอ?',
        html: `บังคับให้ <b>"${label}"</b> หมดอายุทันที?<br><small style="color:#94a3b8">ลูกความจะสามารถส่งคำขอใหม่ได้</small>`,
        showCancelButton: true, confirmButtonText: '⌛ Force Expire', cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#d97706', cancelButtonColor: '#94a3b8',
    });
    if (!result.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fd.append('action', 'force_expire');
    fd.append('request_id', requestId);
    try {
        const res  = await fetch(location.pathname, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
        const data = await res.json();
        if (data.ok) { await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1800,timerProgressBar:true,showConfirmButton:false}); location.reload(); }
        else Swal.fire({icon:'error',title:'ผิดพลาด',text:data.msg,confirmButtonColor:'#1a3a5c'});
    } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
}

function openAdminCancelModal(requestId, label) {
    document.getElementById('ac-request-id').value = requestId;
    document.getElementById('ac-label').textContent = label;
    document.getElementById('ac-reason').value = '';
    document.getElementById('acModal').style.display = 'flex';
}
function closeAcModal() { document.getElementById('acModal').style.display = 'none'; }
document.addEventListener('DOMContentLoaded',function(){var ac=document.getElementById('acModal');if(ac)ac.addEventListener('click',function(e){if(e.target===this)closeAcModal();});});
document.addEventListener('DOMContentLoaded',function(){var af=document.getElementById('acForm');if(af)af.addEventListener('submit', async function(e) {
    e.preventDefault();
    closeAcModal();
    const btn = document.getElementById('acSubmitBtn'), orig = btn.textContent;
    btn.disabled=true; btn.textContent='⏳...';
    try {
        const res  = await fetch(location.pathname, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: new FormData(this) });
        const data = await res.json();
        if (data.ok) { await Swal.fire({icon:'success',title:'สำเร็จ!',text:data.msg,confirmButtonColor:'#1a3a5c',timer:1800,timerProgressBar:true,showConfirmButton:false}); location.reload(); }
        else Swal.fire({icon:'error',title:'ผิดพลาด',text:data.msg,confirmButtonColor:'#1a3a5c'});
    } catch { Swal.fire({icon:'error',title:'ผิดพลาด',text:'เชื่อมต่อไม่ได้',confirmButtonColor:'#1a3a5c'}); }
    finally { btn.disabled=false; btn.textContent=orig; }
});});
</script>

<?php if ($role === 'admin'): ?>
<!-- Modal: Admin Cancel -->
<div id="acModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
<div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 style="margin:0;color:#dc2626;">❌ ยกเลิกคำขอ</h3>
        <button onclick="closeAcModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">✕</button>
    </div>
    <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:9px 13px;margin-bottom:12px;font-size:.83rem;color:#991b1b;">
        ⚠️ ถ้ามีสัญญา active อยู่ — สัญญาจะถูก Terminate พร้อมกันด้วย
    </div>
    <div style="font-size:.87rem;color:#374151;margin-bottom:12px;">คำขอ: <b id="ac-label"></b></div>
    <form id="acForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="admin_cancel">
        <input type="hidden" name="request_id" id="ac-request-id">
        <div class="form-group" style="margin-bottom:14px;">
            <label>เหตุผล <span style="color:red">*</span></label>
            <textarea name="cancel_reason" id="ac-reason" rows="3" required
                style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;resize:vertical;"
                placeholder="ระบุเหตุผลการยกเลิก..."></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="closeAcModal()" style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ปิด</button>
            <button type="submit" id="acSubmitBtn" style="padding:9px 24px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">❌ ยืนยันยกเลิก</button>
        </div>
    </form>
</div></div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>