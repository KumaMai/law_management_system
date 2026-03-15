<?php
// case_summary.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$officeId = $_SESSION['office_id'];
$userId   = $_SESSION['user_id'];
$error    = '';
$success  = '';

// ==============================
// สร้าง PDF
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_pdf') {
    csrf_verify();
    $requestId  = (int)$_POST['request_id'];
    $pdfAction  = $_POST['pdf_action'] ?? 'view';

    // ดึงข้อมูลครบทุก layer
    $caseStmt = $pdo->prepare("
        SELECT cr.*,
               CONCAT(cp.fname,' ',cp.lname) AS client_name,
               cp.phone AS client_phone, cp.address AS client_address,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
               lp.specialization, lp.phone AS lawyer_phone,
               con.contract_id, con.contract_date, con.fee_amount,
               con.contract_review_status, con.payment_status,
               con.lawyer_note, con.client_response, con.proposed_fee,
               f.filing_id, f.case_number, f.charge, f.filing_date,
               ct.court_name,
               v.result AS verdict_result, v.verdict_date
        FROM case_requests cr
        JOIN client_profiles cp  ON cr.client_id  = cp.client_id
        JOIN lawyer_profiles lp  ON cr.lawyer_id  = lp.lawyer_id
        LEFT JOIN contracts con  ON cr.request_id = con.request_id
        LEFT JOIN filings f      ON con.contract_id = f.contract_id
        LEFT JOIN courts ct      ON f.court_id = ct.court_id
        LEFT JOIN verdicts v     ON f.filing_id = v.filing_id
        WHERE cr.request_id = ? AND cr.office_id = ?
    ");
    $caseStmt->execute([$requestId, $officeId]);
    $case = $caseStmt->fetch();

    if (!$case) { $error = 'ไม่พบคดีนี้'; goto render; }

    // นัดขึ้นศาลทั้งหมด
    $hearings = [];
    if ($case['filing_id']) {
        $hStmt = $pdo->prepare("SELECT * FROM court_hearings WHERE filing_id = ? ORDER BY hearing_date ASC, hearing_time ASC");
        $hStmt->execute([$case['filing_id']]);
        $hearings = $hStmt->fetchAll();
    }

    // เอกสารแนบ
    $docs = [];
    if ($case['contract_id']) {
        $dStmt = $pdo->prepare("SELECT * FROM case_documents WHERE contract_id = ? ORDER BY created_at ASC");
        $dStmt->execute([$case['contract_id']]);
        $docs = $dStmt->fetchAll();
    }

    // สถิตินัด
    $totalHearings   = count($hearings);
    $postponedCount  = count(array_filter($hearings, fn($h) => in_array($h['status'], ['postponed','defendant_absent','plaintiff_absent'])));
    $completedCount  = count(array_filter($hearings, fn($h) => $h['status'] === 'completed'));
    $absentDefendant = count(array_filter($hearings, fn($h) => $h['status'] === 'defendant_absent'));
    $absentPlaintiff = count(array_filter($hearings, fn($h) => $h['status'] === 'plaintiff_absent'));

    // วิเคราะห์ผู้ชนะ
    $winnerText = '';
    $winnerColor = '#333';
    if ($case['verdict_result']) {
        if (str_contains($case['verdict_result'], 'ผู้ชนะ: โจทก์') || str_contains($case['verdict_result'], 'โจทก์ชนะ')) {
            $winnerText = 'โจทก์ชนะคดี ('.$case['client_name'].')';
            $winnerColor = '#0f5132';
            $winnerBg = '#d1e7dd';
        } else {
            $winnerText = 'จำเลยชนะคดี';
            $winnerColor = '#084298';
            $winnerBg = '#cfe2ff';
        }
    }

    $statusHearingTH = [
        'scheduled'               => 'นัดไว้',
        'completed'               => 'เสร็จสิ้น',
        'postponed'               => 'เลื่อนนัด',
        'cancelled'               => 'ยกเลิก',
        'defendant_absent'        => 'จำเลยไม่มาศาล',
        'plaintiff_absent'        => 'โจทก์ไม่มาศาล',
        'defendant_guilty_verdict'=> 'โจทก์ชนะ (จำเลยหลบหนี)',
    ];

    $statusRequestTH = [
        'pending'    => 'รอพิจารณา',
        'approved'   => 'อนุมัติ',
        'rejected'   => 'ปฏิเสธ',
        'in_progress'=> 'ดำเนินการอยู่',
        'completed'  => 'เสร็จสิ้น',
    ];

    $docTypeTH = ['contract'=>'สัญญาว่าจ้าง','id_card'=>'สำเนาบัตรประชาชน','evidence'=>'หลักฐาน','power_of_attorney'=>'หนังสือมอบอำนาจ','other'=>'อื่นๆ'];

    // ========== สร้าง HTML ==========
    ob_start();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Garuda','FreeSans','Loma',sans-serif;font-size:13.5px;color:#111;padding:25px 30px;line-height:1.6;}
/* ปก */
.cover{text-align:center;padding:30px 0 24px;border-bottom:3px double #1a3a5c;margin-bottom:28px;}
.cover-logo{font-size:36px;margin-bottom:8px;}
.cover h1{font-size:22px;color:#1a3a5c;margin-bottom:4px;}
.cover h2{font-size:15px;color:#444;font-weight:normal;margin-bottom:10px;}
.cover-meta{font-size:12px;color:#777;margin-top:12px;}
/* Winner Banner */
.winner-banner{padding:12px 20px;border-radius:8px;margin-bottom:24px;text-align:center;font-size:16px;font-weight:bold;}
/* Section */
.section{margin-bottom:22px;page-break-inside:avoid;}
.section-title{background:#1a3a5c;color:#fff;padding:5px 14px;font-size:13px;font-weight:bold;border-radius:4px;margin-bottom:9px;letter-spacing:.3px;}
/* Info table */
table.info{width:100%;border-collapse:collapse;margin-bottom:6px;font-size:13px;}
table.info td{padding:5px 10px;border:1px solid #ddd;vertical-align:top;}
table.info td.label{background:#eef2f7;width:32%;font-weight:bold;color:#1a3a5c;white-space:nowrap;}
/* Stats row */
.stat-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;}
.stat-box{background:#f0f4f8;border:1px solid #d0d9e4;border-radius:6px;padding:7px 14px;font-size:12.5px;text-align:center;min-width:100px;}
.stat-box strong{display:block;font-size:20px;color:#1a3a5c;}
/* Hearing table */
table.htable{width:100%;border-collapse:collapse;font-size:12.5px;}
table.htable th{background:#1a3a5c;color:#fff;padding:5px 8px;text-align:left;font-weight:normal;}
table.htable td{padding:5px 8px;border:1px solid #e0e0e0;vertical-align:top;}
table.htable tr:nth-child(even) td{background:#f7f9fb;}
.badge{display:inline-block;padding:1px 8px;border-radius:8px;font-size:11.5px;}
.b-done{background:#d1e7dd;color:#0f5132;}
.b-post{background:#fff3cd;color:#856404;}
.b-abs {background:#f8d7da;color:#842029;}
.b-sched{background:#cfe2ff;color:#084298;}
.b-cancel{background:#e2e3e5;color:#383d41;}
/* Verdict */
.verdict-box{background:#f8fafc;border-left:4px solid #1a3a5c;padding:12px 16px;border-radius:0 6px 6px 0;font-size:13px;white-space:pre-wrap;line-height:1.7;}
/* Docs */
.doc-row{padding:5px 10px;border:1px solid #e5e5e5;border-radius:4px;margin-bottom:4px;font-size:12.5px;display:flex;justify-content:space-between;}
/* Sign */
.sign-area{margin-top:50px;display:flex;justify-content:space-around;}
.sign-box{text-align:center;min-width:180px;}
.sign-line{border-top:1px solid #555;width:190px;margin:0 auto 5px;}
/* Footer */
.page-footer{margin-top:30px;border-top:1px solid #ccc;padding-top:10px;font-size:11px;color:#999;display:flex;justify-content:space-between;}
.highlight{background:#fffbeb;border-left:3px solid #f59e0b;padding:6px 12px;border-radius:0 4px 4px 0;font-size:12.5px;margin-bottom:6px;}
</style>
</head>
<body>

<!-- ===== ปก ===== -->
<div class="cover">
  <div class="cover-logo"></div>
  <h1>สำนวนคดี</h1>
  <h2><?= htmlspecialchars(mb_substr($case['detail'] ?? 'ไม่ระบุ', 0, 80)) ?></h2>
  <?php if ($case['case_number']): ?>
  <div style="font-size:14px;color:#555;margin-top:6px;">
    เลขคดี: <strong><?= htmlspecialchars($case['case_number']) ?></strong>
    &nbsp;|&nbsp; ศาล: <?= htmlspecialchars($case['court_name'] ?? '-') ?>
  </div>
  <?php endif; ?>
  <div class="cover-meta">
    จัดทำเมื่อ: <?= date('d/m/Y เวลา H:i น.') ?> &nbsp;|&nbsp; สร้างโดย: <?= htmlspecialchars($_SESSION['user_email'] ?? 'ระบบ') ?>
  </div>
</div>

<?php if ($winnerText): ?>
<div class="winner-banner" style="background:<?= $winnerBg ?>;color:<?= $winnerColor ?>;border:2px solid <?= $winnerColor ?>;">
  <?= $winnerText ?>
</div>
<?php endif; ?>

<!-- ===== 1. ข้อมูลคำขอว่าจ้าง ===== -->
<div class="section">
  <div class="section-title">1. ข้อมูลคำขอว่าจ้าง</div>
  <table class="info">
    <tr><td class="label">วันที่ส่งคำขอ</td><td><?= date('d/m/Y', strtotime($case['created_at'])) ?></td>
        <td class="label">วันหมดอายุ</td><td><?= !empty($case['expires_at']) ? date('d/m/Y', strtotime($case['expires_at'])) : '-' ?></td></tr>
    <tr><td class="label">สถานะการดำเนินการ</td>
        <td colspan="3"><strong><?= $statusRequestTH[$case['status']] ?? $case['status'] ?></strong></td></tr>
    <tr><td class="label">ชื่อลูกความ (โจทก์)</td>
        <td><?= htmlspecialchars($case['client_name']) ?><?= $case['client_phone'] ? ' &nbsp;|&nbsp; โทร. '.$case['client_phone'] : '' ?></td>
        <td class="label">ที่อยู่</td>
        <td><?= htmlspecialchars($case['client_address'] ?? '-') ?></td></tr>
    <tr><td class="label">ชื่อทนายความ</td>
        <td><?= htmlspecialchars($case['lawyer_name']) ?><?= $case['lawyer_phone'] ? ' &nbsp;|&nbsp; โทร. '.$case['lawyer_phone'] : '' ?></td>
        <td class="label">ความเชี่ยวชาญ</td>
        <td><?= htmlspecialchars($case['specialization'] ?? '-') ?></td></tr>
    <tr><td class="label">รายละเอียดคดี</td>
        <td colspan="3"><?= nl2br(htmlspecialchars($case['detail'] ?? '-')) ?></td></tr>
  </table>
</div>

<!-- ===== 2. สัญญาว่าจ้าง ===== -->
<div class="section">
  <div class="section-title">2. สัญญาว่าจ้าง</div>
  <?php if ($case['contract_id']): ?>
  <table class="info">
    <tr><td class="label">วันที่ทำสัญญา</td>
        <td><?= $case['contract_date'] ? date('d/m/Y', strtotime($case['contract_date'])) : '-' ?></td>
        <td class="label">สถานะสัญญา</td>
        <td><?= htmlspecialchars($case['contract_review_status'] ?? '-') ?></td></tr>
    <tr><td class="label">ค่าดำเนินคดีที่ตกลง</td>
        <td><strong><?= $case['fee_amount'] ? number_format($case['fee_amount'],2).' บาท' : 'ยังไม่ระบุ' ?></strong></td>
        <td class="label">สถานะการชำระ</td>
        <td><?= htmlspecialchars($case['payment_status'] ?? '-') ?></td></tr>
    <?php if ($case['proposed_fee']): ?>
    <tr><td class="label">ค่าดำเนินคดีที่ลูกความเสนอ</td>
        <td colspan="3"><?= number_format($case['proposed_fee'],2) ?> บาท</td></tr>
    <?php endif; ?>
    <?php if ($case['lawyer_note']): ?>
    <tr><td class="label">บันทึกจากทนาย</td>
        <td colspan="3"><?= nl2br(htmlspecialchars($case['lawyer_note'])) ?></td></tr>
    <?php endif; ?>
    <?php if ($case['client_response']): ?>
    <tr><td class="label">การตอบรับจากลูกความ</td>
        <td colspan="3"><?= nl2br(htmlspecialchars($case['client_response'])) ?></td></tr>
    <?php endif; ?>
  </table>
  <?php else: ?>
  <div class="highlight">ยังไม่มีสัญญาว่าจ้างในระบบ</div>
  <?php endif; ?>
</div>

<!-- ===== 3. การยื่นฟ้องคดี ===== -->
<div class="section">
  <div class="section-title">3. การยื่นฟ้องคดี</div>
  <?php if ($case['filing_id']): ?>
  <table class="info">
    <tr><td class="label">หมายเลขคดีที่ได้รับ</td>
        <td><strong><?= htmlspecialchars($case['case_number'] ?? '-') ?></strong></td>
        <td class="label">วันที่ยื่นฟ้อง</td>
        <td><?= $case['filing_date'] ? date('d/m/Y', strtotime($case['filing_date'])) : '-' ?></td></tr>
    <tr><td class="label">ศาลที่ยื่นฟ้อง</td>
        <td><?= htmlspecialchars($case['court_name'] ?? '-') ?></td>
        <td class="label">ข้อหา</td>
        <td><strong><?= htmlspecialchars($case['charge'] ?? '-') ?></strong></td></tr>
  </table>
  <?php else: ?>
  <div class="highlight">ยังไม่มีการยื่นฟ้องในระบบ</div>
  <?php endif; ?>
</div>

<!-- ===== 4. ประวัติขึ้นศาล ===== -->
<div class="section">
  <div class="section-title">4. วันนัดขึ้นศาลและประวัติการพิจารณา</div>
  <?php if (empty($hearings)): ?>
  <div class="highlight">ยังไม่มีประวัติการขึ้นศาล</div>
  <?php else: ?>
  <!-- สถิติ -->
  <div class="stat-row">
    <div class="stat-box"><strong><?= $totalHearings ?></strong>นัดทั้งหมด</div>
    <div class="stat-box"><strong><?= $completedCount ?></strong>พิจารณาเสร็จ</div>
    <div class="stat-box"><strong style="color:#856404;"><?= $postponedCount ?></strong>เลื่อนนัด</div>
    <div class="stat-box"><strong style="color:#842029;"><?= $absentDefendant ?></strong>จำเลยไม่มา</div>
    <div class="stat-box"><strong style="color:#842029;"><?= $absentPlaintiff ?></strong>โจทก์ไม่มา</div>
  </div>
  <?php if ($postponedCount > 0): ?>
  <div class="highlight" style="margin-bottom:10px;">[!] มีการเลื่อนนัด / ฝ่ายไม่มาศาล รวม <strong><?= $postponedCount ?></strong> ครั้ง</div>
  <?php endif; ?>
  <table class="htable">
    <thead>
      <tr>
        <th style="width:50px;">ครั้งที่</th>
        <th>วันนัด</th><th>เวลา</th><th>ห้องพิจารณา</th>
        <th>สถานะ</th><th>หมายเหตุ</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($hearings as $h):
      $bc = match($h['status']) {
        'completed'               => 'b-done',
        'postponed'               => 'b-post',
        'defendant_absent',
        'plaintiff_absent'        => 'b-abs',
        'scheduled'               => 'b-sched',
        'defendant_guilty_verdict'=> 'b-done',
        default                   => 'b-cancel',
      };
    ?>
    <tr>
      <td style="text-align:center;"><?= $h['hearing_round'] ?></td>
      <td><?= date('d/m/Y', strtotime($h['hearing_date'])) ?></td>
      <td><?= $h['hearing_time'] ? substr($h['hearing_time'],0,5) : '-' ?></td>
      <td><?= htmlspecialchars($h['court_room'] ?? '-') ?></td>
      <td><span class="badge <?= $bc ?>"><?= $statusHearingTH[$h['status']] ?? $h['status'] ?></span></td>
      <td style="font-size:12px;"><?= htmlspecialchars(mb_substr($h['notes'] ?? '', 0, 70)) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- ===== 5. คำพิพากษา ===== -->
<div class="section">
  <div class="section-title">5. ผลคำพิพากษาในชั้นศาล</div>
  <?php if ($case['verdict_result']): ?>
  <table class="info" style="margin-bottom:10px;">
    <tr><td class="label">วันที่พิพากษา</td>
        <td><?= $case['verdict_date'] ? date('d/m/Y', strtotime($case['verdict_date'])) : '-' ?></td>
        <td class="label">ผล</td>
        <td><strong style="color:<?= $winnerColor ?>;"><?= $winnerText ?></strong></td></tr>
  </table>
  <div class="verdict-box"><?= nl2br(htmlspecialchars($case['verdict_result'])) ?></div>
  <?php else: ?>
  <div class="highlight">ยังไม่มีคำพิพากษาในระบบ</div>
  <?php endif; ?>
</div>

<!-- ===== 6. เอกสารในสำนวน ===== -->
<?php if (!empty($docs)): ?>
<div class="section">
  <div class="section-title">6. รายการเอกสารในสำนวนคดี</div>
  <?php foreach ($docs as $di => $doc): ?>
  <div class="doc-row">
    <span><?= ($di+1) ?>. <?= htmlspecialchars($doc['file_name']) ?></span>
    <span style="color:#777;"><?= $docTypeTH[$doc['document_type']] ?? $doc['document_type'] ?>
    &nbsp;|&nbsp; <?= date('d/m/Y', strtotime($doc['created_at'])) ?></span>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ===== ลายเซ็น ===== -->
<div class="sign-area">
  <div class="sign-box">
    <div class="sign-line"></div>
    <div style="font-size:12.5px;font-weight:bold;">ทนายความ</div>
    <div style="font-size:12px;color:#666;"><?= htmlspecialchars($case['lawyer_name']) ?></div>
    <div style="font-size:11px;color:#999;">วันที่ ............/............/............</div>
  </div>
  <div class="sign-box">
    <div class="sign-line"></div>
    <div style="font-size:12.5px;font-weight:bold;">ลูกความ</div>
    <div style="font-size:12px;color:#666;"><?= htmlspecialchars($case['client_name']) ?></div>
    <div style="font-size:11px;color:#999;">วันที่ ............/............/............</div>
  </div>
</div>

<div class="page-footer">
  <span>สำนวนคดี | ระบบจัดการคดีความ</span>
  <span>พิมพ์เมื่อ: <?= date('d/m/Y H:i:s') ?></span>
</div>

</body></html>
<?php
    $html = ob_get_clean();

    // temp files
    $ts      = time();
    $tmpHtml = "/tmp/summary_{$requestId}_{$ts}.html";
    $tmpPdf  = "/tmp/summary_{$requestId}_{$ts}.pdf";
    file_put_contents($tmpHtml, $html);

    // หา path ของ wkhtmltopdf
    $wkPath = '';
    foreach (['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf', '/bin/wkhtmltopdf'] as $p) {
        if (file_exists($p) && is_executable($p)) { $wkPath = $p; break; }
    }
    if (!$wkPath) {
        $wkPath = trim(shell_exec('which wkhtmltopdf 2>/dev/null'));
    }
    if (!$wkPath) {
        $error = 'ไม่พบโปรแกรม wkhtmltopdf ในระบบ กรุณาติดต่อผู้ดูแลระบบ';
        @unlink($tmpHtml);
        goto render;
    }

    // wkhtmltopdf — ใส่ PATH env ครบ เพื่อให้ PHP web process เรียกได้
    $cmd = "PATH=/usr/bin:/usr/local/bin:/bin:/usr/sbin"
         . " " . escapeshellarg($wkPath)
         . " --quiet"
         . " --encoding utf-8"
         . " --page-size A4"
         . " --margin-top 15mm --margin-bottom 15mm"
         . " --margin-left 18mm --margin-right 18mm"
         . " --footer-right 'หน้า [page] / [topage]'"
         . " --footer-font-size 9"
         . " " . escapeshellarg($tmpHtml)
         . " " . escapeshellarg($tmpPdf)
         . " 2>&1";

    exec($cmd, $out, $ret);

    if ($ret !== 0 || !file_exists($tmpPdf)) {
        $error = 'สร้าง PDF ไม่สำเร็จ (path: '.$wkPath.'): ' . implode(' ', $out);
        @unlink($tmpHtml);
        goto render;
    }

    // บันทึก PDF ถาวร
    $saveDir = '/var/www/html/uploads/summaries/';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
    $filename = 'summary_' . $requestId . '_' . date('Ymd_His') . '.pdf';
    $savePath = $saveDir . $filename;

    // ===== รวมไฟล์ PDF แนบเพิ่มเติม (ถ้ามี) =====
    $extraPdfs = [];
    if (!empty($_FILES['extra_pdfs']['name'][0])) {
        $uploadTmpDir = '/tmp/extra_pdfs_' . $ts . '/';
        mkdir($uploadTmpDir, 0755, true);
        foreach ($_FILES['extra_pdfs']['tmp_name'] as $idx => $tmpFile) {
            if ($tmpFile && $_FILES['extra_pdfs']['error'][$idx] === 0) {
                $label    = trim($_POST['extra_pdf_labels'][$idx] ?? '');
                $destFile = $uploadTmpDir . 'extra_' . $idx . '.pdf';
                move_uploaded_file($tmpFile, $destFile);
                $extraPdfs[] = ['path' => $destFile, 'label' => $label];
            }
        }
    }

    if (!empty($extraPdfs)) {
        // รวม PDF ด้วย qpdf: summary + extra pdfs
        $mergedPdf  = '/tmp/merged_' . $requestId . '_' . $ts . '.pdf';
        $pageFiles  = [$tmpPdf];
        foreach ($extraPdfs as $ep) $pageFiles[] = $ep['path'];

        // สร้าง separator page HTML สำหรับแต่ละ extra PDF
        $allParts = [$tmpPdf];
        foreach ($extraPdfs as $idx => $ep) {
            $label = htmlspecialchars($ep['label'] ?: ('เอกสารแนบที่ ' . ($idx + 1)));
            $sepHtml = "/tmp/sep_{$idx}_{$ts}.html";
            $sepPdf  = "/tmp/sep_{$idx}_{$ts}.pdf";
            file_put_contents($sepHtml, "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<style>body{display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:sans-serif;}
.box{text-align:center;border:3px solid #1a3a5c;border-radius:12px;padding:40px 60px;}
h2{color:#1a3a5c;font-size:26px;}p{color:#555;font-size:16px;}</style></head>
<body><div class='box'><div style='font-size:48px'>📎</div><h2>เอกสารแนบ</h2><p>{$label}</p></div></body></html>");
            exec("/usr/bin/wkhtmltopdf --quiet --page-size A4 " . escapeshellarg($sepHtml) . " " . escapeshellarg($sepPdf) . " 2>&1");
            @unlink($sepHtml);
            if (file_exists($sepPdf)) $allParts[] = $sepPdf;
            $allParts[] = $ep['path'];
        }

        // merge ด้วย qpdf
        $partsArg = implode(' ', array_map('escapeshellarg', $allParts));
        exec("qpdf --empty --pages $partsArg -- " . escapeshellarg($mergedPdf) . " 2>&1", $qout, $qret);

        if ($qret === 0 && file_exists($mergedPdf)) {
            copy($mergedPdf, $savePath);
            @unlink($mergedPdf);
        } else {
            copy($tmpPdf, $savePath); // fallback ถ้า merge ไม่ได้
        }
        // cleanup
        foreach ($allParts as $p) @unlink($p);
    } else {
        copy($tmpPdf, $savePath);
    }

    @unlink($tmpHtml);
    @unlink($tmpPdf);

    // stream ออก หรือบันทึกอย่างเดียว
    if ($pdfAction === 'save') {
        // บันทึกอย่างเดียว แล้ว redirect กลับ
        header('Location: /pages/case_summary.php?request_id=' . $requestId . '&saved=1');
        exit;
    }
    $disposition = ($pdfAction === 'download') ? 'attachment' : 'inline';
    header('Content-Type: application/pdf');
    header("Content-Disposition: $disposition; filename=\"$filename\"");
    header('Content-Length: ' . filesize($savePath));
    readfile($savePath);
    exit;
}

// ==============================
// Handle DELETE PDF
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_pdf') {
    csrf_verify();
    $delFile    = basename($_POST['filename'] ?? '');
    $delReqId   = (int)($_POST['request_id'] ?? 0);
    $saveDir    = '/var/www/html/uploads/summaries/';
    $fullPath   = $saveDir . $delFile;

    // ตรวจสอบความปลอดภัย: ต้องเป็นไฟล์ summary ของ request_id นี้เท่านั้น
    if ($delFile && str_starts_with($delFile, 'summary_' . $delReqId . '_') && file_exists($fullPath)) {
        unlink($fullPath);
        header('Location: /pages/case_summary.php?request_id=' . $delReqId . '&deleted=1');
        exit;
    }
    header('Location: /pages/case_summary.php?request_id=' . $delReqId . '&delete_error=1');
    exit;
}

render:

// ==============================
// ดึงรายการคดีสำหรับ dropdown
// ==============================
if ($role === 'lawyer') {
    $lp = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $lp->execute([$userId]);
    $lawyerId = $lp->fetchColumn();
    $whereRole = "AND cr.lawyer_id = " . (int)$lawyerId;
} elseif ($role === 'client') {
    $cp = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
    $cp->execute([$userId]);
    $clientId = $cp->fetchColumn();
    $whereRole = "AND cr.client_id = " . (int)$clientId;
} else {
    $whereRole = '';
}

$casesStmt = $pdo->prepare("
    SELECT cr.request_id, cr.detail, cr.status, cr.created_at,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           con.contract_id, f.case_number, f.filing_id,
           v.verdict_id
    FROM case_requests cr
    JOIN client_profiles cp ON cr.client_id = cp.client_id
    JOIN lawyer_profiles lp ON cr.lawyer_id = lp.lawyer_id
    LEFT JOIN contracts con ON cr.request_id = con.request_id
    LEFT JOIN filings f     ON con.contract_id = f.contract_id
    LEFT JOIN verdicts v    ON f.filing_id = v.filing_id
    WHERE cr.office_id = ? $whereRole
    ORDER BY cr.created_at DESC
");
$casesStmt->execute([$officeId]);
$cases = $casesStmt->fetchAll();

$selectedId = (int)($_GET['request_id'] ?? 0);

// ดึงเอกสารของ case ที่เลือก
$caseDocs = [];
if ($selectedId) {
    $selCon = $pdo->prepare("SELECT con.contract_id FROM case_requests cr LEFT JOIN contracts con ON cr.request_id = con.request_id WHERE cr.request_id = ? AND cr.office_id = ?");
    $selCon->execute([$selectedId, $officeId]);
    $row = $selCon->fetch();
    if ($row && $row['contract_id']) {
        $docStmt = $pdo->prepare("SELECT * FROM case_documents WHERE contract_id = ? ORDER BY created_at ASC");
        $docStmt->execute([$row['contract_id']]);
        $caseDocs = $docStmt->fetchAll();
    }
}

// ดึงข้อมูลแสดง preview ฝั่งขวา
$previewCase = null;
if ($selectedId) {
    $pvStmt = $pdo->prepare("
        SELECT cr.*, cr.created_at,
               CONCAT(cp.fname,' ',cp.lname) AS client_name, cp.phone AS client_phone,
               CONCAT(lp.fname,' ',lp.lname) AS lawyer_name, lp.specialization, lp.phone AS lawyer_phone,
               con.contract_id, con.contract_date, con.fee_amount, con.contract_review_status, con.payment_status,
               f.filing_id, f.case_number, f.charge, f.filing_date,
               ct.court_name,
               v.result AS verdict_result, v.verdict_date,
               (SELECT COUNT(*) FROM court_hearings ch WHERE ch.filing_id = f.filing_id) AS total_hearings,
               (SELECT COUNT(*) FROM court_hearings ch WHERE ch.filing_id = f.filing_id AND ch.status IN ('postponed','defendant_absent','plaintiff_absent')) AS postponed_count
        FROM case_requests cr
        JOIN client_profiles cp  ON cr.client_id  = cp.client_id
        JOIN lawyer_profiles lp  ON cr.lawyer_id  = lp.lawyer_id
        LEFT JOIN contracts con  ON cr.request_id = con.request_id
        LEFT JOIN filings f      ON con.contract_id = f.contract_id
        LEFT JOIN courts ct      ON f.court_id = ct.court_id
        LEFT JOIN verdicts v     ON f.filing_id = v.filing_id
        WHERE cr.request_id = ? AND cr.office_id = ?
    ");
    $pvStmt->execute([$selectedId, $officeId]);
    $previewCase = $pvStmt->fetch();
}

$docTypeTH = ['contract'=>'📄 สัญญา','id_card'=>'🪪 บัตรประชาชน','evidence'=>'🔍 หลักฐาน','power_of_attorney'=>'📝 มอบอำนาจ','other'=>'📎 อื่นๆ'];
$pageTitle = 'สำนวนคดี';
include '../includes/header.php';
?>

<style>
.case-card{border:1px solid #e2e8f0;border-radius:8px;padding:11px 14px;margin-bottom:8px;cursor:pointer;transition:.15s;background:#fff;}
.case-card:hover,.case-card.selected{border-color:#1a3a5c;background:#f0f7ff;}
.step-dot{display:inline-block;width:28px;height:28px;border-radius:50%;text-align:center;line-height:28px;font-size:10px;font-weight:700;color:#fff;flex-shrink:0;}
.info-row{display:grid;grid-template-columns:140px 1fr;gap:4px 10px;font-size:0.87rem;margin-bottom:4px;}
.info-row .lbl{color:#888;font-size:0.8rem;}
.section-preview{margin-bottom:18px;}
.section-preview h4{color:#1a3a5c;border-bottom:2px solid #e2e8f0;padding-bottom:6px;margin-bottom:10px;font-size:0.95rem;}
.stat-pill{display:inline-block;padding:3px 12px;border-radius:12px;font-size:0.78rem;margin:2px;}
</style>

<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success">💾 บันทึก PDF สำเร็จแล้ว — ดูได้ในรายการ "PDF ที่บันทึกไว้" ด้านล่าง</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success">🗑 ลบไฟล์ PDF เรียบร้อยแล้ว</div>
<?php endif; ?>
<?php if (isset($_GET['delete_error'])): ?>
<div class="alert alert-error">ไม่สามารถลบไฟล์ได้</div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:320px 1fr; gap:18px; align-items:start;">

  <!-- ซ้าย: เลือกคดี -->
  <div class="card" style="position:sticky;top:16px;max-height:92vh;display:flex;flex-direction:column;">
    <h2 style="margin-bottom:10px;">📁 เลือกคดี</h2>
    <input type="text" id="case-search" placeholder="🔍 ค้นหาชื่อ / เลขคดี..."
           style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;margin-bottom:10px;font-size:0.85rem;"
           onkeyup="filterCases(this.value)">
    <div id="case-list" style="overflow-y:auto;flex:1;">
    <?php foreach ($cases as $c):
        $step = 1;
        if ($c['contract_id']) $step = 2;
        if ($c['filing_id'])   $step = 3;
        if ($c['verdict_id'])  $step = 4;
        $dotColors = ['','#fd7e14','#1a3a5c','#0d6efd','#198754'];
        $dotLabels = ['','คำขอ','สัญญา','ฟ้อง','จบ'];
    ?>
    <div class="case-card <?= $selectedId == $c['request_id'] ? 'selected' : '' ?>"
         data-search="<?= strtolower(htmlspecialchars($c['client_name'].' '.($c['case_number']??'').' '.$c['lawyer_name'])) ?>"
         onclick="location.href='/pages/case_summary.php?request_id=<?= $c['request_id'] ?>'">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
        <span class="step-dot" style="background:<?= $dotColors[$step] ?>;"><?= $dotLabels[$step] ?></span>
        <strong style="font-size:0.88rem;color:#1a3a5c;"><?= htmlspecialchars($c['client_name']) ?></strong>
      </div>
      <div style="font-size:0.77rem;color:#888;padding-left:36px;">
        👨‍⚖️ <?= htmlspecialchars($c['lawyer_name']) ?>
        <?= $c['case_number'] ? '&nbsp;|&nbsp; 📁 '.$c['case_number'] : '' ?><br>
        🗓 <?= date('d/m/Y', strtotime($c['created_at'])) ?>
        &nbsp;|&nbsp; <?= htmlspecialchars(mb_substr($c['detail']??'',0,28)) ?>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>

  <!-- ขวา: preview + สร้าง PDF -->
  <div>
    <?php if (!$selectedId): ?>
    <div class="card" style="text-align:center;padding:70px 20px;color:#bbb;">
      <div style="font-size:4rem;margin-bottom:14px;">📋</div>
      <div style="font-size:1.1rem;">เลือกคดีจากรายการทางซ้ายเพื่อดูรายละเอียดและสร้างสำนวนคดี</div>
    </div>

    <?php elseif ($previewCase): ?>
    <div class="card">
      <h2 style="margin-bottom:16px;">📋 รายละเอียดคดี</h2>

      <!-- หัวข้อสรุป -->
      <div style="background:#f0f7ff;border-radius:8px;padding:14px 16px;margin-bottom:18px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
          <div>
            <div style="font-size:1.05rem;font-weight:700;color:#1a3a5c;">
              <?= htmlspecialchars($previewCase['client_name']) ?>
              <span style="font-weight:normal;font-size:0.85rem;color:#666;"> — โจทก์</span>
            </div>
            <div style="font-size:0.83rem;color:#555;margin-top:3px;">
              👨‍⚖️ <?= htmlspecialchars($previewCase['lawyer_name']) ?>
              <?= $previewCase['specialization'] ? '('.$previewCase['specialization'].')' : '' ?>
            </div>
          </div>
          <div style="text-align:right;">
            <?php if ($previewCase['case_number']): ?>
            <div style="font-weight:700;color:#1a3a5c;">📁 <?= htmlspecialchars($previewCase['case_number']) ?></div>
            <div style="font-size:0.8rem;color:#888;"><?= htmlspecialchars($previewCase['court_name'] ?? '—') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Section 1: คำขอว่าจ้าง -->
      <div class="section-preview">
        <h4>1. คำขอว่าจ้าง</h4>
        <div class="info-row">
          <span class="lbl">📅 วันที่ส่งคำขอ</span><span><?= date('d/m/Y', strtotime($previewCase['created_at'])) ?></span>
          <span class="lbl">⏰ วันหมดอายุ</span><span><?= !empty($previewCase['expires_at']) ? date('d/m/Y', strtotime($previewCase['expires_at'])) : '—' ?></span>
          <span class="lbl">📊 สถานะ</span><span><?= htmlspecialchars($previewCase['status']) ?></span>
          <span class="lbl">📞 เบอร์ลูกความ</span><span><?= htmlspecialchars($previewCase['client_phone'] ?? '—') ?></span>
          <span class="lbl">📞 เบอร์ทนาย</span><span><?= htmlspecialchars($previewCase['lawyer_phone'] ?? '—') ?></span>
          <span class="lbl" style="grid-column:1;">📄 รายละเอียด</span>
          <span style="grid-column:2;"><?= htmlspecialchars(mb_substr($previewCase['detail']??'—',0,160)) ?></span>
        </div>
      </div>

      <!-- Section 2: สัญญา -->
      <div class="section-preview">
        <h4>2. สัญญาว่าจ้าง</h4>
        <?php if ($previewCase['contract_id']): ?>
        <div class="info-row">
          <span class="lbl">📅 วันทำสัญญา</span><span><?= $previewCase['contract_date'] ? date('d/m/Y', strtotime($previewCase['contract_date'])) : '—' ?></span>
          <span class="lbl">💰 ค่าดำเนินคดี</span><span><strong><?= $previewCase['fee_amount'] ? number_format($previewCase['fee_amount'],2).' บาท' : '—' ?></strong></span>
          <span class="lbl">📊 สถานะสัญญา</span><span><?= htmlspecialchars($previewCase['contract_review_status'] ?? '—') ?></span>
          <span class="lbl">💳 สถานะชำระ</span><span><?= htmlspecialchars($previewCase['payment_status'] ?? '—') ?></span>
        </div>
        <?php else: ?><span style="color:#aaa;font-size:0.85rem;">ยังไม่มีสัญญา</span><?php endif; ?>
      </div>

      <!-- Section 3: ยื่นฟ้อง -->
      <div class="section-preview">
        <h4>3. การยื่นฟ้องคดี</h4>
        <?php if ($previewCase['filing_id']): ?>
        <div class="info-row">
          <span class="lbl">📁 หมายเลขคดี</span><span><strong><?= htmlspecialchars($previewCase['case_number'] ?? '—') ?></strong></span>
          <span class="lbl">🏛️ ศาล</span><span><?= htmlspecialchars($previewCase['court_name'] ?? '—') ?></span>
          <span class="lbl">⚖️ ข้อหา</span><span><?= htmlspecialchars($previewCase['charge'] ?? '—') ?></span>
          <span class="lbl">📅 วันยื่นฟ้อง</span><span><?= $previewCase['filing_date'] ? date('d/m/Y', strtotime($previewCase['filing_date'])) : '—' ?></span>
        </div>
        <?php else: ?><span style="color:#aaa;font-size:0.85rem;">ยังไม่มีการยื่นฟ้อง</span><?php endif; ?>
      </div>

      <!-- Section 4: ขึ้นศาล -->
      <div class="section-preview">
        <h4>4. ประวัติขึ้นศาล</h4>
        <?php if ($previewCase['total_hearings'] > 0): ?>
        <span class="stat-pill" style="background:#e8f0fe;color:#1a3a5c;">นัดทั้งหมด <?= $previewCase['total_hearings'] ?> ครั้ง</span>
        <span class="stat-pill" style="background:#fff3cd;color:#856404;">เลื่อน/ไม่มา <?= $previewCase['postponed_count'] ?> ครั้ง</span>
        <?php else: ?><span style="color:#aaa;font-size:0.85rem;">ยังไม่มีนัดขึ้นศาล</span><?php endif; ?>
      </div>

      <!-- Section 5: คำพิพากษา (รายละเอียดครบ) -->
      <div class="section-preview">
        <h4>5. ผลคำพิพากษา</h4>
        <?php if ($previewCase['verdict_result']):
          $isP = str_contains($previewCase['verdict_result'],'ผู้ชนะ: โจทก์') || str_contains($previewCase['verdict_result'],'โจทก์ชนะ');
          $vLines = explode("\n", $previewCase['verdict_result']);
          // แยก section จาก result text
          $vSummary = ''; $vReason = ''; $vDamage = '';
          $curSection = '';
          foreach ($vLines as $vl) {
              $vl = trim($vl);
              if (str_contains($vl,'สรุปคำพิพากษา:')) { $curSection='summary'; continue; }
              if (str_contains($vl,'เหตุผลของศาล:'))   { $curSection='reason';  continue; }
              if (str_contains($vl,'ค่าเสียหาย'))      { $vDamage .= $vl.' '; continue; }
              if ($curSection==='summary') $vSummary .= $vl."\n";
              if ($curSection==='reason')  $vReason  .= $vl."\n";
          }
        ?>
        <!-- Winner Banner -->
        <div style="padding:10px 16px;border-radius:8px;margin-bottom:12px;display:flex;align-items:center;gap:12px;
                    background:<?= $isP ? '#d1e7dd' : '#cfe2ff' ?>;border:2px solid <?= $isP ? '#198754' : '#0d6efd' ?>;">
          <div style="font-size:2rem;"><?= $isP ? '🏆' : '🛡️' ?></div>
          <div>
            <div style="font-size:1rem;font-weight:700;color:<?= $isP ? '#0f5132' : '#084298' ?>;">
              <?= $isP ? '✅ โจทก์ชนะคดี ('.$previewCase['client_name'].')' : '✅ จำเลยชนะคดี' ?>
            </div>
            <div style="font-size:0.82rem;color:#555;">วันที่พิพากษา: <?= $previewCase['verdict_date'] ? date('d/m/Y', strtotime($previewCase['verdict_date'])) : '—' ?></div>
          </div>
        </div>

        <!-- สรุปคำพิพากษา -->
        <?php if (trim($vSummary)): ?>
        <div style="background:#f8fafc;border-left:3px solid #1a3a5c;padding:8px 12px;border-radius:0 6px 6px 0;margin-bottom:8px;font-size:0.85rem;line-height:1.6;">
          <div style="font-size:0.75rem;color:#888;margin-bottom:4px;font-weight:700;">📋 สรุปคำพิพากษา</div>
          <?= nl2br(htmlspecialchars(trim($vSummary))) ?>
        </div>
        <?php endif; ?>

        <!-- เหตุผลศาล -->
        <?php if (trim($vReason)): ?>
        <div style="background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:0 6px 6px 0;margin-bottom:8px;font-size:0.85rem;line-height:1.6;">
          <div style="font-size:0.75rem;color:#856404;margin-bottom:4px;font-weight:700;">⚖️ เหตุผลของศาล</div>
          <?= nl2br(htmlspecialchars(trim($vReason))) ?>
        </div>
        <?php endif; ?>

        <!-- ค่าเสียหาย -->
        <?php if (trim($vDamage)): ?>
        <div style="background:#fef3c7;border-left:3px solid #d97706;padding:8px 12px;border-radius:0 6px 6px 0;font-size:0.85rem;">
          <div style="font-size:0.75rem;color:#92400e;margin-bottom:4px;font-weight:700;">💰 ค่าเสียหาย / ค่าปรับ</div>
          <?= htmlspecialchars(trim($vDamage)) ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div style="padding:12px;background:#f8f9fa;border-radius:6px;color:#aaa;font-size:0.85rem;text-align:center;">
          ⚖️ ยังไม่มีคำพิพากษาในระบบ
        </div>
        <?php endif; ?>
      </div>

      <!-- Section 6: เอกสาร -->
      <?php if (!empty($caseDocs)): ?>
      <div class="section-preview">
        <h4>6. เอกสารในสำนวน</h4>
        <?php foreach ($caseDocs as $di => $doc): ?>
        <div style="display:flex;justify-content:space-between;padding:5px 10px;border:1px solid #e5e5e5;border-radius:4px;margin-bottom:4px;font-size:0.83rem;">
          <span><?= ($di+1) ?>. <?= htmlspecialchars(mb_substr($doc['file_name'],0,50)) ?></span>
          <span style="color:#888;"><?= $docTypeTH[$doc['document_type']] ?? '📎' ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- ส่งออก PDF + แนบเอกสารเพิ่มเติม -->
      <div style="border-top:2px solid #e2e8f0;padding-top:16px;margin-top:8px;">
        <div style="font-weight:700;color:#1a3a5c;margin-bottom:14px;font-size:1rem;">📥 ส่งออกสำนวนคดีเป็น PDF</div>

        <form method="POST" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="generate_pdf">
          <input type="hidden" name="request_id" value="<?= $selectedId ?>">

          <!-- แนบ PDF เพิ่มเติม -->
          <div style="background:#f8fafc;border:1px dashed #bdd7f5;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
            <div style="font-weight:700;color:#1a3a5c;margin-bottom:8px;font-size:0.9rem;">
              📎 แนบเอกสาร PDF เพิ่มเติม <span style="font-weight:normal;color:#888;font-size:0.8rem;">(ไม่บังคับ — จะรวมต่อท้ายสำนวน)</span>
            </div>
            <p style="font-size:0.8rem;color:#888;margin-bottom:10px;">เช่น เอกสารราชการ, คำสั่งศาล, หลักฐานเพิ่มเติม ที่ต้องการรวมเป็นเล่มเดียวกัน</p>

            <div id="pdf-attach-list">
              <div class="pdf-attach-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                <input type="file" name="extra_pdfs[]" accept=".pdf"
                       style="flex:1;padding:6px;border:1px solid #ddd;border-radius:6px;font-size:0.83rem;background:#fff;">
                <input type="text" name="extra_pdf_labels[]" placeholder="ชื่อ/คำอธิบาย (ไม่บังคับ)"
                       style="width:180px;padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:0.83rem;">
                <button type="button" onclick="addPdfRow()" title="เพิ่มไฟล์"
                        style="background:#1a3a5c;color:#fff;border:none;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:1rem;">＋</button>
              </div>
            </div>
            <div style="font-size:0.75rem;color:#aaa;margin-top:4px;">
              💡 รองรับเฉพาะไฟล์ .pdf | ขนาดสูงสุด 10 MB ต่อไฟล์
            </div>
          </div>

          <!-- 3 ปุ่มแยก -->
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:4px;">

            <button type="submit" name="pdf_action" value="save"
                    style="display:flex;flex-direction:column;align-items:center;gap:5px;
                           padding:14px 8px;border-radius:10px;border:2px solid #1a3a5c;
                           background:#1a3a5c;color:#fff;cursor:pointer;transition:.15s;"
                    onmouseover="this.style.background='#142d4a'" onmouseout="this.style.background='#1a3a5c'">
              <span style="font-size:1.8rem;">💾</span>
              <span style="font-weight:700;font-size:0.88rem;">บันทึก PDF</span>
              <span style="font-size:0.72rem;opacity:.8;">บันทึกเก็บในระบบ</span>
            </button>

            <button type="submit" name="pdf_action" value="view"
                    style="display:flex;flex-direction:column;align-items:center;gap:5px;
                           padding:14px 8px;border-radius:10px;border:2px solid #0d6efd;
                           background:#0d6efd;color:#fff;cursor:pointer;transition:.15s;"
                    onmouseover="this.style.background='#0a58ca'" onmouseout="this.style.background='#0d6efd'">
              <span style="font-size:1.8rem;">👁</span>
              <span style="font-weight:700;font-size:0.88rem;">ดู PDF</span>
              <span style="font-size:0.72rem;opacity:.8;">เปิดดูในเบราว์เซอร์</span>
            </button>

            <button type="submit" name="pdf_action" value="download"
                    style="display:flex;flex-direction:column;align-items:center;gap:5px;
                           padding:14px 8px;border-radius:10px;border:2px solid #198754;
                           background:#198754;color:#fff;cursor:pointer;transition:.15s;"
                    onmouseover="this.style.background='#146c43'" onmouseout="this.style.background='#198754'">
              <span style="font-size:1.8rem;">⬇</span>
              <span style="font-weight:700;font-size:0.88rem;">ดาวน์โหลด PDF</span>
              <span style="font-size:0.72rem;opacity:.8;">บันทึกลงเครื่อง</span>
            </button>

          </div>
        </form>
      </div>
    </div>

    <!-- PDF ที่บันทึกไว้ -->
    <?php
    $pdfDir   = '/var/www/html/uploads/summaries/';
    $pdfFiles = glob($pdfDir . 'summary_' . $selectedId . '_*.pdf') ?: [];
    rsort($pdfFiles);
    if ($pdfFiles): ?>
    <div class="card" style="margin-top:14px;">
      <h3 style="color:#1a3a5c;margin-bottom:12px;">📂 PDF ที่บันทึกไว้ (<?= count($pdfFiles) ?> ไฟล์)</h3>
      <?php foreach ($pdfFiles as $idx => $pf):
          $fname    = basename($pf);
          $fdate    = date('d/m/Y H:i', filemtime($pf));
          $fsize    = number_format(filesize($pf)/1024, 1);
          $isLatest = ($idx === 0);
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;
                  padding:10px 14px;margin-bottom:8px;border-radius:8px;
                  border:1px solid <?= $isLatest ? '#1a3a5c' : '#e2e8f0' ?>;
                  background:<?= $isLatest ? '#f0f7ff' : '#fff' ?>;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:1.6rem;">📄</span>
          <div>
            <div style="font-size:0.84rem;font-weight:600;color:#1a3a5c;">
              <?php if ($isLatest): ?>
              <span style="background:#1a3a5c;color:#fff;font-size:0.68rem;padding:1px 7px;border-radius:8px;margin-right:5px;">ล่าสุด</span>
              <?php endif; ?>
              <?= htmlspecialchars($fname) ?>
            </div>
            <div style="font-size:0.75rem;color:#888;">🗓 <?= $fdate ?> &nbsp;|&nbsp; 📦 <?= $fsize ?> KB</div>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;">
          <a href="/uploads/summaries/<?= $fname ?>" target="_blank"
             style="display:flex;flex-direction:column;align-items:center;gap:2px;
                    padding:8px 16px;border-radius:8px;background:#0d6efd;color:#fff;
                    font-size:0.78rem;font-weight:700;text-decoration:none;">
            <span>👁</span><span>ดู</span>
          </a>
          <a href="/uploads/summaries/<?= $fname ?>" download
             style="display:flex;flex-direction:column;align-items:center;gap:2px;
                    padding:8px 16px;border-radius:8px;background:#198754;color:#fff;
                    font-size:0.78rem;font-weight:700;text-decoration:none;">
            <span>⬇</span><span>โหลด</span>
          </a>
          <form method="POST" onsubmit="return confirm('ยืนยันลบไฟล์ <?= $fname ?> ?')" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_pdf">
            <input type="hidden" name="filename" value="<?= $fname ?>">
            <input type="hidden" name="request_id" value="<?= $selectedId ?>">
            <button type="submit"
                    style="display:flex;flex-direction:column;align-items:center;gap:2px;
                           padding:8px 16px;border-radius:8px;background:#dc3545;color:#fff;
                           font-size:0.78rem;font-weight:700;border:none;cursor:pointer;">
              <span>🗑</span><span>ลบ</span>
            </button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
function filterCases(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.case-card').forEach(function(el) {
        el.style.display = el.dataset.search.includes(q) ? '' : 'none';
    });
}
function addPdfRow() {
    const list = document.getElementById('pdf-attach-list');
    const row  = document.createElement('div');
    row.className = 'pdf-attach-row';
    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;';
    row.innerHTML = `
        <input type="file" name="extra_pdfs[]" accept=".pdf"
               style="flex:1;padding:6px;border:1px solid #ddd;border-radius:6px;font-size:0.83rem;background:#fff;">
        <input type="text" name="extra_pdf_labels[]" placeholder="ชื่อ/คำอธิบาย (ไม่บังคับ)"
               style="width:180px;padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:0.83rem;">
        <button type="button" onclick="this.parentElement.remove()" title="ลบ"
                style="background:#dc3545;color:#fff;border:none;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:1rem;">✕</button>
    `;
    list.appendChild(row);
}
</script>

<?php include '../includes/footer.php'; ?>