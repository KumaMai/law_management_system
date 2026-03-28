<?php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/activity_log_helper.php';
requireRole('admin');

$pdo      = getDB();
$officeId = $_SESSION['office_id'];

// --- Filters ---
$filters = [];
if (!empty($_GET['action']))      $filters['action']      = $_GET['action'];
if (!empty($_GET['entity_type'])) $filters['entity_type'] = $_GET['entity_type'];
if (!empty($_GET['user_id']))     $filters['user_id']     = $_GET['user_id'];
if (!empty($_GET['date_from']))   $filters['date_from']   = $_GET['date_from'];
if (!empty($_GET['date_to']))     $filters['date_to']     = $_GET['date_to'];

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$total = audit_count($pdo, $officeId, $filters);
$logs  = audit_fetch($pdo, $officeId, $filters, $offset, $perPage);
$totalPages = max(1, ceil($total / $perPage));

// ดึง users สำหรับ filter dropdown
$users = $pdo->prepare("SELECT u.user_id, u.email, r.role_name,
                                COALESCE(lp.fname, cp.fname, 'ผู้ดูแลระบบ') AS fname,
                                COALESCE(lp.lname, cp.lname, '') AS lname
                         FROM users u
                         JOIN roles r ON u.role_id = r.role_id
                         LEFT JOIN lawyer_profiles lp ON u.user_id = lp.user_id
                         LEFT JOIN client_profiles cp ON u.user_id = cp.user_id
                         WHERE u.office_id = ? AND u.status = 'active'
                         ORDER BY fname");
$users->execute([$officeId]);
$userList = $users->fetchAll();

$pageTitle = 'ประวัติกิจกรรม';
include '../includes/header.php';
?>

<h2 style="margin-bottom: 18px; color: #1a3a5c;">📜 ประวัติกิจกรรม (Activity Log)</h2>

<!-- Filter Card -->
<div class="card" style="margin-bottom: 18px; padding: 16px;">
    <form method="get" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: end;">
        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 140px;">
            <label>ประเภทกิจกรรม</label>
            <select name="action">
                <option value="">-- ทั้งหมด --</option>
                <?php foreach (['create','update','delete','approve','reject','login','logout','upload','confirm','cancel','expire'] as $a): ?>
                <option value="<?= $a ?>" <?= ($filters['action'] ?? '') === $a ? 'selected' : '' ?>><?= htmlspecialchars(audit_action_label($a)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 140px;">
            <label>หมวดหมู่</label>
            <select name="entity_type">
                <option value="">-- ทั้งหมด --</option>
                <?php foreach (['case_request','contract','payment','hearing','filing','verdict','user','client','lawyer','document','sign_doc','verdict_appointment'] as $et): ?>
                <option value="<?= $et ?>" <?= ($filters['entity_type'] ?? '') === $et ? 'selected' : '' ?>><?= htmlspecialchars(audit_entity_label($et)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 140px;">
            <label>ผู้ใช้</label>
            <select name="user_id">
                <option value="">-- ทั้งหมด --</option>
                <?php foreach ($userList as $u): ?>
                <option value="<?= $u['user_id'] ?>" <?= ((int)($filters['user_id'] ?? 0)) === (int)$u['user_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['fname'] . ' ' . $u['lname']) ?> (<?= htmlspecialchars($u['role_name']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 130px;">
            <label>ตั้งแต่</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin-bottom: 0; min-width: 130px;">
            <label>ถึง</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
        </div>
        <div style="display: flex; gap: 6px;">
            <button type="submit" class="btn btn-primary btn-sm">🔍 กรอง</button>
            <a href="activity_log.php" class="btn btn-sm" style="background: #e2e8f0; color: #475569;">ล้าง</a>
        </div>
    </form>
</div>

<!-- Results -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <span style="font-size: .85rem; color: #64748b;">ทั้งหมด <?= number_format($total) ?> รายการ</span>
    </div>

    <?php if (empty($logs)): ?>
        <p style="text-align: center; color: #94a3b8; padding: 40px 0;">ไม่พบข้อมูล</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width: 150px;">เวลา</th>
                    <th style="width: 120px;">ผู้ใช้</th>
                    <th style="width: 80px;">กิจกรรม</th>
                    <th style="width: 100px;">หมวด</th>
                    <th>รายละเอียด</th>
                    <th style="width: 110px;">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="font-size: .8rem; color: #64748b; white-space: nowrap;">
                        <?= date('d/m/Y H:i', strtotime($log['created_at'])) ?>
                    </td>
                    <td style="font-size: .82rem;">
                        <?= htmlspecialchars(($log['user_fname'] ?? '') . ' ' . ($log['user_lname'] ?? '')) ?>
                    </td>
                    <td>
                        <span style="display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: .72rem; font-weight: 700;
                                     background: <?= htmlspecialchars(audit_action_color($log['action'])) ?>18;
                                     color: <?= htmlspecialchars(audit_action_color($log['action'])) ?>;">
                            <?= htmlspecialchars(audit_action_label($log['action'])) ?>
                        </span>
                    </td>
                    <td style="font-size: .82rem;">
                        <?= htmlspecialchars(audit_entity_label($log['entity_type'])) ?>
                        <?php if ($log['entity_id']): ?>
                            <span style="color: #94a3b8;">#<?= (int)$log['entity_id'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: .82rem;"><?= htmlspecialchars($log['description']) ?></td>
                    <td style="font-size: .75rem; color: #94a3b8; font-family: monospace;"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; gap: 4px; margin-top: 16px;">
        <?php
        $qs = $_GET;
        for ($p = 1; $p <= $totalPages; $p++):
            $qs['page'] = $p;
            $href = '?' . http_build_query($qs);
        ?>
        <a href="<?= htmlspecialchars($href) ?>"
           style="padding: 6px 12px; border-radius: 6px; font-size: .82rem; text-decoration: none;
                  <?= $p === $page ? 'background: #1a3a5c; color: #fff;' : 'background: #f1f5f9; color: #475569;' ?>">
            <?= $p ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
