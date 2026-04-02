<?php
/**
 * Search Helper — ระบบค้นหาสำหรับทุกหน้า
 * ใช้ร่วมกับ MySQL Views (v_cases, v_filings, v_hearings, v_clients, v_lawyers)
 */

/**
 * สร้าง SQL condition + params สำหรับ LIKE search
 *
 * @param string $keyword  คำค้นหา (จาก $_GET['search'])
 * @param array  $columns  คอลัมน์ที่จะค้นหา เช่น ['client_name','lawyer_name','case_detail']
 * @return array ['sql' => 'AND (...)', 'params' => [...]]
 */
function search_build_where(string $keyword, array $columns): array
{
    $keyword = trim($keyword);
    if ($keyword === '' || empty($columns)) {
        return ['sql' => '', 'params' => []];
    }

    $conditions = [];
    $params     = [];
    $like       = '%' . $keyword . '%';

    foreach ($columns as $col) {
        $conditions[] = "$col LIKE ?";
        $params[]     = $like;
    }

    return [
        'sql'    => 'AND (' . implode(' OR ', $conditions) . ')',
        'params' => $params,
    ];
}

/**
 * แสดงกล่องค้นหา (server-side GET form)
 *
 * @param string $value        ค่าปัจจุบันของ search
 * @param string $placeholder  ข้อความ placeholder
 * @param array  $extraParams  GET params อื่นที่ต้องรักษา เช่น ['contract_id'=>5]
 * @return string HTML
 */
function search_render_box(string $value, string $placeholder = 'ค้นหา...', array $extraParams = []): string
{
    $val = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $ph  = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');

    // Hidden inputs สำหรับ GET params ที่ต้องรักษา
    $hidden = '';
    foreach ($extraParams as $k => $v) {
        if ($k !== 'search' && $v !== '' && $v !== null) {
            $hidden .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
        }
    }

    // Clear URL (ไม่มี search param)
    $clearParams = array_diff_key($extraParams, ['search' => 1]);
    $clearUrl    = '?' . http_build_query($clearParams);
    $clearBtn    = '';
    if ($value !== '') {
        $clearBtn = '<a href="' . htmlspecialchars($clearUrl) . '" '
                  . 'style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;text-decoration:none;font-size:1.1rem;line-height:1;" '
                  . 'title="ล้างค้นหา">✕</a>';
    }

    return <<<HTML
<form method="GET" style="display:inline-block;vertical-align:middle;">
    {$hidden}
    <div style="position:relative;min-width:280px;max-width:400px;display:inline-block;">
        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none;font-size:.9rem;">🔍</span>
        <input type="text" name="search" value="{$val}"
               placeholder="{$ph}"
               style="width:100%;padding:8px 32px 8px 34px;border:1px solid #e2e8f0;border-radius:8px;font-size:.87rem;outline:none;transition:border-color .2s;"
               onfocus="this.style.borderColor='#2d5a8a'" onblur="this.style.borderColor='#e2e8f0'">
        {$clearBtn}
    </div>
</form>
HTML;
}

/**
 * แสดง badge จำนวนผลลัพธ์
 */
function search_result_badge(string $keyword, int $count, int $total): string
{
    if ($keyword === '') return '';
    return '<span style="display:inline-block;margin-left:10px;padding:3px 12px;background:#eff6ff;color:#1d4ed8;border-radius:20px;font-size:.78rem;font-weight:600;vertical-align:middle;">'
         . "พบ {$count} จาก {$total} รายการ"
         . '</span>';
}
