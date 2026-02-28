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

// --- POST: Массовое сохранение лимитов ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save_limits'])) {
    $data = json_decode($_POST['bulk_save_limits'], true);
    if (is_array($data)) {
        foreach ($data as $cid => $val) {
            $budgets[$cid] = intval($val);
        }
        file_put_contents($budgets_file, json_encode($budgets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// --- POST: сохранение одиночного лимита ---
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

// --- Функции API Яндекс.Директа ---
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
    .btn-action { padding:7px 18px; margin-left:10px; cursor:pointer; }
    .row-selector { width: 18px; height: 18px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="top-row">
        <h2>Отчет по клиенту: <?=htmlspecialchars($clientName)?> (<?=htmlspecialchars($clientLogin)?>)</h2>
        <a href="ln_report.php" class="get-btn">← К списку клиентов</a>
        <a href="https://direct.yandex.ru/dna/grid/campaigns?ulogin=<?=urlencode($clientLogin)?>" target="_blank" class="get-btn" style="margin-left:12px; background:#ded;">В кабинет Яндекс.Директ</a>
        <a href="javascript:history.back()" class="get-btn">Назад</a>
    </div>
    <div style="margin:32px 0; display:flex; align-items:center;">
        <input type="text" id="searchInput" class="budget-search" placeholder="Поиск..." style="padding:6px 10px; font-size:1em; width:220px;">
        <button onclick="budgetSearch()" style="padding:7px 18px; margin-left:5px;">Найти</button>
        <button onclick="distributeRemainingBudget()" class="btn-action" style="background:#fdf; border:1px solid #c9c;" title="Выровнять остаток дней у всех выбранных">Распределить поровну</button>
        <button onclick="transferRemainingBudget()" class="btn-action" style="background:#dfe; border:1px solid #9c9;" title="Перенести остаток с остановленных/приостановленных на активные">Перенести остаток 🔄</button>
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

    $tmp_stop = [];
    foreach ($all_stop_by_budgets as $val) {
        if (is_array($val) && isset($val['id'])) $tmp_stop[$val['id']] = $val;
        elseif (is_numeric($val)) $tmp_stop[$val] = $val;
    }
    $updated_stop_by_budgets = $tmp_stop;

    foreach ($campaigns as $i => $camp) {
        $state = strtoupper($camp['State']);
        $cid = $camp['Id'];
        $lim_val = isset($budgets[$cid]) ? $budgets[$cid] : '';
        $is_archived = ($state === 'ARCHIVED') ? 1 : 0;

        // Общий расход (1.22)
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

        // Лимиты
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

        $day_limit = ($week_limit !== null) ? floor($week_limit / 7) : (isset($camp['DailyBudget']['Amount']) ? floor($camp['DailyBudget']['Amount'] / 1000000) : 0);

        if ($lim_val !== '' && $day_limit > 0) {
            $days_left = floor(max(0, ($lim_val - $spentNoVAT) / $day_limit));
        } else {
            $days_left = '-';
        }

        if ($days_left !== '-' && $days_left <= 2) {
            $updated_stop_by_budgets[$cid] = ['id' => $cid, 'name' => $camp['Name'], 'login' => $clientLogin, 'days_left' => $days_left, 'date' => date('Y-m-d H:i:s')];
        } elseif ($days_left !== '-' && $days_left > 3 && isset($updated_stop_by_budgets[$cid])) {
            unset($updated_stop_by_budgets[$cid]);
        }

        $row_class = ($state === 'ARCHIVED') ? 'archived-campaign' : '';
        $cost = isset($spend_today[$cid]) ? $spend_today[$cid] : 0;
        $sort_val = ($days_left === '-') ? 999999 : $days_left;

        $jsData[] = ['cid'=>$cid, 'cost'=>$cost, 'spent'=>$spentNoVAT, 'week_limit'=>$week_limit??'', 'day_limit'=>$day_limit, 'lim_val'=>$lim_val ?: 0];

        echo '<tr class="'.$row_class.' data-campaign-row" data-days="'.$sort_val.'" data-archived="'.$is_archived.'" data-idx="'.$i.'" data-state="'.$state.'">';
        echo '<td><input type="checkbox" class="row-selector" onchange="updateTotals()"></td>';
        echo '<td style="padding-left:10px;"><a href="https://direct.yandex.ru/dna/campaigns-edit?ulogin='.urlencode($clientLogin).'&campaigns-ids='.urlencode($cid).'" target="_blank" style="color:#7b288f; text-decoration:underline;">'.htmlspecialchars($camp['Name']).'</a></td>';
        echo '<td>'.$days_left.' &nbsp; '.state_icon($camp['State']).'</td>';
        echo '<td class="cell-cost">'.number_format($cost, 2, '.', ' ').'</td>';
        echo '<td class="cell-spent">'.number_format($spentNoVAT, 2, '.', ' ').'</td>';
        echo '<td id="week_limit_cell_'.$cid.'" class="cell-weekday">';
        if ($week_limit !== null) {
            echo '<span style="'.($manual_limit_set ? 'color:#da8706;font-weight:bold;' : '').'">' . number_format($week_limit, 0, ',', ' ') . ' ₽ / ' . number_format($day_limit, 0, ',', ' ') . ' ₽' . ($manual_limit_set ? ' <span title="Введено вручную">*</span> <a href="#" onclick="editWeekLimit(\''.$cid.'\', '.$week_limit.'); return false;" style="margin-left:5px;">✎</a>' : '') . '</span>';
        } else {
            echo '<input type="number" min="1" style="width:95px;" placeholder="Неделя"> <button onclick="saveWeekLimit(\''.$cid.'\', this)">OK</button>';
        }
        echo '</td>';
        echo '<td class="cell-lim"><div style="font-weight:bold; font-size:1.15em; margin-bottom:3px;">'.($lim_val !== '' ? number_format($lim_val, 0, ',', ' ') : '-').'</div><form method="post" style="display:flex;align-items:center;margin:0;" id="form_lim_'.$cid.'"><input name="save_limit" style="width:70px;text-align:right;"><input type="hidden" name="cid" value="'.$cid.'"><button type="submit" style="margin-left:2px;cursor:pointer;">💾</button><div style="display:flex; gap:2px; margin-left:5px;">';
        if ($day_limit > 0) echo '<button type="button" class="btn-quick-add" onclick="quickAddBudget(\''.$cid.'\', '.$day_limit.', '.($lim_val?:0).')" title="Прибавить бюджет на 30 дней">+30</button>';
        echo '<button type="button" class="btn-quick-add" onclick="quickAdjustLimit(\''.$cid.'\', \'add\', '.($lim_val?:0).')" title="Добавить">+</button><button type="button" class="btn-quick-add" onclick="quickAdjustLimit(\''.$cid.'\', \'sub\', '.($lim_val?:0).')" title="Отнять">-</button></div></form></td>';
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

// --- ОБНОВЛЕННАЯ ФУНКЦИЯ: Перенос остатка с остановленных/приостановленных (оранжевых) на запущенные ---
function transferRemainingBudget() {
    var rows = document.querySelectorAll('.data-campaign-row');
    var selectedActive = [];
    var selectedStopped = [];
    var transferPool = 0;
    var activeRemainder = 0;
    var activeDailyLimit = 0;

    rows.forEach(function(tr) {
        if (tr.style.display !== 'none' && tr.querySelector('.row-selector').checked) {
            var idx = parseInt(tr.getAttribute('data-idx'));
            var data = jsData[idx];
            var state = tr.getAttribute('data-state');
            var daily = parseFloat(data.day_limit) || 0;
            var spent = parseFloat(data.spent) || 0;
            var limit = parseFloat(data.lim_val) || 0;
            var remainder = Math.max(0, limit - spent);

            // Оранжевые (SUSPENDED) и Красные (OFF)
            if (state === 'OFF' || state === 'SUSPENDED') {
                transferPool += remainder;
                selectedStopped.push({cid: data.cid, spent: spent});
            } else if (state === 'ON') {
                if (daily > 0) {
                    activeRemainder += remainder;
                    activeDailyLimit += daily;
                    selectedActive.push({cid: data.cid, spent: spent, daily: daily});
                }
            }
        }
    });

    if (selectedStopped.length === 0) return alert("Выберите хотя бы одну остановленную (OFF) или приостановленную (оранжевую) кампанию!");
    if (selectedActive.length === 0) return alert("Выберите активные (ON) кампании с дневным лимитом!");

    var totalToDistribute = activeRemainder + transferPool;
    var targetDays = totalToDistribute / activeDailyLimit;

    if (!confirm("Собрано остатков: " + Math.round(transferPool) + " ₽\nОбщий бюджет для активных: " + Math.round(totalToDistribute) + " ₽\nБудет распределено на " + targetDays.toFixed(1) + " дн.\nПродолжить?")) return;

    var bulkData = {};
    selectedStopped.forEach(item => { bulkData[item.cid] = Math.round(item.spent); });
    selectedActive.forEach(item => { bulkData[item.cid] = Math.round(item.spent + (targetDays * item.daily)); });

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'bulk_save_limits=' + encodeURIComponent(JSON.stringify(bulkData))
    }).then(r => r.json()).then(res => { if (res.success) location.reload(); });
}

function distributeRemainingBudget() {
    var rows = document.querySelectorAll('.data-campaign-row');
    var selected = [];
    var totalRemainder = 0;
    var totalDailyLimit = 0;

    rows.forEach(function(tr) {
        if (tr.style.display !== 'none' && tr.querySelector('.row-selector').checked) {
            var idx = parseInt(tr.getAttribute('data-idx'));
            var data = jsData[idx];
            var daily = parseFloat(data.day_limit) || 0;
            var spent = parseFloat(data.spent) || 0;
            var limit = parseFloat(data.lim_val) || 0;
            var remainder = Math.max(0, limit - spent);
            if (daily > 0) {
                totalRemainder += remainder;
                totalDailyLimit += daily;
                selected.push({cid: data.cid, spent: spent, daily: daily});
            }
        }
    });

    if (selected.length === 0) return alert("Выберите кампании!");
    var targetDays = totalRemainder / totalDailyLimit;
    if (!confirm("Бюджет: " + Math.round(totalRemainder) + " ₽\nНа " + targetDays.toFixed(1) + " дн.\nПродолжить?")) return;

    var bulkData = {};
    selected.forEach(item => { bulkData[item.cid] = Math.round(item.spent + (targetDays * item.daily)); });

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'bulk_save_limits=' + encodeURIComponent(JSON.stringify(bulkData))
    }).then(r => r.json()).then(res => { if (res.success) location.reload(); });
}

function toggleAllRows(master) {
    var rows = document.querySelectorAll('.data-campaign-row');
    rows.forEach(tr => { if (tr.style.display !== 'none') tr.querySelector('.row-selector').checked = master.checked; });
    updateTotals();
}

function quickAddBudget(cid, daily, currentTotal) {
    var newVal = Math.round(currentTotal + (daily * 30));
    var input = document.getElementById('form_lim_' + cid).querySelector('input[name="save_limit"]');
    input.value = newVal;
    input.form.submit();
}

function quickAdjustLimit(cid, action, currentTotal) {
    var amount = prompt("Сумма:");
    if (!amount || isNaN(amount)) return;
    var newVal = (action === 'add') ? (currentTotal + parseFloat(amount)) : (currentTotal - parseFloat(amount));
    var input = document.getElementById('form_lim_' + cid).querySelector('input[name="save_limit"]');
    input.value = Math.round(newVal);
    input.form.submit();
}

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
    var input = document.getElementById('week_limit_cell_' + campaignId).querySelector('input');
    var val = parseInt(input.value);
    if (isNaN(val) || val < 0) return alert('Число!');
    btn.disabled = true;
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'campaign_id=' + encodeURIComponent(campaignId) + '&week_limit=' + encodeURIComponent(val)
    }).then(r => r.json()).then(res => { if (res.success) location.reload(); });
}

function editWeekLimit(campaignId, current) {
    document.getElementById('week_limit_cell_' + campaignId).innerHTML = '<input type="number" value="'+current+'" style="width:95px;"> <button onclick="saveWeekLimit(\''+campaignId+'\', this)">OK</button>';
}

function budgetSearch() {
    var val = document.getElementById('searchInput').value.toLowerCase();
    var rows = document.querySelectorAll('.data-campaign-row');
    rows.forEach(tr => {
        var name = tr.querySelector('td:nth-child(2)').innerText.toLowerCase();
        tr.style.display = (!val || name.indexOf(val) !== -1) ? '' : 'none';
    });
    updateTotals();
}

document.getElementById('searchInput').addEventListener('keyup', e => { if (e.key === 'Enter') budgetSearch(); });
window.addEventListener('DOMContentLoaded', updateTotals);

function updateTotals() {
    var rows = document.querySelectorAll('.data-campaign-row');
    var tc = 0, ts = 0, tw = 0, td = 0, tl = 0;
    var cw = 0, cd = 0, cl = 0;
    rows.forEach(tr => {
        if (tr.style.display !== 'none' && tr.querySelector('.row-selector').checked) {
            var d = jsData[parseInt(tr.getAttribute('data-idx'))];
            tc += parseFloat(d.cost) || 0;
            ts += parseFloat(d.spent) || 0;
            if (d.week_limit !== '') { tw += parseInt(d.week_limit); cw++; }
            if (d.day_limit > 0) { td += parseInt(d.day_limit); cd++; }
            if (d.lim_val > 0) { tl += parseInt(d.lim_val); cl++; }
        }
    });
    document.getElementById('totals-cost').innerText = tc.toLocaleString('ru-RU', {minimumFractionDigits: 2});
    document.getElementById('totals-spent').innerText = ts.toLocaleString('ru-RU', {minimumFractionDigits: 2});
    document.getElementById('totals-weekday').innerText = (cw ? tw.toLocaleString() : '0') + ' / ' + (cd ? td.toLocaleString() : '0');
    document.getElementById('totals-lim').innerText = tl.toLocaleString();
}
</script>
</body>
</html>