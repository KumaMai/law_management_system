<?php
// courts.php — Admin: จัดการข้อมูลศาล
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireRole('admin');

$pdo      = getDB();
$officeId = $_SESSION['office_id'];

// ==============================
// Handle POST
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---- เพิ่มศาลใหม่ ----
    if ($action === 'add') {
        $courtName = trim($_POST['court_name'] ?? '');
        $courtType = trim($_POST['court_type'] ?? '');
        $location  = trim($_POST['location'] ?? '');
        $error     = '';

        if (!$courtName) {
            $error = 'กรุณากรอกชื่อศาล';
        } else {
            $dup = $pdo->prepare("SELECT court_id FROM courts WHERE court_name = ?");
            $dup->execute([$courtName]);
            if ($dup->fetch()) $error = 'มีชื่อศาลนี้ในระบบแล้ว';
        }

        if ($error) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$error]); exit; }
        } else {
            $pdo->prepare("INSERT INTO courts (court_name, court_type, location) VALUES (?, ?, ?)")
                ->execute([$courtName, $courtType ?: null, $location ?: null]);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'เพิ่มศาล "'.htmlspecialchars($courtName).'" เรียบร้อยแล้ว']); exit; }
        }
    }

    // ---- แก้ไขศาล ----
    if ($action === 'edit') {
        $courtId   = (int)$_POST['court_id'];
        $courtName = trim($_POST['court_name'] ?? '');
        $courtType = trim($_POST['court_type'] ?? '');
        $location  = trim($_POST['location'] ?? '');
        $error     = '';

        if (!$courtName) {
            $error = 'กรุณากรอกชื่อศาล';
        } else {
            $dup = $pdo->prepare("SELECT court_id FROM courts WHERE court_name = ? AND court_id != ?");
            $dup->execute([$courtName, $courtId]);
            if ($dup->fetch()) $error = 'มีชื่อศาลนี้ในระบบแล้ว';
        }

        if ($error) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$error]); exit; }
        } else {
            $pdo->prepare("UPDATE courts SET court_name=?, court_type=?, location=? WHERE court_id=?")
                ->execute([$courtName, $courtType ?: null, $location ?: null, $courtId]);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'แก้ไขข้อมูลศาลเรียบร้อยแล้ว']); exit; }
        }
    }

    // ---- Merge ศาลซ้ำ ----
    if ($action === 'merge') {
        $fromId = (int)$_POST['from_court_id'];
        $toId   = (int)$_POST['to_court_id'];

        if ($fromId === $toId) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถ merge ศาลเดียวกันได้']); exit; }
        } else {
            try {
                $pdo->beginTransaction();
                // ย้าย filings ทั้งหมดจาก from → to
                $pdo->prepare("UPDATE filings SET court_id=? WHERE court_id=?")->execute([$toId, $fromId]);
                // ลบศาลต้นทาง
                $pdo->prepare("DELETE FROM courts WHERE court_id=?")->execute([$fromId]);
                $pdo->commit();
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'รวมข้อมูลศาลเรียบร้อยแล้ว']); exit; }
            } catch (Exception $e) {
                $pdo->rollBack();
                if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'เกิดข้อผิดพลาดในระบบ']); exit; }
            }
        }
    }

    // ---- ลบศาล ----
    if ($action === 'delete') {
        $courtId = (int)$_POST['court_id'];

        // ตรวจว่ายังมี filings อ้างอิงอยู่หรือไม่
        $used = $pdo->prepare("SELECT COUNT(*) FROM filings WHERE court_id=?");
        $used->execute([$courtId]);
        if ((int)$used->fetchColumn() > 0) {
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถลบได้ มีการยื่นฟ้องที่ใช้ศาลนี้อยู่ ใช้ "รวมศาล" แทน']); exit; }
        } else {
            $pdo->prepare("DELETE FROM courts WHERE court_id=?")->execute([$courtId]);
            if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'ลบศาลเรียบร้อยแล้ว']); exit; }
        }
    }

    header('Location: /pages/courts.php');
    exit;
}

// ==============================
// Fetch courts + จำนวน filings
// ==============================
$courts = $pdo->query("
    SELECT c.*,
           COUNT(f.filing_id) AS filing_count
    FROM courts c
    LEFT JOIN filings f ON c.court_id = f.court_id
    GROUP BY c.court_id
    ORDER BY filing_count DESC, c.court_name ASC
")->fetchAll();

$totalCourts   = count($courts);
$courtsWithUse = count(array_filter($courts, fn($c) => $c['filing_count'] > 0));
$courtsUnused  = $totalCourts - $courtsWithUse;

$pageTitle = 'จัดการข้อมูลศาล';
include '../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--navy:#1a3a5c;--navy2:#2d5a8a;--border:#e2e8f0;--muted:#94a3b8;--bg:#f8fafc;}
.stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
.stat-box{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px 18px;text-align:center;}
.stat-num{font-size:2rem;font-weight:900;color:var(--navy);line-height:1;}
.stat-lbl{font-size:.78rem;color:var(--muted);margin-top:4px;}
.toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;}
.search-wrap{position:relative;flex:1;max-width:360px;}
.search-wrap input{width:100%;padding:8px 12px 8px 36px;border:1px solid var(--border);border-radius:8px;font-size:.87rem;outline:none;}
.search-wrap input:focus{border-color:var(--navy2);}
.search-wrap::before{content:"🔍";position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:.85rem;}
.badge-use{display:inline-block;padding:2px 9px;border-radius:8px;font-size:.72rem;font-weight:700;}
.badge-used{background:#d1fae5;color:#065f46;}
.badge-unused{background:#f1f5f9;color:#64748b;}
.action-btn{padding:5px 12px;border-radius:6px;font-size:.75rem;font-weight:700;border:none;cursor:pointer;transition:.15s;}
.btn-edit{background:#eff6ff;color:#1d4ed8;} .btn-edit:hover{background:#1d4ed8;color:#fff;}
.btn-merge{background:#fef3c7;color:#92400e;} .btn-merge:hover{background:#d97706;color:#fff;}
.btn-delete{background:#fee2e2;color:#991b1b;} .btn-delete:hover{background:#dc2626;color:#fff;}
.empty-row td{text-align:center;padding:40px;color:var(--muted);}
</style>

<div class="card">
    <div class="toolbar">
        <h2 style="margin:0;">🏛️ จัดการข้อมูลศาล</h2>
        <button onclick="openAddModal()" class="btn btn-primary">➕ เพิ่มศาลใหม่</button>
    </div>

    <!-- Stats -->
    <div class="stat-row">
        <div class="stat-box">
            <div class="stat-num"><?= $totalCourts ?></div>
            <div class="stat-lbl">ศาลทั้งหมด</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" style="color:#059669;"><?= $courtsWithUse ?></div>
            <div class="stat-lbl">มีคดียื่นฟ้อง</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" style="color:#94a3b8;"><?= $courtsUnused ?></div>
            <div class="stat-lbl">ยังไม่มีคดี</div>
        </div>
    </div>

    <!-- Search -->
    <div class="toolbar">
        <div class="search-wrap">
            <input type="text" id="searchInput" placeholder="ค้นหาชื่อศาล / ประเภท / ที่ตั้ง..." oninput="filterTable(this.value)">
        </div>
        <span style="font-size:.82rem;color:var(--muted);" id="countTxt"><?= $totalCourts ?> รายการ</span>
    </div>

    <?php if (empty($courts)): ?>
    <p style="color:#888;padding:20px;">ยังไม่มีข้อมูลศาลในระบบ</p>
    <?php else: ?>
    <div class="table-wrap">
    <table id="courtsTable">
        <thead>
            <tr>
                <th>#</th>
                <th>ชื่อศาล</th>
                <th>ประเภทศาล</th>
                <th>ที่ตั้ง</th>
                <th>จำนวนคดี</th>
                <th>จัดการ</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($courts as $c): ?>
        <tr data-search="<?= strtolower(htmlspecialchars($c['court_name'].' '.($c['court_type']??'').' '.($c['location']??''))) ?>">
            <td><?= $c['court_id'] ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($c['court_name']) ?></td>
            <td><?= htmlspecialchars($c['court_type'] ?? '—') ?></td>
            <td><?= htmlspecialchars($c['location'] ?? '—') ?></td>
            <td>
                <?php if ($c['filing_count'] > 0): ?>
                <span class="badge-use badge-used"><?= $c['filing_count'] ?> คดี</span>
                <?php else: ?>
                <span class="badge-use badge-unused">ยังไม่มี</span>
                <?php endif; ?>
            </td>
            <td>
                <button class="action-btn btn-edit"
                    onclick='openEditModal(<?= $c["court_id"] ?>, <?= json_encode($c["court_name"], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($c["court_type"] ?? "", JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($c["location"] ?? "", JSON_UNESCAPED_UNICODE) ?>)'>
                    ✏️ แก้ไข
                </button>
                <?php if ($totalCourts > 1): ?>
                <button class="action-btn btn-merge"
                    onclick='openMergeModal(<?= $c["court_id"] ?>, <?= json_encode($c["court_name"], JSON_UNESCAPED_UNICODE) ?>)'>
                    🔀 รวม
                </button>
                <?php endif; ?>
                <button class="action-btn btn-delete"
                    onclick='deleteCourt(<?= $c["court_id"] ?>, <?= json_encode($c["court_name"], JSON_UNESCAPED_UNICODE) ?>, <?= $c["filing_count"] ?>)'>
                    🗑️ ลบ
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal: เพิ่มศาล -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;color:#1a3a5c;">➕ เพิ่มศาลใหม่</h3>
            <button onclick="closeModal('addModal')" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">✕</button>
        </div>
        <form id="addForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="form-group" style="margin-bottom:14px;">
                <label>ชื่อศาล <span style="color:red">*</span></label>
                <input type="text" name="court_name" id="add-name" placeholder="เช่น ศาลแพ่งกรุงเทพใต้" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div class="form-group">
                    <label>ประเภทศาล</label>
                    <input type="text" name="court_type" id="add-type" list="courtTypeList" placeholder="เช่น ศาลแพ่ง">
                    <datalist id="courtTypeList">
                        <option value="ศาลแพ่ง">
                        <option value="ศาลอาญา">
                        <option value="ศาลแพ่งและอาญา">
                        <option value="ศาลเยาวชนและครอบครัว">
                        <option value="ศาลแรงงาน">
                        <option value="ศาลภาษีอากร">
                        <option value="ศาลล้มละลาย">
                        <option value="ศาลทรัพย์สินทางปัญญา">
                        <option value="ศาลปกครอง">
                        <option value="ศาลอุทธรณ์">
                        <option value="ศาลฎีกา">
                    </datalist>
                </div>
                <div class="form-group">
                    <label>ที่ตั้ง / จังหวัด</label>
                    <input type="text" name="location" id="add-location" placeholder="เช่น กรุงเทพมหานคร">
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" onclick="closeModal('addModal')" style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
                <button type="submit" id="addSubmitBtn" style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">💾 บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: แก้ไขศาล -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;color:#1a3a5c;">✏️ แก้ไขข้อมูลศาล</h3>
            <button onclick="closeModal('editModal')" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">✕</button>
        </div>
        <form id="editForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="court_id" id="edit-id">
            <div class="form-group" style="margin-bottom:14px;">
                <label>ชื่อศาล <span style="color:red">*</span></label>
                <input type="text" name="court_name" id="edit-name" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div class="form-group">
                    <label>ประเภทศาล</label>
                    <input type="text" name="court_type" id="edit-type" list="courtTypeList" placeholder="เช่น ศาลแพ่ง">
                </div>
                <div class="form-group">
                    <label>ที่ตั้ง / จังหวัด</label>
                    <input type="text" name="location" id="edit-location" placeholder="เช่น กรุงเทพมหานคร">
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" onclick="closeModal('editModal')" style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
                <button type="submit" id="editSubmitBtn" style="padding:9px 24px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">💾 บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Merge ศาล -->
<div id="mergeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;padding:28px 32px;width:100%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;color:#1a3a5c;">🔀 รวมศาลซ้ำ</h3>
            <button onclick="closeModal('mergeModal')" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">✕</button>
        </div>
        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:11px 14px;margin-bottom:16px;font-size:.84rem;color:#92400e;">
            ⚠️ การรวมศาลจะย้าย <b>คดีที่ยื่นฟ้องทั้งหมด</b> จากศาลต้นทางไปที่ศาลปลายทาง แล้วลบศาลต้นทางออก ไม่สามารถย้อนกลับได้
        </div>
        <form id="mergeForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="merge">
            <input type="hidden" name="from_court_id" id="merge-from-id">
            <div class="form-group" style="margin-bottom:14px;">
                <label>ศาลต้นทาง (จะถูกลบ)</label>
                <input type="text" id="merge-from-name" readonly
                    style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:8px 12px;width:100%;font-size:.87rem;color:#991b1b;">
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label>รวมเข้าศาล (จะเก็บไว้) <span style="color:red">*</span></label>
                <select name="to_court_id" id="merge-to-select" required
                    style="width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;">
                    <option value="">— เลือกศาลปลายทาง —</option>
                    <?php foreach ($courts as $c): ?>
                    <option value="<?= $c['court_id'] ?>" data-name="<?= htmlspecialchars($c['court_name']) ?>">
                        <?= htmlspecialchars($c['court_name']) ?>
                        <?= $c['filing_count'] > 0 ? ' ('.$c['filing_count'].' คดี)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" onclick="closeModal('mergeModal')" style="padding:9px 20px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:700;cursor:pointer;">ยกเลิก</button>
                <button type="submit" id="mergeSubmitBtn" style="padding:9px 24px;background:#d97706;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">🔀 ยืนยันรวมศาล</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Filter ──
function filterTable(q) {
    q = q.toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('#courtsTable tbody tr').forEach(tr => {
        const match = !q || tr.dataset.search.includes(q);
        tr.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('countTxt').textContent = visible + ' รายการ';
}

// ── Modal helpers ──
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
['addModal','editModal','mergeModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});

// ── Add ──
function openAddModal() {
    document.getElementById('addForm').reset();
    document.getElementById('addModal').style.display = 'flex';
    setTimeout(() => document.getElementById('add-name').focus(), 100);
}

// ── Edit ──
function openEditModal(id, name, type, location) {
    document.getElementById('edit-id').value       = id;
    document.getElementById('edit-name').value     = name;
    document.getElementById('edit-type').value     = type;
    document.getElementById('edit-location').value = location;
    document.getElementById('editModal').style.display = 'flex';
    setTimeout(() => document.getElementById('edit-name').focus(), 100);
}

// ── Merge ──
function openMergeModal(fromId, fromName) {
    document.getElementById('merge-from-id').value  = fromId;
    document.getElementById('merge-from-name').value = fromName;
    // กรอง option ออกไม่ให้เลือกตัวเอง
    document.querySelectorAll('#merge-to-select option').forEach(opt => {
        opt.hidden = opt.value == fromId;
    });
    document.getElementById('merge-to-select').value = '';
    document.getElementById('mergeModal').style.display = 'flex';
}

// ── Delete ──
async function deleteCourt(id, name, filingCount) {
    if (filingCount > 0) {
        Swal.fire({ icon:'warning', title:'ลบไม่ได้',
            html: `ศาล <b>"${name}"</b> มีคดียื่นฟ้องอยู่ <b>${filingCount} คดี</b><br>กรุณาใช้ <b>"รวมศาล"</b> เพื่อโอนคดีไปที่ศาลอื่นก่อน`,
            confirmButtonColor:'#1a3a5c' });
        return;
    }
    const result = await Swal.fire({
        icon: 'warning',
        title: 'ยืนยันลบศาล?',
        html: `ต้องการลบ <b>"${name}"</b>?<br><small style="color:#94a3b8">ศาลนี้ยังไม่มีคดีจึงลบได้</small>`,
        showCancelButton: true,
        confirmButtonText: '🗑️ ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#94a3b8',
    });
    if (!result.isConfirmed) return;
    await submitForm({ action:'delete', court_id:id });
}

// ── Generic AJAX submit ──
async function submitForm(data) {
    const fd = new FormData();
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    if (csrfInput) fd.append('csrf_token', csrfInput.value);
    for (const [k,v] of Object.entries(data)) fd.append(k, v);
    try {
        const res  = await fetch(location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        });
        const json = await res.json();
        if (json.ok) {
            await Swal.fire({ icon:'success', title:'สำเร็จ!', text:json.msg,
                confirmButtonColor:'#1a3a5c', timer:1800, timerProgressBar:true, showConfirmButton:false });
            location.reload();
        } else {
            Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text:json.msg, confirmButtonColor:'#1a3a5c' });
        }
    } catch {
        Swal.fire({ icon:'error', title:'ผิดพลาด', text:'ไม่สามารถเชื่อมต่อได้', confirmButtonColor:'#1a3a5c' });
    }
}

// ── Form submits ──
async function handleFormSubmit(formId, btnId) {
    const form = document.getElementById(formId);
    const btn  = document.getElementById(btnId);
    const origText = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳...';
    try {
        const res  = await fetch(location.pathname, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form),
        });
        const json = await res.json();
        closeModal(formId.replace('Form','Modal'));
        if (json.ok) {
            await Swal.fire({ icon:'success', title:'สำเร็จ!', text:json.msg,
                confirmButtonColor:'#1a3a5c', timer:1800, timerProgressBar:true, showConfirmButton:false });
            location.reload();
        } else {
            Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text:json.msg, confirmButtonColor:'#1a3a5c' });
        }
    } catch {
        Swal.fire({ icon:'error', title:'ผิดพลาด', text:'ไม่สามารถเชื่อมต่อได้', confirmButtonColor:'#1a3a5c' });
    } finally {
        btn.disabled = false; btn.textContent = origText;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var addForm = document.getElementById('addForm');
    var editForm = document.getElementById('editForm');
    var mergeForm = document.getElementById('mergeForm');

    if (addForm) addForm.addEventListener('submit', e => { e.preventDefault(); handleFormSubmit('addForm', 'addSubmitBtn'); });
    if (editForm) editForm.addEventListener('submit', e => { e.preventDefault(); handleFormSubmit('editForm', 'editSubmitBtn'); });
    if (mergeForm) mergeForm.addEventListener('submit', async e => {
    e.preventDefault();
    const toOpt = document.getElementById('merge-to-select');
    const toName = toOpt.options[toOpt.selectedIndex]?.dataset.name || '?';
    const fromName = document.getElementById('merge-from-name').value;
    const confirm = await Swal.fire({
        icon: 'warning',
        title: 'ยืนยันรวมศาล?',
        html: `คดีทั้งหมดของ <b>"${fromName}"</b><br>จะถูกย้ายไปที่ <b>"${toName}"</b><br><small style="color:#dc2626">แล้วศาล "${fromName}" จะถูกลบออก — ไม่สามารถย้อนกลับได้</small>`,
        showCancelButton: true,
        confirmButtonText: '🔀 ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#d97706',
        cancelButtonColor: '#94a3b8',
    });
    if (!confirm.isConfirmed) return;
    handleFormSubmit('mergeForm', 'mergeSubmitBtn');
    });
}); // end DOMContentLoaded
</script>

<?php include '../includes/footer.php'; ?>