<?php
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/calendar_helper.php';
requireLogin();

$pdo      = getDB();
$role     = $_SESSION['role'];
$userId   = $_SESSION['user_id'];
$officeId = $_SESSION['office_id'];

// เดือน/ปี ที่แสดง
$year  = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('n'));

// clamp
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$events   = cal_get_events($pdo, $officeId, $role, $userId, $year, $month);
$byDate   = cal_events_by_date($events);
$grid     = cal_month_grid($year, $month);

$prevM = $month - 1; $prevY = $year;
if ($prevM < 1) { $prevM = 12; $prevY--; }
$nextM = $month + 1; $nextY = $year;
if ($nextM > 12) { $nextM = 1; $nextY++; }

$thaiMonths = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
$thaiDays   = ['อา','จ','อ','พ','พฤ','ศ','ส'];

$today     = date('Y-m-d');
$todayDay  = (int)date('j');
$todayM    = (int)date('n');
$todayY    = (int)date('Y');

$pageTitle = 'ปฏิทินนัดหมาย';
include '../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
    <h2 style="color: #1a3a5c;">📅 ปฏิทินนัดหมาย</h2>
    <div style="display: flex; gap: 8px; align-items: center;">
        <a href="?y=<?= $prevY ?>&m=<?= $prevM ?>" class="btn btn-sm" style="background: #e2e8f0; color: #475569;">◀ ก่อนหน้า</a>
        <span style="font-weight: 700; font-size: 1rem; min-width: 160px; text-align: center;">
            <?= $thaiMonths[$month] ?> <?= $year + 543 ?>
        </span>
        <a href="?y=<?= $nextY ?>&m=<?= $nextM ?>" class="btn btn-sm" style="background: #e2e8f0; color: #475569;">ถัดไป ▶</a>
        <?php if ($month !== $todayM || $year !== $todayY): ?>
        <a href="?y=<?= $todayY ?>&m=<?= $todayM ?>" class="btn btn-sm btn-primary">วันนี้</a>
        <?php endif; ?>
    </div>
</div>

<!-- Legend -->
<div style="display: flex; gap: 16px; margin-bottom: 14px; font-size: .8rem; flex-wrap: wrap;">
    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#2563eb;margin-right:4px;"></span> นัดยื่นฟ้อง</span>
    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#d97706;margin-right:4px;"></span> นัดขึ้นศาล</span>
    <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#dc2626;margin-right:4px;"></span> นัดพิพากษา</span>
</div>

<!-- Calendar Grid -->
<div class="card" style="padding: 0; overflow: hidden;">
    <table class="cal-table">
        <thead>
            <tr>
                <?php foreach ($thaiDays as $d): ?>
                <th class="cal-th"><?= $d ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grid as $week): ?>
            <tr>
                <?php foreach ($week as $dayNum):
                    $dateStr = $dayNum ? sprintf('%04d-%02d-%02d', $year, $month, $dayNum) : '';
                    $isToday = ($dayNum && $dateStr === $today);
                    $dayEvents = $dayNum ? ($byDate[$dateStr] ?? []) : [];
                ?>
                <td class="cal-cell <?= $isToday ? 'cal-today' : '' ?> <?= !$dayNum ? 'cal-empty' : '' ?>">
                    <?php if ($dayNum): ?>
                    <div class="cal-day-num <?= $isToday ? 'cal-day-today' : '' ?>"><?= $dayNum ?></div>
                    <?php foreach (array_slice($dayEvents, 0, 3) as $ev): ?>
                    <div class="cal-event" style="border-left: 3px solid <?= htmlspecialchars($ev['color']) ?>;"
                         title="<?= htmlspecialchars($ev['title'] . ' — ' . $ev['sub']) ?>">
                        <a href="<?= htmlspecialchars($ev['link']) ?>" class="cal-event-link">
                            <span class="cal-event-label"><?= htmlspecialchars($ev['label']) ?></span>
                            <span class="cal-event-title"><?= htmlspecialchars($ev['title']) ?></span>
                        </a>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($dayEvents) > 3): ?>
                    <div class="cal-more">+<?= count($dayEvents) - 3 ?> อื่นๆ</div>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Event List (below calendar) -->
<?php if (!empty($events)): ?>
<div class="card" style="margin-top: 18px;">
    <h3 style="font-size: 1rem; color: #1a3a5c; margin-bottom: 12px;">📋 รายการนัดหมายเดือนนี้ (<?= count($events) ?> รายการ)</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>วันที่</th>
                    <th>ประเภท</th>
                    <th>รายละเอียด</th>
                    <th>สถานที่</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $ev): ?>
                <tr>
                    <td style="white-space: nowrap;">
                        <?= date('d/m/Y', strtotime($ev['date'])) ?>
                        <?php if ($ev['date'] === $today): ?>
                        <span class="badge badge-active" style="font-size: .65rem;">วันนี้</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: .72rem; font-weight: 700;
                                     background: <?= htmlspecialchars($ev['color']) ?>18; color: <?= htmlspecialchars($ev['color']) ?>;">
                            <?= htmlspecialchars($ev['label']) ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?= htmlspecialchars($ev['link']) ?>" style="color: #1a3a5c; font-weight: 600;">
                            <?= htmlspecialchars($ev['title']) ?>
                        </a>
                    </td>
                    <td style="font-size: .82rem; color: #64748b;"><?= htmlspecialchars($ev['sub']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
