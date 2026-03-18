# migrations/ — archived

Migration files เหล่านี้ถูกรวมเข้าไปใน `../init.sql` แล้ว (ตั้งแต่ v4.0)

**ไม่จำเป็นต้องรัน migration files เหล่านี้สำหรับ setup ใหม่**
ใช้แค่ `init.sql` ไฟล์เดียวก็เพียงพอ

---

## ไฟล์ที่ archive และสิ่งที่เพิ่ม

| ไฟล์ | Column / Table ที่เพิ่ม |
|---|---|
| `Migrate username.sql` | `users.username` (VARCHAR 50, UNIQUE) |
| `Migrate_payments.sql` | ตาราง `payments` + `lawyer_profiles.qr_code_file` + `payments.installment_note` |
| `migrate_contract_review.sql` | `contracts.contract_review_status`, `lawyer_note`, `client_response`, `proposed_fee` |
| `migrate_dashboard.sql.sql` | ตาราง `announcements`, `lawyer_reviews` + `lawyer_profiles.profile_photo/bio/experience_yr/education` + `client_profiles.profile_photo` |
| `migrate_hearing_absent.sql` | `court_hearings.updated_at` |
| `Migrate add missing fk.sql` | Foreign Keys สำหรับตาราง announcements, case_summary_docs, client_sign_docs, lawyer_reviews, payments, profile_change_requests |

---

เก็บไว้เป็น reference เฉยๆ เผื่อต้องการ alter ฐานข้อมูลที่มีอยู่แล้ว