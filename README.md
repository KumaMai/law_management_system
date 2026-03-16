# ระบบจัดการคดีความ (Law Case Management System)

## Tech Stack
- **Backend:** PHP 8.2
- **Database:** MySQL 8.0
- **Web Server:** Nginx (Alpine)
- **Runtime:** Docker + Docker Compose
- **Frontend:** Vanilla JS, HTML/CSS
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
    │   ├── csrf_helper.php        ← ใหม่ (CSRF protection)
    │   └── file_upload_helper.php ← ใหม่ (MIME type validation)
    ├── includes/
    │   ├── header.php
    │   └── footer.php
    ├── pages/
    │   ├── login.php / logout.php / register.php
    │   ├── dashboard.php / profile.php
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
1. Admin สร้างบัญชีทนาย (lawyers.php)
2. ลูกความสมัคร (register.php) หรือ Admin สร้างให้ (clients.php)
3. ลูกความส่งคำขอว่าจ้างทนาย (send_request.php)
4. ทนายรับ/ปฏิเสธ (case_requests.php) → รับแล้วระบบสร้าง Contract อัตโนมัติ
5. ลูกความอัปโหลดเอกสาร (contract_documents.php)
6. ทนายยืนยัน/ตีกลับสัญญา (contracts.php)
7. ทนายยื่นฟ้อง (filings.php) → นัดขึ้นศาล (hearings.php)
8. บันทึกคำพิพากษา (Verdicts.php)
9. ลูกความชำระเงิน (payments.php)
10. ทนายส่งเอกสารให้เซ็น (client_sign_docs.php)
11. Export PDF (Case_summary.php)
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
| Session Fixation | 🔴 | login.php |
| Missing Authorization | 🔴 | case_requests.php |
| IDOR ใน payments | 🔴 | payments.php |
| Race Condition | 🟠 | payments.php |
| File Upload (MIME) | 🟠 | payments.php, client_sign_docs.php |
| CSRF ทุกฟอร์ม | 🟡 | ทุกไฟล์ |
| Rate Limiting (login) | 🟡 | login.php |
| Logout Cookie | 🟢 | logout.php |
| Security Headers | 🟢 | nginx/default.conf |
| PHP execute in uploads | 🟢 | nginx/default.conf |

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

## TODO ที่ยังค้างอยู่

- [ ] Verbose Error ใน clients.php (แสดง exception message)
- [ ] Mask citizen_id ที่ DB ไม่ใช่ PHP
- [ ] Password complexity (ตอนนี้แค่ 6 ตัว)
- [ ] รัน `migrate_add_missing_fk.sql` เพิ่ม Foreign Keys
- [ ] ตรวจ XSS ทุกจุดที่ output ข้อมูล user

---

## หมายเหตุสำคัญ

- `index.php` ต้องไม่มี `<!-- index.php -->` บรรทัดแรก (ทำให้ session error)
- `nginx/default.conf` ต้องใช้ `root /var/www/html;` (ไม่ใช่ `/var/www/html/src`)
- ทุกฟอร์ม POST ต้องมี `<?= csrf_field() ?>` และทุก handler ต้องมี `csrf_verify()`
- File upload ทุกที่ต้องใช้ `validateUpload()` จาก `file_upload_helper.php`