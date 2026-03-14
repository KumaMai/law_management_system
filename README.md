# ระบบจัดการคดีความ (Law Case Management System)

## โครงสร้างไฟล์

```
law-system/
├── docker-compose.yml
├── docker/
│   ├── php/
│   │   └── Dockerfile          # PHP 8.2-FPM + PDO MySQL
│   ├── nginx/
│   │   └── default.conf        # Nginx config
│   └── mysql/
│       └── init.sql            # Schema + Seed data
└── src/
    ├── index.php               # Redirect หน้าแรก
    ├── config/
    │   ├── db.php              # Database connection (PDO)
    │   └── auth.php            # Session / Role helper
    ├── includes/
    │   ├── header.php          # Navbar + HTML head
    │   └── footer.php          # HTML footer
    ├── pages/
    │   ├── login.php           # หน้าเข้าสู่ระบบ
    │   ├── logout.php          # ออกจากระบบ
    │   ├── dashboard.php       # หน้าหลัก (stats + เมนู)
    │   ├── lawyers.php         # จัดการทนาย (admin)
    │   ├── clients.php         # จัดการลูกความ (admin)
    │   ├── case_requests.php   # คำขอว่าจ้าง
    │   ├── contracts.php       # สัญญาว่าจ้าง
    │   ├── filings.php         # การยื่นฟ้อง
    │   ├── hearings.php        # นัดขึ้นศาล
    │   └── my_cases.php        # คดีของฉัน (client)
    └── assets/
        └── css/
            └── style.css       # CSS ทั้งหมด
```

## วิธีติดตั้งและรัน

### 1. ติดตั้ง Docker Desktop
https://www.docker.com/products/docker-desktop

### 2. Clone หรือวางไฟล์โปรเจกต์
```bash
cd law-system
```

### 3. รัน Docker
```bash
docker-compose up -d
```

### 4. เปิดเว็บ
- **เว็บหลัก:** http://localhost:8080
- **phpMyAdmin:** http://localhost:8081

### 5. Login ทดสอบ
| Email | Password | Role |
|---|---|---|
| admin@lawfirm.com | admin1234 | admin |

> หมายเหตุ: ต้องรอ MySQL init เสร็จก่อน ประมาณ 10-15 วินาทีหลัง docker up

---

## Roles และสิทธิ์การใช้งาน

| Role | สิทธิ์ |
|---|---|
| admin | เพิ่ม/ดูทนาย, ลูกความ, ดูคำขอทั้งหมด, สัญญา, การยื่นฟ้อง |
| lawyer | รับ/ปฏิเสธคำขอ, ดูสัญญา, เพิ่มการยื่นฟ้อง, นัดขึ้นศาล |
| client | ดูความคืบหน้าคดีของตัวเองผ่าน my_cases |

## Flow การทำงาน

```
1. Admin สร้างบัญชี lawyer และ client
2. Client ส่งคำขอว่าจ้างไปให้ Lawyer (case_requests)
3. Lawyer รับหรือปฏิเสธคำขอ
   - รับ → ระบบสร้าง contract อัตโนมัติ
   - ปฏิเสธ → บันทึกเหตุผล
4. Lawyer เพิ่มการยื่นฟ้อง (filings) และนัดขึ้นศาล (hearings)
5. Client ดูความคืบหน้าผ่าน my_cases (step progress bar)
```

## คำสั่ง Docker ที่ใช้บ่อย

```bash
# เริ่มระบบ
docker-compose up -d

# หยุดระบบ
docker-compose down

# ดู log
docker-compose logs -f

# รีสตาร์ท
docker-compose restart

# ล้างฐานข้อมูลและเริ่มใหม่
docker-compose down -v
docker-compose up -d
```
