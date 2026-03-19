<?php
// reports.php — Admin: รายงานและสถิติสำนักงาน
session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/csrf_helper.php';
requireRole('admin');

$pdo      = getDB();
$officeId = $_SESSION['office_id'];

// ==============================
// Export CSV
// ==============================
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report_'.$type.'_'.date('Ymd').'.csv"');
    echo "\xEF\xBB\xBF"; // BOM for Thai Excel

    if ($type === 'payments') {
        echo "รหัสสัญญา,ลูกความ,ทนาย,วิธีชำระ,จำนวน (บาท),วันที่,สถานะ\n";
        $rows = $pdo->prepare("
            SELECT p.contract_id, CONCAT(cp.fname,' ',cp.lname) AS client,
                   CONCAT(lp.fname,' ',lp.lname) AS lawyer,
                   p.payment_method, p.amount, p.payment_date, p.status
            FROM payments p
            JOIN contracts c ON p.contract_id=c.contract_id
            JOIN case_requests cr ON c.request_id=cr.request_id
            JOIN client_profiles cp ON cr.client_id=cp.client_id
            JOIN lawyer_profiles lp ON cr.lawyer_id=lp.lawyer_id
            WHERE cr.office_id=? ORDER BY p.payment_date DESC
        ");
        $rows->execute([$officeId]);
        foreach ($rows->fetchAll() as $r)
            echo implode(',', array_map(fn($v) => '"'.str_replace('"','""',$v).'"', $r))."\n";
    } elseif ($type === 'cases') {
        echo "รหัสคำขอ,ลูกความ,ทนาย,วันที่ส่ง,สถานะ,ค่าธรรมเนียม (บาท),สถานะสัญญา\n";
        $rows = $pdo->prepare("
            SELECT cr.request_id, CONCAT(cp.fname,' ',cp.lname) AS client,
                   CONCAT(lp.fname,' ',lp.lname) AS lawyer,
                   cr.request_date, cr.status,
                   c.fee_amount, c.status AS contract_status
            FROM case_requests cr
            JOIN client_profiles cp ON cr.client_id=cp.client_id
            JOIN lawyer_profiles lp ON cr.lawyer_id=lp.lawyer_id
            LEFT JOIN contracts c ON c.request_id=cr.request_id
            WHERE cr.office_id=? ORDER BY cr.request_date DESC
        ");
        $rows->execute([$officeId]);
        foreach ($rows->fetchAll() as $r)
            echo implode(',', array_map(fn($v) => '"'.str_replace('"','""',(string)($v??'')).'"', $r))."\n";
    } elseif ($type === 'lawyers') {
        echo "ทนาย,ใบอนุญาต,คดีที่รับ,คดีสำเร็จ,คดีกำลังดำเนินการ,รายได้รวม (บาท),คะแนนเฉลี่ย\n";
        $rows = $pdo->prepare("
            SELECT CONCAT(lp.fname,' ',lp.lname) AS lawyer, lp.license_no,
                   COUNT(DISTINCT cr.request_id) AS total_cases,
                   SUM(CASE WHEN con.status='completed' THEN 1 ELSE 0 END) AS done,
                   SUM(CASE WHEN con.status='active' THEN 1 ELSE 0 END) AS active,
                   COALESCE(SUM(CASE WHEN p.status='confirmed' THEN p.amount ELSE 0 END),0) AS revenue,
                   ROUND(COALESCE(AVG(lr.rating),0),1) AS avg_rating
            FROM lawyer_profiles lp
            JOIN users u ON lp.user_id=u.user_id
            LEFT JOIN case_requests cr ON cr.lawyer_id=lp.lawyer_id AND cr.office_id=?
            LEFT JOIN contracts con ON con.request_id=cr.request_id
            LEFT JOIN payments p ON p.contract_id=con.contract_id
            LEFT JOIN lawyer_reviews lr ON lr.lawyer_id=lp.lawyer_id
            WHERE u.office_id=?
            GROUP BY lp.lawyer_id
        ");
        $rows->execute([$officeId, $officeId]);
        foreach ($rows->fetchAll() as $r)
            echo implode(',', array_map(fn($v) => '"'.str_replace('"','""',(string)($v??'')).'"', $r))."\n";
    }
    exit;
}

// ==============================
// KPI Stats
// ==============================
$stats = [];

// คดีทั้งหมด
$r = $pdo->prepare("SELECT COUNT(*) FROM case_requests WHERE office_id=?");
$r->execute([$officeId]); $stats['total_cases'] = (int)$r->fetchColumn();

// คดีสำเร็จ
$r = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN case_requests cr ON c.request_id=cr.request_id WHERE cr.office_id=? AND c.status='completed'");
$r->execute([$officeId]); $stats['completed'] = (int)$r->fetchColumn();

// คดีกำลังดำเนินการ
$r = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN case_requests cr ON c.request_id=cr.request_id WHERE cr.office_id=? AND c.status='active'");
$r->execute([$officeId]); $stats['active'] = (int)$r->fetchColumn();

// รายรับทั้งหมด (confirmed payments)
$r = $pdo->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN contracts c ON p.contract_id=c.contract_id JOIN case_requests cr ON c.request_id=cr.request_id WHERE cr.office_id=? AND p.status='confirmed'");
$r->execute([$officeId]); $stats['revenue'] = (float)$r->fetchColumn();

// ยอดค้างชำระ (active contracts ยังไม่จ่ายครบ)
$r = $pdo->prepare("SELECT COALESCE(SUM(c.fee_amount - COALESCE(paid.total,0)),0) FROM contracts c JOIN case_requests cr ON c.request_id=cr.request_id LEFT JOIN (SELECT contract_id, SUM(amount) AS total FROM payments WHERE status='confirmed' GROUP BY contract_id) paid ON paid.contract_id=c.contract_id WHERE cr.office_id=? AND c.status='active' AND c.fee_amount IS NOT NULL");
$r->execute([$officeId]); $stats['outstanding'] = max(0, (float)$r->fetchColumn());

// ทนายทั้งหมด
$r = $pdo->prepare("SELECT COUNT(*) FROM lawyer_profiles lp JOIN users u ON lp.user_id=u.user_id WHERE u.office_id=? AND lp.status='active'");
$r->execute([$officeId]); $stats['lawyers'] = (int)$r->fetchColumn();

// อัตราสำเร็จ
$stats['success_rate'] = $stats['total_cases'] > 0 ? round($stats['completed'] / $stats['total_cases'] * 100, 1) : 0;

// ==============================
// คดีรายเดือน 12 เดือนล่าสุด
// ==============================
$monthlyStmt = $pdo->prepare("
    SELECT DATE_FORMAT(cr.request_date,'%Y-%m') AS ym,
           COUNT(*) AS cnt,
           COALESCE(SUM(CASE WHEN p.status='confirmed' THEN p.amount ELSE 0 END),0) AS revenue
    FROM case_requests cr
    LEFT JOIN contracts c ON c.request_id=cr.request_id
    LEFT JOIN payments p ON p.contract_id=c.contract_id
    WHERE cr.office_id=?
      AND cr.request_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym ORDER BY ym ASC
");
$monthlyStmt->execute([$officeId]);
$monthlyRaw = $monthlyStmt->fetchAll();

// fill เดือนที่ไม่มีข้อมูล
$monthly = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $monthly[$ym] = ['ym'=>$ym,'cnt'=>0,'revenue'=>0];
}
foreach ($monthlyRaw as $row) {
    if (isset($monthly[$row['ym']])) {
        $monthly[$row['ym']]['cnt']     = (int)$row['cnt'];
        $monthly[$row['ym']]['revenue'] = (float)$row['revenue'];
    }
}
$monthly = array_values($monthly);

// ==============================
// Top Lawyers
// ==============================
$topLawyers = $pdo->prepare("
    SELECT CONCAT(lp.fname,' ',lp.lname) AS name,
           lp.specialization,
           COUNT(DISTINCT cr.request_id) AS total,
           SUM(CASE WHEN con.status='completed' THEN 1 ELSE 0 END) AS done,
           COALESCE(SUM(CASE WHEN p.status='confirmed' THEN p.amount ELSE 0 END),0) AS revenue,
           ROUND(COALESCE(AVG(lr.rating),0),1) AS avg_rating,
           COUNT(DISTINCT lr.review_id) AS review_count
    FROM lawyer_profiles lp
    JOIN users u ON lp.user_id=u.user_id
    LEFT JOIN case_requests cr ON cr.lawyer_id=lp.lawyer_id AND cr.office_id=?
    LEFT JOIN contracts con ON con.request_id=cr.request_id
    LEFT JOIN payments p ON p.contract_id=con.contract_id
    LEFT JOIN lawyer_reviews lr ON lr.lawyer_id=lp.lawyer_id
    WHERE u.office_id=?
    GROUP BY lp.lawyer_id
    ORDER BY revenue DESC, total DESC
    LIMIT 10
");
$topLawyers->execute([$officeId, $officeId]);
$topLawyers = $topLawyers->fetchAll();

// ==============================
// ค้างชำระ (Active contracts ที่เกิน 30 วัน)
// ==============================
$overduePayments = $pdo->prepare("
    SELECT c.contract_id, c.fee_amount, c.contract_date, c.payment_status,
           CONCAT(cp.fname,' ',cp.lname) AS client_name,
           CONCAT(lp.fname,' ',lp.lname) AS lawyer_name,
           COALESCE(paid.total,0) AS total_paid,
           DATEDIFF(CURDATE(), c.contract_date) AS days_since
    FROM contracts c
    JOIN case_requests cr ON c.request_id=cr.request_id
    JOIN client_profiles cp ON cr.client_id=cp.client_id
    JOIN lawyer_profiles lp ON cr.lawyer_id=lp.lawyer_id
    LEFT JOIN (SELECT contract_id, SUM(amount) AS total FROM payments WHERE status='confirmed' GROUP BY contract_id) paid
        ON paid.contract_id=c.contract_id
    WHERE cr.office_id=? AND c.status='active'
      AND c.payment_status != 'paid'
      AND c.fee_amount IS NOT NULL
      AND c.fee_amount > COALESCE(paid.total,0)
      AND DATEDIFF(CURDATE(), c.contract_date) > 30
    ORDER BY days_since DESC
    LIMIT 20
");
$overduePayments->execute([$officeId]);
$overduePayments = $overduePayments->fetchAll();

// ==============================
// สถิติ payment methods
// ==============================
$payMethods = $pdo->prepare("
    SELECT payment_method, COUNT(*) AS cnt, SUM(amount) AS total
    FROM payments p
    JOIN contracts c ON p.contract_id=c.contract_id
    JOIN case_requests cr ON c.request_id=cr.request_id
    WHERE cr.office_id=? AND p.status='confirmed'
    GROUP BY payment_method
");
$payMethods->execute([$officeId]);
$payMethods = $payMethods->fetchAll();

$pageTitle = 'รายงานและสถิติ';
include '../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
:root{--navy:#1a3a5c;--navy2:#2d5a8a;--border:#e2e8f0;--muted:#94a3b8;}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:22px;}
.kpi{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px 18px;}
.kpi-num{font-size:1.9rem;font-weight:900;line-height:1;margin-bottom:4px;}
.kpi-lbl{font-size:.75rem;color:var(--muted);}
.section-title{font-size:.75rem;font-weight:600;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin:0 0 12px;}
.report-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:18px;}
.export-btn{padding:7px 16px;border-radius:8px;font-size:.8rem;font-weight:700;border:none;cursor:pointer;background:#f1f5f9;color:#374151;transition:.15s;}
.export-btn:hover{background:#1a3a5c;color:#fff;}
table{width:100%;border-collapse:collapse;}
th{text-align:left;font-size:.77rem;color:var(--muted);font-weight:600;padding:8px 10px;border-bottom:2px solid var(--border);}
td{padding:9px 10px;font-size:.85rem;border-bottom:1px solid #f1f5f9;vertical-align:top;}
tr:last-child td{border-bottom:none;}
.bar-row{display:flex;align-items:center;gap:10px;margin-bottom:6px;}
.bar-label{width:120px;font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#374151;}
.bar-track{flex:1;background:#f1f5f9;border-radius:4px;height:10px;overflow:hidden;}
.bar-fill{height:10px;border-radius:4px;background:linear-gradient(90deg,#1a3a5c,#2d5a8a);transition:width .6s;}
.bar-val{font-size:.78rem;color:var(--muted);min-width:60px;text-align:right;}
.star{color:#f59e0b;}
.badge-m{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:700;}
.badge-paid{background:#d1fae5;color:#065f46;}
.badge-partial{background:#fef3c7;color:#92400e;}
.badge-pending{background:#fee2e2;color:#991b1b;}
.chart-wrap{position:relative;height:220px;}
</style>

<!-- Header -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:0;">📊 รายงานและสถิติ</h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="?export=cases"   class="export-btn">📥 Export คดี</a>
            <a href="?export=payments" class="export-btn">📥 Export การชำระ</a>
            <a href="?export=lawyers"  class="export-btn">📥 Export ทนาย</a>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi">
            <div class="kpi-num" style="color:#1a3a5c;"><?= number_format($stats['total_cases']) ?></div>
            <div class="kpi-lbl">คดีทั้งหมด</div>
        </div>
        <div class="kpi">
            <div class="kpi-num" style="color:#059669;"><?= number_format($stats['completed']) ?></div>
            <div class="kpi-lbl">คดีสำเร็จแล้ว</div>
        </div>
        <div class="kpi">
            <div class="kpi-num" style="color:#2563eb;"><?= number_format($stats['active']) ?></div>
            <div class="kpi-lbl">กำลังดำเนินการ</div>
        </div>
        <div class="kpi">
            <div class="kpi-num" style="color:#059669;font-size:1.5rem;">
                ฿<?= number_format($stats['revenue'], 0) ?>
            </div>
            <div class="kpi-lbl">รายรับรวม</div>
        </div>
        <div class="kpi">
            <div class="kpi-num" style="color:#dc2626;font-size:1.5rem;">
                ฿<?= number_format($stats['outstanding'], 0) ?>
            </div>
            <div class="kpi-lbl">ยอดค้างชำระ</div>
        </div>
        <div class="kpi">
            <div class="kpi-num" style="color:#7c3aed;"><?= $stats['success_rate'] ?>%</div>
            <div class="kpi-lbl">อัตราสำเร็จ</div>
        </div>
        <div class="kpi">
            <div class="kpi-num" style="color:#0891b2;"><?= $stats['lawyers'] ?></div>
            <div class="kpi-lbl">ทนายความ active</div>
        </div>
    </div>

    <!-- Charts Row -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:18px;">

        <!-- Bar Chart: คดีรายเดือน -->
        <div class="report-card" style="padding:18px;">
            <div class="section-title">คดีและรายรับ 12 เดือนล่าสุด</div>
            <div class="chart-wrap"><canvas id="monthlyChart"></canvas></div>
        </div>

        <!-- Payment Methods Doughnut -->
        <div class="report-card" style="padding:18px;">
            <div class="section-title">สัดส่วนวิธีชำระเงิน</div>
            <?php if (empty($payMethods)): ?>
            <p style="color:#94a3b8;font-size:.85rem;padding:20px 0;">ยังไม่มีข้อมูลการชำระ</p>
            <?php else: ?>
            <div class="chart-wrap" style="height:170px;"><canvas id="payChart"></canvas></div>
            <div style="margin-top:10px;">
                <?php
                $methodTH = ['cash'=>'เงินสด','qr'=>'QR Code','transfer'=>'โอนเงิน'];
                foreach ($payMethods as $pm):
                ?>
                <div style="display:flex;justify-content:space-between;font-size:.79rem;margin-bottom:4px;">
                    <span><?= $methodTH[$pm['payment_method']] ?? $pm['payment_method'] ?> (<?= $pm['cnt'] ?> รายการ)</span>
                    <span style="font-weight:700;color:#1a3a5c;">฿<?= number_format($pm['total'],0) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Lawyers -->
    <div class="report-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <div class="section-title" style="margin:0;">ทนายความ — ผลงาน</div>
            <a href="?export=lawyers" class="export-btn">📥 CSV</a>
        </div>
        <?php if (empty($topLawyers)): ?>
        <p style="color:#94a3b8;">ยังไม่มีข้อมูล</p>
        <?php else: ?>

        <!-- Bar chart ทนาย -->
        <?php
        $maxRev = max(array_column($topLawyers, 'revenue') ?: [1]);
        foreach ($topLawyers as $l):
            $pct = $maxRev > 0 ? round($l['revenue'] / $maxRev * 100) : 0;
            $stars = str_repeat('★', (int)round($l['avg_rating'])) . str_repeat('☆', 5-(int)round($l['avg_rating']));
        ?>
        <div class="bar-row">
            <div class="bar-label" title="<?= htmlspecialchars($l['name']) ?>">
                <?= htmlspecialchars(mb_substr($l['name'],0,14)) ?>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
            <div class="bar-val">฿<?= number_format($l['revenue'],0) ?></div>
            <div style="font-size:.75rem;min-width:90px;color:#94a3b8;">
                <?= $l['total'] ?> คดี · <?= $l['done'] ?> สำเร็จ
            </div>
            <?php if ($l['avg_rating'] > 0): ?>
            <div style="font-size:.75rem;color:#f59e0b;min-width:70px;">
                <?= $l['avg_rating'] ?>/5 (<?= $l['review_count'] ?>)
            </div>
            <?php else: ?>
            <div style="min-width:70px;"></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Overdue Payments -->
    <?php if (!empty($overduePayments)): ?>
    <div class="report-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <div class="section-title" style="margin:0;">⚠️ ค้างชำระเกิน 30 วัน (<?= count($overduePayments) ?> รายการ)</div>
        </div>
        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>ลูกความ</th><th>ทนาย</th><th>ค่าธรรมเนียม</th><th>ชำระแล้ว</th><th>ค้างอยู่</th><th>วันทำสัญญา</th><th>เลยมาแล้ว</th><th>สถานะ</th></tr>
            </thead>
            <tbody>
            <?php foreach ($overduePayments as $op):
                $owe = max(0, (float)$op['fee_amount'] - (float)$op['total_paid']);
            ?>
            <tr>
                <td><?= $op['contract_id'] ?></td>
                <td><?= htmlspecialchars($op['client_name']) ?></td>
                <td><?= htmlspecialchars($op['lawyer_name']) ?></td>
                <td>฿<?= number_format($op['fee_amount'],0) ?></td>
                <td style="color:#059669;">฿<?= number_format($op['total_paid'],0) ?></td>
                <td style="color:#dc2626;font-weight:700;">฿<?= number_format($owe,0) ?></td>
                <td><?= $op['contract_date'] ?></td>
                <td><span style="color:#dc2626;font-weight:700;"><?= $op['days_since'] ?> วัน</span></td>
                <td>
                    <span class="badge-m badge-<?= $op['payment_status'] ?>">
                        <?= ['pending'=>'ยังไม่ชำระ','partial'=>'ชำระบางส่วน','paid'=>'ชำระแล้ว'][$op['payment_status']] ?? $op['payment_status'] ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const monthlyData = <?= json_encode($monthly, JSON_UNESCAPED_UNICODE) ?>;
const payData     = <?= json_encode($payMethods, JSON_UNESCAPED_UNICODE) ?>;

// Monthly chart
const mLabels  = monthlyData.map(d => d.ym.slice(2).replace('-','/'));
const mCases   = monthlyData.map(d => d.cnt);
const mRevenue = monthlyData.map(d => d.revenue);

new Chart(document.getElementById('monthlyChart'), {
    data: {
        labels: mLabels,
        datasets: [
            { type:'bar',  label:'จำนวนคดี',   data: mCases,   backgroundColor:'rgba(26,58,92,.7)',   yAxisID:'y'  },
            { type:'line', label:'รายรับ (฿)',  data: mRevenue, borderColor:'#059669', backgroundColor:'rgba(5,150,105,.08)', yAxisID:'y2', tension:.35, fill:true, pointRadius:3 },
        ]
    },
    options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ labels:{ font:{size:11}, boxWidth:12 } } },
        scales:{
            y:  { beginAtZero:true, ticks:{font:{size:10}}, grid:{color:'#f1f5f9'} },
            y2: { beginAtZero:true, position:'right', ticks:{font:{size:10}, callback: v=>'฿'+v.toLocaleString()}, grid:{display:false} }
        }
    }
});

// Payment doughnut
if (payData.length) {
    const methodTH = {cash:'เงินสด', qr:'QR Code', transfer:'โอนเงิน'};
    new Chart(document.getElementById('payChart'), {
        type: 'doughnut',
        data: {
            labels: payData.map(d => methodTH[d.payment_method] || d.payment_method),
            datasets:[{ data: payData.map(d=>d.total), backgroundColor:['#1a3a5c','#2d5a8a','#7c3aed','#059669'], borderWidth:2 }]
        },
        options: {
            responsive:true, maintainAspectRatio:false, cutout:'60%',
            plugins:{ legend:{ display:false } }
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>