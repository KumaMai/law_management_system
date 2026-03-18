# Security & Patch Status — v4.0

เอกสารนี้บันทึกสถานะปัจจุบันของการแก้ไขด้านความปลอดภัยและ patch ทั้งหมด
**ทุก patch ได้ถูก apply เข้าโค้ดแล้ว** ไม่ต้องทำอะไรเพิ่มสำหรับ setup ใหม่

---

## ✅ Patch ที่ apply แล้ว

### 🔴 Critical

| ปัญหา | วิธีแก้ | ไฟล์ |
|---|---|---|
| **Session Fixation** — session ID เดิมไม่เปลี่ยนหลัง login | `session_regenerate_id(true)` หลัง login สำเร็จ | `login.php` |
| **Missing Authorization** — ทนายอนุมัติคดีของทนายคนอื่นได้ | ตรวจ `lawyer_id` + `office_id` ใน query ทุก action | `case_requests.php` |
| **IDOR ใน payments** — ยืนยันชำระเงินของ contract คนอื่นได้ | JOIN verify `payment_id → contract_id → office_id` | `payments.php` |

### 🟠 High

| ปัญหา | วิธีแก้ | ไฟล์ |
|---|---|---|
| **Race Condition ใน payments** — confirm พร้อมกันหลายครั้ง | `BEGIN TRANSACTION` + `SELECT ... FOR UPDATE` | `payments.php` |
| **File Upload — ตรวจแค่ extension** (8 จุด) | ใช้ `validateUpload()` ตรวจ MIME type จาก `finfo_file()` | ดูตาราง Upload ด้านล่าง |

### 🟡 Medium

| ปัญหา | วิธีแก้ | ไฟล์ |
|---|---|---|
| **CSRF** — ทุก POST form | `csrf_helper.php`: `csrf_field()` + `csrf_verify()` | ทุกไฟล์ |
| **Rate Limiting** — login brute force | 10 ครั้ง/15 นาที ด้วย session counter | `login.php` |
| **XSS** — output ข้อมูล user | `htmlspecialchars()` ทุกจุด + `json_encode()` ใน JS | ทุกไฟล์ |
| **Password Complexity** | ≥8 ตัว + uppercase + lowercase + digit | `register.php`, `lawyers.php`, `clients.php` |
| **citizen_id แสดงเต็ม** | mask display: `193•••••••61` | `clients.php` |

### 🟢 Low

| ปัญหา | วิธีแก้ | ไฟล์ |
|---|---|---|
| **Logout ไม่ลบ Session** | `session_destroy()` + unset cookie | `logout.php` |
| **Missing Security Headers** | X-Frame-Options, X-Content-Type-Options ฯลฯ | `nginx/default.conf` |
| **PHP execute ใน uploads** | `location ~* ^/uploads/.*\.php$ { deny all; }` | `nginx/default.conf` |
| **Missing Foreign Keys** | FK ครบทุกตาราง (รวมใน init.sql แล้ว) | `init.sql` |

---

## 📁 File Upload Validation — จุดที่ครอบคลุม

ทุกจุดใช้ `validateUpload()` จาก `src/config/file_upload_helper.php`
ซึ่งตรวจ MIME type จากเนื้อหาไฟล์จริง (`finfo_file`) ไม่ใช่จากนามสกุล

| ไฟล์ | input name | MIME Constant | ขนาดสูงสุด |
|---|---|---|---|
| `dashboard.php` | `profile_photo` (ทนาย) | `MIME_IMAGES` | 5 MB |
| `dashboard.php` | `client_photo` (ลูกความ) | `MIME_IMAGES` | 5 MB |
| `payments.php` | `qr_file` | `MIME_IMAGES` | 5 MB |
| `payments.php` | `slip_file` | `MIME_PDF_IMGS` | 10 MB |
| `contract_documents.php` | `document` | `MIME_DOCS` | 10 MB |
| `case_documents_ext.php` | `doc_file` | `MIME_DOCS_FULL` | 30 MB |
| `client_sign_docs.php` | `pdf_file` | `MIME_PDF` | 20 MB |
| `client_sign_docs.php` | `signed_pdf` | `MIME_PDF` | 20 MB |
| `Case_summary.php` | `extra_pdfs[]` (multiple) | `MIME_PDF` | 50 MB/file |

---

## 🗄️ Database — init.sql (v4.0)

`docker/mysql/init.sql` รวม migration ทั้งหมดไว้แล้ว:

| Migration เดิม | สิ่งที่เพิ่ม | สถานะ |
|---|---|---|
| `Migrate username.sql` | `users.username` | ✅ รวมแล้ว |
| `Migrate_payments.sql` | ตาราง `payments`, `lawyer_profiles.qr_code_file` | ✅ รวมแล้ว |
| `migrate_contract_review.sql` | `contracts.contract_review_status` + negotiation columns | ✅ รวมแล้ว |
| `migrate_dashboard.sql.sql` | ตาราง `announcements`, `lawyer_reviews` + profile columns | ✅ รวมแล้ว |
| `migrate_hearing_absent.sql` | `court_hearings.updated_at` | ✅ รวมแล้ว |
| `Migrate add missing fk.sql` | Foreign Keys ทุกตาราง | ✅ รวมแล้ว |

migration files เดิมย้ายไปเก็บที่ `docker/mysql/migrations/` (ใช้อ้างอิงเท่านั้น)

---

## 🚀 Setup ใหม่

```bash
# 1. Clone / วางไฟล์โปรเจค
git clone <repo>
cd law_management_system

# 2. Start (init.sql จะรันอัตโนมัติผ่าน Docker volume)
docker-compose up -d

# 3. รอ ~15 วินาที แล้วเปิด
# http://localhost:8080  (Web App)
# http://localhost:8081  (phpMyAdmin)
```

> admin password จะถูก hash อัตโนมัติโดย `docker/php/init-hash.sh` ตอน container start