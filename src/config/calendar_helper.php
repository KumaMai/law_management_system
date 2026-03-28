<?php
// ============================================================
// calendar_helper.php — ปฏิทินรวมนัดหมาย
// ============================================================

/**
 * ดึง events ทั้งหมดในเดือนที่กำหนด (role-based)
 */
function cal_get_events(PDO $pdo, int $officeId, string $role, int $userId, int $year, int $month): array {
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate   = date('Y-m-t', strtotime($startDate));

    $events = [];

    // --- นัดยื่นฟ้อง (filings) ---
    $filingSql = "SELECT f.filing_id, f.scheduled_filing_date AS event_date, f.charge,
                         co.court_name, cr.request_id
                  FROM filings f
                  JOIN contracts ct ON f.contract_id = ct.contract_id
                  JOIN case_requests cr ON ct.request_id = cr.request_id
                  JOIN courts co ON f.court_id = co.court_id
                  WHERE cr.office_id = ?
                    AND f.scheduled_filing_date BETWEEN ? AND ?";
    $filingParams = [$officeId, $startDate, $endDate];

    if ($role === 'lawyer') {
        $lid = _cal_get_lawyer_id($pdo, $userId);
        if (!$lid) return [];
        $filingSql .= " AND cr.lawyer_id = ?";
        $filingParams[] = $lid;
    } elseif ($role === 'client') {
        $cid = _cal_get_client_id($pdo, $userId);
        if (!$cid) return [];
        $filingSql .= " AND cr.client_id = ?";
        $filingParams[] = $cid;
    }

    $stmt = $pdo->prepare($filingSql);
    $stmt->execute($filingParams);
    foreach ($stmt->fetchAll() as $r) {
        $events[] = [
            'date'  => $r['event_date'],
            'type'  => 'filing',
            'label' => 'นัดยื่นฟ้อง',
            'title' => $r['charge'] ?: 'ยื่นฟ้อง',
            'sub'   => $r['court_name'],
            'link'  => '/pages/filings.php',
            'color' => '#2563eb',
        ];
    }

    // --- นัดขึ้นศาล (court_hearings) ---
    $hearingSql = "SELECT ch.hearing_id, ch.hearing_date AS event_date, ch.hearing_time,
                          ch.court_room, ch.hearing_round, ch.case_number, ch.status,
                          co.court_name
                   FROM court_hearings ch
                   JOIN filings f ON ch.filing_id = f.filing_id
                   JOIN contracts ct ON f.contract_id = ct.contract_id
                   JOIN case_requests cr ON ct.request_id = cr.request_id
                   JOIN courts co ON f.court_id = co.court_id
                   WHERE cr.office_id = ?
                     AND ch.hearing_date BETWEEN ? AND ?
                     AND ch.status NOT IN ('cancelled')";
    $hearingParams = [$officeId, $startDate, $endDate];

    if ($role === 'lawyer') {
        $hearingSql .= " AND cr.lawyer_id = ?";
        $hearingParams[] = $lid;
    } elseif ($role === 'client') {
        $hearingSql .= " AND cr.client_id = ?";
        $hearingParams[] = $cid;
    }

    $stmt = $pdo->prepare($hearingSql);
    $stmt->execute($hearingParams);
    foreach ($stmt->fetchAll() as $r) {
        $time = $r['hearing_time'] ? substr($r['hearing_time'], 0, 5) . ' น.' : '';
        $events[] = [
            'date'  => $r['event_date'],
            'type'  => 'hearing',
            'label' => 'นัดขึ้นศาล',
            'title' => ($r['case_number'] ? 'คดี ' . $r['case_number'] : 'นัดขึ้นศาล') . ($r['hearing_round'] ? ' ครั้งที่ ' . $r['hearing_round'] : ''),
            'sub'   => $r['court_name'] . ($r['court_room'] ? ' ห้อง ' . $r['court_room'] : '') . ($time ? ' ' . $time : ''),
            'link'  => '/pages/hearings.php',
            'color' => '#d97706',
        ];
    }

    // --- นัดฟังคำพิพากษา (verdict_appointments) ---
    $vaSql = "SELECT va.appointment_id, va.scheduled_date AS event_date, va.scheduled_time,
                     va.note, va.status, co.court_name
              FROM verdict_appointments va
              JOIN filings f ON va.filing_id = f.filing_id
              JOIN contracts ct ON f.contract_id = ct.contract_id
              JOIN case_requests cr ON ct.request_id = cr.request_id
              JOIN courts co ON f.court_id = co.court_id
              WHERE cr.office_id = ?
                AND va.scheduled_date BETWEEN ? AND ?
                AND va.status NOT IN ('cancelled')";
    $vaParams = [$officeId, $startDate, $endDate];

    if ($role === 'lawyer') {
        $vaSql .= " AND cr.lawyer_id = ?";
        $vaParams[] = $lid;
    } elseif ($role === 'client') {
        $vaSql .= " AND cr.client_id = ?";
        $vaParams[] = $cid;
    }

    $stmt = $pdo->prepare($vaSql);
    $stmt->execute($vaParams);
    foreach ($stmt->fetchAll() as $r) {
        $time = $r['scheduled_time'] ? substr($r['scheduled_time'], 0, 5) . ' น.' : '';
        $events[] = [
            'date'  => $r['event_date'],
            'type'  => 'verdict_appointment',
            'label' => 'นัดพิพากษา',
            'title' => 'นัดฟังคำพิพากษา',
            'sub'   => $r['court_name'] . ($time ? ' ' . $time : ''),
            'link'  => '/pages/verdict_appointments.php',
            'color' => '#dc2626',
        ];
    }

    // sort by date
    usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $events;
}

/**
 * จัดกลุ่ม events ตามวันที่ (สำหรับวาด calendar grid)
 */
function cal_events_by_date(array $events): array {
    $grouped = [];
    foreach ($events as $ev) {
        $grouped[$ev['date']][] = $ev;
    }
    return $grouped;
}

/**
 * สร้าง grid ปฏิทินเดือน
 * return array of weeks, each week = array of 7 days (null = outside month)
 */
function cal_month_grid(int $year, int $month): array {
    $firstDay   = mktime(0, 0, 0, $month, 1, $year);
    $daysInMonth = (int)date('t', $firstDay);
    $startDow   = (int)date('w', $firstDay); // 0=Sun

    $grid = [];
    $week = array_fill(0, 7, null);
    $dayNum = 1;

    for ($i = $startDow; $dayNum <= $daysInMonth; $i++) {
        $week[$i % 7] = $dayNum;
        if (($i + 1) % 7 === 0 || $dayNum === $daysInMonth) {
            $grid[] = $week;
            $week = array_fill(0, 7, null);
        }
        $dayNum++;
    }

    return $grid;
}

function cal_type_label(string $type): string {
    $map = [
        'filing'              => 'นัดยื่นฟ้อง',
        'hearing'             => 'นัดขึ้นศาล',
        'verdict_appointment' => 'นัดพิพากษา',
    ];
    return $map[$type] ?? $type;
}

// --- internal helpers ---
function _cal_get_lawyer_id(PDO $pdo, int $userId): ?int {
    $stmt = $pdo->prepare("SELECT lawyer_id FROM lawyer_profiles WHERE user_id=?");
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : null;
}

function _cal_get_client_id(PDO $pdo, int $userId): ?int {
    $stmt = $pdo->prepare("SELECT client_id FROM client_profiles WHERE user_id=?");
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    return $v !== false ? (int)$v : null;
}
