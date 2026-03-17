# ระบบจัดการคดีความ (Law Case Management System)

## Tech Stack
- **Backend:** PHP 8.2
- **Database:** MySQL 8.0
- **Web Server:** Nginx (Alpine)
- **Runtime:** Docker + Docker Compose
- **Frontend:** Vanilla JS, HTML/CSS + SweetAlert2
- **PDF Export:** wkhtmltopdf + qpdf (merge)
- **Font:** Sarabun (ภาษาไทย)

---

## โครงสร้างโปรเจค

```
law_management_system/
├── docker-compose.yml
├── docker/
│   ├── nginx/
│   │   └── default.conf
│   └── php/
│       └── Dockerfile
└── src/
    ├── index.php
    ├── assets/css/style.css
    ├── config/
    │   ├── db.php
    │   ├── auth.php
    │   ├── csrf_helper.php        ← CSRF protection
    │   └── file_upload_helper.php ← MIME type validation
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

## Docker Containers

| Container | Image | Port |
|---|---|---|
| `law_php` | php:8.2-fpm (custom) | 9000 |
| `law_nginx` | nginx:alpine | 8080 |
| `law_db` | mysql:8.0 | 3306 |
| `law_phpmyadmin` | phpmyadmin | 8081 |

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

## URL และข้อมูลเข้าถึง

- **Web App:** http://localhost:8080
- **phpMyAdmin:** http://localhost:8081
- **Database:** `law_system`

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `admin1234` |
| Lawyer 1 | `test` | (ตั้งตอนสร้าง) |
| Lawyer 2 | `aut` | (ตั้งตอนสร้าง) |
| Client | `KumaMai` | (ตั้งตอนสมัคร) |

---

## Flow การทำงาน

```
1.  Admin สร้างบัญชีทนาย (lawyers.php)
2.  ลูกความสมัคร (register.php) หรือ Admin สร้างให้ (clients.php)
3.  ลูกความส่งคำขอว่าจ้างทนาย (send_request.php) — หมดอายุ 14 วัน
4.  ทนายรับ/ปฏิเสธ (case_requests.php) → รับแล้วระบบสร้าง Contract อัตโนมัติ
5.  ลูกความอัปโหลดเอกสาร (contract_documents.php)
6.  ทนายยืนยัน/ตีกลับ/ต่อรองราคาสัญญา (contracts.php)
7.  ทนายยื่นฟ้อง (filings.php) → นัดขึ้นศาล (hearings.php)
8.  บันทึกคำพิพากษา (Verdicts.php) → คดีปิด
9.  ลูกความชำระเงิน (payments.php) — QR/โอน/เงินสด Transaction-safe
10. ทนายส่งเอกสารให้เซ็น (client_sign_docs.php) ← ลูกความส่ง PDF กลับ
11. Export PDF สำนวนคดี (Case_summary.php) — merge ได้ด้วย qpdf
12. ลูกความรีวิวทนาย (dashboard.php) — 1-5 ดาว
```

### สถานะสำคัญ

```
case_requests:    pending → approved / rejected / expired
contracts:        pending_lawyer_review → lawyer_accepted
                  → revision_requested ↔ negotiating → finalized
                  → lawyer_rejected / terminated
contracts.status: active → completed
payments:         pending → confirmed / rejected
client_sign_docs: pending → acknowledged → signed / rejected
```

---

## Security Patches ✅

### ช่องโหว่ที่แก้แล้ว

| ช่องโหว่ | ระดับ | ไฟล์ |
|---|---|---|
| Session Fixation | 🔴 Critical | login.php |
| Missing Authorization (ทนายอนุมัติคดีคนอื่น) | 🔴 Critical | case_requests.php |
| IDOR ใน payments | 🔴 Critical | payments.php |
| Race Condition ใน payments | 🟠 High | payments.php (Transaction) |
| File Upload ตรวจแค่ extension | 🟠 High | payments.php, client_sign_docs.php |
| CSRF ทุกฟอร์ม POST (19 ไฟล์) | 🟡 Medium | ทุกไฟล์ |
| Rate Limiting (login brute force) | 🟡 Medium | login.php |
| Verbose Error ใน clients.php | 🟡 Medium | clients.php ✅ |
| Verbose Error ใน register.php | 🟡 Medium | register.php ✅ |
| Sensitive Data — citizen_id แสดงเต็ม | 🟡 Medium | clients.php ✅ mask display |
| Logout ไม่ลบ Session Cookie | 🟢 Low | logout.php |
| Missing Security Headers | 🟢 Low | nginx/default.conf |
| PHP execute ใน uploads folder | 🟢 Low | nginx/default.conf |
| Missing Foreign Keys | 🟢 Low | ✅ รัน migrate_add_missing_fk.sql แล้ว |

### Bug Fixes

| Bug | ไฟล์ | รายละเอียด |
|---|---|---|
| `$_SESSION['user_email']` key ผิด | Case_summary.php | แก้เป็น `$_SESSION['email']` |
| `status='accepted'` ผิด | Profile.php | แก้เป็น `status='approved'` |
| ปุ่ม "ปฏิเสธสัญญา" condition ซ้ำซ้อน | contracts.php | ลบ if ซ้อนออก |

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

## UI/UX Updates ✅

### Pattern มาตรฐาน (ทุกฟอร์มเพิ่มข้อมูล)
- ปุ่ม ➕ อยู่มุมขวาบน → คลิกเปิด Modal popup
- Submit ผ่าน AJAX + SweetAlert2 ✅ / ❌ ไม่ reload หน้า
- PHP detect `HTTP_X_REQUESTED_WITH` → return JSON

### ไฟล์ที่ปรับแล้ว

| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| lawyers.php | Modal + AJAX + SweetAlert2, Username field + validate |
| clients.php | Modal + AJAX + SweetAlert2, Username field, citizen_id mask |
| send_request.php | ฟอร์มส่งคำขอ → Modal + AJAX |
| contract_documents.php | ฟอร์มส่งเอกสาร → Modal + AJAX + file upload |
| filings.php | ฟอร์มยื่นฟ้อง → Modal + AJAX + court custom dropdown + keyboard nav |
| hearings.php | ฟอร์มเพิ่มนัด → Modal + AJAX, real-time countdown |
| my_cases.php | Cards → Collapsible toggle, auto-open ⚠️ รอตอบกลับ |
| Verdicts.php | Cards → Collapsible toggle, header แสดงผลคำพิพากษา |

---

## TODO ที่ยังค้างอยู่

- [ ] Password complexity (ตอนนี้แค่ 6 ตัว) — รอหลัง go-live
- [ ] ตรวจ XSS ทุกจุดที่ output ข้อมูล user

---

## หมายเหตุสำคัญ

- `index.php` ต้องไม่มี `<!-- index.php -->` บรรทัดแรก (ทำให้ session error)
- `nginx/default.conf` ต้องใช้ `root /var/www/html;` (ไม่ใช่ `/var/www/html/src`)
- ทุกฟอร์ม POST ต้องมี `<?= csrf_field() ?>` และทุก handler ต้องมี `csrf_verify()`
- File upload ทุกที่ต้องใช้ `validateUpload()` จาก `file_upload_helper.php`
- Windows CMD ไม่รองรับ `&&` — ต้องรันคำสั่งแยกทีละบรรทัด
- `docker-compose restart nginx` ใช้ service name ไม่ใช่ container name (`law_nginx`)