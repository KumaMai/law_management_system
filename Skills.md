# 🛠️ Skills — ระบบจัดการคดีความ v4.6
*สรุปทักษะและความสามารถที่แสดงออกตลอดการพัฒนาโปรเจกต์นี้*

---

## 1. PHP Backend Development

### Handler Design
- เขียน POST handler หลาย action ในไฟล์เดียว (`action=save/delete/update_status`) โดยใช้ if แบบ flat ไม่ซ้อน เพื่อให้อ่านและ maintain ง่าย
- รองรับ **dual mode** ในทุก handler: normal form submit และ AJAX (`X-Requested-With`) คืน JSON response โดยไม่ต้องแยกไฟล์
- ออกแบบ **multi-role page** ให้ admin/lawyer/client เข้าหน้าเดียวกันแต่ได้ข้อมูลและ UI คนละชุด ตาม `$_SESSION['role']`

### Database Access (PDO)
- ใช้ **prepared statement + positional `?`** ทุกจุด ห้าม mix กับ named `:param` ในข้อความเดียว
- Transaction + `SELECT ... FOR UPDATE` ป้องกัน race condition ในการชำระเงิน
- `closeCursor()` หลัง `CALL stored_procedure()` เพื่อเคลียร์ result set ก่อน query ถัดไป
- Query JOIN ข้าม 5-6 ตารางพร้อม `COALESCE`, subquery, `GROUP BY`, `ORDER BY FIELD()`

### Authentication & Authorization
- `session_regenerate_id(true)` หลัง login สำเร็จ ป้องกัน session fixation
- Rate limiting ด้วย session counter: 10 ครั้ง/15 นาที
- Authorization check ทุก handler โดย JOIN verify `office_id` + `lawyer_id`/`client_id` — ไม่ใช่แค่ตรวจ session
- `requireLogin()` / `requireRole('admin')` ป้องกันการเข้าถึงผิด role

### File Upload
- ตรวจ MIME type จริงด้วย `finfo_file()` ไม่ใช่แค่ extension
- `validateUpload()` helper รองรับ MIME constant 5 ชุด: `MIME_IMAGES`, `MIME_PDF`, `MIME_PDF_IMGS`, `MIME_DOCS`, `MIME_DOCS_FULL`
- Path hardcode เป็น `/var/www/html/uploads/` เสมอ ห้ามใช้ `$_SERVER['DOCUMENT_ROOT']`

### Helpers & Utilities ที่เขียนเอง
- **`notification_helper.php`** — `notif_create()`, `notif_create_multi()`, `notif_fetch_recent()`, `notif_count_unread()`, `notif_mark_all_read()`, time_ago formatter
- **`activity_log_helper.php`** — `audit_log()` บันทึก old/new value ทุก action, `audit_fetch()` พร้อม filter หลายเงื่อนไข, pagination
- **`chat_helper.php`** — `chat_get_or_create()`, `chat_send()`, `chat_get_messages()`, `chat_count_unread()`, `chat_verify_access()`
- **`calendar_helper.php`** — `cal_get_events()`, `cal_events_by_date()`, `cal_month_grid()` สร้าง weekly grid สำหรับ calendar view
- **`search_helper.php`** — `search_build_where()` สร้าง LIKE condition จาก array ของ columns, `search_render_box()`, `search_result_badge()`
- **`csrf_helper.php`** — include ใน `header.php` ระดับ global เพื่อให้ทุกหน้าได้ CSRF อัตโนมัติ

---

## 2. MySQL / Database Design

### Schema Design (24 ตาราง)
- ออกแบบ relational chain หลัก: `case_requests → contracts → filings → court_hearings → verdicts`
- ตาราง support ใหม่: `verdict_appointments`, `postponement_requests`
- ระบบ communication: `notifications`, `activity_logs`, `chat_conversations`, `chat_messages`

### State Machine in DB
- `contract_review_status` ENUM ควบคุม flow: ห้าม finalize จาก `pending_lawyer_review` โดยตรง ต้องผ่าน `lawyer_accepted` หรือ `negotiating`
- `postponement_requests.status` — `pending → approved / rejected`
- `verdict_appointments.status` — `scheduled → completed / cancelled`

### Stored Procedure
- `global_search(p_office_id, p_user_id, p_role, p_keyword, p_max)` — UNION ALL ข้าม 7 entity ในครั้งเดียว กรองตาม role และ office ภายใน procedure, ใช้ `CONCAT('%', keyword, '%')` เพื่อป้องกัน injection
- ใช้ `SET NAMES utf8mb4` แก้ปัญหา double-encoding ภาษาไทย

### MySQL Views (5 views)
- `v_cases`, `v_filings`, `v_hearings`, `v_clients`, `v_lawyers` — pre-join ตารางหลักเพื่อลด complexity ของ query ในหน้าต่างๆ
- Drop + recreate pattern เพื่อให้ migration idempotent

### Migrations
- ทุก migration ใช้ pattern `IF NOT EXISTS` / `DROP ... IF EXISTS` เพื่อ re-run ได้ปลอดภัย
- ใช้ temporary stored procedure สร้างตารางแล้ว drop ทิ้ง เพื่อหลีกเลี่ยง `IF NOT EXISTS` บน ALTER TABLE

### Performance
- เพิ่ม Index บน `court_hearings` และ `filings` สำหรับ query ที่ใช้บ่อย
- ป้องกัน duplicate entries ด้วย SELECT ก่อน INSERT ทุกจุด

---

## 3. Security (18 จุด)

| ระดับ | ช่องโหว่ | วิธีแก้ |
|---|---|---|
| 🔴 Critical | Session Fixation | `session_regenerate_id(true)` หลัง login |
| 🔴 Critical | Missing Authorization (IDOR) | JOIN verify office_id ทุก handler |
| 🔴 Critical | IDOR payments | JOIN payment → contract → office |
| 🟠 High | Race Condition | Transaction + SELECT FOR UPDATE |
| 🟠 High | File Upload MIME bypass | finfo_file() + MIME constants |
| 🟠 High | SQL Injection (IN clause) | prepared + array_fill() placeholders |
| 🟡 Medium | CSRF | csrf_field() + csrf_verify() ทุก POST |
| 🟡 Medium | Rate Limiting | 10/15min session counter |
| 🟡 Medium | XSS | htmlspecialchars() ทุก output |
| 🟡 Medium | Duplicate filing | SELECT ก่อน INSERT |
| 🟡 Medium | Duplicate case request | status IN ('pending','approved') |
| 🟡 Medium | Overpayment | ตรวจ totalPaid >= fee ก่อน INSERT |
| 🟡 Medium | Finalize ข้ามขั้น | ตรวจ status IN ('lawyer_accepted','negotiating') |
| 🟡 Medium | Hearing ในคดีปิด | ตรวจ contract.status != 'completed' |
| 🟡 Medium | Password Complexity | ≥8 + upper + lower + digit |
| 🟡 Medium | CSRF error → die() | เปลี่ยนเป็น redirect แทน |
| 🟢 Low | Logout | session_destroy() + cookie expire |
| 🟢 Low | Security Headers + PHP in uploads | Nginx config |

---

## 4. JavaScript / Frontend

### AJAX & Async Patterns
- Modal form + `fetch()` + `FormData` ใน 6 หน้า (lawyers, clients, send_request, contract_documents, filings, hearings) โดยไม่ reload หน้า
- **Short polling** ใน `chat.php` ทุก 3 วินาที เพื่อดึงข้อความใหม่และ mark read อัตโนมัติ
- **Debounce 300ms** ใน Global Search Bar ป้องกัน API call ทุกครั้งที่พิมพ์

### Global Search Bar
- Shortcut **Ctrl+K** โฟกัสช่องค้นหาจากทุกหน้า
- พิมพ์ ≥ 2 ตัวอักษร → fetch `/api/global_search.php` → แสดง dropdown จัดกลุ่ม 7 หมวด: ลูกความ, ทนาย, สัญญา, นัดยื่นฟ้อง, นัดขึ้นศาล, ศาล, ประกาศ
- กรองผลลัพธ์ตาม role อัตโนมัติ — client ไม่เห็นข้อมูลทนายคนอื่น

### Interactive UI Components
- **Collapsible cards** — toggle ▼/▲, auto-open คดีที่มี ⚠️ รอตอบกลับ, auto-open คดีแรกถ้ามีเดียว
- **Realtime countdown** — `setInterval` 1 วินาที เปลี่ยนสีตามความเร่งด่วน: navy (> 3 วัน) → orange (≤ 1 วัน) → red (เลยกำหนด)
- **Custom court dropdown** — keyboard navigation (↑↓ Enter Esc), แสดง badge "พบในฐานข้อมูล" vs "ศาลใหม่ — จะเพิ่มอัตโนมัติ"
- **Dynamic info card** — แสดงรายละเอียด contract/filing เมื่อเลือก dropdown โดยไม่ reload
- **Notification bell** — dropdown แจ้งเตือนล่าสุด 10 รายการ + mark all read + badge count (99+)
- **Chat UI** — แยกซ้าย (conversation list + unread badge) / ขวา (message area), polling อัตโนมัติ

### Calendar View
- Monthly grid สร้างจาก PHP `cal_month_grid()` + render ด้วย CSS
- Navigate เดือนก่อน/หลัง via query string `?y=&m=` พร้อม clamp ปีอัตโนมัติ
- Events แสดง color-coded ตาม type พร้อม link ไปหน้าที่เกี่ยวข้อง

### SweetAlert2
- Success popup + timer 2 วิ + progress bar หลัง AJAX สำเร็จ
- Error popup พร้อม error message จาก server
- เรียก `closeModal()` ก่อน `Swal.fire()` เสมอ ป้องกัน z-index ทับกัน

### Best Practices
- `DOMContentLoaded` wrapper สำหรับ script ที่ bind event บน DOM ที่อาจยังไม่ render
- `window.open()` แทน `<iframe>` สำหรับ PDF เพื่อหลีกเลี่ยง port mismatch บน localhost:8080

---

## 5. Infrastructure (Docker + Nginx)

### Dockerfile
- ติดตั้ง `wkhtmltopdf` จาก `.deb` package เพื่อรองรับ Debian version ล่าสุด (Trixie/Bookworm)
- ติดตั้ง `fonts-thai-tlwg` (Sarabun font) สำหรับ PDF ภาษาไทยที่แสดงผลถูกต้อง

### Nginx (docker/nginx/default.conf)
- `root /var/www/html` (ห้ามใส่ /src — uploads อยู่นอก src/)
- `X-Frame-Options: SAMEORIGIN` อนุญาต iframe ภายใน domain เดียวกัน (PDF modal)
- Block PHP execution ใน `/uploads/` ด้วย `location ~*` block
- `server_tokens off`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`
- Reload config โดยไม่ restart container: `docker exec law_nginx nginx -s reload`

### uploads.ini
- `upload_max_filesize = 50MB`, `post_max_size = 55MB`
- `memory_limit = 256MB`, `max_execution_time = 300`

---

## 6. Debugging (จาก Screenshot + โค้ด)

วินิจฉัย root cause ได้จาก screenshot และโค้ดโดยไม่ต้องรันระบบ:

| อาการ | Root Cause | วิธีแก้ |
|---|---|---|
| "localhost refused to connect" | iframe ใช้ absolute path `/uploads/...` โดยไม่มี port 8080 | `window.open()` แทน iframe |
| ไม่มีสัญญาขึ้นในหน้าชำระเงิน | query filter แค่ `'finalized'` | เพิ่ม `IN ('lawyer_accepted','negotiating','revision_requested','finalized')` |
| SweetAlert ถูก modal บัง | modal ยังเปิดอยู่ขณะ Swal แสดง (z-index conflict) | เรียก `closeModal()` ก่อน `Swal.fire()` |
| `getElementById` คืน null | script อยู่ก่อน HTML element ใน `<?php if admin:?>` block | `DOMContentLoaded` wrapper |
| connection error ใน client_sign_docs | column `signed_file` ยังไม่มีใน DB | try/catch + fallback query |
| ภาษาไทยเป็น ??? ใน stored procedure | `character_set_client` ผิดตอนสร้าง procedure | `SET NAMES utf8mb4` ก่อน CREATE PROCEDURE |
| CSRF error แสดง plain text | `die()` แทน redirect | `header('Location:...')` + `exit` |
| stat สัญญาแสดง 0 ทั้งที่มีคดี | dashboard นับเฉพาะ `finalized` | เพิ่มทุก status active ใน IN clause |

---

## 7. Flow & Business Logic Analysis

- ตรวจ business logic flow ครบ **15 ขั้นตอน** (v4.6) เพิ่มจาก 13 เดิม
- ระบุ "จุดลัดขั้นตอน" ที่ทำให้เกิดปัญหาในภายหลัง:
  - ลูกความส่งคำขอซ้ำกับทนายที่มีคดี approved อยู่แล้ว
  - ทนาย finalize สัญญาจาก `pending_lawyer_review` ข้าม negotiation
  - ยื่นฟ้อง 2 ครั้งต่อสัญญาเดียวกัน
  - ชำระเงินเกินยอดเพราะไม่ check ก่อน INSERT
  - เพิ่มนัดในคดีที่ `status = 'completed'` แล้ว
- ตรวจ authorization ว่าทุก role เข้าถึงได้เฉพาะข้อมูลของตัวเอง (office isolation)
- วิเคราะห์ว่า `payments.php` ต้องรองรับ contract_review_status ไหนบ้าง เพื่อไม่ให้ลูกความชำระเงินไม่ได้ระหว่าง negotiation

---

## 8. API Design

- `GET /api/global_search.php?q=keyword` — stateless, auth-required, role-aware, คืน grouped JSON
- Response standard: `{ ok: bool, results: [], grouped: { entity_type: { label, icon, items[] } } }`
- AJAX endpoints ฝังใน page file ด้วย `?ajax=action` pattern ป้องกัน URL proliferation
- ทุก AJAX response คืน `{ ok: bool, msg: string }` เป็น standard เดียวกันทุกหน้า

---

## 9. Documentation

- **README.md** ครบ: Tech Stack, Flow 15 ขั้นตอน, State Machine, DB schema 24 ตาราง, MySQL Views, Security table, Nginx config, UI/UX features, Changelog ทุก version
- **SECURITY_PATCH_README.md** — บันทึกช่องโหว่ + วิธีแก้ + ไฟล์ที่ได้รับผลกระทบ แยกตามระดับ
- **Project Document (.docx)** — 11 บท พร้อม cover page, header/footer, page number, color-coded tables ด้วย Node.js + docx library
- **Changelog** — ละเอียดระดับ "แก้บรรทัดไหน เพราะอะไร ผลกระทบต่อไฟล์ไหน"
- **migrations/README.md** — คำสั่ง apply migration + rollback ชัดเจน

---

## สรุปตัวเลข

| ด้าน | จำนวน |
|---|---|
| หน้า PHP | 29 หน้า |
| ตาราง DB | 24 ตาราง |
| MySQL Views | 5 views |
| Stored Procedures | 1 (global_search — UNION ALL 7 entity) |
| Config Helpers | 8 ไฟล์ |
| API Endpoints | 1 (/api/global_search.php) |
| Modal Forms (AJAX+SweetAlert2) | 6 หน้า |
| Realtime Features | Chat polling (3s), Countdown (1s), Notification badge |
| ช่องโหว่ที่แก้ | 18 จุด |