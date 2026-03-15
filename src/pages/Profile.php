<?php
// Profile.php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
requireLogin();

$pdo    = getDB();
$role   = $_SESSION['role'];
$userId = $_SESSION['user_id'];
$officeId = $_SESSION['office_id'];

$profile = null;

if ($role === 'lawyer') {
    try {
        $s = $pdo->prepare("
            SELECT lp.*, u.email, u.username, u.created_at AS account_created,
                   ROUND(COALESCE(AVG(r.rating),0),1) AS avg_rating,
                   COUNT(r.review_id) AS review_count
            FROM lawyer_profiles lp
            JOIN users u ON lp.user_id = u.user_id
            LEFT JOIN lawyer_reviews r ON lp.lawyer_id = r.lawyer_id
            WHERE lp.user_id = ?
            GROUP BY lp.lawyer_id
        ");
        $s->execute([$userId]);
        $profile = $s->fetch();
    } catch (Exception $e) {
        $s = $pdo->prepare("SELECT lp.*, u.email, u.username, u.created_at AS account_created FROM lawyer_profiles lp JOIN users u ON lp.user_id=u.user_id WHERE lp.user_id=?");
        $s->execute([$userId]);
        $profile = $s->fetch();
        $profile['avg_rating']   = 0;
        $profile['review_count'] = 0;
    }

    // สถิติคดี
    $lid = $profile['lawyer_id'] ?? null;
    $statTotal   = 0; $statActive = 0; $statDone = 0;
    if ($lid) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE lawyer_id=? AND office_id=?");
        $q->execute([$lid, $officeId]); $statTotal = (int)$q->fetchColumn();

        $q = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE lawyer_id=? AND office_id=? AND status='accepted'");
        $q->execute([$lid, $officeId]); $statActive = (int)$q->fetchColumn();

        $q = $pdo->prepare("SELECT COUNT(*) FROM case_requests cr JOIN contracts c ON cr.request_id=c.request_id WHERE cr.lawyer_id=? AND c.contract_review_status='finalized'");
        $q->execute([$lid]); $statDone = (int)$q->fetchColumn();
    }

    // รีวิวล่าสุด
    $reviews = [];
    if (($profile['review_count'] ?? 0) > 0) {
        try {
            $r = $pdo->prepare("
                SELECT rv.rating, rv.comment, rv.created_at, CONCAT(cp.fname,' ',cp.lname) AS client_name
                FROM lawyer_reviews rv
                JOIN client_profiles cp ON rv.client_id = cp.client_id
                WHERE rv.lawyer_id = ?
                ORDER BY rv.created_at DESC LIMIT 5
            ");
            $r->execute([$lid]);
            $reviews = $r->fetchAll();
        } catch (Exception $e) {}
    }

} elseif ($role === 'client') {
    try {
        $s = $pdo->prepare("SELECT cp.*, u.email, u.username, u.created_at AS account_created FROM client_profiles cp JOIN users u ON cp.user_id=u.user_id WHERE cp.user_id=?");
        $s->execute([$userId]);
        $profile = $s->fetch();
    } catch (Exception $e) {}

    // สถิติ
    $cid = $profile['client_id'] ?? null;
    $statTotal = 0; $statActive = 0; $statDone = 0;
    if ($cid) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE client_id=? AND office_id=?");
        $q->execute([$cid, $officeId]); $statTotal = (int)$q->fetchColumn();

        $q = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE client_id=? AND office_id=? AND status='accepted'");
        $q->execute([$cid, $officeId]); $statActive = (int)$q->fetchColumn();

        $q = $pdo->prepare("SELECT COUNT(*) FROM case_requests cr JOIN contracts c ON cr.request_id=c.request_id WHERE cr.client_id=? AND c.contract_review_status='finalized'");
        $q->execute([$cid]); $statDone = (int)$q->fetchColumn();
    }

} elseif ($role === 'admin') {
    $s = $pdo->prepare("SELECT u.*, o.office_name FROM users u JOIN offices o ON u.office_id=o.office_id WHERE u.user_id=?");
    $s->execute([$userId]);
    $profile = $s->fetch();
    $statTotal = 0; $statActive = 0; $statDone = 0;

    $q = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE office_id=?");
    $q->execute([$officeId]); $statTotal = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM lawyer_profiles lp JOIN users u ON lp.user_id=u.user_id WHERE u.office_id=?");
    $q->execute([$officeId]); $statActive = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM client_profiles cp JOIN users u ON cp.user_id=u.user_id WHERE u.office_id=?");
    $q->execute([$officeId]); $statDone = (int)$q->fetchColumn();
}

function starHtml(float $n): string {
    $full = max(0, min(5, (int)round($n)));
    return str_repeat('★', $full) . str_repeat('☆', 5 - $full);
}

$photoUrl = null;
if ($role === 'lawyer' && !empty($profile['profile_photo'])) {
    $photoUrl = '/uploads/lawyer_photos/' . $profile['profile_photo'];
} elseif ($role === 'client' && !empty($profile['profile_photo'])) {
    $photoUrl = '/uploads/client_photos/' . $profile['profile_photo'];
}

$displayName = 'ผู้ใช้งาน';
if ($role === 'admin') {
    $displayName = 'ผู้ดูแลระบบ';
} elseif (!empty($profile['fname'])) {
    $displayName = $profile['fname'] . ' ' . $profile['lname'];
}

$roleLabel = ['admin' => 'Administrator', 'lawyer' => 'ทนายความ', 'client' => 'ลูกความ'][$role] ?? $role;
$roleColor = ['admin' => '#7c3aed', 'lawyer' => '#1a3a5c', 'client' => '#059669'][$role] ?? '#64748b';

$pageTitle = 'โปรไฟล์ของฉัน';
include '../includes/header.php';
?>

<style>
:root {
    --navy: #1a3a5c;
    --navy2: #2d5a8a;
    --border: #e2e8f0;
    --muted: #94a3b8;
    --bg: #f8fafc;
    --green: #059669;
    --blue: #2563eb;
    --red: #dc2626;
}

/* ── Page wrapper ── */
.profile-page {
    max-width: 860px;
    margin: 0 auto;
    padding: 24px 16px 48px;
}

/* ── Hero card ── */
.hero-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 20px;
    overflow: hidden;
    margin-bottom: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
}
.hero-banner {
    height: 110px;
    background: linear-gradient(135deg, #0f2744 0%, #1a3a5c 50%, #2d5a8a 100%);
    position: relative;
}
.hero-banner::after {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.hero-body {
    padding: 0 28px 24px;
    display: flex;
    gap: 20px;
    align-items: flex-end;
    flex-wrap: wrap;
}
.hero-avatar-wrap {
    margin-top: -46px;
    flex-shrink: 0;
}
.hero-avatar {
    width: 92px;
    height: 92px;
    border-radius: 50%;
    border: 4px solid #fff;
    object-fit: cover;
    box-shadow: 0 4px 14px rgba(0,0,0,.15);
    display: block;
}
.hero-avatar-ph {
    width: 92px;
    height: 92px;
    border-radius: 50%;
    border: 4px solid #fff;
    background: var(--navy);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.4rem;
    box-shadow: 0 4px 14px rgba(0,0,0,.15);
}
.hero-info {
    flex: 1;
    min-width: 0;
    padding-top: 14px;
}
.hero-name {
    font-size: 1.45rem;
    font-weight: 800;
    color: var(--navy);
    line-height: 1.2;
    margin-bottom: 4px;
}
.hero-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 12px;
    border-radius: 20px;
    font-size: .73rem;
    font-weight: 700;
    color: #fff;
    margin-bottom: 6px;
}
.hero-meta {
    font-size: .78rem;
    color: var(--muted);
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-top: 4px;
}
.hero-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}
.hero-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
    padding-top: 14px;
    flex-wrap: wrap;
    align-items: flex-start;
}
.btn-primary {
    padding: 9px 20px;
    background: var(--navy);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: .84rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: .15s;
    white-space: nowrap;
}
.btn-primary:hover { background: var(--navy2); transform: translateY(-1px); }
.btn-secondary {
    padding: 9px 16px;
    background: var(--bg);
    color: var(--navy);
    border: 1.5px solid var(--border);
    border-radius: 10px;
    font-size: .84rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: .15s;
    white-space: nowrap;
}
.btn-secondary:hover { border-color: var(--navy); background: #fff; }

/* ── Stat strip ── */
.stat-strip {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    border-top: 1px solid var(--border);
    margin-top: 16px;
}
.stat-cell {
    text-align: center;
    padding: 14px 10px;
    border-right: 1px solid var(--border);
}
.stat-cell:last-child { border-right: none; }
.stat-val {
    font-size: 1.7rem;
    font-weight: 800;
    color: var(--navy);
    line-height: 1;
    display: block;
}
.stat-lbl {
    font-size: .72rem;
    color: var(--muted);
    margin-top: 3px;
    display: block;
}

/* ── Rating banner (lawyer) ── */
.rating-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border: 1px solid #fde68a;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 20px;
}
.rating-big {
    font-size: 2.8rem;
    font-weight: 900;
    color: var(--navy);
    line-height: 1;
}
.rating-stars {
    font-size: 1.3rem;
    color: #f59e0b;
    letter-spacing: 2px;
}
.rating-count {
    font-size: .8rem;
    color: #92400e;
    margin-top: 2px;
}

/* ── Info sections ── */
.section-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 16px;
    margin-bottom: 16px;
    overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,.04);
}
.section-head {
    padding: 13px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    color: var(--navy);
    font-size: .9rem;
    background: var(--bg);
}
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
}
.info-cell {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    border-right: 1px solid var(--border);
}
.info-cell:nth-child(2n) { border-right: none; }
.info-cell.full { grid-column: 1 / -1; border-right: none; }
.info-cell:last-child, .info-cell:nth-last-child(2):not(.full) {
    border-bottom: none;
}
.info-lbl {
    font-size: .72rem;
    color: var(--muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.info-lbl svg {
    width: 12px; height: 12px;
    stroke: var(--muted); fill: none;
    stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
}
.info-val {
    font-size: .88rem;
    color: #1e293b;
    font-weight: 600;
    line-height: 1.5;
}
.info-val.muted { color: var(--muted); font-weight: 400; font-style: italic; }
.info-val.masked {
    color: var(--muted);
    letter-spacing: 2px;
    font-family: monospace;
}

/* ── Bio box ── */
.bio-box {
    padding: 16px 20px;
    font-size: .87rem;
    color: #334155;
    line-height: 1.7;
}
.bio-box.empty {
    color: var(--muted);
    font-style: italic;
}

/* ── Spec tag ── */
.spec-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #eff6ff;
    color: var(--blue);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: .78rem;
    font-weight: 600;
}

/* ── Reviews ── */
.review-item {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
}
.review-item:last-child { border-bottom: none; }
.review-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}
.review-name { font-weight: 700; color: var(--navy); font-size: .85rem; }
.review-stars { color: #f59e0b; font-size: .95rem; letter-spacing: 1px; }
.review-date { font-size: .7rem; color: var(--muted); margin-top: 2px; }
.review-comment {
    font-size: .82rem;
    color: #475569;
    background: var(--bg);
    border-radius: 8px;
    padding: 8px 12px;
    line-height: 1.55;
    margin-top: 6px;
}

/* ── Back link ── */
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--muted);
    font-size: .82rem;
    text-decoration: none;
    margin-bottom: 16px;
    transition: color .15s;
}
.back-link:hover { color: var(--navy); }

/* ── Responsive ── */
@media (max-width: 600px) {
    .info-grid { grid-template-columns: 1fr; }
    .info-cell { border-right: none !important; }
    .hero-body { flex-direction: column; align-items: flex-start; gap: 10px; }
    .hero-actions { width: 100%; }
    .stat-strip { grid-template-columns: repeat(3,1fr); }
}
</style>

<div class="profile-page">

    <a href="/pages/dashboard.php" class="back-link">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><polyline points="15 18 9 12 15 6"/></svg>
        กลับหน้าหลัก
    </a>

    <!-- ── Hero Card ── -->
    <div class="hero-card">
        <div class="hero-banner"></div>
        <div class="hero-body">
            <div class="hero-avatar-wrap">
                <?php if ($photoUrl): ?>
                    <img src="<?= htmlspecialchars($photoUrl) ?>" class="hero-avatar" alt="avatar">
                <?php else: ?>
                    <div class="hero-avatar-ph">
                        <?= $role === 'admin' ? '🏛️' : ($role === 'lawyer' ? '⚖️' : '👤') ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="hero-info">
                <div class="hero-name"><?= htmlspecialchars($displayName) ?></div>
                <div>
                    <span class="hero-role-badge" style="background:<?= $roleColor ?>;">
                        <?= $role === 'admin' ? '🏛️' : ($role === 'lawyer' ? '⚖️' : '👤') ?>
                        <?= htmlspecialchars($roleLabel) ?>
                    </span>
                    <?php if ($role === 'lawyer' && !empty($profile['specialization'])): ?>
                    <span class="spec-tag" style="margin-left:4px;">
                        <?= htmlspecialchars($profile['specialization']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="hero-meta">
                    <?php if (!empty($profile['email'])): ?>
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <?= htmlspecialchars($profile['email']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($profile['account_created'])): ?>
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        สมาชิกตั้งแต่ <?= date('M Y', strtotime($profile['account_created'])) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($role === 'lawyer' && !empty($profile['experience_yr'])): ?>
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        ประสบการณ์ <?= $profile['experience_yr'] ?> ปี
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($role !== 'admin'): ?>
            <div class="hero-actions">
                <button class="btn-primary" onclick="<?= $role === 'lawyer' ? 'openModal()' : 'openClientModal()' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    แก้ไขโปรไฟล์
                </button>
                <a href="/pages/dashboard.php" class="btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    Dashboard
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Stat Strip -->
        <div class="stat-strip">
            <?php if ($role === 'lawyer'): ?>
                <div class="stat-cell">
                    <span class="stat-val"><?= $statTotal ?></span>
                    <span class="stat-lbl">คดีทั้งหมด</span>
                </div>
                <div class="stat-cell">
                    <span class="stat-val"><?= $statActive ?></span>
                    <span class="stat-lbl">คดีที่รับแล้ว</span>
                </div>
                <div class="stat-cell">
                    <span class="stat-val"><?= number_format($profile['avg_rating'] ?? 0, 1) ?></span>
                    <span class="stat-lbl">คะแนนเฉลี่ย</span>
                </div>
            <?php elseif ($role === 'client'): ?>
                <div class="stat-cell">
                    <span class="stat-val"><?= $statTotal ?></span>
                    <span class="stat-lbl">คดีทั้งหมด</span>
                </div>
                <div class="stat-cell">
                    <span class="stat-val"><?= $statActive ?></span>
                    <span class="stat-lbl">คดีที่รับแล้ว</span>
                </div>
                <div class="stat-cell">
                    <span class="stat-val"><?= $statDone ?></span>
                    <span class="stat-lbl">สัญญายืนยันแล้ว</span>
                </div>
            <?php else: ?>
                <div class="stat-cell">
                    <span class="stat-val"><?= $statTotal ?></span>
                    <span class="stat-lbl">คดีทั้งหมด</span>
                </div>
                <div class="stat-cell">
                    <span class="stat-val"><?= $statActive ?></span>
                    <span class="stat-lbl">ทนายในสำนักงาน</span>
                </div>
                <div class="stat-cell">
                    <span class="stat-val"><?= $statDone ?></span>
                    <span class="stat-lbl">ลูกความทั้งหมด</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Rating banner (lawyer only) ── -->
    <?php if ($role === 'lawyer' && ($profile['avg_rating'] ?? 0) > 0): ?>
    <div class="rating-banner">
        <div class="rating-big"><?= number_format($profile['avg_rating'], 1) ?></div>
        <div>
            <div class="rating-stars"><?= starHtml((float)$profile['avg_rating']) ?></div>
            <div class="rating-count">จาก <?= $profile['review_count'] ?> รีวิว</div>
        </div>
        <div style="flex:1;"></div>
        <div style="font-size:.78rem;color:#92400e;text-align:right;">
            <?php
            $r = (float)$profile['avg_rating'];
            if ($r >= 4.5) echo '🏆 ยอดเยี่ยม';
            elseif ($r >= 3.5) echo '👍 ดีมาก';
            elseif ($r >= 2.5) echo '😊 พอใจ';
            else echo '📈 กำลังพัฒนา';
            ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── ข้อมูลส่วนตัว ── -->
    <?php if ($role !== 'admin'): ?>
    <div class="section-card">
        <div class="section-head">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            ข้อมูลส่วนตัว
        </div>
        <div class="info-grid">
            <div class="info-cell">
                <div class="info-lbl">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    ชื่อ
                </div>
                <div class="info-val"><?= htmlspecialchars($profile['fname'] ?? '-') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-lbl">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    นามสกุล
                </div>
                <div class="info-val"><?= htmlspecialchars($profile['lname'] ?? '-') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-lbl">
                    <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    อีเมล
                </div>
                <div class="info-val"><?= htmlspecialchars($profile['email'] ?? '-') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-lbl">
                    <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6 6l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 17z"/></svg>
                    เบอร์โทร
                </div>
                <div class="info-val <?= empty($profile['phone']) ? 'muted' : '' ?>">
                    <?= htmlspecialchars($profile['phone'] ?? '') ?: 'ยังไม่ระบุ' ?>
                </div>
            </div>

            <?php if ($role === 'client'): ?>
            <div class="info-cell">
                <div class="info-lbl">
                    <svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    บัตรประชาชน
                </div>
                <?php if (!empty($profile['citizen_id'])): ?>
                    <div class="info-val masked"><?= substr($profile['citizen_id'],0,4) ?>X-XXXX-XXXXX-XX-X</div>
                <?php else: ?>
                    <div class="info-val muted">ยังไม่ระบุ</div>
                <?php endif; ?>
            </div>
            <div class="info-cell full">
                <div class="info-lbl">
                    <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    ที่อยู่
                </div>
                <div class="info-val <?= empty($profile['address']) ? 'muted' : '' ?>">
                    <?= nl2br(htmlspecialchars($profile['address'] ?? '')) ?: 'ยังไม่ระบุ' ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($role === 'lawyer'): ?>
            <div class="info-cell">
                <div class="info-lbl">
                    <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    เลขใบอนุญาต
                </div>
                <div class="info-val <?= empty($profile['license_no']) ? 'muted' : '' ?>">
                    <?= htmlspecialchars($profile['license_no'] ?? '') ?: 'ยังไม่ระบุ' ?>
                </div>
            </div>
            <div class="info-cell">
                <div class="info-lbl">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    ใบอนุญาตหมดอายุ
                </div>
                <div class="info-val <?= empty($profile['license_exp']) ? 'muted' : '' ?>">
                    <?= !empty($profile['license_exp']) ? date('d/m/Y', strtotime($profile['license_exp'])) : 'ยังไม่ระบุ' ?>
                </div>
            </div>
            <div class="info-cell">
                <div class="info-lbl">
                    <svg viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    การศึกษา
                </div>
                <div class="info-val <?= empty($profile['education']) ? 'muted' : '' ?>">
                    <?= htmlspecialchars($profile['education'] ?? '') ?: 'ยังไม่ระบุ' ?>
                </div>
            </div>
            <div class="info-cell">
                <div class="info-lbl">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    ประสบการณ์
                </div>
                <div class="info-val <?= empty($profile['experience_yr']) ? 'muted' : '' ?>">
                    <?= !empty($profile['experience_yr']) ? $profile['experience_yr'].' ปี' : 'ยังไม่ระบุ' ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bio (lawyer only) -->
    <?php if ($role === 'lawyer'): ?>
    <div class="section-card">
        <div class="section-head">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            แนะนำตัว / Bio
        </div>
        <div class="bio-box <?= empty($profile['bio']) ? 'empty' : '' ?>">
            <?= !empty($profile['bio']) ? nl2br(htmlspecialchars($profile['bio'])) : 'ยังไม่มีการแนะนำตัว' ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Admin info -->
    <?php if ($role === 'admin'): ?>
    <div class="section-card">
        <div class="section-head">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            ข้อมูลผู้ดูแลระบบ
        </div>
        <div class="info-grid">
            <div class="info-cell">
                <div class="info-lbl">อีเมล</div>
                <div class="info-val"><?= htmlspecialchars($profile['email'] ?? '-') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-lbl">สำนักงาน</div>
                <div class="info-val"><?= htmlspecialchars($profile['office_name'] ?? '-') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-lbl">Username</div>
                <div class="info-val"><?= htmlspecialchars($profile['username'] ?? '-') ?></div>
            </div>
            <div class="info-cell">
                <div class="info-lbl">สมาชิกตั้งแต่</div>
                <div class="info-val"><?= !empty($profile['created_at']) ? date('d/m/Y', strtotime($profile['created_at'])) : '-' ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- รีวิวที่ได้รับ (lawyer) -->
    <?php if ($role === 'lawyer' && !empty($reviews)): ?>
    <div class="section-card">
        <div class="section-head">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            รีวิวที่ได้รับล่าสุด
        </div>
        <?php foreach ($reviews as $rv): ?>
        <div class="review-item">
            <div class="review-top">
                <div>
                    <div class="review-name">👤 <?= htmlspecialchars($rv['client_name']) ?></div>
                    <div class="review-date"><?= date('d/m/Y H:i', strtotime($rv['created_at'])) ?></div>
                </div>
                <div>
                    <div class="review-stars"><?= starHtml((float)$rv['rating']) ?></div>
                    <div style="font-size:.71rem;color:var(--muted);text-align:right;"><?= $rv['rating'] ?> / 5</div>
                </div>
            </div>
            <?php if ($rv['comment']): ?>
            <div class="review-comment">"<?= htmlspecialchars($rv['comment']) ?>"</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- include modal จาก dashboard.php โดยฝัง logic เดิม -->
<?php
// Re-use modal จาก dashboard ผ่าน query param
// ถ้า role = lawyer → เรียกใช้ openModal() ที่ define ใน dashboard
// แต่เนื่องจากเราอยู่หน้า profile.php เราต้อง include modal ของตัวเอง
if ($role === 'lawyer') {
    $myLawyer = $profile;
}
if ($role === 'client') {
    $meClient = $profile;
    // pending request
    $myPendingReq = null;
    try {
        $pr = $pdo->prepare("SELECT * FROM profile_change_requests WHERE user_id=? AND status='pending' ORDER BY created_at DESC LIMIT 1");
        $pr->execute([$userId]); $myPendingReq = $pr->fetch();
    } catch (Exception $e) {}
}
?>

<!-- Modal แก้ไขโปรไฟล์ทนาย -->
<?php if ($role === 'lawyer' && $myLawyer): ?>
<div class="modal-ov" id="editModal" onclick="closeModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;padding:22px;width:90%;max-width:500px;max-height:90vh;overflow-y:auto;" onclick="event.stopPropagation()">
    <div style="font-weight:700;color:#1a3a5c;font-size:.98rem;margin-bottom:14px;">✏️ แก้ไขข้อมูลส่วนตัว</div>
    <form method="POST" action="/pages/dashboard.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update_profile">
      <div style="margin-bottom:10px;">
        <label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">📷 รูปโปรไฟล์ (JPG/PNG สูงสุด 5MB)</label>
        <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png" style="width:100%;padding:5px;border:1px solid #e2e8f0;border-radius:8px;font-size:.81rem;background:#fff;">
        <?php if ($myLawyer['profile_photo']): ?><div style="font-size:.7rem;color:#94a3b8;margin-top:2px;">มีรูปอยู่แล้ว — อัปโหลดใหม่เพื่อเปลี่ยน</div><?php endif; ?>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
        <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">👤 ชื่อ</label><input type="text" name="fname" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;background:#fff;outline:none;box-sizing:border-box;" value="<?= htmlspecialchars($myLawyer['fname']??'') ?>" required></div>
        <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">👤 นามสกุล</label><input type="text" name="lname" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;background:#fff;outline:none;box-sizing:border-box;" value="<?= htmlspecialchars($myLawyer['lname']??'') ?>" required></div>
        <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">📞 เบอร์โทร</label><input type="text" name="phone" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;background:#fff;outline:none;box-sizing:border-box;" value="<?= htmlspecialchars($myLawyer['phone']??'') ?>"></div>
        <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">⚖️ ความเชี่ยวชาญ</label><input type="text" name="specialization" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;background:#fff;outline:none;box-sizing:border-box;" value="<?= htmlspecialchars($myLawyer['specialization']??'') ?>"></div>
        <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">🪪 เลขใบอนุญาต</label><input type="text" name="license_no" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;background:#fff;outline:none;box-sizing:border-box;" value="<?= htmlspecialchars($myLawyer['license_no']??'') ?>"></div>
        <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">📆 ใบอนุญาตหมดอายุ</label><input type="date" name="license_exp" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;background:#fff;outline:none;box-sizing:border-box;" value="<?= htmlspecialchars($myLawyer['license_exp']??'') ?>"></div>
        <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">📅 ประสบการณ์ (ปี)</label><input type="number" name="experience_yr" min="0" max="60" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;background:#fff;outline:none;box-sizing:border-box;" value="<?= $myLawyer['experience_yr']??'' ?>"></div>
        <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">🎓 การศึกษา</label><input type="text" name="education" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;background:#fff;outline:none;box-sizing:border-box;" value="<?= htmlspecialchars($myLawyer['education']??'') ?>"></div>
        <div style="grid-column:1/-1;"><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">📝 แนะนำตัว / Bio</label><textarea name="bio" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;background:#fff;outline:none;resize:vertical;min-height:75px;box-sizing:border-box;"><?= htmlspecialchars($myLawyer['bio']??'') ?></textarea></div>
      </div>
      <div style="text-align:right;margin-top:4px;">
        <button type="button" onclick="closeModal()" style="padding:8px 16px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-size:.86rem;cursor:pointer;margin-right:6px;">ยกเลิก</button>
        <button type="submit" style="padding:8px 22px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-size:.86rem;font-weight:700;cursor:pointer;">💾 บันทึก</button>
      </div>
    </form>
  </div>
</div>
<script>
function openModal()  { var el=document.getElementById('editModal');  if(el) el.style.display='flex'; }
function closeModal() { var el=document.getElementById('editModal');  if(el) el.style.display='none'; }
</script>
<?php endif; ?>

<!-- Modal แก้ไขโปรไฟล์ลูกความ -->
<?php if ($role === 'client' && $meClient): ?>
<div class="modal-ov" id="clientModal" onclick="closeClientModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;padding:22px;width:90%;max-width:560px;max-height:90vh;overflow-y:auto;" onclick="event.stopPropagation()">
    <div style="font-weight:700;color:#1a3a5c;font-size:.98rem;margin-bottom:14px;">✏️ แก้ไขข้อมูลส่วนตัว</div>
    <div style="display:flex;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:16px;">
      <button type="button" id="tabPhoto" onclick="switchTab('photo')" style="flex:1;padding:9px;border:none;background:#1a3a5c;color:#fff;font-size:.83rem;font-weight:700;cursor:pointer;">🖼️ รูปโปรไฟล์</button>
      <button type="button" id="tabInfo"  onclick="switchTab('info')"  style="flex:1;padding:9px;border:none;background:#f1f5f9;color:#64748b;font-size:.83rem;cursor:pointer;">📋 ข้อมูลส่วนตัว</button>
    </div>
    <div id="panelPhoto">
      <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:.8rem;color:#065f46;">✅ รูปโปรไฟล์จะ <strong>อัปเดตทันที</strong></div>
      <form method="POST" action="/pages/dashboard.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_client">
        <input type="file" name="client_photo" accept=".jpg,.jpeg,.png" style="width:100%;padding:6px;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;background:#fff;margin-bottom:12px;">
        <div style="text-align:right;">
          <button type="button" onclick="closeClientModal()" style="padding:8px 16px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-size:.86rem;cursor:pointer;margin-right:6px;">ยกเลิก</button>
          <button type="submit" style="padding:8px 22px;background:#1a3a5c;color:#fff;border:none;border-radius:8px;font-size:.86rem;font-weight:700;cursor:pointer;">🖼️ บันทึกรูป</button>
        </div>
      </form>
    </div>
    <div id="panelInfo" style="display:none;">
      <?php if ($myPendingReq): ?>
      <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:.8rem;color:#92400e;">⏳ มีคำขอแก้ไขรออนุมัติ — ส่งใหม่จะยกเลิกของเดิม</div>
      <?php else: ?>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:.8rem;color:#1e40af;">ℹ️ ข้อมูลส่วนตัวจะ <strong>รอ Admin ยืนยัน</strong> ก่อนมีผล</div>
      <?php endif; ?>
      <form method="POST" action="/pages/dashboard.php">
        <input type="hidden" name="action" value="update_client">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
          <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">ชื่อ</label><input type="text" name="fname" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;outline:none;box-sizing:border-box;" value="<?= htmlspecialchars($meClient['fname']??'') ?>"></div>
          <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">นามสกุล</label><input type="text" name="lname" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;outline:none;box-sizing:border-box;" value="<?= htmlspecialchars($meClient['lname']??'') ?>"></div>
          <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">เบอร์โทร</label><input type="text" name="phone" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;outline:none;box-sizing:border-box;" value="<?= htmlspecialchars($meClient['phone']??'') ?>"></div>
          <div><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">บัตรประชาชน</label>
            <?php if ($meClient['citizen_id']): ?>
              <input type="text" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;background:#f1f5f9;color:#94a3b8;box-sizing:border-box;" value="<?= substr($meClient['citizen_id'],0,4) ?>X-XXXX-XXXXX-XX-X" readonly>
            <?php else: ?>
              <input type="text" name="citizen_id" maxlength="13" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;outline:none;box-sizing:border-box;" placeholder="กรอก 13 หลัก">
            <?php endif; ?>
          </div>
          <div style="grid-column:1/-1;"><label style="font-size:.75rem;color:#64748b;font-weight:600;display:block;margin-bottom:3px;">ที่อยู่</label><textarea name="address" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;outline:none;resize:vertical;min-height:70px;box-sizing:border-box;"><?= htmlspecialchars($meClient['address']??'') ?></textarea></div>
        </div>
        <div style="text-align:right;">
          <button type="button" onclick="closeClientModal()" style="padding:8px 16px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-size:.86rem;cursor:pointer;margin-right:6px;">ยกเลิก</button>
          <button type="submit" style="padding:8px 22px;background:#d97706;color:#fff;border:none;border-radius:8px;font-size:.86rem;font-weight:700;cursor:pointer;">📋 ส่งคำขอแก้ไข</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function openClientModal() { var el=document.getElementById('clientModal'); if(el) el.style.display='flex'; }
function closeClientModal(){ var el=document.getElementById('clientModal'); if(el) el.style.display='none'; }
function switchTab(t) {
    var isPhoto = t==='photo';
    document.getElementById('panelPhoto').style.display = isPhoto ? '' : 'none';
    document.getElementById('panelInfo').style.display  = isPhoto ? 'none' : '';
    document.getElementById('tabPhoto').style.background = isPhoto ? '#1a3a5c' : '#f1f5f9';
    document.getElementById('tabPhoto').style.color = isPhoto ? '#fff' : '#64748b';
    document.getElementById('tabInfo').style.background  = isPhoto ? '#f1f5f9' : '#1a3a5c';
    document.getElementById('tabInfo').style.color  = isPhoto ? '#64748b' : '#fff';
}
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>