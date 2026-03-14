# ระบบจัดการคดีความ (Law Case Management System)

> ระบบบริหารจัดการคดีความสำหรับสำนักงานกฎหมาย พัฒนาด้วย PHP + MySQL + Docker

---

## 📋 สารบัญ

- [ฟีเจอร์หลัก](#ฟีเจอร์หลัก)
- [โครงสร้างโปรเจกต์](#โครงสร้างโปรเจกต์)
- [Database Schema](#database-schema)
- [การติดตั้งและรัน](#การติดตั้งและรัน)
- [Roles และสิทธิ์](#roles-และสิทธิ์)
- [Flow การทำงาน](#flow-การทำงาน)
- [คำสั่ง Docker](#คำสั่ง-docker)

---

## ✨ ฟีเจอร์หลัก

### 🔐 Authentication
- Login ด้วย **Username หรืออีเมล** ก็ได้
- Role-based access: `admin`, `lawyer`, `client`
- สมัครสมาชิกออนไลน์ (client)

### 👤 Dashboard & Profile
- Sidebar ด้านซ้าย ย่อ/ขยายได้ พร้อมจำสถานะ
- Profile avatar แสดงรูปจริงของ user ที่ sidebar
- Dropdown แก้ไขโปรไฟล์จาก sidebar
- ลูกความแก้รูปโปรไฟล์ได้ทันที / แก้ข้อมูลส่วนตัวรอ Admin อนุมัติ
- ทนายแก้ไขข้อมูลทั้งหมดได้เอง (ชื่อ, ใบอนุญาต, ประสบการณ์, bio)
- ระบบประกาศ/ข่าวสารสำนักงาน (pin ได้)
- ระบบรีวิว/ให้คะแนนดาวทนาย

### 📋 คดีความ
- ส่งคำขอว่าจ้างทนาย
- ทนายรับ/ปฏิเสธคำขอ (สร้างสัญญาอัตโนมัติเมื่อรับ)
- ติดตามสถานะคดีแบบ step-by-step

### 📄 สัญญา
- ระบบ review สัญญา (ทนาย ↔ ลูกความ)
- ระบบ negotiation ต่อรองค่าดำเนินคดี
- ส่งเอกสารประกอบสัญญา

### ⚖️ กระบวนการคดี
- การยื่นฟ้อง (filings)
- นัดขึ้นศาล + countdown timer + บันทึกผลการพิจารณา
- คำพิพากษา (verdicts)
- สำนวนคดี (case summary) + export PDF

### 📝 เอกสารเซ็น *(ฟีเจอร์ใหม่)*
- ทนายแนบ PDF (หนังสือมอบอำนาจ, ใบยินยอม ฯลฯ) ส่งให้ลูกความ
- ลูกความเห็นแจ้งเตือน badge สีแดงใน sidebar ทุกหน้า
- ลูกความรับทราบ / ส่ง PDF ที่เซ็นแล้วกลับ
- ทนายดาวน์โหลดเอกสารที่เซ็นแล้ว
- ทนายเพิ่ม Note ติดตาม + ปุ่ม Remind + แสดงวันเลยกำหนด

### 📂 เอกสารภายนอก
- อัปโหลดเอกสารแนบให้แต่ละคดี (PDF, Word, Excel, รูปภาพ)
- จัดหมวดหมู่และค้นหาเอกสาร

### 💳 การชำระเงิน
- บันทึกการชำระ (โอน, เงินสด, อื่นๆ)
- แนบสลิปการโอน
- QR Code PromptPay ของทนาย
- Progress bar แสดงยอดชำระ/คงเหลือ
- ประวัติการชำระย้อนหลัง

---

## 📁 โครงสร้างโปรเจกต์

```
law_management_system/
├── docker-compose.yml
├── docker/
│   ├── php/
│   │   └── Dockerfile          # PHP 8.2-FPM + PDO MySQL + wkhtmltopdf
│   ├── nginx/
│   │   └── default.conf        # Nginx config (upload limit 50MB)
│   └── mysql/
│       └── init.sql            # Schema + Seed data
└── src/
    ├── index.php
    ├── config/
    │   ├── db.php              # PDO connection
    │   └── auth.php            # Session / Role helper
    ├── includes/
    │   ├── header.php          # Sidebar navigation + profile
    │   └── footer.php
    ├── pages/
    │   ├── login.php           # Login (username หรือ email)
    │   ├── register.php        # สมัครสมาชิก (client)
    │   ├── logout.php
    │   ├── dashboard.php       # หน้าหลัก
    │   ├── lawyers.php         # จัดการทนาย (admin)
    │   ├── clients.php         # จัดการลูกความ (admin)
    │   ├── case_requests.php   # คำขอว่าจ้าง
    │   ├── contracts.php       # สัญญาและ negotiation
    │   ├── contract_documents.php  # ส่งเอกสารสัญญา (client)
    │   ├── filings.php         # การยื่นฟ้อง
    │   ├── hearings.php        # นัดขึ้นศาล
    │   ├── verdicts.php        # คำพิพากษา
    │   ├── Case_summary.php    # สำนวนคดี + PDF export
    │   ├── case_documents_ext.php  # เอกสารภายนอก
    │   ├── client_sign_docs.php    # เอกสารให้ลูกความเซ็น ⭐
    │   ├── payments.php        # การชำระเงิน + QR Code
    │   ├── send_request.php    # ส่งคำขอ (client)
    │   └── my_cases.php        # คดีของฉัน (client)
    ├── assets/
    │   └── css/
    │       └── style.css       # Sidebar + Common CSS
    └── uploads/                # ไฟล์ที่อัปโหลด (auto-created)
        ├── lawyer_photos/
        ├── client_photos/
        ├── payment_slips/
        ├── qr_codes/
        ├── sign_docs/
        │   └── signed/
        └── contracts/
```

---

## 🗄️ Database Schema

| ตาราง | คำอธิบาย |
|-------|----------|
| `users` | บัญชีผู้ใช้ (username, email, role) |
| `roles` | admin, lawyer, client |
| `offices` | ข้อมูลสำนักงาน |
| `lawyer_profiles` | ข้อมูลทนาย (ใบอนุญาต, รูป, bio) |
| `client_profiles` | ข้อมูลลูกความ |
| `case_requests` | คำขอว่าจ้าง |
| `contracts` | สัญญาว่าจ้าง |
| `case_documents` | เอกสารสัญญา |
| `case_summary_docs` | เอกสารสำนวนคดี |
| `courts` | ข้อมูลศาล |
| `filings` | การยื่นฟ้อง |
| `court_hearings` | นัดขึ้นศาล |
| `verdicts` | คำพิพากษา |
| `payments` | การชำระเงิน |
| `announcements` | ประกาศสำนักงาน |
| `lawyer_reviews` | รีวิว/ดาวทนาย |
| `client_sign_docs` | เอกสารให้ลูกความเซ็น ⭐ |
| `profile_change_requests` | คำขอแก้ไขข้อมูล (รอ admin) ⭐ |

### Migration ที่ต้องรัน (หลัง init.sql)

```sql
-- users: เพิ่ม username
ALTER TABLE `users` ADD COLUMN `username` VARCHAR(50) DEFAULT NULL AFTER `user_id`;
ALTER TABLE `users` ADD UNIQUE KEY `uq_username` (`username`);
UPDATE `users` SET `username` = CONCAT(SUBSTRING_INDEX(email,'@',1), user_id) WHERE username IS NULL;

-- lawyer_profiles: เพิ่มข้อมูลเพิ่มเติม
ALTER TABLE `lawyer_profiles` ADD COLUMN `profile_photo` VARCHAR(500) DEFAULT NULL;
ALTER TABLE `lawyer_profiles` ADD COLUMN `bio` TEXT DEFAULT NULL;
ALTER TABLE `lawyer_profiles` ADD COLUMN `experience_yr` INT DEFAULT NULL;
ALTER TABLE `lawyer_profiles` ADD COLUMN `education` VARCHAR(500) DEFAULT NULL;

-- client_profiles: เพิ่มรูปโปรไฟล์
ALTER TABLE `client_profiles` ADD COLUMN `profile_photo` VARCHAR(500) DEFAULT NULL;

-- ตารางใหม่
CREATE TABLE IF NOT EXISTS `announcements` (...);
CREATE TABLE IF NOT EXISTS `lawyer_reviews` (...);
CREATE TABLE IF NOT EXISTS `profile_change_requests` (...);
CREATE TABLE IF NOT EXISTS `client_sign_docs` (...);

-- client_sign_docs: เพิ่ม columns
ALTER TABLE `client_sign_docs` ADD COLUMN `lawyer_note` TEXT DEFAULT NULL;
ALTER TABLE `client_sign_docs` ADD COLUMN `last_remind_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `client_sign_docs` ADD COLUMN `signed_file` VARCHAR(500) DEFAULT NULL;
```

> ดู SQL ฉบับเต็มได้ที่ไฟล์ `migrate_*.sql` ในโปรเจกต์

---

## 🚀 การติดตั้งและรัน

### ข้อกำหนด
- Docker Desktop

### ขั้นตอน

```bash
# 1. Clone
git clone https://github.com/KumaMai/law_management_system.git
cd law_management_system

# 2. รัน Docker
docker-compose up -d

# 3. รอ MySQL init (~15 วินาที) แล้วเปิดเว็บ
```

| URL | บริการ |
|-----|--------|
| http://localhost:8080 | เว็บหลัก |
| http://localhost:8081 | phpMyAdmin |

### บัญชีทดสอบ

| Role  | Username |      Email      |  Password  |
|-------|----------|-----------------|------------|
| admin |   admin  | admin@admin.com |  admin1234 |

### เพิ่ม Upload Limit (สำหรับ PDF)

```bash
docker exec law_php sh -c "printf 'upload_max_filesize = 50M\npost_max_size = 55M\nmemory_limit = 256M\n' > /usr/local/etc/php/conf.d/uploads.ini"
docker restart law_php
```

---

## 👥 Roles และสิทธิ์

| Role | สิทธิ์ |
|------|--------|
| **admin** | จัดการทนาย/ลูกความ, ดูทุกคดี, อนุมัติการแก้ไขข้อมูล, ดูเอกสารเซ็นทั้งหมด |
| **lawyer** | รับ/ปฏิเสธคำขอ, จัดการสัญญา, ยื่นฟ้อง, นัดศาล, ส่งเอกสารให้ลูกความเซ็น, รับ QR ชำระเงิน |
| **client** | ส่งคำขอ, ดูคดีของตัวเอง, ชำระเงิน, เซ็นเอกสาร, รีวิวทนาย |

---

## 🔄 Flow การทำงาน

```
1. Admin/Client สร้างบัญชี
2. Client ส่งคำขอว่าจ้าง → Lawyer รับ/ปฏิเสธ
3. รับคำขอ → สร้างสัญญาอัตโนมัติ
4. Lawyer + Client review/negotiate สัญญา
5. Lawyer ยื่นฟ้อง → นัดขึ้นศาล → บันทึกผล
6. Lawyer ส่งเอกสารให้ลูกความเซ็น (client_sign_docs)
7. Client ดาวน์โหลด เซ็น แล้วส่ง PDF กลับ
8. Client ชำระเงิน (QR / สลิป)
9. Lawyer/Admin บันทึกคำพิพากษา
10. สร้างสำนวนคดี + export PDF
```

---

## 🐳 คำสั่ง Docker

```bash
docker-compose up -d        # เริ่มระบบ
docker-compose down         # หยุดระบบ
docker-compose down -v      # หยุด + ลบฐานข้อมูล
docker-compose logs -f      # ดู log
docker restart law_php      # restart PHP container
docker exec law_php php -r "echo ini_get('upload_max_filesize');"  # ตรวจ PHP config
```

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.2 |
| Database | MySQL 8.0 |
| Web Server | Nginx |
| Frontend | HTML/CSS/JS (Vanilla) |
| PDF Export | wkhtmltopdf |
| Container | Docker + Docker Compose |
| Font | Sarabun (Google Fonts) |