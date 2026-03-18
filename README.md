# ⚖️ ระบบจัดการคดีความ (Law Case Management System)

**สำนักงานพันชรรม | Project Document v4.0 — March 2026**

---

## Tech Stack

| Component | Detail |
|---|---|
| Backend | PHP 8.2 (FPM) |
| Database | MySQL 8.0 |
| Web Server | Nginx Alpine |
| Runtime | Docker + Docker Compose |
| Frontend | Vanilla JS, HTML/CSS + SweetAlert2 |
| PDF Export | wkhtmltopdf + qpdf (merge) + Sarabun font |

---

## Docker Containers

| Container | Image | Port |
|---|---|---|
| `law_php` | php:8.2-fpm (custom) | 9000 |
| `law_nginx` | nginx:alpine | 8080→80 |
| `law_db` | mysql:8.0 | 3306→3306 |
| `law_phpmyadmin` | phpmyadmin | 8081→80 |

### คำสั่ง Docker ที่ใช้บ่อย
```bash
docker-compose up -d
docker-compose down
docker-compose ps
docker exec law_nginx nginx -s reload
docker logs law_nginx
docker exec -it law_php bash
```

---

## URL & ข้อมูลเข้าถึง

- **Web App:** http://localhost:8080
- **phpMyAdmin:** http://localhost:8081 | Database: `law_system`

| Role | Username | Password | Email |
|---|---|---|---|
| Admin | `admin` | `admin1234` | admin@lawfirm.com |
| Lawyer 1 | `test` | (ตั้งตอนสร้าง) | test@gmail.com |
| Lawyer 2 | `aut` | (ตั้งตอนสร้าง) | AutAuttapon@gmail.com |
| Client | `KumaMai` | (ตั้งตอนสมัคร) | — |

---

## โครงสร้างไฟล์

```
law_management_system/
├── docker-compose.yml
├── docker/
│   ├── nginx/default.conf            ← security headers
│   ├── php/Dockerfile
│   └── mysql/
│       ├── init.sql                   ← Schema ครบสมบูรณ์ (รวม migrations แล้ว)
│       └── migrations/                ← archived — ไม่ต้องรันสำหรับ setup ใหม่
└── src/
    ├── index.php
    ├── assets/css/style.css
    ├── config/
    │   ├── db.php                     ← PDO connection
    │   ├── auth.php                   ← requireLogin(), requireRole()
    │   ├── csrf_helper.php            ← CSRF token generation & verification
    │   └── file_upload_helper.php     ← MIME validation (validateUpload())
    ├── includes/
    │   ├── header.php
    │   └── footer.php
    ├── pages/
    │   ├── login.php / logout.php / register.php
    │   ├── dashboard.php / Profile.php
    │   ├── lawyers.php / clients.php
    │   ├── send_request.php / case_requests.php
    │   ├── contracts.php / contract_documents.php
    │   ├── filings.php / hearings.php / Verdicts.php
    │   ├── payments.php / client_sign_docs.php
    │   ├── case_documents_ext.php / Case_summary.php
    │   └── my_cases.php
    └── uploads/
        ├── contracts/  case_docs/  summaries/
        ├── sign_docs/  sign_docs/signed/
        ├── lawyer_photos/  client_photos/
        └── payment_slips/  qr_codes/
```

---

## Flow การทำงานหลัก (13 ขั้นตอน)

```
1.  Admin สร้างบัญชีทนาย (lawyers.php)
2.  ลูกความสมัครเอง (register.php) หรือ Admin สร้างให้ (clients.php)
3.  ลูกความส่งคำขอว่าจ้างทนาย (send_request.php) — หมดอายุ 14 วัน
    → ส่งซ้ำได้เฉพาะทนายคนอื่น หรือถ้าคดีเดิม completed/terminated แล้ว
4.  ทนายรับ/ปฏิเสธคำขอ (case_requests.php)
    → รับ: ระบบสร้าง Contract อัตโนมัติ
5.  ลูกความอัปโหลดเอกสารสัญญา (contract_documents.php)
    → ส่งได้เฉพาะคดีที่ยังไม่ปิด
6.  ทนายพิจารณาสัญญา (contracts.php) — ต่อรองราคาได้หลายรอบ
    → ยืนยันรับ / revision_requested ↔ negotiating → finalize / ปฏิเสธ
7.  ทนายยื่นฟ้อง (filings.php) — 1 contract = 1 filing
8.  นัดขึ้นศาล (hearings.php)
    → ขาดนัด: สร้างนัดใหม่ hearing_round+1 อัตโนมัติ
    → จำเลยหลบหนี: ปิดคดีทันที
9.  บันทึกคำพิพากษา (Verdicts.php) → contracts.status = completed ทันที
10. ลูกความชำระเงิน (payments.php) — QR/โอน/เงินสด
    → Transaction + FOR UPDATE ป้องกัน race condition
11. ทนายส่งเอกสารให้เซ็น (client_sign_docs.php) ← ลูกความส่ง PDF กลับ
12. Export PDF สำนวนคดี (Case_summary.php) — wkhtmltopdf + qpdf merge
13. ลูกความรีวิวทนาย (dashboard.php) — 1-5 ดาว (หลังคดีปิดเท่านั้น)
```

### สถานะสำคัญในระบบ

```
case_requests.status:
  pending → approved / rejected / expired

contracts.contract_review_status:
  pending_lawyer_review → lawyer_accepted
  → revision_requested ↔ negotiating → finalized
  → lawyer_rejected

contracts.status:         active → completed / terminated
contracts.payment_status: pending → partial → paid
payments.status:          pending → confirmed / rejected
client_sign_docs.status:  pending → acknowledged → signed / rejected
```

### ความหมายของ contracts.status = 'completed'
คดีถือว่าปิดเมื่อ **มีคำพิพากษา** เท่านั้น (Verdicts.php หรือ defendant_guilty_verdict ใน hearings.php)
การจ่ายเงินครบเพียงอย่างเดียวไม่ทำให้คดีปิด — สามารถจ่ายก่อนหรือหลัง verdict ได้

---

## Database Schema (18 ตาราง)

| กลุ่ม | ตาราง |
|---|---|
| Core | `offices`, `roles`, `users` |
| Profiles | `lawyer_profiles`, `client_profiles` |
| Case Flow | `case_requests` → `contracts` → `filings` → `court_hearings` → `verdicts` |
| Finance | `payments` |
| Documents | `case_documents`, `case_summary_docs`, `client_sign_docs` |
| Office | `announcements`, `lawyer_reviews`, `profile_change_requests` |
| Reference | `courts` |

> **Setup ใหม่:** ใช้แค่ `docker/mysql/init.sql` ไฟล์เดียว — รวม schema ครบทั้งหมดแล้ว

---

## Security ✅

### ช่องโหว่ที่แก้แล้ว (v4.0)

| ช่องโหว่ | ระดับ | ไฟล์ |
|---|---|---|
| Session Fixation | 🔴 Critical | login.php |
| Missing Authorization (ทนายอนุมัติคดีคนอื่น) | 🔴 Critical | case_requests.php |
| IDOR ใน payments | 🔴 Critical | payments.php |
| Race Condition ใน payments | 🟠 High | payments.php (Transaction+FOR UPDATE) |
| File Upload ตรวจแค่ extension (8 จุด) | 🟠 High | dashboard.php, payments.php, contract_documents.php, case_documents_ext.php, client_sign_docs.php, Case_summary.php |
| CSRF ทุกฟอร์ม POST | 🟡 Medium | ทุกไฟล์ |
| Rate Limiting (10 ครั้ง/15 นาที) | 🟡 Medium | login.php |
| XSS ทุกจุด output | 🟡 Medium | ทุกไฟล์ (htmlspecialchars + json_encode) |
| Password Complexity < 8 ตัว | 🟡 Medium | register.php, lawyers.php, clients.php |
| citizen_id แสดงเต็ม | 🟡 Medium | clients.php (mask: 193•••••••61) |
| Logout ไม่ลบ Session | 🟢 Low | logout.php |
| Missing Security Headers | 🟢 Low | nginx/default.conf |
| PHP execute ใน uploads | 🟢 Low | nginx/default.conf |
| Missing Foreign Keys | 🟢 Low | init.sql (ครบทุกตาราง) |

### File Upload Validation (validateUpload)
ทุกจุดที่รับไฟล์ใช้ `validateUpload()` จาก `src/config/file_upload_helper.php` แบบสม่ำเสมอ
ตรวจ MIME type จากเนื้อหาไฟล์จริง (`finfo_file`) ไม่ใช่จากนามสกุลไฟล์

| Constant | MIME types | ใช้ใน |
|---|---|---|
| `MIME_IMAGES` | jpg, png | รูปโปรไฟล์, QR code |
| `MIME_PDF` | pdf | เอกสารเซ็น |
| `MIME_PDF_IMGS` | pdf, jpg, png | สลิปชำระเงิน |
| `MIME_DOCS` | pdf, jpg, png, doc, docx | เอกสารสัญญา |
| `MIME_DOCS_FULL` | pdf, jpg, png, doc, docx, xls, xlsx | เอกสารคดี |

### Password Complexity
- อย่างน้อย **8 ตัวอักษร** · ตัวพิมพ์ใหญ่ · ตัวพิมพ์เล็ก · ตัวเลข
- ใช้กับ: register.php, lawyers.php, clients.php

### Security Headers (Nginx)
```nginx
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
server_tokens off;
location ~* ^/uploads/.*\.php$ { deny all; return 403; }
```

---

## UI/UX (v4.0)

### Pattern มาตรฐาน — Modal + AJAX + SweetAlert2
ทุกฟอร์มบันทึกข้อมูล:
- ปุ่ม ➕ มุมขวาบน → เปิด Modal popup
- Submit ผ่าน `fetch()` + `X-Requested-With: XMLHttpRequest`
- PHP: `$isAjax` → return JSON `{ok: bool, msg: string}`
- SweetAlert2: ✅ success (timer 2s) / ❌ error + auto-reload

### Dashboard Widgets (v4.0)
| Role | Widget |
|---|---|
| Admin | ⏰ คำขอ pending ใกล้หมดอายุ 3 วัน |
| Admin | 🏛️ นัดขึ้นศาล 7 วันข้างหน้าของสำนักงาน |
| ทนาย | ⚡ Action items รอดำเนินการ |
| ทนาย | 🏛️ นัดขึ้นศาล 7 วันข้างหน้าของตัวเอง |
| ลูกความ | 🏛️ นัดขึ้นศาล 30 วันข้างหน้า |
| ลูกความ | 💳 ยอดค้างชำระแต่ละคดี + progress bar |

---

## หมายเหตุสำคัญ

- `index.php` บรรทัดแรกต้องเป็น `<?php` เท่านั้น (ไม่มี HTML comment)
- `nginx/default.conf` ต้องใช้ `root /var/www/html;` (ไม่ใช่ `/var/www/html/src`)
- ทุกฟอร์ม POST ต้องมี `<?= csrf_field() ?>` และทุก handler ต้องมี `csrf_verify()`
- File upload ทุกจุดใช้ `validateUpload()` จาก `file_upload_helper.php`
- Windows CMD ไม่รองรับ `&&` — รันคำสั่งแยกทีละบรรทัด
- `docker-compose restart nginx` ใช้ service name ไม่ใช่ container name (`law_nginx`)
