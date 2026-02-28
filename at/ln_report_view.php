<?php
require_once "auth_check.php";

// --- Получаем логин из GET-параметра ---
$clientLogin = $_GET['client'] ?? '';
if (!preg_match('/^[\w\-.]+$/u', $clientLogin)) die("Некорректный логин клиента!");

// --- Получаем имя клиента из списка ---
$clients_file = __DIR__ . "/clients_ln_active.json";
if (!file_exists($clients_file)) die("Нет файла clients_ln_active.json!");
$clients = json_decode(file_get_contents($clients_file), true);

$clientName = null;
foreach ($clients as $c) {
    if ($c['login'] === $clientLogin) {
        $clientName = $c['name'];
        break;
    }
}
if (!$clientName) die("Клиент с логином $clientLogin не найден!");

// --- Работаем с лимитами ---
$budgets_file = __DIR__ . "/ln_budgets.json";
if (!file_exists($budgets_file)) file_put_contents($budgets_file, "{}");
$budgets = json_decode(file_get_contents($budgets_file), true);

// --- Ручные недельные лимиты ---
$manual_week_file = __DIR__ . "/manual_week_limits.json";
if (!file_exists($manual_week_file)) file_put_contents($manual_week_file, "{}");
$manual_week_limits = json_decode(file_get_contents($manual_week_file), true);

// --- Работаем с кампаниями на исходе бюджета ---
$stop_file = __DIR__ . "/ln_stop_by_budgets.json";
if (!file_exists($stop_file)) file_put_contents($stop_file, "[]");
$stop_by_budgets = json_decode(file_get_contents($stop_file), true);
if (!is_array($stop_by_budgets)) $stop_by_budgets = [];

// --- POST: сохранение лимитов ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_limit'], $_POST['cid'])) {
    $cid = $_POST['cid'];
    $val = intval($_POST['save_limit']);
    $budgets[$cid] = $val;
    file_put_contents($budgets_file, json_encode($budgets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    header("Location: ".$_SERVER['REQUEST_URI']); exit;
}

// --- Сохранение недельного лимита через AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['campaign_id'], $_POST['week_limit'])) {
    $cid = $_POST['campaign_id'];
    $week_limit = max(0, intval($_POST['week_limit']));
    if ($week_limit == 0) {
        unset($manual_week_limits[$cid]);
    } else {
        $manual_week_limits[$cid] = $week_limit;
    }
    file_put_contents($manual_week_file, json_encode($manual_week_limits, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'week_limit'=>$week_limit,'day_limit'=>round($week_limit/7)]);
    exit;
}

// --- Получение токена Яндекса из файла ---
$token_file = __DIR__ . '/../ya_access_token.txt';
if (!file_exists($token_file)) die('Нет файла токена!');
$access_token = trim(file_get_contents($token_file));
if (!$access_token) die('Пустой токен!');

// --- POST: смена статуса кампании ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'], $_POST['cid'], $_POST['login'])) {
    $cid = $_POST['cid'];
    $login = $_POST['login'];
    $action = $_POST['change_status'];
    change_campaign_status($access_token, $login, $cid, $action);
    header("Location: ".$_SERVER['REQUEST_URI']); exit;
}

// --- Функции API ---
function change_campaign_status($access_token, $client_login, $campaign_id, $action) {
    $url = 'https://api.direct.yandex.com/json/v5/campaigns';
    $headers = [
        "Authorization: Bearer $access_token",
        "Accept-Language: ru",
        "Content-Type: application/json; charset=utf-8",
        "Client-Login: $client_login"
    ];
    $body = [
        "method" => $action,
        "params" => [
            "SelectionCriteria" => [
                "Ids" => [$campaign_id]
            ]
        ]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function get_all_campaigns_ids($access_token, $client_login) {
    $url = 'https://api.direct.yandex.com/json/v5/campaigns';
    $headers = [
        "Authorization: Bearer $access_token",
        "Accept-Language: ru",
        "Content-Type: application/json; charset=utf-8",
        "Client-Login: $client_login"
    ];
    $body = [
        'method' => 'get',
        'params' => [
            'SelectionCriteria' => (object)[],
            'FieldNames' => ['Id', 'Name', 'State']
        ]
    ];
    $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    $ids = [];
    if (!empty($data['result']['Campaigns']) && is_array($data['result']['Campaigns'])) {
        foreach ($data['result']['Campaigns'] as $camp) {
            $ids[] = $camp['Id'];
        }
    }
    return $ids;
}

function get_campaigns_daily_spend($access_token, $client_login, $ids) {
    if (empty($ids)) return [];
    $url = 'https://api.direct.yandex.com/json/v5/reports';
    $headers = [
        "Authorization: Bearer $access_token",
        "Client-Login: $client_login",
        "Accept-Language: ru",
        "processingMode: auto",
        "returnMoneyInMicros: false",
        "skipReportHeader: true",
        "skipReportSummary: true",
        "Content-Type: application/json; charset=utf-8"
    ];
    $body = json_encode([
        "params" => [
            "SelectionCriteria" => [
                "Filter" => [
                    [
                        "Field" => "CampaignId",
                        "Operator" => "IN",
                        "Values" => $ids,
                    ]
                ]
            ],
            "FieldNames" => ["CampaignId", "Cost"],
            "ReportName" => "DailySpend_" . time(),
            "ReportType" => "CAMPAIGN_PERFORMANCE_REPORT",
            "DateRangeType" => "TODAY",
            "Format" => "TSV",
            "IncludeVAT" => "NO",
            "IncludeDiscount" => "NO"
        ]
    ]);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $result = curl_exec($ch);
    curl_close($ch);

    $lines = explode("\n", trim($result));
    $day_spent = [];
    foreach ($lines as $line) {
        if (!$line) continue;
        if (strpos($line, "\t") === false) continue;
        list($cid, $cost) = explode("\t", $line);
        $day_spent[$cid] = round((float)$cost, 2);
    }
    return $day_spent;
}

function get_campaigns_details_by_ids($access_token, $client_login, $ids) {
    if (empty($ids)) return [];
    $url = 'https://api.direct.yandex.com/json/v5/campaigns';
    $headers = [
        "Authorization: Bearer $access_token",
        "Accept-Language: ru",
        "Content-Type: application/json; charset=utf-8",
        "Client-Login: $client_login"
    ];
    $body = [
        'method' => 'get',
        'params' => [
            'SelectionCriteria' => [
                'Ids' => $ids
            ],
            'FieldNames' => ['Id', 'Name', 'State', 'Status', 'Type', 'Funds', 'DailyBudget', 'StartDate'],
            'TextCampaignFieldNames' => ["BiddingStrategy", "Settings"]
        ]
    ];
    $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['result']['Campaigns'] ?? [];
}

function state_icon($state) {
    $state = strtoupper($state);
    if ($state === 'ON') {
        return '<span style="color:green;font-size:1.2em;" title="Активна">&#9679;</span>';
    } elseif ($state === 'OFF') {
        return '<span style="color:#bb2c2c;font-size:1.2em;" title="Остановлена">&#9679;</span>';
    } elseif ($state === 'SUSPENDED') {
        return '<span style="color:orange;font-size:1.2em;" title="Приостановлена">&#9679;</span>';
    } elseif ($state === 'ARCHIVED') {
        return '<span style="color:gray;font-size:1.2em;" title="Архив/Снята">&#9679;</span>';
    } else {
        return '<span style="color:gray;font-size:1.2em;" title="' . htmlspecialchars($state) . '">&#9679;</span>';
    }
}

// --- Получаем кампании, расходы, лимиты ---
$all_ids = get_all_campaigns_ids($access_token, $clientLogin);
$campaigns = get_campaigns_details_by_ids($access_token, $clientLogin, $all_ids);
$spend_today = get_campaigns_daily_spend($access_token, $clientLogin, $all_ids);

// -------- Сортируем кампании по статусу ---------
$state_order = ['ON'=>0, 'OFF'=>1, 'SUSPENDED'=>2, 'ARCHIVED'=>3];
usort($campaigns, function($a, $b) use ($state_order) {
    $sa = strtoupper($a['State'] ?? '');
    $sb = strtoupper($b['State'] ?? '');
    $oa = isset($state_order[$sa]) ? $state_order[$sa] : 10;
    $ob = isset($state_order[$sb]) ? $state_order[$sb] : 10;
    return $oa <=> $ob;
});
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Отчет по клиенту <?=htmlspecialchars($clientName)?> (<?=htmlspecialchars($clientLogin)?>)</title>
    <link rel="stylesheet" href="ln_report.css?v=1">
    <style>
    .archived-campaign { opacity: 0.5; }
    .total-row { font-weight: bold; background: #f8f8fb; }
    .sort-header { cursor: pointer; color: #7b288f; text-decoration: underline; }
    .sort-header:hover { color: #000; }
    .btn-quick-add { margin-left: 4px; padding: 2px 6px; font-size: 0.85em; cursor: pointer; background: #f0f0f5; border: 1px solid #ccc; border-radius: 3px; color: #333; }
    .btn-quick-add:hover { background: #e0e0f0; border-color: #999; }
    /* Стиль для чекбоксов */
    .row-selector { width: 18px; height: 18px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="top-row">
        <h2>Отчет по клиенту: <?=htmlspecialchars($clientName)?> (<?=htmlspecialchars($clientLogin)?>)</h2>
        <a href="ln_report.php" class="get-btn">← К списку клиентов</a>
        <a href="https://direct.yandex.ru/dna/grid/campaigns?ulogin=<?=urlencode($clientLogin)?>" target="_blank" class="get-btn" style="margin-left:12px; background:#ded;">В кабинет Директ</a>
        <a href="javascript:history.back()" class="get-btn">Назад</a>
    </div>
    <div style="margin:32px 0;">
        <input type="text" id="searchInput" class="budget-search" placeholder="Поиск по имени кампании..." style="padding:6px 10px; font-size:1em; width:260px;">
        <button onclick="budgetSearch()" style="padding:7px 18px;">Найти</button>
    </div>
    <table class="budget-table" id="budgets-table">
        <thead>
            <tr>
                <th style="width:30px;"><input type="checkbox" id="selectAllRows" onclick="toggleAllRows(this)" title="Выбрать все"></th>
                <th>Название кампании</th>
                <th onclick="sortByDays()" class="sort-header" title="Нажмите для сортировки (Архив всегда внизу)">Дней / Статус ↕️</th>
                <th>Расход за день</th>
                <th>Общий расход</th>
                <th>Лимит на неделю / день</th>
                <th>Общий лимит</th>
            </tr>
        </thead>
        <tbody id="table-body">
<?php
    $jsData = [];
    $all_stop_by_budgets = [];
    if (file_exists($stop_file)) {
        $all_stop_by_budgets = json_decode(file_get_contents($stop_file), true);
        if (!is_array($all_stop_by_budgets)) $all_stop_by_budgets = [];
    }

    $updated_stop_by_budgets = [];
    foreach ($all_stop_by_budgets as $key => $val) {
        if (is_array($val) && isset($val['id'])) {
            $updated_stop_by_budgets[$val['id']] = $val;
        } elseif (is_numeric($val)) {
            $updated_stop_by_budgets[$val] = $val;
        }
    }

    foreach ($campaigns as $camp) {
        $state = strtoupper($camp['State']);
        $cid = $camp['Id'];
        $lim_val = isset($budgets[$cid]) ? $budgets[$cid] : '';
        $is_archived = ($state === 'ARCHIVED') ? 1 : 0;

        // Общий расход (Коэффициент 1.22)
        $spent = 0;
        if (isset($camp['Funds'])) {
            if (isset($camp['Funds']['SharedAccountFunds']['Spend'])) {
                $spent = $camp['Funds']['SharedAccountFunds']['Spend'];
            } elseif (isset($camp['Funds']['CampaignFunds']['Spend'])) {
                $spent = $camp['Funds']['CampaignFunds']['Spend'];
            }
        }
        $spentRur = $spent / 1000000;
        $spentNoVAT = round($spentRur / 1.22, 2);

        // --- Лимиты ---
        $week_limit = null;
        if (isset($camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit']) && $camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit'] > 0) {
            $week_limit = floor($camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit'] / 1000000);
        } elseif (isset($camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit']) && $camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit'] > 0) {
            $week_limit = floor($camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit'] / 1000000);
        } elseif (isset($camp['Funds']['WeeklySpendLimit']) && $camp['Funds']['WeeklySpendLimit'] > 0) {
            $week_limit = floor($camp['Funds']['WeeklySpendLimit'] / 1000000);
        }

        $manual_limit_set = false;
        if ($week_limit === null && isset($manual_week_limits[$cid]) && $manual_week_limits[$cid] > 0) {
            $week_limit = intval($manual_week_limits[$cid]);
            $manual_limit_set = true;
        }

        $day_limit = null;
        if ($week_limit !== null) {
            $day_limit = floor($week_limit / 7);
        } elseif (isset($camp['DailyBudget']['Amount']) && $camp['DailyBudget']['Amount'] > 0) {
            $day_limit = floor($camp['DailyBudget']['Amount'] / 1000000);
        }

        if ($lim_val !== '' && $day_limit > 0) {
            $days_left = floor( max(0, ($lim_val - $spentNoVAT) / $day_limit ) );
        } else {
            $days_left = '-';
        }

        $sort_val = ($days_left === '-') ? 999999 : $days_left;

        if ($days_left !== '-' && $days_left <= 2) {
            $updated_stop_by_budgets[$cid] = ['id' => $cid, 'name' => $camp['Name'], 'login' => $clientLogin, 'days_left' => $days_left, 'date' => date('Y-m-d H:i:s')];
        } elseif ($days_left !== '-' && $days_left > 3 && isset($updated_stop_by_budgets[$cid])) {
            unset($updated_stop_by_budgets[$cid]);
        }

        $row_class = ($state === 'ARCHIVED') ? 'archived-campaign' : '';
        $cost = isset($spend_today[$cid]) ? $spend_today[$cid] : 0;

        $jsData[] = ['cid' => $cid, 'cost' => $cost, 'spent' => $spentNoVAT, 'week_limit' => ($week_limit !== null) ? $week_limit : '', 'day_limit' => ($day_limit !== null) ? $day_limit : '', 'lim_val' => ($lim_val !== '') ? $lim_val : ''];

        echo '<tr class="' . $row_class . ' data-campaign-row" data-days="'.$sort_val.'" data-archived="'.$is_archived.'">';
        // Новая ячейка с чекбоксом
        echo '<td><input type="checkbox" class="row-selector" onchange="updateTotals()"></td>';
        echo '<td style="padding-left:10px;"><a href="https://direct.yandex.ru/dna/campaigns-edit?ulogin=' . urlencode($clientLogin) . '&campaigns-ids=' . urlencode($cid) . '" target="_blank" style="color:#7b288f; text-decoration:underline;">' . htmlspecialchars($camp['Name']) . '</a></td>';

        echo '<td>' . $days_left . ' &nbsp; ' . state_icon($camp['State']) .
             ' <span style="color:#555; display:none">' . htmlspecialchars($camp['State']) . '</span>';
        if ($state === 'ON') {
            echo '<form method="post" style="display:inline;margin-left:8px;"><input type="hidden" name="cid" value="'.htmlspecialchars($cid).'"><input type="hidden" name="login" value="'.htmlspecialchars($clientLogin).'"><button name="change_status" value="suspend" title="Остановить" style="background:none; border:none; color:#bb2c2c; cursor:pointer;">⏸️</button></form>';
        } elseif ($state === 'OFF') {
            echo '<form method="post" style="display:inline;margin-left:8px;"><input type="hidden" name="cid" value="'.htmlspecialchars($cid).'"><input type="hidden" name="login" value="'.htmlspecialchars($clientLogin).'"><button name="change_status" value="resume" title="Включить" style="background:none; border:none; color:green; cursor:pointer;">▶️</button></form>';
        }
        echo '</td>';

        echo '<td class="cell-cost">' . number_format($cost, 2, '.', ' ') . '</td>';
        echo '<td class="cell-spent">' . number_format($spentNoVAT, 2, '.', ' ') . '</td>';

        echo '<td id="week_limit_cell_'.$cid.'" class="cell-weekday">';
        if ($week_limit !== null) {
            echo '<span style="'.($manual_limit_set ? 'color:#da8706;font-weight:bold;' : '').'">'
                . number_format($week_limit, 0, ',', ' ') . ' ₽ / '
                . number_format($day_limit, 0, ',', ' ') . ' ₽'
                . ($manual_limit_set ? ' <span title="Введено вручную">*</span> <a href="#" onclick="editWeekLimit(\''.$cid.'\', '.$week_limit.'); return false;" style="margin-left:5px;" title="Изменить">✎</a>' : '')
                . '</span>';
        } else {
            echo '<input type="number" min="1" style="width:95px;" placeholder="Лимит/неделя"> <button onclick="saveWeekLimit(\''.$cid.'\', this)">OK</button>';
        }
        echo '</td>';

        echo '<td class="cell-lim">
                <div style="font-weight:bold; font-size:1.15em; margin-bottom:3px;">' . ($lim_val !== '' ? number_format($lim_val, 0, ',', ' ') : '-') . '</div>
                <form method="post" style="display:flex;align-items:center;margin:0;" id="form_lim_'.$cid.'">
                    <input name="save_limit" value="" placeholder="Изменить..." style="width:70px;text-align:right;">
                    <input type="hidden" name="cid" value="' . htmlspecialchars($cid) . '">
                    <button type="submit" title="Сохранить" style="margin-left:2px;cursor:pointer;">💾</button>
                    <div style="display:flex; gap:2px; margin-left:5px;">';
        
        if ($day_limit > 0) {
            echo '<button type="button" class="btn-quick-add" onclick="quickAddBudget(\''.$cid.'\', '.$day_limit.', '.($lim_val ?: 0).')" title="Прибавить бюджет на 30 дней">+30</button>';
        }
        
        echo '          <button type="button" class="btn-quick-add" onclick="quickAdjustLimit(\''.$cid.'\', \'add\', '.($lim_val ?: 0).')" title="Добавить свою сумму">+</button>
                        <button type="button" class="btn-quick-add" onclick="quickAdjustLimit(\''.$cid.'\', \'sub\', '.($lim_val ?: 0).')" title="Отнять свою сумму">-</button>
                    </div>
                </form>
              </td>';
        echo '</tr>';
    }
    file_put_contents($stop_file, json_encode(array_keys($updated_stop_by_budgets), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
?>
        </tbody>
        <tr class="total-row" id="totals-row">
            <td colspan="3">Итого (выбрано):</td>
            <td id="totals-cost">-</td>
            <td id="totals-spent">-</td>
            <td id="totals-weekday">-</td>
            <td id="totals-lim">-</td>
        </tr>
    </table>
<script>
var jsData = <?php echo json_encode($jsData, JSON_UNESCAPED_UNICODE); ?>;
var daysSortOrder = 'asc';

// --- Функция выделения всех строк ---
function toggleAllRows(master) {
    var rows = document.querySelectorAll('.data-campaign-row');
    rows.forEach(function(tr) {
        if (tr.style.display !== 'none') { // Выделяем только видимые при поиске
            var checkbox = tr.querySelector('.row-selector');
            if (checkbox) checkbox.checked = master.checked;
        }
    });
    updateTotals();
}

// --- Кнопка быстрого добавления бюджета (+30 дней) ---
function quickAddBudget(cid, daily, currentTotal) {
    var newVal = Math.round(currentTotal + (daily * 30));
    var form = document.getElementById('form_lim_' + cid);
    var input = form.querySelector('input[name="save_limit"]');
    input.value = newVal;
    form.submit();
}

// --- Кнопки произвольного изменения (+ и -) ---
function quickAdjustLimit(cid, action, currentTotal) {
    var amount = prompt(action === 'add' ? "Сколько добавить к общему лимиту?" : "Сколько отнять от общего лимита?");
    if (amount === null || amount === "" || isNaN(amount)) return;
    
    amount = parseFloat(amount);
    var newVal = (action === 'add') ? (currentTotal + amount) : (currentTotal - amount);
    
    var form = document.getElementById('form_lim_' + cid);
    var input = form.querySelector('input[name="save_limit"]');
    input.value = Math.round(newVal);
    form.submit();
}

// --- Функция умной сортировки ---
function sortByDays() {
    const tbody = document.getElementById('table-body');
    const rows = Array.from(tbody.querySelectorAll('tr.data-campaign-row'));

    rows.sort((a, b) => {
        const archA = parseInt(a.getAttribute('data-archived'));
        const archB = parseInt(b.getAttribute('data-archived'));
        if (archA !== archB) return archA - archB;
        const valA = parseInt(a.getAttribute('data-days'));
        const valB = parseInt(b.getAttribute('data-days'));
        return daysSortOrder === 'asc' ? valA - valB : valB - valA;
    });

    daysSortOrder = (daysSortOrder === 'asc') ? 'desc' : 'asc';
    rows.forEach(row => tbody.appendChild(row));
}

function saveWeekLimit(campaignId, btn) {
    var cell = document.getElementById('week_limit_cell_' + campaignId);
    var input = cell.querySelector('input');
    var val = parseInt(input.value);
    if (isNaN(val) || val < 0) { alert('Введите число'); return; }
    btn.disabled = true;
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'campaign_id=' + encodeURIComponent(campaignId) + '&week_limit=' + encodeURIComponent(val)
    }).then(r => r.json()).then(res => {
        if (res.success) { location.reload(); } else { alert('Ошибка'); btn.disabled = false; }
    });
}

function editWeekLimit(campaignId, current) {
    var cell = document.getElementById('week_limit_cell_' + campaignId);
    cell.innerHTML = '<input type="number" min="0" value="'+current+'" style="width:95px;"> <button onclick="saveWeekLimit(\''+campaignId+'\', this)">OK</button>';
}

function budgetSearch() {
    var val = document.getElementById('searchInput').value.toLowerCase();
    var rows = document.querySelectorAll('.data-campaign-row');
    rows.forEach(function(tr) {
        var nameCell = tr.querySelector('td:nth-child(2)'); // Теперь имя во 2-й колонке
        if (!nameCell) return;
        var name = nameCell.innerText.toLowerCase();
        tr.style.display = (!val || name.indexOf(val) !== -1) ? '' : 'none';
    });
    updateTotals();
}

document.getElementById('searchInput').addEventListener('keyup', function(e){ if (e.key === 'Enter') budgetSearch(); });
window.addEventListener('DOMContentLoaded', updateTotals);

// --- ОБНОВЛЕННАЯ ФУНКЦИЯ ИТОГОВ: только для выделенных строк ---
function updateTotals() {
    var rows = document.querySelectorAll('.data-campaign-row');
    var t_cost = 0, t_spent = 0, t_week = 0, t_day = 0, t_lim = 0;
    var c_week = 0, c_day = 0, c_lim = 0;

    rows.forEach(function(tr, idx){
        // Считаем только если строка видима И выделена чекбоксом
        var isVisible = (tr.style.display !== 'none');
        var isChecked = tr.querySelector('.row-selector').checked;
        
        if (isVisible && isChecked) {
            var data = jsData[idx];
            t_cost += parseFloat(data.cost) || 0;
            t_spent += parseFloat(data.spent) || 0;
            if (data.week_limit !== '') { t_week += parseInt(data.week_limit); c_week++; }
            if (data.day_limit !== '') { t_day += parseInt(data.day_limit); c_day++; }
            if (data.lim_val !== '') { t_lim += parseInt(data.lim_val); c_lim++; }
        }
    });

    document.getElementById('totals-cost').innerText = t_cost.toLocaleString('ru-RU', {minimumFractionDigits: 2});
    document.getElementById('totals-spent').innerText = t_spent.toLocaleString('ru-RU', {minimumFractionDigits: 2});
    document.getElementById('totals-weekday').innerText = (c_week ? t_week.toLocaleString() : '0') + ' / ' + (c_day ? t_day.toLocaleString() : '0');
    document.getElementById('totals-lim').innerText = t_lim.toLocaleString();
}
</script>
</body>
</html>