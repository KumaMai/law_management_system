# Security Patch — วิธีนำไปใช้

## ไฟล์ที่ได้รับ และต้องเอาไปวางที่ไหน

| ไฟล์ที่ได้ | นำไปวางที่ | หมายเหตุ |
|---|---|---|
| `csrf_helper.php` | `src/config/csrf_helper.php` | ไฟล์ใหม่ — สร้างขึ้นมาใหม่ |
| `file_upload_helper.php` | `src/config/file_upload_helper.php` | ไฟล์ใหม่ — สร้างขึ้นมาใหม่ |
| `login.php` | `src/pages/login.php` | **แทนที่ทั้งไฟล์** |
| `logout.php` | `src/pages/logout.php` | **แทนที่ทั้งไฟล์** |
| `case_requests.php` | `src/pages/case_requests.php` | **แทนที่ทั้งไฟล์** |
| `payments_handlers.php` | อ่านวิธีด้านล่าง | แทนที่เฉพาะ handlers |
| `hearings_patch.php` | อ่านวิธีด้านล่าง | แทนที่เฉพาะส่วน |
| `client_sign_docs_patch.php` | อ่านวิธีด้านล่าง | แทนที่เฉพาะส่วน |
| `nginx_security_headers.conf` | `docker/nginx/default.conf` | **แทนที่ทั้งไฟล์** |

---

## ขั้นตอนการแก้ไขทีละไฟล์

### ขั้นที่ 1 — วางไฟล์ config ใหม่ (ทำก่อน)
```
src/config/csrf_helper.php        ← วางไฟล์ csrf_helper.php ที่ได้รับ
src/config/file_upload_helper.php ← วางไฟล์ file_upload_helper.php ที่ได้รับ
```

### ขั้นที่ 2 — แทนที่ login.php และ logout.php
แทนที่ทั้งไฟล์ได้เลย ไม่มีอะไรต้องปรับเพิ่ม

### ขั้นที่ 3 — แทนที่ case_requests.php
แทนที่ทั้งไฟล์ได้เลย

### ขั้นที่ 4 — แก้ payments.php
เปิด `src/pages/payments.php` แล้ว:

1. เพิ่มบรรทัดนี้ต้นไฟล์ (หลัง require_once อื่นๆ):
```php
require_once '../config/csrf_helper.php';
require_once '../config/file_upload_helper.php';
```

2. **แทนที่** POST handlers ทั้งหมด (ตั้งแต่ `// Handle: ทนายอัปโหลด QR Code`
   จนถึง `// Handle: ทนายบันทึกรับเงินสดเอง`) ด้วยโค้ดจากไฟล์ `payments_handlers.php`

3. ส่วน fetch + HTML ด้านล่างเหมือนเดิม **แต่ต้องเพิ่ม** `<?= csrf_field() ?>`
   ในทุก `<form method="POST">` (หาด้วย Ctrl+F แล้วเพิ่มทีละตัว)

### ขั้นที่ 5 — แก้ hearings.php
เปิด `src/pages/hearings.php` แล้ว:

1. เพิ่มบรรทัดนี้ต้นไฟล์:
```php
require_once '../config/csrf_helper.php';
```

2. หาโค้ดส่วนนี้:
```php
if (!empty($pending)) {
    $fidList = implode(',', array_column(array_values($pending), 'filing_id'));
    if ($fidList) {
        $hStmt = $pdo->query("
            SELECT ... WHERE filing_id IN ($fidList)
        ");
```
**แทนที่ด้วย:**
```php
if (!empty($pending)) {
    $fidList      = array_column(array_values($pending), 'filing_id');
    $placeholders = implode(',', array_fill(0, count($fidList), '?'));
    $hStmt = $pdo->prepare("
        SELECT filing_id, hearing_date, hearing_time, hearing_round, status, notes
        FROM court_hearings
        WHERE filing_id IN ($placeholders)
        ORDER BY hearing_date DESC
    ");
    $hStmt->execute($fidList);
```

3. เพิ่ม `csrf_verify();` ต้น POST handler block
4. เพิ่ม `<?= csrf_field() ?>` ในทุก `<form method="POST">`

### ขั้นที่ 6 — แก้ client_sign_docs.php
เปิด `src/pages/client_sign_docs.php` แล้ว:

1. เพิ่มบรรทัดนี้ต้นไฟล์:
```php
require_once '../config/csrf_helper.php';
require_once '../config/file_upload_helper.php';
```

2. แทนที่ upload handlers ทั้ง 3 ตัว (`upload_doc`, `upload_signed`, `delete_doc`)
   ด้วยโค้ดจากไฟล์ `client_sign_docs_patch.php`

3. เพิ่ม `<?= csrf_field() ?>` ในทุก `<form method="POST">`

### ขั้นที่ 7 — แก้ Nginx config
แทนที่ `docker/nginx/default.conf` ด้วยไฟล์ `nginx_security_headers.conf`
แล้ว restart docker:
```bash
docker-compose restart law_nginx
```

### ขั้นที่ 8 — เพิ่ม csrf_field() ในไฟล์ที่เหลือ
ไฟล์เหล่านี้มีฟอร์ม POST ต้องเพิ่ม `<?= csrf_field() ?>` และ `csrf_verify()` ด้วย:

- `dashboard.php`
- `contracts.php`
- `contract_documents.php`
- `case_documents_ext.php`
- `case_summary.php`
- `verdicts.php`
- `send_request.php`
- `lawyers.php`
- `clients.php`
- `profile.php`
- `register.php`
- `filings.php`

**วิธีเพิ่มอย่างเร็ว:**
- ทุกไฟล์: เพิ่ม `require_once '../config/csrf_helper.php';` ต้นไฟล์
- ทุก POST handler: เพิ่ม `csrf_verify();` บรรทัดแรก
- ทุก `<form method="POST">`: เพิ่ม `<?= csrf_field() ?>` บรรทัดแรกในฟอร์ม

---

## สรุปช่องโหว่ที่แก้ในแต่ละไฟล์

| ช่องโหว่ | ไฟล์ที่แก้ |
|---|---|
| Session Fixation | `login.php` |
| Rate Limiting | `login.php` |
| Logout Cookie | `logout.php` |
| Missing Authorization | `case_requests.php` |
| IDOR ใน Payment | `payments.php` |
| Race Condition | `payments.php` |
| Raw SQL จาก Array | `hearings.php` |
| Path Traversal | `client_sign_docs.php` |
| File MIME Validation | `client_sign_docs.php`, `payments.php` |
| Security Headers | `nginx/default.conf` |
| CSRF | ทุกไฟล์ที่มีฟอร์ม POST |
