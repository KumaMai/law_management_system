<?php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
requireLogin();

if ($_SESSION['role'] !== 'client') {
    header('Location: /pages/dashboard.php');
    exit;
}

$pdo      = getDB();
$officeId = $_SESSION['office_id'];
$userId   = $_SESSION['user_id'];
$error    = '';
$success  = '';

// ดึง client_id
$cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id = ?");
$cp->execute([$userId]);
$clientId = $cp->fetchColumn();

if (!$clientId) {
    die('<div class="container"><div class="alert alert-error">ไม่พบข้อมูลโปรไฟล์ กรุณาติดต่อผู้ดูแลระบบ</div></div>');
}

// ดึงสัญญาที่ approved และมี contract แล้วของลูกความคนนี้
$contractsStmt = $pdo->prepare("
    SELECT con.contract_id, con.contract_date, con.fee_amount, con.payment_status,
           cr.request_id, cr.detail,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           lp.specialization
    FROM contracts con
    JOIN case_requests cr  ON con.request_id  = cr.request_id
    JOIN lawyer_profiles lp ON cr.lawyer_id   = lp.lawyer_id
    WHERE cr.client_id = ? AND cr.status = 'approved'
    ORDER BY con.created_at DESC
");
$contractsStmt->execute([$clientId]);
$contracts = $contractsStmt->fetchAll();

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contractId   = (int)($_POST['contract_id'] ?? 0);
    $docType      = trim($_POST['document_type'] ?? '');
    $note         = trim($_POST['note'] ?? '');
    $proposedFee  = $_POST['proposed_fee'] !== '' ? (float)$_POST['proposed_fee'] : null;

    // Validate contract เป็นของลูกความคนนี้จริง
    $chk = $pdo->prepare("
        SELECT con.contract_id FROM contracts con
        JOIN case_requests cr ON con.request_id = cr.request_id
        WHERE con.contract_id = ? AND cr.client_id = ?
    ");
    $chk->execute([$contractId, $clientId]);

    if (!$contractId || !$docType || !$chk->fetch()) {
        $error = 'ข้อมูลไม่ถูกต้อง กรุณาลองใหม่';
    } elseif (empty($_FILES['document']['name'])) {
        $error = 'กรุณาเลือกไฟล์ที่ต้องการส่ง';
    } else {
        $file     = $_FILES['document'];
        $origName = basename($file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed  = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        $maxSize  = 10 * 1024 * 1024; // 10MB

        if (!in_array($ext, $allowed)) {
            $error = 'ประเภทไฟล์ไม่รองรับ (รองรับ: PDF, DOC, DOCX, JPG, PNG)';
        } elseif ($file['size'] > $maxSize) {
            $error = 'ไฟล์มีขนาดเกิน 10MB';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'เกิดข้อผิดพลาดในการอัปโหลด กรุณาลองใหม่';
        } else {
            // สร้างชื่อไฟล์ unique
            $newName  = 'contract_' . $contractId . '_' . time() . '_' . uniqid() . '.' . $ext;
            $uploadDir = '/var/www/html/uploads/contracts/';
            $filePath  = $uploadDir . $newName;

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // รวม note เข้า file_name เลย ไม่ต้อง UPDATE แยก
                $displayName = $note ? $origName . ' | หมายเหตุ: ' . $note : $origName;

                $pdo->prepare("
                    INSERT INTO case_documents
                        (contract_id, document_type, file_name, file_path, file_size, uploaded_by, visibility)
                    VALUES (?, ?, ?, ?, ?, ?, 'lawyer_only')
                ")->execute([
                    $contractId,
                    $docType,
                    $displayName,
                    'uploads/contracts/' . $newName,
                    $file['size'],
                    $userId,
                ]);

                // อัปเดตค่าดำเนินคดีที่ลูกความเสนอ (ถ้ามี)
                if ($proposedFee !== null) {
                    $pdo->prepare("
                        UPDATE contracts SET fee_amount = ?
                        WHERE contract_id = ?
                    ")->execute([$proposedFee, $contractId]);
                    // reload contracts list
                    $contractsStmt->execute([$clientId]);
                    $contracts = $contractsStmt->fetchAll();
                }

                $success = 'ส่งเอกสารสำเร็จ!' . ($proposedFee ? ' เสนอค่าดำเนินคดี '.number_format($proposedFee,2).' บาทแล้ว' : '') . ' ทนายจะได้รับเอกสารของคุณแล้ว';
            } else {
                $error = 'ไม่สามารถบันทึกไฟล์ได้ กรุณาลองใหม่';
            }
        }
    }
}

// ดึงเอกสารที่ส่งไปแล้วทั้งหมด
$docsStmt = $pdo->prepare("
    SELECT cd.*, con.contract_id,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name
    FROM case_documents cd
    JOIN contracts con       ON cd.contract_id  = con.contract_id
    JOIN case_requests cr    ON con.request_id  = cr.request_id
    JOIN lawyer_profiles lp  ON cr.lawyer_id    = lp.lawyer_id
    WHERE cr.client_id = ? AND cd.uploaded_by = ?
    ORDER BY cd.created_at DESC
");
$docsStmt->execute([$clientId, $userId]);
$documents = $docsStmt->fetchAll();

$docTypes = [
    'contract'  => '📄 สัญญาว่าจ้าง',
    'id_card'   => '🪪 สำเนาบัตรประชาชน',
    'evidence'  => '🔍 หลักฐานประกอบคดี',
    'power_of_attorney' => '📝 หนังสือมอบอำนาจ',
    'other'     => '📎 เอกสารอื่นๆ',
];

$pageTitle = 'ส่งเอกสารและเสนอค่าดำเนินคดี';
include '../includes/header.php';
?>

<!-- ฟอร์มส่งเอกสาร -->
<div class="card">
    <h2>📤 ส่งเอกสารสัญญาและเสนอค่าดำเนินคดี</h2>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (empty($contracts)): ?>
    <div style="text-align:center; padding:32px; color:#888;">
        <div style="font-size:3rem; margin-bottom:12px;">📋</div>
        <p>ยังไม่มีสัญญาที่พร้อมส่งเอกสาร</p>
        <p style="font-size:0.85rem; margin-top:8px;">ต้องรอให้ทนายรับคำขอก่อน จึงจะส่งเอกสารได้</p>
        <a href="/pages/send_request.php" class="btn btn-primary" style="margin-top:16px;">📨 ส่งคำขอว่าจ้าง</a>
    </div>
    <?php else: ?>

    <form method="POST" enctype="multipart/form-data">

        <!-- เลือกสัญญา -->
        <div class="form-group">
            <label>เลือกสัญญา / คดี <span style="color:red">*</span></label>
            <select name="contract_id" required onchange="showContractInfo(this)">
                <option value="">-- เลือกคดีที่ต้องการส่งเอกสาร --</option>
                <?php foreach ($contracts as $c): ?>
                <option value="<?= $c['contract_id'] ?>"
                    data-lawyer="<?= htmlspecialchars($c['lawyer_name']) ?>"
                    data-spec="<?= htmlspecialchars($c['specialization'] ?? '—') ?>"
                    data-detail="<?= htmlspecialchars(mb_substr($c['detail'], 0, 80)) ?>..."
                    <?= (isset($_POST['contract_id']) && $_POST['contract_id'] == $c['contract_id']) ? 'selected' : '' ?>>
                    สัญญา #<?= $c['contract_id'] ?> — ทนาย <?= htmlspecialchars($c['lawyer_name']) ?>
                    <?= $c['contract_date'] ? '('.$c['contract_date'].')' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Info card สัญญาที่เลือก -->
        <div id="contract-info" style="display:none; background:#f0f4f8; border-radius:8px; padding:14px; margin-bottom:16px; font-size:0.88rem;">
            <strong>👨‍⚖️ ทนาย:</strong> <span id="info-lawyer"></span>
            (<span id="info-spec"></span>)<br>
            <strong>📋 รายละเอียดคดี:</strong> <span id="info-detail"></span>
        </div>

        <!-- ค่าดำเนินคดีที่เสนอ -->
        <div class="form-group">
            <label>💰 ค่าดำเนินคดีที่เสนอ (บาท)</label>
            <input type="number" name="proposed_fee" step="0.01" min="0"
                   placeholder="ระบุค่าดำเนินคดีที่คุณเสนอ เช่น 50000"
                   value="<?= htmlspecialchars($_POST['proposed_fee'] ?? '') ?>">
            <small style="color:#888; display:block; margin-top:4px;">
                💡 กรอกเพื่อแจ้งทนายว่าคุณมีงบประมาณเท่าไหร่สำหรับคดีนี้ (ไม่บังคับ)
            </small>
        </div>

        <!-- ประเภทเอกสาร -->
        <div class="form-group">
            <label>ประเภทเอกสาร <span style="color:red">*</span></label>
            <select name="document_type" required>
                <option value="">-- เลือกประเภท --</option>
                <?php foreach ($docTypes as $val => $label): ?>
                <option value="<?= $val ?>" <?= (isset($_POST['document_type']) && $_POST['document_type'] === $val) ? 'selected' : '' ?>>
                    <?= $label ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- อัปโหลดไฟล์ -->
        <div class="form-group">
            <label>ไฟล์เอกสาร <span style="color:red">*</span></label>
            <input type="file" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required
                   onchange="showFilePreview(this)">
            <div id="file-preview" style="display:none; margin-top:8px; padding:10px; background:#f8f9fa; border-radius:6px; font-size:0.85rem; color:#555;"></div>
            <small style="color:#888; display:block; margin-top:4px;">
                รองรับ: PDF, DOC, DOCX, JPG, PNG | ขนาดสูงสุด 10MB
            </small>
        </div>

        <!-- หมายเหตุ -->
        <div class="form-group">
            <label>หมายเหตุเพิ่มเติม (ถ้ามี)</label>
            <textarea name="note" rows="3"
                placeholder="อธิบายเพิ่มเติมเกี่ยวกับเอกสารนี้..."><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
        </div>

        <div style="background:#e8f4e8; border-left:4px solid #198754; padding:12px; border-radius:4px; margin-bottom:16px; font-size:0.88rem; color:#555;">
            🔒 เอกสารที่ส่งจะมองเห็นได้เฉพาะทนายของคุณเท่านั้น
        </div>

        <button type="submit" class="btn btn-primary">📤 ส่งเอกสาร</button>
    </form>
    <?php endif; ?>
</div>

<!-- เอกสารที่ส่งไปแล้ว -->
<div class="card">
    <h2>📁 เอกสารที่ส่งไปแล้ว</h2>

    <?php if (empty($documents)): ?>
    <p style="color:#888;">ยังไม่มีเอกสารที่ส่ง</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>ทนาย</th>
                <th>ประเภทเอกสาร</th>
                <th>ชื่อไฟล์</th>
                <th>ขนาด</th>
                <th>วันที่ส่ง</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($documents as $d): ?>
            <tr>
                <td><?= $d['document_id'] ?></td>
                <td><?= htmlspecialchars($d['lawyer_name']) ?></td>
                <td><?= htmlspecialchars($docTypes[$d['document_type']] ?? $d['document_type']) ?></td>
                <td>
                    <a href="/<?= htmlspecialchars($d['file_path']) ?>" target="_blank"
                       style="color:#1a3a5c;">
                        📎 <?= htmlspecialchars($d['file_name']) ?>
                    </a>
                </td>
                <td><?= $d['file_size'] ? number_format($d['file_size'] / 1024, 1) . ' KB' : '—' ?></td>
                <td><?= date('d/m/Y H:i', strtotime($d['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script>
function showContractInfo(select) {
    const opt  = select.options[select.selectedIndex];
    const info = document.getElementById('contract-info');
    if (select.value) {
        document.getElementById('info-lawyer').textContent = opt.dataset.lawyer;
        document.getElementById('info-spec').textContent   = opt.dataset.spec;
        document.getElementById('info-detail').textContent = opt.dataset.detail;
        info.style.display = 'block';
    } else {
        info.style.display = 'none';
    }
}

function showFilePreview(input) {
    const preview = document.getElementById('file-preview');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const size = (file.size / 1024).toFixed(1);
        preview.innerHTML = `📎 <strong>${file.name}</strong> &nbsp;|&nbsp; ขนาด: ${size} KB`;
        preview.style.display = 'block';
    }
}
</script>

<?php include '../includes/footer.php'; ?>