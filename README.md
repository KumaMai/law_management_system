# ⚖️ ระบบจัดการคดีความ (Law Case Management System)

**สำนักงานพันชรรม | v4.5 — March 2026**

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
│   ├── nginx/default.conf           ← Security headers + deny PHP in /uploads/
│   ├── php/Dockerfile + init-hash.sh
│   └── mysql/
│       ├── init.sql                  ← Schema v4.5 ครบ 20 ตาราง (รวม migrations)
│       └── migrations/               ← archived
└── src/                              ← mount → /var/www/html ใน container
    ├── index.php
    ├── config/
    │   ├── db.php / auth.php / csrf_helper.php
    │   └── file_upload_helper.php    ← validateUpload() + MIME constants
    ├── includes/ header.php footer.php
    ├── pages/  (26 หน้า — ดูตารางด้านล่าง)
    └── uploads/ contracts/ case_docs/ summaries/
                 sign_docs/ lawyer_photos/ client_photos/
                 payment_slips/ qr_codes/
```

---

## หน้าทั้งหมด (26 หน้า)

### Auth
| หน้า | คำอธิบาย |
|---|---|
| login.php | เข้าสู่ระบบ + rate limit 10/15min |
| logout.php | ลบ session + cookie |
| register.php | ลูกความสมัครเอง (Transaction ป้องกัน partial insert) |

### Admin — จัดการระบบ
| หน้า | Actions |
|---|---|
| lawyers.php | เพิ่ม · แก้ไข · ระงับ/เปิดใช้ · 🗑️ ลบ (block ถ้ามีคดี active) |
| clients.php | เพิ่ม · แก้ไข · ระงับ/เปิดใช้ · 🗑️ ลบ (block ถ้ามีคดี active) |
| users.php | ดูทุก role · Reset Password · Toggle Status · 🗑️ ลบ (lawyer/client เท่านั้น) |
| courts.php | เพิ่ม · แก้ไข · ลบ · Merge ศาลซ้ำ |
| reports.php | KPI 7 ตัว · Charts · Top Lawyers · Overdue · Export CSV 3 ประเภท |
| settings.php | ข้อมูลสำนักงาน · Upload Logo · เปลี่ยน PW Admin · System Info |

### Admin — คดีความ (Override)
| หน้า | สิทธิ์พิเศษ Admin |
|---|---|
| case_requests.php | Force Expire · Cancel + Terminate cascade |
| contracts.php | Force Terminate + เหตุผล · แก้ค่าธรรมเนียม |
| filings.php | Edit (ศาล/ข้อหา/วันนัด) · Delete (block ถ้ามีนัด/verdict) |
| payments.php | Void confirmed payment + recalculate + revert contract |

### คดีความ (ทนาย)
| หน้า | สิทธิ์ |
|---|---|
| case_requests.php | รับ/ปฏิเสธคำขอ |
| contracts.php | ต่อรอง · finalize · reject |
| filings.php | เพิ่มนัดยื่นฟ้อง (วันนัด + ศาล + ข้อหา) |
| hearings.php | เพิ่ม/แก้ไข/ลบนัด · กรอกเลขคดี · auto-reschedule |
| verdict_appointments.php | นัดวันฟังคำพิพากษา · เลื่อน/ยกเลิก |
| Verdicts.php | บันทึก/แก้คำพิพากษา |

### บริการ (ลูกความ)
| หน้า | สิทธิ์ |
|---|---|
| send_request.php | ส่งคำขอว่าจ้าง (หมดอายุ 14 วัน) |
| my_cases.php | ดูคดี · ต่อรองตอบกลับ · Countdown นัดยื่นฟ้อง/ขึ้นศาล · ขอเลื่อนวันนัด |
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

## Flow 15 ขั้นตอน

```
1.  Admin → lawyers.php              สร้างบัญชีทนาย
2.  Client → register.php            สมัครสมาชิก (หรือ Admin → clients.php)
3.  Client → send_request.php        ส่งคำขอ (หมดอายุ 14 วัน)
4.  Lawyer → case_requests.php       รับ → ระบบสร้าง Contract อัตโนมัติ
5.  Client → contract_documents      อัปโหลดเอกสาร
6.  Lawyer/Client → contracts.php    ต่อรองค่าธรรมเนียม (หลายรอบ)
7.  Lawyer → filings.php             นัดวันยื่นฟ้อง (ศาล + ข้อหา + วันนัด)
8.  Lawyer → hearings.php            นัดขึ้นศาล + กรอกเลขคดี + auto-reschedule
9.  Lawyer → verdict_appointments    นัดวันฟังคำพิพากษา ⭐ ใหม่
10. Lawyer → Verdicts.php            บันทึกคำพิพากษา → status = completed
11. Client → payments.php            ชำระเงิน (Transaction + FOR UPDATE)
12. Lawyer → client_sign_docs        ส่งเอกสาร → Client ส่งกลับ
13. Lawyer → Case_summary.php        Export PDF สำนวนคดี
14. Client → my_cases.php            ดู Countdown + ขอเลื่อนวันนัด ⭐ ใหม่
15. Client → dashboard.php           รีวิวทนาย 1-5 ดาว (หลังคดีปิดเท่านั้น)
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

contracts.status:              active → completed / terminated
contracts.payment_status:      pending → partial → paid
payments.status:               pending → confirmed / rejected
                               Admin: void (confirmed→rejected + recalculate + revert)
client_sign_docs.status:       pending → acknowledged → signed / rejected
verdict_appointments.status:   scheduled → completed / postponed / cancelled ⭐ ใหม่
postponement_requests.status:  pending → approved / rejected ⭐ ใหม่
```

---

## Database (20 ตาราง)

| กลุ่ม | ตาราง |
|---|---|
| Core | offices, roles, users |
| Profiles | lawyer_profiles, client_profiles |
| Case Flow | case_requests → contracts → filings → court_hearings → verdicts |
| Schedule | verdict_appointments ⭐, postponement_requests ⭐ |
| Finance | payments |
| Documents | case_documents, case_summary_docs, client_sign_docs |
| Office | announcements, lawyer_reviews, profile_change_requests |
| Reference | courts |

### Schema Changes (v4.5)
| ตาราง | การเปลี่ยนแปลง |
|---|---|
| `filings` | ลบ `case_number` + `uq_case_number` key, เปลี่ยน `filing_date` → `scheduled_filing_date` |
| `court_hearings` | เพิ่ม `case_number` (กรอกตอนนัดขึ้นศาลครั้งแรก) |
| `verdict_appointments` | ⭐ ตารางใหม่ — นัดวันฟังคำพิพากษา |
| `postponement_requests` | ⭐ ตารางใหม่ — คำขอเลื่อนวันนัดจากลูกความ |

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
| Partial Insert (register) | 🟠 | Transaction ครอบ INSERT users + client_profiles |
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

### v4.5 (March 2026)
- **ใหม่:** `verdict_appointments.php` — หน้านัดวันฟังคำพิพากษา (Admin/ทนาย) พร้อม countdown + เลื่อน/ยกเลิก
- **ใหม่:** `my_cases.php` — Countdown นับถอยหลังวันนัดยื่นฟ้อง + ขอเลื่อนวันนัด (ทั้งยื่นฟ้องและขึ้นศาล)
- **ใหม่:** `users.php` — ปุ่มลบผู้ใช้ (lawyer/client, block ถ้ามีคดี active)
- **Schema:** `filings` — ลบ `case_number`, เปลี่ยน `filing_date` → `scheduled_filing_date`
- **Schema:** `court_hearings` — เพิ่ม `case_number` (กรอกตอนนัดขึ้นศาล)
- **Schema:** เพิ่มตาราง `verdict_appointments` และ `postponement_requests`
- **Bug fix:** `filings.php` — เปลี่ยนชื่อหน้าเป็น "นัดยื่นฟ้อง" ลบช่องเลขคดีออก
- **Bug fix:** `hearings.php` — เพิ่มช่องกรอกเลขคดีในฟอร์มเพิ่มนัด
- **Bug fix:** `register.php` — ใช้ Transaction ป้องกัน INSERT users สำเร็จแต่ client_profiles ล้มเหลว
- **Bug fix:** ทุก onclick ที่ใช้ `json_encode` กับชื่อคน — เปลี่ยนจาก `"..."` เป็น `'...'` ป้องกัน HTML attribute แตก (`lawyers.php`, `clients.php`, `users.php`, `courts.php`, `contracts.php`, `case_requests.php`, `filings.php`)

### v4.4-hotfix (March 2026)
- **Bug fix:** `payments.php`, `contracts.php`, `case_requests.php`, `filings.php` — เรียก `closeModal()` ก่อน Swal confirm เสมอ (z-index issue)

### v4.4 (March 2026)
- **Bug fix:** `addEventListener` นอก `DOMContentLoaded` → `getElementById` คืน null เมื่อ modal HTML อยู่หลัง script — แก้ครบทุกไฟล์
- **Bug fix:** `filings.php` — `efCourtInput` declare นอก DOMContentLoaded → null ตอนกดแก้ไข
- **ใหม่:** `lawyers.php` — ปุ่มลบทนาย (block ถ้ามีคดี active)
- **ใหม่:** `clients.php` — ปุ่มลบลูกความ (block ถ้ามีคดี active)
- **Bug fix:** `lawyers.php` / `clients.php` — handler `delete_lawyer` / `delete_client` อยู่นอก POST block แรก → ย้ายเข้ามาใน block เดียวกัน

### v4.3 (March 2026)
- **Bug fix:** Mixed PDO named+positional → Fatal Error (`contracts.php`, `case_requests.php`)
- **Bug fix:** Raw SQL interpolation + upload path ผิด (`settings.php`)
- **Bug fix:** `contracts.php` ปีกกาเกิน
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
- ทุก `onclick` ที่มี `json_encode` ชื่อคน/ข้อความ — ต้องครอบ `onclick='...'` ด้วย single quote เท่านั้น
- Admin modal ทุกอัน: ต้องเรียก `closeModal()` **ก่อน** แสดง Swal confirm (z-index issue)
- JS ที่ bind event บน element ในส่วน modal HTML ต้องใช้ `DOMContentLoaded` wrapper เสมอ
- `register.php`: ต้องครอบ INSERT users + client_profiles ด้วย Transaction เสมอ
- `case_number` ตอนนี้อยู่ใน `court_hearings` (กรอกตอนนัดขึ้นศาล) ไม่ใช่ `filings` แล้ว