<?php
// case_documents_ext.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
require_once '../config/file_upload_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$officeId = $_SESSION['office_id'];
$userId   = $_SESSION['user_id'];

// ==============================
// Handle UPLOAD
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    csrf_verify();
    $requestId = (int)($_POST['request_id'] ?? 0);
    $label     = trim($_POST['doc_label'] ?? '');
    $docType   = $_POST['doc_type'] ?? 'other';
    $notes     = trim($_POST['notes'] ?? '');

    // ตรวจสิทธิ์
    $chk = $pdo->prepare("SELECT request_id FROM case_requests WHERE request_id=? AND office_id=?");
    $chk->execute([$requestId, $officeId]);
    if (!$chk->fetch()) {
        header('Location: /pages/case_documents_ext.php?error=permission');
        exit;
    }

    if (!empty($_FILES['doc_file']['tmp_name']) && $_FILES['doc_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $origName = basename($_FILES['doc_file']['name']);
        $maxSize  = 30 * 1024 * 1024; // 30MB

        // ── ตรวจ MIME type จริง + ขนาดไฟล์ ──
        $up = validateUpload($_FILES['doc_file'], MIME_DOCS_FULL, $maxSize);
        if (!$up['ok']) {
            header('Location: /pages/case_documents_ext.php?request_id=' . $requestId . '&error=' . ($up['error'] === 'ประเภทไฟล์ไม่รองรับ' ? 'type' : 'size'));
            exit;
        }

        $saveDir = '/var/www/html/uploads/case_docs/';
        if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

        $newName  = 'extdoc_' . $requestId . '_' . time() . '_' . uniqid() . '.' . $up['ext'];
        move_uploaded_file($_FILES['doc_file']['tmp_name'], $saveDir . $newName);

        $pdo->prepare("
            INSERT INTO case_summary_docs (request_id, file_name, file_path, file_size, doc_label, doc_type, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $requestId, $origName, $newName,
            $_FILES['doc_file']['size'], $label ?: $origName,
            $docType, $userId
        ]);

        header('Location: /pages/case_documents_ext.php?request_id='.$requestId.'&uploaded=1');
        exit;
    }
    header('Location: /pages/case_documents_ext.php?request_id='.$requestId.'&error=nofile');
    exit;
}

// ==============================
// Handle DELETE
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $docId    = (int)$_POST['doc_id'];
    $reqId    = (int)$_POST['request_id'];

    $doc = $pdo->prepare("
        SELECT sd.* FROM case_summary_docs sd
        JOIN case_requests cr ON sd.request_id = cr.request_id
        WHERE sd.doc_id=? AND cr.office_id=?
    ");
    $doc->execute([$docId, $officeId]);
    $row = $doc->fetch();

    if ($row) {
        $fp = '/var/www/html/uploads/case_docs/' . $row['file_path'];
        if (file_exists($fp)) unlink($fp);
        $pdo->prepare("DELETE FROM case_summary_docs WHERE doc_id=?")->execute([$docId]);
    }
    header('Location: /pages/case_documents_ext.php?request_id='.$reqId.'&deleted=1');
    exit;
}

// ==============================
// ดึงรายการคดี
// ==============================
if ($role === 'lawyer') {
    $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lp->execute([$userId]);
    $lawyerId  = $lp->fetchColumn();
    $whereRole = "AND cr.lawyer_id = " . (int)$lawyerId;
} elseif ($role === 'client') {
    $cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
    $cp->execute([$userId]);
    $clientId  = $cp->fetchColumn();
    $whereRole = "AND cr.client_id = " . (int)$clientId;
} else {
    $whereRole = '';
}

$casesStmt = $pdo->prepare("
    SELECT cr.request_id, cr.detail, cr.status, cr.created_at,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           (SELECT MAX(ch2.case_number) FROM court_hearings ch2 JOIN filings f2 ON ch2.filing_id=f2.filing_id JOIN contracts con2 ON f2.contract_id=con2.contract_id WHERE con2.request_id=cr.request_id) AS case_number,
           (SELECT COUNT(*) FROM case_summary_docs sd WHERE sd.request_id = cr.request_id) AS doc_count
    FROM case_requests cr
    JOIN client_profiles cp ON cr.client_id = cp.client_id
    JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
    LEFT JOIN contracts con ON cr.request_id = con.request_id
    LEFT JOIN filings f     ON con.contract_id = f.contract_id
    WHERE cr.office_id = ? $whereRole
    GROUP BY cr.request_id, cr.detail, cr.status, cr.created_at,
             cp.fname, cp.lname, lp.fname, lp.lname
    ORDER BY cr.created_at DESC
");
$casesStmt->execute([$officeId]);
$cases = $casesStmt->fetchAll();

$selectedId = (int)($_GET['request_id'] ?? ($cases[0]['request_id'] ?? 0));

// ดึงเอกสารของคดีที่เลือก
$docs = [];
$selectedCase = null;
if ($selectedId) {
    $selStmt = $pdo->prepare("
        SELECT cr.request_id, cr.detail, cr.status,
               CONCAT(cp.fname,' ',cp.lname) AS client_name,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
               (SELECT MAX(ch3.case_number) FROM court_hearings ch3 JOIN filings f3 ON ch3.filing_id=f3.filing_id JOIN contracts con3 ON f3.contract_id=con3.contract_id WHERE con3.request_id=cr.request_id) AS case_number,
               ct.court_name
        FROM case_requests cr
        JOIN client_profiles cp ON cr.client_id = cp.client_id
        JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
        LEFT JOIN contracts con ON cr.request_id = con.request_id
        LEFT JOIN filings f     ON con.contract_id = f.contract_id
        LEFT JOIN courts ct     ON f.court_id = ct.court_id
        WHERE cr.request_id=? AND cr.office_id=?
    ");
    $selStmt->execute([$selectedId, $officeId]);
    $selectedCase = $selStmt->fetch();

    $docsStmt = $pdo->prepare("
        SELECT sd.*, u.email AS uploader_email
        FROM case_summary_docs sd
        LEFT JOIN users u ON sd.uploaded_by = u.user_id
        WHERE sd.request_id=?
        ORDER BY sd.created_at DESC
    ");
    $docsStmt->execute([$selectedId]);
    $docs = $docsStmt->fetchAll();
}

$docTypeTH = [
    'court_order'  => 'คำสั่งศาล',
    'evidence'     => 'หลักฐาน',
    'official_doc' => 'เอกสารราชการ',
    'contract'     => 'สัญญา',
    'id_card'      => 'บัตรประชาชน',
    'photo'        => 'ภาพถ่าย',
    'other'        => 'อื่นๆ',
];

$pageTitle = 'เอกสารภายนอก';
include '../includes/header.php';
?>

<style>
:root {
    --navy: #1a3a5c;
    --navy-light: #2d5a8a;
    --accent: #3b82f6;
    --green: #10b981;
    --red: #ef4444;
    --bg: #f0f4f8;
    --card: #ffffff;
    --border: #e2e8f0;
    --text: #1e293b;
    --muted: #94a3b8;
}

.ext-layout {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
    align-items: start;
}

/* ---- Case list ---- */
.case-list-wrap {
    background: var(--card);
    border-radius: 14px;
    border: 1px solid var(--border);
    overflow: hidden;
    position: sticky;
    top: 16px;
    max-height: 92vh;
    display: flex;
    flex-direction: column;
}
.case-list-head {
    background: var(--navy);
    color: #fff;
    padding: 16px 18px;
    font-size: 0.95rem;
    font-weight: 700;
    letter-spacing: .3px;
}
.case-search {
    margin: 12px;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.84rem;
    width: calc(100% - 24px);
    outline: none;
    transition: border-color .2s;
}
.case-search:focus { border-color: var(--accent); }
.case-items { overflow-y: auto; flex: 1; padding: 0 8px 12px; }

.case-item {
    padding: 10px 12px;
    border-radius: 8px;
    cursor: pointer;
    transition: .15s;
    border: 2px solid transparent;
    margin-bottom: 4px;
}
.case-item:hover { background: #f0f7ff; border-color: #bdd7f5; }
.case-item.active { background: #eff6ff; border-color: var(--navy); }
.case-item .ci-name { font-weight: 700; font-size: 0.86rem; color: var(--navy); }
.case-item .ci-meta { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }
.case-item .ci-badge {
    display: inline-block;
    background: var(--navy);
    color: #fff;
    font-size: 0.66rem;
    padding: 1px 7px;
    border-radius: 10px;
    margin-left: 6px;
    vertical-align: middle;
}

/* ---- Right panel ---- */
.right-panel { min-width: 0; }

/* Case header bar */
.case-header-bar {
    background: var(--navy);
    color: #fff;
    border-radius: 12px;
    padding: 16px 22px;
    margin-bottom: 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.case-header-bar .ch-name { font-size: 1.1rem; font-weight: 700; }
.case-header-bar .ch-meta { font-size: 0.82rem; opacity: .75; margin-top: 2px; }
.case-header-bar .ch-badge {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.3);
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

/* Upload zone */
.upload-zone {
    border: 2px dashed var(--accent);
    border-radius: 14px;
    background: #f8fbff;
    padding: 28px 24px;
    margin-bottom: 22px;
    transition: .2s;
}
.upload-zone:hover { background: #eff6ff; border-color: var(--navy); }
.upload-zone-title {
    font-weight: 700;
    color: var(--navy);
    font-size: 0.98rem;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.upload-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 160px;
    gap: 12px;
    align-items: end;
}
.upload-label {
    font-size: 0.78rem;
    color: #64748b;
    font-weight: 600;
    display: block;
    margin-bottom: 5px;
}
.upload-input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.84rem;
    transition: border-color .2s;
    background: #fff;
}
.upload-input:focus { outline: none; border-color: var(--accent); }
.upload-file-input {
    width: 100%;
    padding: 7px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.82rem;
    background: #fff;
    cursor: pointer;
}
.upload-select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.84rem;
    background: #fff;
    cursor: pointer;
}
.btn-upload {
    padding: 9px 22px;
    background: var(--navy);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    transition: .15s;
    white-space: nowrap;
    width: 100%;
}
.btn-upload:hover { background: var(--navy-light); }

/* Doc list */
.doc-list-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.doc-list-title {
    font-weight: 700;
    color: var(--navy);
    font-size: 0.95rem;
}
.doc-count-badge {
    background: var(--navy);
    color: #fff;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 0.78rem;
    font-weight: 700;
}

/* Filter tabs */
.filter-tabs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}
.ftab {
    padding: 4px 14px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid var(--border);
    background: #fff;
    color: var(--muted);
    transition: .15s;
}
.ftab:hover { border-color: var(--navy); color: var(--navy); }
.ftab.active { background: var(--navy); color: #fff; border-color: var(--navy); }

/* Doc card */
.doc-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 16px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: .15s;
}
.doc-card:hover { border-color: var(--accent); box-shadow: 0 2px 8px rgba(59,130,246,.08); }
.doc-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.doc-icon.pdf  { background: #fee2e2; }
.doc-icon.img  { background: #fce7f3; }
.doc-icon.word { background: #dbeafe; }
.doc-icon.xl   { background: #dcfce7; }
.doc-icon.other{ background: #f1f5f9; }

.doc-info { flex: 1; min-width: 0; }
.doc-name {
    font-weight: 700;
    font-size: 0.88rem;
    color: var(--navy);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 320px;
}
.doc-meta { font-size: 0.74rem; color: var(--muted); margin-top: 2px; }
.doc-type-badge {
    display: inline-block;
    padding: 1px 9px;
    border-radius: 8px;
    font-size: 0.7rem;
    font-weight: 600;
    background: #e0f2fe;
    color: #0369a1;
    margin-right: 6px;
}

.doc-actions { display: flex; gap: 6px; flex-shrink: 0; }
.btn-act {
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 0.76rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: .15s;
}
.btn-view     { background: #eff6ff; color: var(--accent); }
.btn-view:hover { background: var(--accent); color: #fff; }
.btn-dl       { background: #ecfdf5; color: var(--green); }
.btn-dl:hover { background: var(--green); color: #fff; }
.btn-del      { background: #fef2f2; color: var(--red); }
.btn-del:hover { background: var(--red); color: #fff; }

/* Empty state */
.empty-state {
    text-align: center;
    padding: 50px 20px;
    color: var(--muted);
}
.empty-icon { font-size: 3rem; margin-bottom: 10px; opacity: .4; }

/* Alert */
.toast-bar {
    padding: 12px 18px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: 0.88rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.toast-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.toast-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* Drag-drop overlay */
.drop-overlay {
    display: none;
    position: absolute;
    inset: 0;
    background: rgba(59,130,246,.08);
    border: 3px dashed var(--accent);
    border-radius: 12px;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--accent);
    z-index: 10;
}
.upload-zone { position: relative; }
.upload-zone.drag-over .drop-overlay { display: flex; }
</style>

<?php
$errMap = [
    'permission' => 'ไม่มีสิทธิ์เข้าถึงคดีนี้',
    'type'       => 'ประเภทไฟล์ไม่รองรับ (รองรับ PDF, รูป, Word, Excel)',
    'size'       => 'ไฟล์ใหญ่เกิน 30MB',
    'nofile'     => 'ไม่พบไฟล์ที่อัปโหลด',
];
?>

<?php if (isset($_GET['uploaded'])): ?>
<div class="toast-bar toast-success">✅ อัปโหลดและบันทึกเอกสารเรียบร้อยแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="toast-bar toast-success">🗑 ลบเอกสารเรียบร้อยแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
<div class="toast-bar toast-error">❌ <?= $errMap[$_GET['error']] ?? 'เกิดข้อผิดพลาด' ?></div>
<?php endif; ?>

<div class="ext-layout">

  <!-- ===== ซ้าย: รายการคดี ===== -->
  <div class="case-list-wrap">
    <div class="case-list-head">📁 เลือกคดี <span style="font-weight:400;font-size:0.8rem;opacity:.7;">(<?= count($cases) ?> คดี)</span></div>
    <input type="text" class="case-search" id="caseSearch" placeholder="ค้นหาชื่อ / เลขคดี..."
           onkeyup="filterCases(this.value)">
    <div class="case-items" id="caseList">
      <?php foreach ($cases as $c): ?>
      <div class="case-item <?= $selectedId == $c['request_id'] ? 'active' : '' ?>"
           data-search="<?= strtolower(htmlspecialchars($c['client_name'].' '.($c['case_number']??'').' '.$c['lawyer_name'])) ?>"
           onclick="location.href='/pages/case_documents_ext.php?request_id=<?= $c['request_id'] ?>'">
        <div class="ci-name">
          <?= htmlspecialchars($c['client_name']) ?>
          <?php if ($c['doc_count'] > 0): ?>
          <span class="ci-badge"><?= $c['doc_count'] ?></span>
          <?php endif; ?>
        </div>
        <div class="ci-meta">
          <?= $c['case_number'] ? htmlspecialchars($c['case_number']).' | ' : '' ?>
          <?= htmlspecialchars(mb_substr($c['detail']??'',0,32)) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ===== ขวา: เนื้อหา ===== -->
  <div class="right-panel">

    <?php if (!$selectedCase): ?>
    <div style="background:#fff;border-radius:14px;border:1px solid var(--border);padding:80px 20px;text-align:center;color:var(--muted);">
      <div style="font-size:3.5rem;margin-bottom:14px;opacity:.3;">📂</div>
      <div style="font-size:1.05rem;">เลือกคดีจากรายการทางซ้ายเพื่อจัดการเอกสาร</div>
    </div>

    <?php else: ?>

    <!-- Case Header -->
    <div class="case-header-bar">
      <div>
        <div class="ch-name"><?= htmlspecialchars($selectedCase['client_name']) ?> — โจทก์</div>
        <div class="ch-meta">
          ทนาย: <?= htmlspecialchars($selectedCase['lawyer_name']) ?>
          <?= $selectedCase['case_number'] ? ' &nbsp;|&nbsp; คดีหมายเลข: '.htmlspecialchars($selectedCase['case_number']) : '' ?>
          <?= $selectedCase['court_name']  ? ' &nbsp;|&nbsp; '.htmlspecialchars($selectedCase['court_name']) : '' ?>
        </div>
      </div>
      <div class="ch-badge"><?= count($docs) ?> เอกสาร</div>
    </div>

    <!-- Upload Zone -->
    <div class="upload-zone" id="uploadZone">
      <div class="drop-overlay">วางไฟล์ที่นี่เพื่ออัปโหลด</div>
      <div class="upload-zone-title">
        <span style="font-size:1.3rem;">📤</span>
        อัปโหลดเอกสารใหม่
      </div>
      <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="request_id" value="<?= $selectedId ?>">
        <div class="upload-grid">
          <div>
            <label class="upload-label">ไฟล์เอกสาร <span style="color:#ef4444">*</span></label>
            <input type="file" name="doc_file" id="docFile" required
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                   class="upload-file-input" onchange="previewFile(this)">
            <div style="font-size:0.7rem;color:var(--muted);margin-top:3px;">
              PDF, รูปภาพ, Word, Excel &nbsp;|&nbsp; สูงสุด 30MB
            </div>
          </div>
          <div>
            <label class="upload-label">ชื่อ / คำอธิบาย</label>
            <input type="text" name="doc_label" id="docLabel" class="upload-input"
                   placeholder="เช่น คำสั่งศาลนัดที่ 1, หลักฐานการชำระเงิน...">
          </div>
          <div>
            <label class="upload-label">ประเภทเอกสาร</label>
            <select name="doc_type" class="upload-select">
              <option value="court_order">⚖️ คำสั่งศาล</option>
              <option value="evidence">🔍 หลักฐาน</option>
              <option value="official_doc">📋 เอกสารราชการ</option>
              <option value="contract">📄 สัญญา</option>
              <option value="id_card">🪪 บัตรประชาชน</option>
              <option value="photo">🖼️ ภาพถ่าย</option>
              <option value="other" selected>📎 อื่นๆ</option>
            </select>
          </div>
        </div>

        <!-- File preview -->
        <div id="filePreview" style="display:none;margin-top:12px;padding:10px 14px;
             background:#fff;border:1px solid var(--border);border-radius:8px;
             display:flex;align-items:center;gap:10px;">
          <span id="previewIcon" style="font-size:1.8rem;"></span>
          <div>
            <div id="previewName" style="font-weight:600;font-size:0.86rem;color:var(--navy);"></div>
            <div id="previewSize" style="font-size:0.75rem;color:var(--muted);"></div>
          </div>
        </div>

        <div style="margin-top:14px;">
          <button type="submit" class="btn-upload" style="width:auto;padding:10px 28px;">
            💾 บันทึกเอกสารไว้ในระบบ
          </button>
        </div>
      </form>
    </div>

    <!-- Doc List -->
    <div>
      <div class="doc-list-head">
        <div class="doc-list-title">เอกสารทั้งหมดในคดีนี้</div>
        <span class="doc-count-badge"><?= count($docs) ?> ไฟล์</span>
      </div>

      <!-- Filter -->
      <div class="filter-tabs" id="filterTabs">
        <div class="ftab active" onclick="filterDocs('all', this)">ทั้งหมด</div>
        <?php
        $typeSet = array_unique(array_column($docs, 'doc_type'));
        foreach ($typeSet as $t):
        ?>
        <div class="ftab" onclick="filterDocs('<?= $t ?>', this)"><?= $docTypeTH[$t] ?? $t ?></div>
        <?php endforeach; ?>
      </div>

      <?php if (empty($docs)): ?>
      <div class="empty-state">
        <div class="empty-icon">📭</div>
        <div>ยังไม่มีเอกสารในคดีนี้</div>
        <div style="font-size:0.82rem;margin-top:4px;">อัปโหลดเอกสารแรกได้เลย</div>
      </div>
      <?php else: ?>
      <div id="docItems">
        <?php foreach ($docs as $doc):
          $ext  = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
          $iconClass = match(true) {
            $ext === 'pdf'                          => 'pdf',
            in_array($ext, ['jpg','jpeg','png'])    => 'img',
            in_array($ext, ['doc','docx'])          => 'word',
            in_array($ext, ['xls','xlsx'])          => 'xl',
            default                                 => 'other',
          };
          $iconEmoji = match($iconClass) {
            'pdf'   => '📄',
            'img'   => '🖼️',
            'word'  => '📝',
            'xl'    => '📊',
            default => '📎',
          };
          $fsize = $doc['file_size'] ? number_format($doc['file_size']/1024, 1).' KB' : '';
        ?>
        <div class="doc-card" data-type="<?= htmlspecialchars($doc['doc_type']) ?>">
          <div class="doc-icon <?= $iconClass ?>"><?= $iconEmoji ?></div>
          <div class="doc-info">
            <div class="doc-name">
              <?= htmlspecialchars($doc['doc_label'] ?: $doc['file_name']) ?>
            </div>
            <div class="doc-meta">
              <span class="doc-type-badge"><?= $docTypeTH[$doc['doc_type']] ?? htmlspecialchars($doc['doc_type']) ?></span>
              <?= $fsize ?>
              &nbsp;|&nbsp; <?= date('d/m/Y H:i', strtotime($doc['created_at'])) ?>
              <?php if ($doc['doc_label'] && $doc['doc_label'] !== $doc['file_name']): ?>
              &nbsp;|&nbsp; <span style="color:#94a3b8;"><?= htmlspecialchars($doc['file_name']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="doc-actions">
            <a href="/uploads/case_docs/<?= urlencode($doc['file_path']) ?>" target="_blank"
               class="btn-act btn-view">👁 ดู</a>
            <a href="/uploads/case_docs/<?= urlencode($doc['file_path']) ?>" download="<?= htmlspecialchars($doc['file_name']) ?>"
               class="btn-act btn-dl">⬇ โหลด</a>
            <form method="POST" onsubmit="return confirm('ลบเอกสาร ' + <?= json_encode($doc['doc_label'] ?: $doc['file_name']) ?> + ' ?')" style="margin:0;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="doc_id" value="<?= $doc['doc_id'] ?>">
              <input type="hidden" name="request_id" value="<?= $selectedId ?>">
              <button type="submit" class="btn-act btn-del">🗑 ลบ</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php endif; ?>
  </div>

</div>

<script>
function filterCases(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.case-item').forEach(function(el) {
        el.style.display = el.dataset.search.includes(q) ? '' : 'none';
    });
}

function filterDocs(type, btn) {
    document.querySelectorAll('.ftab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.doc-card').forEach(function(card) {
        card.style.display = (type === 'all' || card.dataset.type === type) ? '' : 'none';
    });
}

function previewFile(input) {
    const preview = document.getElementById('filePreview');
    const pIcon   = document.getElementById('previewIcon');
    const pName   = document.getElementById('previewName');
    const pSize   = document.getElementById('previewSize');
    const label   = document.getElementById('docLabel');

    if (!input.files || !input.files[0]) {
        preview.style.display = 'none';
        return;
    }
    const f    = input.files[0];
    const ext  = f.name.split('.').pop().toLowerCase();
    const icons = { pdf: '📄', jpg: '🖼️', jpeg: '🖼️', png: '🖼️', doc: '📝', docx: '📝', xls: '📊', xlsx: '📊' };
    const sizeKB = (f.size / 1024).toFixed(1);
    const sizeMB = (f.size / 1024 / 1024).toFixed(2);

    pIcon.textContent = icons[ext] || '📎';
    pName.textContent = f.name;
    pSize.textContent = f.size > 1024*1024 ? sizeMB+' MB' : sizeKB+' KB';
    preview.style.display = 'flex';

    // auto-fill label ถ้ายังว่าง
    if (!label.value) {
        label.value = f.name.replace(/\.[^.]+$/, '');
    }
}

// Drag-and-drop
const zone = document.getElementById('uploadZone');
if (zone) {
    zone.addEventListener('dragover', function(e) {
        e.preventDefault();
        zone.classList.add('drag-over');
    });
    zone.addEventListener('dragleave', function() {
        zone.classList.remove('drag-over');
    });
    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('drag-over');
        const dt = e.dataTransfer;
        if (dt.files && dt.files[0]) {
            const fileInput = document.getElementById('docFile');
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(dt.files[0]);
            fileInput.files = dataTransfer.files;
            previewFile(fileInput);
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>