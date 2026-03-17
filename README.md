# ระบบจัดการคดีความ (Law Case Management System)

## Tech Stack
- **Backend:** PHP 8.2
- **Database:** MySQL 8.0
- **Web Server:** Nginx (Alpine)
- **Runtime:** Docker + Docker Compose
- **Frontend:** Vanilla JS, HTML/CSS + SweetAlert2
- **PDF Export:** wkhtmltopdf
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
        ├── case_docs/ / case_summary_docs/
        ├── client_sign_docs/ / signed/
        ├── lawyer_photos/ / client_photos/
        ├── payment_slips/ / qr_codes/
        └── contracts/
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
3.  ลูกความส่งคำขอว่าจ้างทนาย (send_request.php)
4.  ทนายรับ/ปฏิเสธ (case_requests.php) → รับแล้วระบบสร้าง Contract อัตโนมัติ
5.  ลูกความอัปโหลดเอกสาร (contract_documents.php)
6.  ทนายยืนยัน/ตีกลับสัญญา ต่อรองราคาได้ (contracts.php)
7.  ทนายยื่นฟ้อง (filings.php) → นัดขึ้นศาล (hearings.php)
8.  บันทึกคำพิพากษา (Verdicts.php)
9.  ลูกความชำระเงิน (payments.php)
10. ทนายส่งเอกสารให้เซ็น (client_sign_docs.php)
11. Export PDF สำนวนคดี (Case_summary.php)
12. ลูกความรีวิวทนาย (dashboard.php)
```

---

## Security Patches ที่แก้ไปแล้ว ✅

### ไฟล์ใหม่ที่ต้องมีใน src/config/
- `csrf_helper.php` — `csrf_token()`, `csrf_field()`, `csrf_verify()`
- `file_upload_helper.php` — `validateUpload()`, MIME constants

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
| Logout ไม่ลบ Session Cookie | 🟢 Low | logout.php |
| Missing Security Headers | 🟢 Low | nginx/default.conf |
| PHP execute ใน uploads folder | 🟢 Low | nginx/default.conf |
| Verbose Error ใน clients.php | 🟡 Medium | clients.php ✅ แก้แล้ว |
| Sensitive Data (citizen_id แสดงเต็ม) | 🟡 Medium | clients.php ✅ แก้แล้ว (mask display) |
| Missing Foreign Keys | 🟢 Low | ✅ รัน migrate_add_missing_fk.sql แล้ว |

### Security Headers ใน Nginx
```nginx
root /var/www/html;   ← สำคัญ: ต้องเป็น path นี้
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
server_tokens off;
location ~* ^/uploads/.*\.php$ { deny all; return 403; }
```

---

## UI/UX Updates ที่ทำไปแล้ว ✅

### lawyers.php & clients.php
- เพิ่มช่อง Username ในฟอร์ม (validate รูปแบบ + เช็คซ้ำ)
- INSERT `username` เข้า `users` table
- ตารางแสดงคอลัมน์ Username
- ฟอร์มเปลี่ยนเป็น Modal — กดปุ่ม "➕ เพิ่ม" ถึงจะโผล่
- Submit ผ่าน AJAX ไม่ reload หน้า
- แจ้งเตือนด้วย SweetAlert2 (✅ success / ❌ error)
- Reload หน้าอัตโนมัติหลัง success

### send_request.php
- ฟอร์มส่งคำขอเปลี่ยนเป็น Modal popup
- Submit ผ่าน AJAX + SweetAlert2

### contract_documents.php
- ฟอร์มส่งเอกสารเปลี่ยนเป็น Modal popup
- รองรับ file upload ผ่าน AJAX (FormData)
- Submit ผ่าน AJAX + SweetAlert2

### filings.php
- ฟอร์มเพิ่มการยื่นฟ้องเปลี่ยนเป็น Modal popup
- Court custom dropdown (พิมพ์+เลือก+เพิ่มศาลใหม่อัตโนมัติ) ย้ายเข้า modal
- Auto-open modal ถ้ามี `?contract_id=` ใน URL
- Submit ผ่าน AJAX + SweetAlert2

### hearings.php
- ฟอร์มเพิ่มนัดขึ้นศาลเปลี่ยนเป็น Modal popup
- Auto-open modal ถ้ามี `?filing_id=` ใน URL
- Submit ผ่าน AJAX + SweetAlert2
- Modal อัปเดตสถานะ (เดิม) ยังคงเป็น regular POST

### my_cases.php
- แต่ละ card เปลี่ยนเป็น collapsible header ที่คลิกได้
- Header แสดง ชื่อคำขอ, ทนาย, badge สถานะ, arrow ▼/▲
- Auto-open อัตโนมัติถ้าคดีนั้นมี badge ⚠️ รอตอบกลับ
- ถ้ามีคดีเดียว auto-open เสมอ

### Verdicts.php
- Pending และ Verdicted cards เปลี่ยนเป็น collapsible toggle
- Header แสดง เลขคดี, ศาล, ชื่อลูกความ, badge ผลคำพิพากษา
- ทุก card ซ่อน body ไว้ก่อน ต้องคลิกถึงจะเปิด

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