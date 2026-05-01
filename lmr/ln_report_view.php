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

// --- POST: смена статуса кампании ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'], $_POST['cid'], $_POST['login'])) {
    $cid = $_POST['cid'];
    $login = $_POST['login'];
    $action = $_POST['change_status'];
    change_campaign_status($_SESSION['ya_access_token'], $login, $cid, $action);
    header("Location: ".$_SERVER['REQUEST_URI']); exit;
}

// --- Функции такие же, как в budgets.php ---
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
        $day_spent[$cid] = round((float)$cost);
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

$access_token = $_SESSION['ya_access_token'];
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
    .archived-campaign {
        opacity: 0.5;
    }
    .total-row {
        font-weight: bold;
        background: #f8f8fb;
    }
    </style>
</head>
<body>
    <div class="top-row">
        <h2>Отчет по клиенту: <?=htmlspecialchars($clientName)?> (<?=htmlspecialchars($clientLogin)?>)</h2>
        <a href="ln_report.php" class="get-btn">← К списку клиентов</a>
        <a href="/vk_dir_buh/ln/link_dir.php" target="_blank" class="get-btn">Метки</a>
        <a href="https://direct.yandex.ru/dna/grid/campaigns?ulogin=<?=urlencode($clientLogin)?>" target="_blank" class="get-btn" style="margin-left:12px; background:#ded;">В кабинет Яндекс.Директ</a>
        <a href="javascript:history.back()" class="get-btn">Назад</a>
    </div>
    <div style="margin:32px 0;">
        <input type="text" id="searchInput" class="budget-search" placeholder="Поиск по имени кампании..." style="padding:6px 10px; font-size:1em; width:260px;">
        <button onclick="budgetSearch()" style="padding:7px 18px;">Найти</button>
    </div>

    <table class="budget-table" id="budgets-table">
        <tr>
            <th>Название кампании</th>
            <th>Дней / Статус</th>
            <th>Расход за день</th>
            <th>Общий расход</th>
            <th>Лимит на неделю / день</th>
            <th>Общий лимит</th>
        </tr>
<?php
// Продолжение во 3/4...
        // Для JS: массив с данными (одна строка на кампанию)
        $jsData = [];
        // --- Загрузить старые кампании для ln_stop_by_budgets.json ---
        $all_stop_by_budgets = [];
        if (file_exists($stop_file)) {
            $all_stop_by_budgets = json_decode(file_get_contents($stop_file), true);
            if (!is_array($all_stop_by_budgets)) $all_stop_by_budgets = [];
        }

        // Поддерживаем два возможных формата: либо массив ID, либо массив объектов с ключами id/name/login/days_left/date
        $existing_ids = [];
        if (isset($all_stop_by_budgets[0]) && is_array($all_stop_by_budgets[0])) {
            // старый формат: только ID (на всякий случай)
            foreach ($all_stop_by_budgets as $el) {
                if (is_numeric($el)) $existing_ids[] = (int)$el;
            }
        } else {
            foreach ($all_stop_by_budgets as $k => $el) {
                if (is_array($el) && isset($el['id'])) $existing_ids[] = (int)$el['id'];
                elseif (is_numeric($el)) $existing_ids[] = (int)$el;
            }
        }

        // Соберём новый массив для записи
        $updated_stop_by_budgets = [];
        // Скопируем всех старых, чтобы сохранить другие кампании других клиентов
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

            // Общий расход
            $spent = 0;
            if (
                isset($camp['Funds']['Mode']) &&
                $camp['Funds']['Mode'] === 'SHARED_ACCOUNT_FUNDS' &&
                isset($camp['Funds']['SharedAccountFunds']['Spend'])
            ) {
                $spent = $camp['Funds']['SharedAccountFunds']['Spend'];
            }
            $spentRur = $spent / 1000000;
            $spentNoVAT = floor($spentRur / 1.2);

            // --- Лимит на неделю / день ---
            $week_limit = null;
            if (
                isset($camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit']) &&
                $camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit'] > 0
            ) {
                $week_limit = floor($camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit'] / 1000000);
            }
            elseif (
                isset($camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit']) &&
                $camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit'] > 0
            ) {
                $week_limit = floor($camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit'] / 1000000);
            }
            elseif (
                isset($camp['Funds']['WeeklySpendLimit']) &&
                $camp['Funds']['WeeklySpendLimit'] > 0
            ) {
                $week_limit = floor($camp['Funds']['WeeklySpendLimit'] / 1000000);
            }
            // === Ручной лимит если нет лимита в API
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

            // Остаток дней до конца бюджета
            if ($lim_val !== '' && $day_limit > 0) {
                $days_left = floor( max(0, ($lim_val - $spentNoVAT) / $day_limit ) );
            } else {
                $days_left = '-';
            }

            // === Блок управления stop_by_budgets (дополнение, а не перезапись!) ===
            if ($days_left !== '-' && $days_left <= 2) {
                // Добавить или обновить инфу
                $updated_stop_by_budgets[$cid] = [
                    'id'     => $cid,
                    'name'   => $camp['Name'],
                    'login'  => $clientLogin,
                    'days_left' => $days_left,
                    'date'   => date('Y-m-d H:i:s')
                ];
            } elseif ($days_left !== '-' && $days_left > 3 && isset($updated_stop_by_budgets[$cid])) {
                // Удалить из массива если уже был
                unset($updated_stop_by_budgets[$cid]);
            }

            // -- Прозрачность для архивных --
            $row_class = ($state === 'ARCHIVED') ? 'archived-campaign' : '';

            // Сохраняем значения для JS-подсчета
            $jsData[] = [
                'cid' => $cid,
                'cost' => $cost = isset($spend_today[$camp['Id']]) ? $spend_today[$camp['Id']] : 0,
                'spent' => $spentNoVAT,
                'week_limit' => ($week_limit !== null) ? $week_limit : '',
                'day_limit' => ($day_limit !== null) ? $day_limit : '',
                'lim_val' => ($lim_val !== '') ? $lim_val : ''
            ];

            echo '<tr class="' . $row_class . ' data-campaign-row">';
            echo '<td style="padding-left:18px;"><a href="https://direct.yandex.ru/dna/campaigns-edit?ulogin=' . urlencode($clientLogin) . '&campaigns-ids=' . urlencode($cid) . '" target="_blank" style="color:#7b288f; text-decoration:underline;">'
                . htmlspecialchars($camp['Name']) . '</a></td>';

            echo '<td>' . $days_left . ' &nbsp; ' . state_icon($camp['State']) .
                 ' <span style="color:#555; display:none">' . htmlspecialchars($camp['State']) . '</span>';
            if ($state === 'ON') {
                echo '<form method="post" style="display:inline;margin-left:8px;">
                    <input type="hidden" name="cid" value="'.htmlspecialchars($cid).'">
                    <input type="hidden" name="login" value="'.htmlspecialchars($clientLogin).'">
                    <button name="change_status" value="suspend" title="Остановить" style="color:#bb2c2c;cursor:pointer;">⏸️</button>
                </form>';
            } elseif ($state === 'OFF') {
                echo '<form method="post" style="display:inline;margin-left:8px;">
                    <input type="hidden" name="cid" value="'.htmlspecialchars($cid).'">
                    <input type="hidden" name="login" value="'.htmlspecialchars($clientLogin).'">
                    <button name="change_status" value="resume" title="Включить" style="color:green;cursor:pointer;">▶️</button>
                </form>';
            }
            echo '</td>';

            echo '<td class="cell-cost">' . $cost . '</td>';
            echo '<td class="cell-spent">' . $spentNoVAT . '</td>';

            // === Лимит на неделю/день с ручным вводом ===
            echo '<td id="week_limit_cell_'.$cid.'" class="cell-weekday">';
            if ($week_limit !== null) {
                echo '<span style="'.($manual_limit_set ? 'color:#da8706;font-weight:bold;' : '').'">'
                    . number_format($week_limit, 0, ',', ' ') . ' ₽ / '
                    . number_format($day_limit, 0, ',', ' ') . ' ₽'
                    . ($manual_limit_set
                        ? ' <span title="Введено вручную">*</span>
                            <a href="#" onclick="editWeekLimit(\''.$cid.'\', '.$week_limit.'); return false;" style="margin-left:5px;" title="Изменить"><span style="font-size:1.1em;">✎</span></a>'
                        : ''
                    )
                    . '</span>';
            } else {
                echo '<input type="number" min="1" style="width:95px;" placeholder="Лимит/неделя (0=удалить)"> ';
                echo '<button onclick="saveWeekLimit(\''.$cid.'\', this)">Сохранить</button>';
            }
            echo '</td>';

            echo '<td class="cell-lim">'
                . '<div style="font-weight:bold; font-size:1.15em; margin-bottom:3px;">'
                . ($lim_val !== '' ? htmlspecialchars($lim_val) : '-') .
                '</div>
                <form method="post" style="display:flex;align-items:center;margin:0;">
                    <input name="save_limit" value="" placeholder="Изменить..." style="width:70px;text-align:right;">
                    <input type="hidden" name="cid" value="' . htmlspecialchars($cid) . '">
                    <button type="submit" title="Сохранить" style="margin-left:2px;cursor:pointer;">💾</button>
                </form>
            </td>';

            echo '</tr>';
        }

// Собираем только id для записи (старый формат)
file_put_contents($stop_file, json_encode(array_keys($updated_stop_by_budgets), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

?>
        <tr class="total-row" id="totals-row"><td colspan="2">Итого:</td>
            <td id="totals-cost">-</td>
            <td id="totals-spent">-</td>
            <td id="totals-weekday">-</td>
            <td id="totals-lim">-</td>
        </tr>
    </table>
<script>
// Данные для JS
var jsData = <?php echo json_encode($jsData, JSON_UNESCAPED_UNICODE); ?>;

// --- JS для ручного недельного лимита ---
function saveWeekLimit(campaignId, btn) {
    var cell = document.getElementById('week_limit_cell_' + campaignId);
    var input = cell.querySelector('input');
    var val = parseInt(input.value);
    if (isNaN(val) || val < 0) {
        alert('Введите неотрицательное число');
        return;
    }
    btn.disabled = true;
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'campaign_id=' + encodeURIComponent(campaignId) + '&week_limit=' + encodeURIComponent(val)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            if (val === 0) {
                cell.innerHTML = '<input type="number" min="1" style="width:95px;" placeholder="Лимит/неделя (0=удалить)"> ' +
                    '<button onclick="saveWeekLimit(\''+campaignId+'\', this)">Сохранить</button>';
            } else {
                cell.innerHTML = '<span style="color:#da8706;font-weight:bold;">' +
                    res.week_limit + ' ₽ / ' + res.day_limit + ' ₽ <span title="Введено вручную">*</span>' +
                    ' <a href="#" onclick="editWeekLimit(\''+campaignId+'\', '+res.week_limit+'); return false;" style="margin-left:5px;" title="Изменить"><span style="font-size:1.1em;">✎</span></a>' +
                    '</span>';
            }
        } else {
            alert('Ошибка: ' + (res.message || ''));
            btn.disabled = false;
        }
    })
    .catch(() => {
        alert('Ошибка соединения');
        btn.disabled = false;
    });
}

function editWeekLimit(campaignId, current) {
    var cell = document.getElementById('week_limit_cell_' + campaignId);
    cell.innerHTML =
        '<input type="number" min="0" value="'+current+'" style="width:95px;" placeholder="Лимит/неделя (0=удалить)"> ' +
        '<button onclick="saveWeekLimit(\''+campaignId+'\', this)">Сохранить</button>';
}

function budgetSearch() {
    var val = document.getElementById('searchInput').value.toLowerCase();
    var rows = document.querySelectorAll('.budget-table tr.data-campaign-row');
    rows.forEach(function(tr) {
        var nameCell = tr.querySelector('td');
        if (!nameCell) return;
        var name = nameCell.innerText.toLowerCase();
        tr.style.display = (!val || name.indexOf(val) !== -1) ? '' : 'none';
    });
    updateTotals();
}
document.getElementById('searchInput').addEventListener('keyup', function(e){
    if (e.key === 'Enter') budgetSearch();
});
window.addEventListener('DOMContentLoaded', updateTotals);

function updateTotals() {
    var rows = document.querySelectorAll('.budget-table tr.data-campaign-row');
    var total_cost = 0, total_spent = 0, total_week = 0, total_day = 0, total_lim = 0;
    var cnt_week = 0, cnt_day = 0, cnt_lim = 0;
    rows.forEach(function(tr, idx){
        if (tr.style.display === 'none') return;
        // По индексу в jsData (PHP сохраняет одинаково)
        var data = jsData[idx];
        var cost = parseInt(data.cost) || 0;
        total_cost += cost;
        var spent = parseInt(data.spent) || 0;
        total_spent += spent;
        var week = parseInt(data.week_limit);
        if (!isNaN(week) && week !== '') { total_week += week; cnt_week++; }
        var day = parseInt(data.day_limit);
        if (!isNaN(day) && day !== '') { total_day += day; cnt_day++; }
        var lim = parseInt(data.lim_val);
        if (!isNaN(lim) && lim !== '') { total_lim += lim; cnt_lim++; }
    });
    document.getElementById('totals-cost').innerText = total_cost;
    document.getElementById('totals-spent').innerText = total_spent;
    document.getElementById('totals-weekday').innerText =
        (cnt_week ? total_week : '-') + ' / ' + (cnt_day ? total_day : '-');
    document.getElementById('totals-lim').innerText = cnt_lim ? total_lim : '-';
}
</script>
</body>
</html>
