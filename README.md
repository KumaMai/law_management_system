# ⚖️ ระบบจัดการคดีความ (Law Case Management System)

**สำนักงานพันชรรม | v4.3 — March 2026**

---

## Tech Stack

| Component | Detail |
|---|---|
| Backend | PHP 8.2 (FPM) |
| Database | MySQL 8.0 |
| Web Server | Nginx Alpine |
| Runtime | Docker + Docker Compose |
| Frontend | Vanilla JS + SweetAlert2 + Chart.js 4 |
| PDF Export | wkhtmltopdf + qpdf + Sarabun font |

---

## Quick Start

```bash
git clone <repo> && cd law_management_system
docker-compose up -d
# http://localhost:8080  (Web)
# http://localhost:8081  (phpMyAdmin)
```

| Role | Username | Password |
|---|---|---|
| Admin | admin | admin1234 |
| Lawyer | (สร้างโดย Admin) | (ตั้งตอนสร้าง) |
| Client | (สมัครเอง) | (ตั้งตอนสมัคร) |

---

## โครงสร้างไฟล์

```
law_management_system/
├── docker-compose.yml
├── docker/
│   ├── nginx/default.conf         ← Security headers + deny PHP in uploads
│   ├── php/Dockerfile + init-hash.sh
│   └── mysql/
│       ├── init.sql                ← Schema v4.1 ครบ 18 ตาราง
│       └── migrations/             ← archived
└── src/                            ← mount เป็น /var/www/html ใน container
    ├── index.php
    ├── config/
    │   ├── db.php / auth.php / csrf_helper.php
    │   └── file_upload_helper.php  ← validateUpload() + MIME constants
    ├── includes/ header.php footer.php
    ├── pages/  (ดูตารางด้านล่าง)
    └── uploads/ contracts/ case_docs/ summaries/
                 sign_docs/ lawyer_photos/ client_photos/
                 payment_slips/ qr_codes/
```

---

## หน้าทั้งหมด (24 หน้า)

### Auth
| หน้า | คำอธิบาย |
|---|---|
| login.php | เข้าสู่ระบบ + rate limit 10/15min |
| logout.php | ลบ session + cookie |
| register.php | ลูกความสมัครเอง |

### Admin — จัดการระบบ
| หน้า | v | สิทธิ์ |
|---|---|---|
| lawyers.php | 4.1 | เพิ่ม, แก้ไข, ระงับ/เปิดใช้, แสดงใบอนุญาตหมดอายุ |
| clients.php | 4.1 | เพิ่ม, แก้ไข, ระงับ/เปิดใช้ |
| users.php | 4.1 | ดูทุก role, Reset Password, Toggle Status |
| courts.php | 4.1 | CRUD + Merge ศาลซ้ำ |
| reports.php | 4.3 | KPI 7 ตัว, Charts, Top Lawyers, Overdue, Export CSV 3 ประเภท |
| settings.php | 4.3 | ข้อมูลสำนักงาน, Upload โลโก้, เปลี่ยน PW Admin, System Info |

### คดีความ
| หน้า | Admin Override | ทนาย | ลูกความ |
|---|---|---|---|
| case_requests.php | Force Expire, Cancel+Terminate cascade | รับ/ปฏิเสธ | ดู |
| contracts.php | Force Terminate, แก้ค่าธรรมเนียม | ต่อรอง, finalize | ต่อรองตอบกลับ |
| filings.php | Edit+Delete (block ถ้ามีนัด/verdict) | เพิ่ม | — |
| hearings.php | เหมือนทนาย | เพิ่ม/แก้ไข/ลบ, auto-reschedule | — |
| Verdicts.php | เหมือนทนาย | บันทึก/แก้ไข | — |
| send_request.php | — | — | ส่งคำขอว่าจ้าง |
| my_cases.php | — | — | ดูคดีของตัวเอง, ต่อรองตอบกลับ |

### เอกสาร & การเงิน
| หน้า | Admin Override | ทนาย | ลูกความ |
|---|---|---|---|
| payments.php | Void confirmed payment | ยืนยัน/ปฏิเสธ, เงินสด | ส่งสลิป |
| contract_documents.php | — | — | อัปโหลดเอกสาร |
| client_sign_docs.php | ดูทั้งหมด | ส่งเอกสาร, รับกลับ | รับ/ส่งกลับ |
| case_documents_ext.php | ดูทั้งหมด | อัปโหลด | — |
| Case_summary.php | ดูทั้งหมด | Export PDF | — |

### อื่นๆ
| หน้า | ใช้ได้กับ |
|---|---|
| dashboard.php | ทุก role (widget ต่างกัน) |
| Profile.php | ทุก role (read-only, แก้ผ่าน dashboard) |

---

## Flow 13 ขั้นตอน

```
1.  Admin → lawyers.php          สร้างบัญชีทนาย
2.  Client → register.php        สมัครสมาชิก (หรือ Admin → clients.php)
3.  Client → send_request.php    ส่งคำขอ (หมดอายุ 14 วัน)
4.  Lawyer → case_requests.php   รับ → ระบบสร้าง Contract อัตโนมัติ
5.  Client → contract_documents  อัปโหลดเอกสาร
6.  Lawyer/Client → contracts.php ต่อรองค่าธรรมเนียม (หลายรอบ)
7.  Lawyer → filings.php         ยื่นฟ้อง (1 contract = 1 filing)
8.  Lawyer → hearings.php        นัดขึ้นศาล + auto-reschedule
9.  Lawyer → Verdicts.php        บันทึกคำพิพากษา → status = completed
10. Client → payments.php        ชำระเงิน (Transaction + FOR UPDATE)
11. Lawyer → client_sign_docs    ส่งเอกสาร → Client ส่งกลับ
12. Lawyer → Case_summary.php    Export PDF สำนวนคดี
13. Client → dashboard.php       รีวิวทนาย 1-5 ดาว (หลังคดีปิดเท่านั้น)
```

---

## State Machine

```
case_requests.status:
  pending → approved (ระบบสร้าง contract) / rejected / expired
  Admin: force_expire, admin_cancel + cascade terminate

contracts.contract_review_status:
  pending_lawyer_review
  → lawyer_accepted → finalized
  → revision_requested ↔ negotiating → finalized
  → lawyer_rejected (→ status=terminated)
  Admin: force_terminate, edit_fee

contracts.status:      active → completed / terminated
contracts.payment_status: pending → partial → paid
payments.status:       pending → confirmed / rejected
                       Admin: void (confirmed→rejected + recalculate)
client_sign_docs.status: pending → acknowledged → signed / rejected
```

---

## Database (18 ตาราง)

| กลุ่ม | ตาราง |
|---|---|
| Core | offices, roles, users |
| Profiles | lawyer_profiles, client_profiles |
| Case Flow | case_requests → contracts → filings → court_hearings → verdicts |
| Finance | payments |
| Documents | case_documents, case_summary_docs, client_sign_docs |
| Office | announcements, lawyer_reviews, profile_change_requests |
| Reference | courts |

**Columns พิเศษใน contracts:**
`contract_review_status`, `negotiation_status` (DEFAULT 'accepted'), `negotiated_at`, `fee_amount`, `proposed_fee`, `lawyer_note`, `client_response`

---

## Security

| ช่องโหว่ | ระดับ | วิธีแก้ |
|---|---|---|
| Session Fixation | 🔴 | session_regenerate_id(true) |
| Missing Authorization | 🔴 | AND lawyer_id=? / office_id check ทุก handler |
| IDOR payments | 🔴 | JOIN verify payment→contract→office |
| Race Condition | 🟠 | BEGIN TRANSACTION + SELECT FOR UPDATE |
| File Upload | 🟠 | validateUpload() + finfo_file() 9 จุด |
| CSRF | 🟡 | csrf_field() + csrf_verify() ทุก POST |
| Rate Limiting | 🟡 | 10 ครั้ง/15 นาที (login) |
| XSS | 🟡 | htmlspecialchars() ทุก output |
| Password | 🟡 | ≥8+upper+lower+digit |
| citizen_id | 🟡 | mask 193•••••••61 |
| Logout | 🟢 | session_destroy() + setcookie expire |
| Headers | 🟢 | X-Frame-Options, X-Content-Type-Options (nginx) |
| PHP in uploads | 🟢 | nginx deny .php in /uploads/ |

### MIME Constants (file_upload_helper.php)
`MIME_IMAGES` · `MIME_PDF` · `MIME_PDF_IMGS` · `MIME_DOCS` · `MIME_DOCS_FULL`

---

## Changelog

### v4.3 (March 2026) — Bug Fixed
- **Bug fix:** `contracts.php`, `case_requests.php` — Mixed PDO named/positional parameters → Fatal Error
- **Bug fix:** `settings.php` — Raw SQL interpolation + DOCUMENT_ROOT path ผิด
- **ใหม่:** `reports.php` — KPI, Chart.js Bar+Line+Doughnut, Export CSV
- **ใหม่:** `settings.php` — Office info, Logo upload, Change PW, System Info

### v4.2 (March 2026)
- `case_requests.php` — Admin Force Expire + Cancel cascade
- `contracts.php` — Admin Force Terminate + Edit Fee
- `filings.php` — Edit + Delete (block ถ้ามีนัด/verdict)
- `payments.php` — Admin Void confirmed + revert contract

### v4.1 (March 2026)
- ใหม่: `courts.php` — CRUD + Merge ศาลซ้ำ
- ใหม่: `users.php` — Reset PW + Toggle Status
- `lawyers.php` / `clients.php` — Edit + Deactivate/Activate
- `init.sql` — เพิ่ม negotiation_status + negotiated_at (bug fix)

### v4.0 (March 2026)
- File Upload MIME validation ครบ 9 จุด
- รวม 6 migration files เข้า init.sql

---

## หมายเหตุสำคัญ

- `index.php` บรรทัดแรก = `<?php` เท่านั้น (ห้ามมี HTML)
- `nginx root = /var/www/html` (src/ mount ตรงนี้)
- Upload path = `/var/www/html/uploads/` (hardcode, ไม่ใช้ DOCUMENT_ROOT)
- ทุก POST ต้องมี `csrf_field()` + `csrf_verify()`
- ทุก upload ต้องใช้ `validateUpload()` จาก file_upload_helper.php