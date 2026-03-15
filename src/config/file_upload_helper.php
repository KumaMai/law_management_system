<?php
// ============================================================
// file_upload_helper.php
// วางไว้ที่: src/config/file_upload_helper.php
// ใช้แทน extension check ในทุกไฟล์ที่รับ upload
// ============================================================

/**
 * ตรวจสอบไฟล์อัปโหลดแบบปลอดภัย
 *
 * @param array  $file          ข้อมูลจาก $_FILES['ชื่อ']
 * @param array  $allowedMimes  MIME types ที่อนุญาต
 * @param int    $maxBytes      ขนาดสูงสุด (bytes)
 * @return array ['ok'=>bool, 'error'=>string, 'ext'=>string]
 */
function validateUpload(array $file, array $allowedMimes, int $maxBytes): array {

    // ตรวจว่าอัปโหลดสำเร็จ
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'อัปโหลดไม่สำเร็จ (error code: ' . $file['error'] . ')'];
    }

    // ตรวจขนาด
    if ($file['size'] > $maxBytes) {
        $mb = round($maxBytes / 1024 / 1024, 1);
        return ['ok' => false, 'error' => "ไฟล์ใหญ่เกิน {$mb}MB"];
    }

    // ── ตรวจ MIME type จริงจาก content ของไฟล์ (ไม่ใช่จากชื่อ) ──
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimes)) {
        return ['ok' => false, 'error' => 'ประเภทไฟล์ไม่รองรับ'];
    }

    // หา extension จาก MIME type (ปลอดภัยกว่าใช้ชื่อไฟล์)
    $mimeToExt = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $ext = $mimeToExt[$mimeType] ?? 'bin';

    return ['ok' => true, 'error' => '', 'ext' => $ext, 'mime' => $mimeType];
}

// ── MIME type sets ที่ใช้บ่อย ──
const MIME_PDF       = ['application/pdf'];
const MIME_IMAGES    = ['image/jpeg', 'image/png'];
const MIME_PDF_IMGS  = ['application/pdf', 'image/jpeg', 'image/png'];
const MIME_DOCS      = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
