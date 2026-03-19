# ⚖️ ระบบจัดการคดีความ (Law Case Management System)

**สำนักงานพันชรรม | v4.4 — March 2026**

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
# http://localhost:8081  (phpMyAdmin — DB: law_system)
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
│   ├── nginx/default.conf         ← Security headers + deny PHP in /uploads/
│   ├── php/Dockerfile + init-hash.sh
│   └── mysql/
│       ├── init.sql                ← Schema v4.1 ครบ 18 ตาราง (รวม migrations)
│       └── migrations/             ← archived
└── src/                            ← mount → /var/www/html ใน container
    ├── index.php
    ├── config/
    │   ├── db.php / auth.php / csrf_helper.php
    │   └── file_upload_helper.php  ← validateUpload() + MIME constants
    ├── includes/ header.php footer.php
    ├── pages/  (24 หน้า — ดูตารางด้านล่าง)
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
| หน้า | Actions |
|---|---|
| lawyers.php | เพิ่ม · แก้ไข · ระงับ/เปิดใช้ · 🗑️ ลบ (block ถ้ามีคดี active) |
| clients.php | เพิ่ม · แก้ไข · ระงับ/เปิดใช้ · 🗑️ ลบ (block ถ้ามีคดี active) |
| users.php | ดูทุก role · Reset Password · Toggle Status |
| courts.php | เพิ่ม · แก้ไข · ลบ · Merge ศาลซ้ำ |
| reports.php | KPI 7 ตัว · Charts · Top Lawyers · Overdue · Export CSV 3 ประเภท |
| settings.php | ข้อมูลสำนักงาน · Upload Logo · เปลี่ยน PW Admin · System Info |

### Admin — คดีความ (Override)
| หน้า | สิทธิ์พิเศษ Admin |
|---|---|
| case_requests.php | Force Expire · Cancel + Terminate cascade |
| contracts.php | Force Terminate + เหตุผล · แก้ค่าธรรมเนียม |
| filings.php | Edit (ศาล/เลขคดี/ข้อหา/วันที่) · Delete (block ถ้ามีนัด/verdict) |
| payments.php | Void confirmed payment + recalculate + revert contract |

### คดีความ (ทนาย)
| หน้า | สิทธิ์ |
|---|---|
| case_requests.php | รับ/ปฏิเสธคำขอ |
| contracts.php | ต่อรอง · finalize · reject |
| filings.php | เพิ่มการยื่นฟ้อง |
| hearings.php | เพิ่ม/แก้ไข/ลบนัด · auto-reschedule |
| Verdicts.php | บันทึก/แก้คำพิพากษา |

### บริการ (ลูกความ)
| หน้า | สิทธิ์ |
|---|---|
| send_request.php | ส่งคำขอว่าจ้าง (หมดอายุ 14 วัน) |
| my_cases.php | ดูคดี · ต่อรองตอบกลับ |
| contract_documents.php | อัปโหลดเอกสาร |
| payments.php | ส่งสลิปชำระ |

### เอกสาร & อื่นๆ
| หน้า | ใช้ได้กับ |
|---|---|
| client_sign_docs.php | ทนายส่ง → ลูกความเซ็นส่งกลับ |
| case_documents_ext.php | ทนายอัปโหลดเอกสารภายนอก |
| Case_summary.php | ทนาย Export PDF สำนวนคดี |
| dashboard.php | ทุก role (widget ต่างกัน) |
| Profile.php | ทุก role (read-only) |

---

## Flow 13 ขั้นตอน

```
1.  Admin → lawyers.php           สร้างบัญชีทนาย
2.  Client → register.php         สมัครสมาชิก (หรือ Admin → clients.php)
3.  Client → send_request.php     ส่งคำขอ (หมดอายุ 14 วัน)
4.  Lawyer → case_requests.php    รับ → ระบบสร้าง Contract อัตโนมัติ
5.  Client → contract_documents   อัปโหลดเอกสาร
6.  Lawyer/Client → contracts.php ต่อรองค่าธรรมเนียม (หลายรอบ)
7.  Lawyer → filings.php          ยื่นฟ้อง (1 contract = 1 filing)
8.  Lawyer → hearings.php         นัดขึ้นศาล + auto-reschedule
9.  Lawyer → Verdicts.php         บันทึกคำพิพากษา → status = completed
10. Client → payments.php         ชำระเงิน (Transaction + FOR UPDATE)
11. Lawyer → client_sign_docs     ส่งเอกสาร → Client ส่งกลับ
12. Lawyer → Case_summary.php     Export PDF สำนวนคดี
13. Client → dashboard.php        รีวิวทนาย 1-5 ดาว (หลังคดีปิดเท่านั้น)
```

---

## State Machine

```
case_requests.status:
  pending → approved (ระบบสร้าง contract) / rejected / expired
  Admin: force_expire · admin_cancel + cascade terminate contract

contracts.contract_review_status:
  pending_lawyer_review → lawyer_accepted → finalized
  → revision_requested ↔ negotiating → finalized
  → lawyer_rejected (contracts.status = terminated)
  Admin: force_terminate · edit_fee

contracts.status:         active → completed / terminated
contracts.payment_status: pending → partial → paid
payments.status:          pending → confirmed / rejected
                          Admin: void (confirmed→rejected + recalculate + revert)
client_sign_docs.status:  pending → acknowledged → signed / rejected
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
`contract_review_status` · `negotiation_status` (DEFAULT 'accepted') · `negotiated_at` · `fee_amount` · `proposed_fee` · `lawyer_note` · `client_response`

---

## Security

| ช่องโหว่ | ระดับ | วิธีแก้ |
|---|---|---|
| Session Fixation | 🔴 | session_regenerate_id(true) |
| Missing Authorization | 🔴 | AND lawyer_id=? / office_id check ทุก handler |
| IDOR payments | 🔴 | JOIN verify payment→contract→office |
| Race Condition | 🟠 | BEGIN TRANSACTION + SELECT FOR UPDATE |
| File Upload (9 จุด) | 🟠 | validateUpload() + finfo_file() |
| CSRF | 🟡 | csrf_field() + csrf_verify() ทุก POST |
| Rate Limiting | 🟡 | 10 ครั้ง/15 นาที (login) |
| XSS | 🟡 | htmlspecialchars() ทุก output |
| Password | 🟡 | ≥8+upper+lower+digit |
| citizen_id | 🟡 | mask 193•••••••61 |
| Logout | 🟢 | session_destroy() + setcookie expire |
| Headers + PHP in uploads | 🟢 | nginx config |

### MIME Constants
`MIME_IMAGES` · `MIME_PDF` · `MIME_PDF_IMGS` · `MIME_DOCS` · `MIME_DOCS_FULL`

---

## Changelog

### v4.4-hotfix (March 2026)
- **Bug fix:** `payments.php`, `contracts.php`, `case_requests.php`, `filings.php` — Modal ยังเปิดอยู่ขณะ Swal confirm แสดง ทำให้ z-index บัง Swal → แก้โดยเรียก `closeModal()` ก่อนแสดง Swal confirm เสมอ

### v4.4 (March 2026)
- **Bug fix:** Script อยู่ก่อน modal HTML (อยู่ใน `<?php if admin:?>`) → `getElementById` คืน null → ใช้ `DOMContentLoaded` wrapper
- **ใหม่:** `lawyers.php` — ปุ่มลบทนาย (block ถ้ามีคดี active)
- **ใหม่:** `clients.php` — ปุ่มลบลูกความ (block ถ้ามีคดี active)

### v4.3 (March 2026)
- **Bug fix:** Mixed PDO named+positional → Fatal Error (`contracts.php`, `case_requests.php`)
- **Bug fix:** Raw SQL interpolation + upload path ผิด (`settings.php`)
- **Bug fix:** contracts.php ปีกกาเกิน
- **ใหม่:** `reports.php` — KPI, Charts, Export CSV
- **ใหม่:** `settings.php` — Office info, Logo, Change PW

### v4.2 (March 2026)
- `case_requests.php` — Admin Force Expire + Cancel cascade
- `contracts.php` — Admin Force Terminate + Edit Fee
- `filings.php` — Edit + Delete (admin)
- `payments.php` — Admin Void confirmed

### v4.1 (March 2026)
- ใหม่: `courts.php` — CRUD + Merge ศาลซ้ำ
- ใหม่: `users.php` — Reset PW + Toggle Status
- `lawyers.php` / `clients.php` — Edit + Deactivate/Activate
- `init.sql` — เพิ่ม `negotiation_status` + `negotiated_at` (bug fix)

### v4.0 (March 2026)
- File Upload MIME validation ครบ 9 จุด
- รวม 6 migration files เข้า init.sql

---

## หมายเหตุสำคัญ

- `index.php` บรรทัดแรก = `<?php` เท่านั้น (ห้ามมี HTML comment)
- `nginx root = /var/www/html` (src/ mount ที่นี่)
- Upload path = `/var/www/html/uploads/` (hardcode เสมอ ห้ามใช้ `$_SERVER['DOCUMENT_ROOT']`)
- ทุก POST ต้องมี `csrf_field()` + `csrf_verify()`
- ทุก upload ใช้ `validateUpload()` จาก `file_upload_helper.php`
- ห้าม mix PDO named `:param` กับ positional `?` ใน query เดียวกัน
- Admin modal ทุกอัน: ต้องเรียก `closeModal()` **ก่อน** แสดง Swal confirm (z-index issue)
- JS ที่ bind event บน admin modal ต้องใช้ `DOMContentLoaded` wrapper เสมอ