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
| Admin | `admin` | `admin1234` | admin@admin.com |
| Lawyer 1 | `test` | (ตั้งตอนสร้าง) | test@gmail.com |
| Lawyer 2 | `aut` | (ตั้งตอนสร้าง) | AutAuttapon@gmail.com |
| Client | `KumaMai` | (ตั้งตอนสมัคร) | — |

---

## โครงสร้างไฟล์

```
law_management_system/
├── docker-compose.yml
├── docker/
│   ├── nginx/default.conf        ← security headers
│   └── php/Dockerfile
└── src/
    ├── index.php
    ├── assets/css/style.css
    ├── config/
    │   ├── db.php                 ← PDO connection
    │   ├── auth.php               ← requireLogin(), requireRole()
    │   ├── csrf_helper.php        ← CSRF protection
    │   └── file_upload_helper.php ← MIME validation
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
        ├── contracts/  case_docs/  case_summary_docs/  summaries/
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
    → รับ: ระบบสร้าง Contract อัตโนมัติ (status = active, contract_review_status = pending_lawyer_review)
5.  ลูกความอัปโหลดเอกสารสัญญา (contract_documents.php)
    → ส่งได้เฉพาะคดีที่ยังไม่ปิด (contracts.status NOT IN completed/terminated)
6.  ทนายพิจารณาสัญญา (contracts.php) — ต่อรองราคาได้หลายรอบ
    → ยืนยันรับ → revision_requested ↔ negotiating → finalize
    → ปฏิเสธ: contract terminated + case_request rejected
7.  ทนายยื่นฟ้อง (filings.php) — 1 contract = 1 filing
    → dropdown แสดงเฉพาะคดียังไม่ปิด
8.  นัดขึ้นศาล (hearings.php)
    → ขาดนัด: สร้างนัดใหม่ hearing_round+1 อัตโนมัติ
    → จำเลยหลบหนี: ปิดคดีทันที (contracts.status = completed)
    → dropdown แสดงเฉพาะคดียังไม่ปิด
9.  บันทึกคำพิพากษา (Verdicts.php)
    → contracts.status = completed ทันที (ไม่ขึ้นกับการจ่ายเงิน)
10. ลูกความชำระเงิน (payments.php) — QR/โอน/เงินสด Transaction+FOR UPDATE
    → จ่ายครบ + มี verdict: contracts.payment_status = paid
    → validation: ถ้าเลือก "จ่ายเต็มจำนวน" แต่ยอดไม่ตรง → แจ้งเตือน Swal
11. ทนายส่งเอกสารให้เซ็น (client_sign_docs.php) ← ลูกความส่ง PDF กลับ
12. Export PDF สำนวนคดี (Case_summary.php) — wkhtmltopdf + qpdf merge
13. ลูกความรีวิวทนาย (dashboard.php) — 1-5 ดาว (รีวิวได้หลังคดีปิดแล้วเท่านั้น)
```

### สถานะสำคัญในระบบ

```
case_requests.status:
  pending → approved / rejected / expired

contracts.contract_review_status:
  pending_lawyer_review → lawyer_accepted
  → revision_requested ↔ negotiating → finalized
  → lawyer_rejected

contracts.status:       active → completed / terminated
contracts.payment_status: pending → partial → paid

payments.status:        pending → confirmed / rejected

client_sign_docs.status: pending → acknowledged → signed / rejected
```

### ความหมายของ contracts.status = 'completed'
คดีถือว่าปิดเมื่อ **มีคำพิพากษา** เท่านั้น (Verdicts.php หรือ defendant_guilty_verdict ใน hearings.php)
การจ่ายเงินครบเพียงอย่างเดียวไม่ทำให้คดีปิด — สามารถจ่ายก่อนหรือหลัง verdict ได้

---

## Security Patches ✅

### ช่องโหว่ที่แก้แล้ว

| ช่องโหว่ | ระดับ | ไฟล์ |
|---|---|---|
| Session Fixation | 🔴 Critical | login.php |
| Missing Authorization (ทนายอนุมัติคดีคนอื่น) | 🔴 Critical | case_requests.php |
| IDOR ใน payments | 🔴 Critical | payments.php |
| Race Condition ใน payments | 🟠 High | payments.php (Transaction+FOR UPDATE) |
| File Upload ตรวจแค่ extension | 🟠 High | payments.php, client_sign_docs.php |
| CSRF ทุกฟอร์ม POST | 🟡 Medium | ทุกไฟล์ |
| Rate Limiting (login brute force 10ครั้ง/15นาที) | 🟡 Medium | login.php |
| XSS ทุกจุด output ข้อมูล user | 🟡 Medium | ทุกไฟล์ ✅ |
| addslashes() ใน onclick → json_encode() | 🟡 Medium | hearings.php, Verdicts.php, clients.php, lawyers.php |
| Verbose Error | 🟡 Medium | clients.php, register.php ✅ |
| Sensitive Data citizen_id แสดงเต็ม | 🟡 Medium | clients.php ✅ mask display |
| Logout ไม่ลบ Session Cookie | 🟢 Low | logout.php |
| Missing Security Headers | 🟢 Low | nginx/default.conf |
| PHP execute ใน uploads folder | 🟢 Low | nginx/default.conf |
| Missing Foreign Keys | 🟢 Low | migrate_add_missing_fk.sql ✅ |

### Password Complexity (v4.0 ใหม่)
- อย่างน้อย **8 ตัวอักษร**
- ต้องมีตัวพิมพ์ใหญ่ (A-Z), ตัวพิมพ์เล็ก (a-z), ตัวเลข (0-9)
- ใช้กับ: register.php, lawyers.php, clients.php

### Security Headers ใน Nginx
```nginx
root /var/www/html;
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
server_tokens off;
location ~* ^/uploads/.*\.php$ { deny all; return 403; }
```

---

## UI/UX Updates (v4.0)

### Pattern มาตรฐาน — Modal + AJAX + SweetAlert2
ทุกฟอร์มบันทึกข้อมูลในระบบใช้ pattern เดียวกัน:
- ปุ่ม ➕ มุมขวาบน → เปิด Modal popup
- Submit ผ่าน `fetch()` + `X-Requested-With: XMLHttpRequest`
- PHP ตรวจ `$isAjax` → return JSON `{ok: bool, msg: string}`
- SweetAlert2: ✅ success (timer 2s) / ❌ error
- Auto-reload หลัง success

### Collapsible Cards
- `contracts.php` — header กดซ่อน/แสดง body พร้อม arrow ▼/▲
- `my_cases.php` — collapsible auto-open คดีที่ ⚠️ รอตอบกลับ
- `Verdicts.php` — collapsible pending/verdicted cards

### Dashboard Widgets ใหม่ (v4.0)
| Role | Widget |
|---|---|
| Admin | ⏰ คำขอ pending ใกล้หมดอายุ 3 วัน |
| Admin | 🏛️ นัดขึ้นศาล 7 วันข้างหน้าของสำนักงาน |
| ทนาย | ⚡ Action items รอดำเนินการ (สัญญา/payment) |
| ทนาย | 🏛️ นัดขึ้นศาล 7 วันข้างหน้าของตัวเอง |
| ลูกความ | 🏛️ นัดขึ้นศาล 30 วันข้างหน้า |
| ลูกความ | 💳 ยอดค้างชำระแต่ละคดี + progress bar |

### Business Logic Fixes (v4.0)
| ไฟล์ | สิ่งที่แก้ |
|---|---|
| send_request.php | ส่งคำขอหาทนายคนเดิมได้ถ้าคดีเดิม completed/terminated แล้ว |
| contract_documents.php | ไม่แสดงคดีปิดแล้วใน dropdown + บล็อก server-side |
| filings.php | ไม่แสดงคดีปิดแล้วใน dropdown |
| hearings.php | ไม่แสดงคดีปิดแล้วใน dropdown |
| Verdicts.php | บันทึก verdict → contracts.status = completed ทันที (ไม่รอ payment) |
| hearings.php | defendant_guilty_verdict → contracts.status = completed ทันที |
| payments.php | จ่ายครบ + มี verdict → completed (รองรับจ่ายหลังคดีจบ) |
| payments.php | validate "จ่ายเต็มจำนวน" — auto-fill / warn ถ้ายอดไม่ตรง |
| my_cases.php | badge แสดง "คดีปิดแล้ว" ถ้า contract.status = completed |
| dashboard.php | รีวิวทนายได้เฉพาะหลังคดีปิด (has_completed check) |

---

## หมายเหตุสำคัญ

- `index.php` บรรทัดแรกต้องเป็น `<?php` เท่านั้น (ไม่มี HTML comment)
- `nginx/default.conf` ต้องใช้ `root /var/www/html;` (ไม่ใช่ `/var/www/html/src`)
- ทุกฟอร์ม POST ต้องมี `<?= csrf_field() ?>` และทุก handler ต้องมี `csrf_verify()`
- File upload ทุกที่ต้องใช้ `validateUpload()` จาก `file_upload_helper.php`
- Windows CMD ไม่รองรับ `&&` — รันคำสั่งแยกทีละบรรทัด
- `docker-compose restart nginx` ใช้ service name ไม่ใช่ container name