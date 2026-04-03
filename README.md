# ⚖️ ระบบจัดการคดีความ (Law Case Management System)

**สำนักงานพันชรรม | v4.6 — April 2026**

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
# http://localhost:8181  (Web)
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
│       ├── init.sql                  ← Schema v4.0 ครบ 24 ตาราง + Stored Procedure (รวม migrations + SET NAMES utf8mb4)
│       ├── migration_global_search.sql   ← Stored Procedure: global_search (archived)
│       ├── migration_search_views.sql    ← Views: v_cases, v_filings, v_hearings, v_clients, v_lawyers
│       └── migrations/               ← archived
└── src/                              ← mount → /var/www/html ใน container
    ├── index.php
    ├── api/
    │   └── global_search.php         ← AJAX endpoint สำหรับ Global Search (GET ?q=keyword)
    ├── config/
    │   ├── db.php / auth.php / csrf_helper.php
    │   ├── file_upload_helper.php    ← validateUpload() + MIME constants
    │   ├── search_helper.php         ← search_build_where() + search_render_box() สำหรับ per-page search
    │   ├── notification_helper.php   ← สร้าง/ดึง notifications
    │   ├── activity_log_helper.php   ← บันทึก audit log ทุก action
    │   ├── calendar_helper.php       ← helper สำหรับ calendar.php
    │   └── chat_helper.php           ← helper สำหรับ chat.php
    ├── includes/ header.php footer.php
    ├── pages/  (29 หน้า — ดูตารางด้านล่าง)
    └── uploads/ contracts/ case_docs/ summaries/
                 sign_docs/ lawyer_photos/ client_photos/
                 payment_slips/ qr_codes/
```

---

## หน้าทั้งหมด (29 หน้า)

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

### ระบบสนับสนุน ⭐ ใหม่
| หน้า | ใช้ได้กับ |
|---|---|
| notifications.php | ทุก role — ดู/อ่านแจ้งเตือน, dropdown real-time, mark as read |
| activity_log.php | Admin เท่านั้น — ดู Audit Trail ทุก action, filter ตาม user/วันที่/ประเภท, pagination |
| chat.php | ทนาย/ลูกความ (Admin ถูก redirect) — แชทผูกกับคดี, ส่งข้อความ real-time via AJAX |

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

## Database (24 ตาราง)

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
| Notifications ⭐ | notifications |
| Audit ⭐ | activity_logs |
| Chat ⭐ | chat_conversations, chat_messages |

### MySQL Views (v4.6)
| View | ใช้โดย | รวมข้อมูลจาก |
|---|---|---|
| `v_cases` ⭐ | case_requests.php, contracts.php, my_cases.php, payments.php | case_requests + contracts + client/lawyer profiles |
| `v_filings` ⭐ | filings.php | filings + courts + contracts + คู่ความ |
| `v_hearings` ⭐ | hearings.php | court_hearings + filings + courts + คู่ความ |
| `v_clients` ⭐ | clients.php | client_profiles + users |
| `v_lawyers` ⭐ | lawyers.php | lawyer_profiles + users + case_count |

### Schema Changes (v4.5)
| ตาราง | การเปลี่ยนแปลง |
|---|---|
| `filings` | ลบ `case_number` + `uq_case_number` key, เปลี่ยน `filing_date` → `scheduled_filing_date` |
| `court_hearings` | เพิ่ม `case_number` (กรอกตอนนัดขึ้นศาลครั้งแรก) |
| `verdict_appointments` | ⭐ ตารางใหม่ — นัดวันฟังคำพิพากษา |
| `postponement_requests` | ⭐ ตารางใหม่ — คำขอเลื่อนวันนัดจากลูกความ |
| `notifications` | ⭐ ตารางใหม่ — แจ้งเตือนผู้ใช้ (`type`, `title`, `body`, `link`, `ref_type`, `ref_id`, `is_read`) CASCADE delete ตาม user/office |
| `activity_logs` | ⭐ ตารางใหม่ — Audit Trail (`action`, `entity_type`, `entity_id`, `old_value` JSON, `new_value` JSON, `ip_address`, `user_agent`) |
| `chat_conversations` | ⭐ ตารางใหม่ — ห้องสนทนาผูกกับ `request_id` (1 คดี = 1 ห้อง) |
| `chat_messages` | ⭐ ตารางใหม่ — ข้อความแชท (`sender_user_id`, `message`, `is_read`) |

**Columns พิเศษใน contracts:**
`contract_review_status` · `fee_amount` · `proposed_fee` · `lawyer_note` · `client_response`

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

### v4.6 (April 2026)

#### ✨ Features เพิ่มใหม่

| หน้า / ส่วน | รายละเอียด |
|---|---|
| `header.php` + `api/global_search.php` ⭐ | **Global Search Bar** — ค้นหาข้ามทุกตารางในครั้งเดียว, debounce 300ms, พิมพ์ ≥ 2 ตัวอักษร, แสดง dropdown จัดกลุ่มตาม entity, shortcut Ctrl+K, แสดงผลตาม role อัตโนมัติ |
| `config/search_helper.php` ⭐ | helper สำหรับ per-page search — `search_build_where()` สร้าง LIKE condition, `search_render_box()` render กล่องค้นหา, `search_result_badge()` แสดงจำนวนผลลัพธ์ |

#### 🗄️ Schema / Database Changes

| รายการ | รายละเอียด |
|---|---|
| `Stored Procedure: global_search` ⭐ | รับ `p_office_id`, `p_user_id`, `p_role`, `p_keyword`, `p_max` — UNION ALL ค้น 7 entity (client, lawyer, contract, filing, hearing, court, announcement) กรองตาม role และ office อัตโนมัติ |
| `View: v_cases` ⭐ | pre-join case_requests + contracts + client/lawyer profiles |
| `View: v_filings` ⭐ | pre-join filings + courts + contracts + คู่ความ |
| `View: v_hearings` ⭐ | pre-join court_hearings + filings + courts + คู่ความ |
| `View: v_clients` ⭐ | pre-join client_profiles + users |
| `View: v_lawyers` ⭐ | pre-join lawyer_profiles + users + นับจำนวนคดี |
| `init.sql` | รวม Stored Procedure `global_search` เข้าไปแล้ว ครบ 24 ตาราง + 5 views + 1 procedure |

#### 🐛 Bug Fixes

| ไฟล์ | ปัญหา | วิธีแก้ |
|---|---|---|
| `init.sql` + Stored Procedure | ค้นหาภาษาไทยใน Global Search ขึ้น "ไม่พบผลลัพธ์" ทั้งที่มีข้อมูลอยู่ เพราะ procedure ถูก compile ด้วย `character_set_client` ผิด | เพิ่ม `SET NAMES utf8mb4;` ก่อน `DROP PROCEDURE IF EXISTS global_search` ใน `init.sql` และ re-create procedure ใน container ที่รันอยู่ด้วย `docker exec` |

#### 📝 หมายเหตุการ re-create procedure บน container ที่รันอยู่

```bash
# copy ไฟล์เข้า container
docker cp docker/mysql/migrations/004_global_search_procedure.sql law_management_db:/tmp/004.sql

# รัน procedure ใน container (Windows ใช้คำสั่งนี้)
docker exec law_management_db mysql -u law_user -plaw_password law_system -e "source /tmp/004.sql"
```

> **หมายเหตุ:** บน Windows ห้ามใช้ `< /tmp/004.sql` (redirect) กับ `docker exec` เพราะ path จะถูกตีความเป็น Windows path — ใช้ `-e "source ..."` แทน

---

### v4.5.1 (March 2026)

#### ✨ Features เพิ่มใหม่

| หน้า / ส่วน | รายละเอียด |
|---|---|
| `filings.php` | เพิ่มส่วน "คำขอเลื่อนวันยื่นฟ้อง" — ทนาย/Admin ดูคำขอจากลูกความ, อนุมัติ/ปฏิเสธด้วย SweetAlert, บันทึก audit log + แจ้งเตือนลูกความ |
| `hearings.php` | เพิ่มส่วน "คำขอเลื่อนนัดขึ้นศาล" — โครงสร้างเดียวกับ filings.php |
| `my_cases.php` | แสดงวันนัดฟังคำพิพากษาพร้อม countdown ในกรอบสีเหลือง (เฉพาะเมื่อยังไม่มีผลคำพิพากษา) |
| `calendar.php` | หน้าใหม่ — ปฏิทินรายเดือนแสดงนัดยื่นฟ้อง/ขึ้นศาล/พิพากษา |

#### 🐛 Bug Fixes

| ไฟล์ | ปัญหา | วิธีแก้ |
|---|---|---|
| `my_cases.php` | ลูกความกดขอเลื่อนวันแล้วขึ้น error "ไม่พบสัญญา" แต่คำขอยังถูกสร้าง | `request_postpone` handler อยู่หลัง contract check ที่ fail เพราะไม่มี `contract_id` → ย้ายขึ้นมาก่อน contract check และ exit AJAX ก่อน |
| `my_cases.php`, `contracts.php` | อ้างอิง column `negotiation_status` / `negotiated_at` ที่ไม่มีอยู่จริง | ลบ references ออกทั้งหมด ใช้ `contract_review_status` แทน |
| `activity_log.php`, `calendar.php` | หน้าเปล่า — ไม่ได้ include `csrf_helper.php` | เพิ่ม `require_once csrf_helper.php` ใน `header.php` แก้ได้ทุกหน้าพร้อมกัน |
| `Profile.php` | หน้าเปล่า — สาเหตุเดียวกับข้อบน | แก้แล้วโดยการ include ใน header.php |
| `chat.php` | ค้างที่ "กำลังโหลด..." ไม่แสดงข้อความ | `lastCount` เริ่มต้นที่ 0 เท่ากับ message count จริง → เปลี่ยนเป็น -1 |
| `activity_log_helper.php` | LIMIT/OFFSET ส่งเป็น string ผ่าน `execute()` ทำให้ MySQL reject | ใช้ `bindValue($i, $val, PDO::PARAM_INT)` แทน |
| `csrf_helper.php` | CSRF error แสดง `die()` หน้าตายตัว ผู้ใช้ติดค้าง | เปลี่ยนเป็น redirect กลับหน้าเดิม + regenerate token, AJAX คืน JSON error |
| `Dockerfile` | `wkhtmltopdf` ไม่มีใน Debian Trixie apt repo | ดาวน์โหลด `.deb` จาก GitHub releases แทน + เพิ่ม Thai fonts |

#### 🔧 Infrastructure

| การเปลี่ยนแปลง | รายละเอียด |
|---|---|
| `docker-compose.yml` | ลบ deprecated `version` attribute, container names: `law_management_php/nginx/db/phpmyadmin` |
| `docker/nginx/default.conf` | เปลี่ยน `fastcgi_pass` ให้ตรงชื่อ container ใหม่ |
| `header.php` | เพิ่ม `require_once csrf_helper.php` ที่ต้นไฟล์ แก้ปัญหาหน้าเปล่าทุกหน้าพร้อมกัน |

---

### v4.5 (March 2026)

#### ✨ Features เพิ่มใหม่

| หน้า / ส่วน | รายละเอียด |
|---|---|
| `verdict_appointments.php` ⭐ | หน้าใหม่สำหรับ Admin/ทนาย — เพิ่ม/แก้ไขนัดวันฟังคำพิพากษา, countdown นับถอยหลัง, เปลี่ยนสถานะ (scheduled / completed / postponed / cancelled), บันทึก audit log ทุก action |
| `my_cases.php` ⭐ | อัปเกรดหน้าลูกความ — เพิ่ม countdown นับถอยหลังวันนัดยื่นฟ้อง + วันขึ้นศาล, ปุ่มขอเลื่อนวันนัด (ทั้ง filing และ hearing), ระบบตรวจ duplicate request (ป้องกันส่งซ้ำถ้ายัง pending อยู่) |
| `users.php` | เพิ่มปุ่ม 🗑️ ลบบัญชีผู้ใช้ — รองรับ lawyer/client เท่านั้น, block อัตโนมัติถ้ายังมีคดี active, ป้องกันลบบัญชีตัวเอง |
| `notifications.php` ⭐ | หน้าใหม่ทุก role — ดู/อ่านแจ้งเตือน, dropdown real-time ดึง 10 รายการล่าสุดผ่าน AJAX, mark as read, นับ unread badge |
| `activity_log.php` ⭐ | หน้าใหม่ Admin เท่านั้น — Audit Trail ทุก action ในระบบ, filter ตาม user/action/entity/วันที่, pagination 30 รายการ/หน้า |
| `chat.php` ⭐ | หน้าใหม่สำหรับทนาย/ลูกความ — แชทผูกกับคดี (1 คดี = 1 ห้องสนทนา), ส่ง-รับข้อความผ่าน AJAX, Admin ถูก redirect ออกอัตโนมัติ |

#### 🗄️ Schema Changes

| ตาราง | การเปลี่ยนแปลง |
|---|---|
| `verdict_appointments` | ⭐ ตารางใหม่ — เก็บนัดวันฟังคำพิพากษา (`filing_id`, `scheduled_date`, `scheduled_time`, `status`, `note`, `created_by`) |
| `postponement_requests` | ⭐ ตารางใหม่ — เก็บคำขอเลื่อนวันนัดจากลูกความ (`request_type`: filing/hearing, `reference_id`, `reason`, `requested_date`, `status`) |
| `notifications` | ⭐ ตารางใหม่ — แจ้งเตือนผู้ใช้ (`type`, `title`, `body`, `link`, `ref_type`, `ref_id`, `is_read`) CASCADE delete ตาม user/office |
| `activity_logs` | ⭐ ตารางใหม่ — Audit Trail (`action`, `entity_type`, `entity_id`, `old_value` JSON, `new_value` JSON, `ip_address`, `user_agent`) |
| `chat_conversations` | ⭐ ตารางใหม่ — ห้องสนทนาผูกกับ `request_id` (UNIQUE — 1 คดี = 1 ห้อง) CASCADE delete ตาม office/request/user |
| `chat_messages` | ⭐ ตารางใหม่ — ข้อความแชท (`conversation_id`, `sender_user_id`, `message`, `is_read`) |
| `filings` | ลบ column `case_number` + `uq_case_number` key ออก, เปลี่ยนชื่อ `filing_date` → `scheduled_filing_date` เพื่อให้ชัดเจนว่าเป็น "วันนัด" ไม่ใช่วันที่ยื่นจริง |
| `court_hearings` | เพิ่ม column `case_number` — กรอกตอนนัดขึ้นศาลครั้งแรก (หลังได้รับเลขคดีจากศาลแล้ว) |

#### 🐛 Bug Fixes

| ไฟล์ | ปัญหา | วิธีแก้ |
|---|---|---|
| `filings.php` | หน้ายังแสดงชื่อเก่าและมีช่อง "เลขคดี" ที่ถูกย้ายออกจาก schema แล้ว | เปลี่ยนชื่อหน้าเป็น "นัดยื่นฟ้อง" และลบช่อง `case_number` ออกจากฟอร์ม |
| `hearings.php` | ฟอร์มเพิ่มนัดขึ้นศาลไม่มีช่องกรอกเลขคดี ทั้งที่ `case_number` ย้ายมาอยู่ที่ตารางนี้แล้ว | เพิ่มช่อง `case_number` ในฟอร์มเพิ่มนัดขึ้นศาล |
| `register.php` | กรณี INSERT `users` สำเร็จแต่ INSERT `client_profiles` ล้มเหลว จะเกิด orphan record ใน users | ครอบทั้งสอง INSERT ด้วย Transaction — ถ้าขั้นตอนใดล้มเหลวจะ ROLLBACK ทั้งหมด |
| `lawyers.php`, `clients.php`, `users.php`, `courts.php`, `contracts.php`, `case_requests.php`, `filings.php` | `onclick` ที่ใช้ `json_encode` กับชื่อคน/ข้อความภาษาไทย — เมื่อชื่อมีเครื่องหมาย `"` HTML attribute แตกทำให้ปุ่มทำงานผิดพลาด | เปลี่ยน attribute wrapper จาก `onclick="..."` เป็น `onclick='...'` (single quote) ครบทุกจุด |

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
- **Global Search** ใช้ Stored Procedure `global_search` — ต้องมี `SET NAMES utf8mb4` ก่อน CREATE PROCEDURE เสมอ มิฉะนั้นภาษาไทยจะ match ไม่ได้
- **Per-page search** ใช้ MySQL Views (`v_cases`, `v_filings` ฯลฯ) ร่วมกับ `search_build_where()` จาก `search_helper.php` — ไม่เกี่ยวกับ Global Search dropdown
- `api/global_search.php` ต้อง `closeCursor()` หลัง `fetchAll()` เสมอเมื่อใช้ `CALL` procedure ผ่าน PDO