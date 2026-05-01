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

// --- Корректировки расхода (Ручной расход без НДС) ---
$corrections_file = __DIR__ . "/ln_spent_corrections.json";
if (!file_exists($corrections_file)) file_put_contents($corrections_file, "{}");
$spent_corrections = json_decode(file_get_contents($corrections_file), true);

// --- ШАБЛОНЫ ФИЛЬТРОВ ---
$templates_file = __DIR__ . "/ln_filter_templates.json";
if (!file_exists($templates_file)) file_put_contents($templates_file, "{}");
$all_templates = json_decode(file_get_contents($templates_file), true);
$client_templates = isset($all_templates[$clientLogin]) ? $all_templates[$clientLogin] : [];

// --- Ручные недельные лимиты ---
$manual_week_file = __DIR__ . "/manual_week_limits.json";
if (!file_exists($manual_week_file)) file_put_contents($manual_week_file, "{}");
$manual_week_limits = json_decode(file_get_contents($manual_week_file), true);

// --- Работаем с кампаниями на исходе бюджета ---
$stop_file = __DIR__ . "/ln_stop_by_budgets.json";
if (!file_exists($stop_file)) file_put_contents($stop_file, "[]");
$stop_by_budgets = json_decode(file_get_contents($stop_file), true);
if (!is_array($stop_by_budgets)) $stop_by_budgets = [];

// --- POST: Сохранение шаблона фильтра ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'], $_POST['tpl_name'])) {
    $name = trim($_POST['tpl_name']);
    if ($name) {
        $all_templates[$clientLogin][$name] = [
            'search' => $_POST['tpl_search'] ?? '',
            'status' => $_POST['tpl_status'] ?? 'all'
        ];
        file_put_contents($templates_file, json_encode($all_templates, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    header("Location: ".$_SERVER['REQUEST_URI']); exit;
}

// --- POST: Удаление шаблона фильтра ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_template'])) {
    $name = $_POST['delete_template'];
    if (isset($all_templates[$clientLogin][$name])) {
        unset($all_templates[$clientLogin][$name]);
        file_put_contents($templates_file, json_encode($all_templates, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    header("Location: ".$_SERVER['REQUEST_URI']); exit;
}

// --- POST: Сохранение корректировки расхода (0 = удалить) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_spent_correction'], $_POST['cid'])) {
    $cid = $_POST['cid'];
    $val = floatval($_POST['save_spent_correction']);
    if ($val <= 0) unset($spent_corrections[$cid]);
    else $spent_corrections[$cid] = $val;
    file_put_contents($corrections_file, json_encode($spent_corrections, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    header("Location: ".$_SERVER['REQUEST_URI']); exit;
}

// --- POST: Массовое сохранение лимитов ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save_limits'])) {
    $data = json_decode($_POST['bulk_save_limits'], true);
    if (is_array($data)) {
        foreach ($data as $cid => $val) { $budgets[$cid] = intval($val); }
        file_put_contents($budgets_file, json_encode($budgets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else { echo json_encode(['success' => false]); }
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

// --- AJAX: сохранение недельного лимита ---
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
    change_campaign_status($access_token, $_POST['login'], $_POST['cid'], $_POST['change_status']);
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
        "params" => ["SelectionCriteria" => ["Ids" => [$campaign_id]]]
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
            'SelectionCriteria' => ['Ids' => $ids],
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
    if ($state === 'ON') return '<span style="color:green;font-size:1.2em;" title="Активна">&#9679;</span>';
    if ($state === 'OFF') return '<span style="color:#bb2c2c;font-size:1.2em;" title="Остановлена">&#9679;</span>';
    if ($state === 'SUSPENDED') return '<span style="color:orange;font-size:1.2em;" title="Приостановлена">&#9679;</span>';
    if ($state === 'ARCHIVED') return '<span style="color:gray;font-size:1.2em;" title="Архив">&#9679;</span>';
    return '<span style="color:gray;font-size:1.2em;">&#9679;</span>';
}

// --- Получаем данные ---
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
    .btn-action { padding:7px 15px; cursor:pointer; font-size: 0.9em; }
    .row-selector { width: 18px; height: 18px; cursor: pointer; }
    .template-box { background: #f4f4f9; padding: 15px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #ddd; display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
    .divider { height: 24px; width: 1px; background: #ccc; margin: 0 5px; }
    .manual-val { color: #d00; font-weight: bold; }
    </style>
</head>
<body>
    <div class="top-row">
        <h2>Отчет по клиенту: <?=htmlspecialchars($clientName)?> (<?=htmlspecialchars($clientLogin)?>)</h2>
        <a href="ln_report.php" class="get-btn">← К списку клиентов</a>
        <a href="https://direct.yandex.ru/dna/grid/campaigns?ulogin=<?=urlencode($clientLogin)?>" target="_blank" class="get-btn" style="margin-left:12px; background:#ded;">В кабинет Яндекс.Директ</a>
        <a href="javascript:history.back()" class="get-btn">Назад</a>
    </div>

    <div class="template-box">
        <input type="text" id="searchInput" class="budget-search" placeholder="Поиск по имени..." style="padding:6px 10px; width:180px;" onkeyup="if(event.key==='Enter') budgetSearch()">
        
        <div class="divider"></div>

        <strong>Шаблоны фильтров:</strong>
        <select id="templateSelector" onchange="applyTemplate(this)" style="padding:5px; max-width: 150px;">
            <option value="">-- Выбрать --</option>
            <?php foreach ($client_templates as $name => $tpl): ?>
                <option value="<?=htmlspecialchars($name)?>" data-search="<?=htmlspecialchars($tpl['search'])?>" data-status="<?=htmlspecialchars($tpl['status'])?>"><?=htmlspecialchars($name)?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" onclick="saveCurrentAsTemplate()" style="padding:5px 8px;" title="Сохранить текущий поиск и фильтры">💾 Сохранить</button>
        <button type="button" onclick="deleteSelectedTemplate()" style="padding:5px; color:red;" title="Удалить выбранный шаблон">❌</button>
    </div>

    <div style="margin:20px 0; display:flex; align-items:center; flex-wrap: wrap; gap: 10px;">
        <label style="font-weight:bold;">Показать:</label>
        <select id="statusFilter" style="padding:6px;" onchange="budgetSearch()">
            <option value="all">Все кампании</option>
            <option value="active_only">Только активные</option>
            <option value="hide_archived">Скрыть архивные</option>
            <option value="hide_stopped">Скрыть остановленные</option>
        </select>

        <button onclick="budgetSearch()" style="padding:7px 18px;">Найти</button>

        <button onclick="distributeRemainingBudget()" class="btn-action" style="background:#fdf; border:1px solid #c9c;" title="Выровнять остаток дней у выбранных">Распределить поровну</button>
        <button onclick="transferRemainingBudget()" class="btn-action" style="background:#dfe; border:1px solid #9c9;" title="Перенести бюджет с остановленных/оранжевых на активные">Перенести остаток 🔄</button>
    </div>

    <table class="budget-table" id="budgets-table">
        <thead>
            <tr>
                <th style="width:30px;"><input type="checkbox" id="selectAllRows" onclick="toggleAllRows(this)" title="Выбрать все"></th>
                <th>Название кампании</th>
                <th onclick="sortByDays()" class="sort-header" title="Нажмите для сортировки (Архив всегда внизу)">Дней / Статус ↕️</th>
                <th>Расход за день</th>
                <th>Общий расход (исправление)</th>
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
    foreach ($all_stop_by_budgets as $val) {
        if (is_array($val) && isset($val['id'])) $updated_stop_by_budgets[$val['id']] = $val;
        elseif (is_numeric($val)) $updated_stop_by_budgets[$val] = $val;
    }

    foreach ($campaigns as $i => $camp) {
        $state = strtoupper($camp['State']); $cid = $camp['Id'];
        $lim_val = isset($budgets[$cid]) ? $budgets[$cid] : '';
        $is_archived = ($state === 'ARCHIVED') ? 1 : 0;

        // Расход: корректировка или API (с налогом 1.22)
        $spent_api = ($camp['Funds']['SharedAccountFunds']['Spend'] ?? $camp['Funds']['CampaignFunds']['Spend'] ?? 0);
        $spent_api_no_vat = round(($spent_api / 1000000) / 1.22, 2);
        $is_manual_spent = isset($spent_corrections[$cid]);
        $spent_final = $is_manual_spent ? $spent_corrections[$cid] : $spent_api_no_vat;

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
        $days_left = ($lim_val !== '' && $day_limit > 0) ? floor(max(0, ($lim_val - $spent_final) / $day_limit)) : '-';
        $sort_val = ($days_left === '-') ? 999999 : $days_left;

        if ($days_left !== '-' && $days_left <= 2) {
            $updated_stop_by_budgets[$cid] = ['id' => $cid, 'name' => $camp['Name'], 'login' => $clientLogin, 'days_left' => $days_left, 'date' => date('Y-m-d H:i:s')];
        } elseif ($days_left !== '-' && $days_left > 3 && isset($updated_stop_by_budgets[$cid])) {
            unset($updated_stop_by_budgets[$cid]);
        }

        $jsData[] = ['cid' => $cid, 'cost' => isset($spend_today[$cid])?$spend_today[$cid]:0, 'spent' => $spent_final, 'week_limit' => ($week_limit !== null) ? $week_limit : '', 'day_limit' => $day_limit, 'lim_val' => ($lim_val !== '') ? $lim_val : 0];

        echo '<tr class="'.(($state === 'ARCHIVED') ? 'archived-campaign' : '').' data-campaign-row" data-days="'.$sort_val.'" data-archived="'.$is_archived.'" data-idx="'.$i.'" data-state="'.$state.'">';
        echo '<td><input type="checkbox" class="row-selector" onchange="updateTotals()"></td>';
        echo '<td style="padding-left:10px;"><a href="https://direct.yandex.ru/dna/campaigns-edit?ulogin='.urlencode($clientLogin).'&campaigns-ids='.urlencode($cid).'" target="_blank" style="color:#7b288f; text-decoration:underline;">'.htmlspecialchars($camp['Name']).'</a></td>';
        echo '<td>' . $days_left . ' &nbsp; ' . state_icon($camp['State']);
        if ($state === 'ON') {
            echo '<form method="post" style="display:inline;margin-left:8px;"><input type="hidden" name="cid" value="'.$cid.'"><input type="hidden" name="login" value="'.$clientLogin.'"><button name="change_status" value="suspend" title="Остановить" style="background:none;border:none;color:#bb2c2c;cursor:pointer;">⏸️</button></form>';
        } elseif ($state === 'OFF') {
            echo '<form method="post" style="display:inline;margin-left:8px;"><input type="hidden" name="cid" value="'.$cid.'"><input type="hidden" name="login" value="'.$clientLogin.'"><button name="change_status" value="resume" title="Включить" style="background:none;border:none;color:green;cursor:pointer;">▶️</button></form>';
        }
        echo '</td>';
        echo '<td class="cell-cost">'.number_format($jsData[$i]['cost'], 2, '.', ' ').'</td>';

        // Общий расход с СКРЫТОЙ корректировкой
        echo '<td class="cell-spent">';
        echo '  <div style="display:flex; align-items:center; justify-content:space-between;">';
        echo '    <div style="'.($is_manual_spent ? 'color:#d00;font-weight:bold;' : '').'">'.number_format($spent_final, 2, '.', ' ').'</div>';
        echo '    <button type="button" onclick="toggleCorrection(\''.$cid.'\')" style="background:none;border:none;cursor:pointer;font-size:0.9em;" title="Исправить расход (0 = сброс)">✏️</button>';
        echo '  </div>';
        echo '  <form method="post" id="corr_form_'.$cid.'" style="display:none; margin-top:5px; align-items:center;">';
        echo '    <input name="save_spent_correction" style="width:65px; font-size:0.85em;" placeholder="0=Reset" value="'.($is_manual_spent ? $spent_corrections[$cid] : '').'">';
        echo '    <input type="hidden" name="cid" value="'.$cid.'">';
        echo '    <button type="submit" style="font-size:0.8em; margin-left:2px;">OK</button>';
        echo '  </form>';
        echo '</td>';

        echo '<td id="week_limit_cell_'.$cid.'">';
        if ($week_limit !== null) {
            echo '<span style="'.($manual_limit_set ? 'color:#da8706;font-weight:bold;' : '').'">' . number_format($week_limit, 0, ',', ' ') . ' ₽ / ' . number_format($day_limit, 0, ',', ' ') . ' ₽' . ($manual_limit_set ? ' <span title="Введено вручную">*</span> <a href="#" onclick="editWeekLimit(\''.$cid.'\', '.$week_limit.'); return false;" style="margin-left:5px;">✎</a>' : '') . '</span>';
        } else {
            echo '<input type="number" min="1" style="width:95px;" placeholder="Лимит/нед."> <button onclick="saveWeekLimit(\''.$cid.'\', this)">OK</button>';
        }
        echo '</td>';
        echo '<td class="cell-lim"><div style="font-weight:bold; font-size:1.15em; margin-bottom:3px;">'.($lim_val !== '' ? number_format($lim_val, 0, ',', ' ') : '-').'</div><form method="post" style="display:flex;align-items:center;margin:0;" id="form_lim_'.$cid.'"><input name="save_limit" style="width:70px;text-align:right;"><input type="hidden" name="cid" value="'.$cid.'"><button type="submit" style="margin-left:2px;cursor:pointer;">💾</button><div style="display:flex; gap:2px; margin-left:5px;">';
        if ($day_limit > 0) echo '<button type="button" class="btn-quick-add" onclick="quickAddBudget(\''.$cid.'\', '.$day_limit.', '.($lim_val?:0).')" title="+30 дней">+30</button>';
        echo '<button type="button" class="btn-quick-add" onclick="quickAdjustLimit(\''.$cid.'\', \'add\', '.($lim_val?:0).')" title="Добавить">+</button><button type="button" class="btn-quick-add" onclick="quickAdjustLimit(\''.$cid.'\', \'sub\', '.($lim_val?:0).')" title="Отнять">-</button></div></form></td>';
        echo '</tr>';
    }
    file_put_contents($stop_file, json_encode(array_keys($updated_stop_by_budgets), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
?>
        </tbody>
        <tr class="total-row" id="totals-row">
            <td colspan="3">Итого (выбрано):</td><td id="totals-cost">0.00</td><td id="totals-spent">0.00</td><td id="totals-weekday">0 / 0</td><td id="totals-lim">0</td>
        </tr>
    </table>

    <form id="tpl_save_form" method="post" style="display:none;">
        <input type="hidden" name="save_template" value="1"><input type="hidden" name="tpl_name" id="tpl_name_in"><input type="hidden" name="tpl_search" id="tpl_search_in"><input type="hidden" name="tpl_status" id="tpl_status_in">
    </form>
    <form id="tpl_del_form" method="post" style="display:none;"><input type="hidden" name="delete_template" id="tpl_del_name"></form>

<script>
var jsData = <?php echo json_encode($jsData, JSON_UNESCAPED_UNICODE); ?>;
var daysSortOrder = 'asc';

function applyTemplate(sel) {
    var opt = sel.options[sel.selectedIndex]; if (!opt.value) return;
    document.getElementById('searchInput').value = opt.getAttribute('data-search');
    document.getElementById('statusFilter').value = opt.getAttribute('data-status');
    budgetSearch();
}
function saveCurrentAsTemplate() {
    var name = prompt("Название шаблона:"); if (!name) return;
    document.getElementById('tpl_name_in').value = name;
    document.getElementById('tpl_search_in').value = document.getElementById('searchInput').value;
    document.getElementById('tpl_status_in').value = document.getElementById('statusFilter').value;
    document.getElementById('tpl_save_form').submit();
}
function deleteSelectedTemplate() {
    var sel = document.getElementById('templateSelector');
    if (!sel.value || !confirm("Удалить шаблон '" + sel.value + "'?")) return;
    document.getElementById('tpl_del_name').value = sel.value; document.getElementById('tpl_del_form').submit();
}

function toggleCorrection(cid) { var f = document.getElementById('corr_form_' + cid); f.style.display = (f.style.display === 'none' || f.style.display === '') ? 'flex' : 'none'; }

function budgetSearch() {
    var v = document.getElementById('searchInput').value.toLowerCase(), f = document.getElementById('statusFilter').value;
    document.querySelectorAll('.data-campaign-row').forEach(tr => {
        var n = tr.querySelector('td:nth-child(2)').innerText.toLowerCase(), s = tr.getAttribute('data-state');
        var mS = (!v || n.indexOf(v) !== -1), mF = (f === 'all') || (f === 'active_only' && s === 'ON') || (f === 'hide_archived' && s !== 'ARCHIVED') || (f === 'hide_stopped' && s !== 'OFF');
        tr.style.display = (mS && mF) ? '' : 'none';
    });
    updateTotals();
}

function updateTotals() {
    var tc = 0, ts = 0, tw = 0, td = 0, tl = 0, cw = 0, cd = 0, cl = 0;
    document.querySelectorAll('.data-campaign-row').forEach(tr => {
        if (tr.style.display !== 'none' && tr.querySelector('.row-selector').checked) {
            var d = jsData[tr.getAttribute('data-idx')]; if (!d) return;
            tc += parseFloat(d.cost); ts += parseFloat(d.spent);
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

function transferRemainingBudget() {
    var selA = [], selS = [], pool = 0, remA = 0, dailyA = 0;
    document.querySelectorAll('.data-campaign-row').forEach(tr => {
        if (tr.style.display !== 'none' && tr.querySelector('.row-selector').checked) {
            var d = jsData[tr.getAttribute('data-idx')], st = tr.getAttribute('data-state'), daily = parseFloat(d.day_limit), spent = parseFloat(d.spent), rem = Math.max(0, parseFloat(d.lim_val) - spent);
            if (st === 'OFF' || st === 'SUSPENDED') { pool += rem; selS.push({cid: d.cid, spent: spent}); }
            else if (st === 'ON' && daily > 0) { remA += rem; dailyA += daily; selA.push({cid: d.cid, spent: spent, daily: daily}); }
        }
    });
    if (!selS.length || !selA.length) return alert("Выберите активные и остановленные/оранжевые!");
    var target = (remA + pool) / dailyA; if (!confirm("Перенести " + Math.round(pool) + " ₽ на активные?")) return;
    var bulk = {}; selS.forEach(i => bulk[i.cid] = Math.round(i.spent)); selA.forEach(i => bulk[i.cid] = Math.round(i.spent + (target * i.daily)));
    sendBulk(bulk);
}

function distributeRemainingBudget() {
    var sel = [], pool = 0, dailyT = 0;
    document.querySelectorAll('.data-campaign-row').forEach(tr => {
        if (tr.style.display !== 'none' && tr.querySelector('.row-selector').checked) {
            var d = jsData[tr.getAttribute('data-idx')], daily = parseFloat(d.day_limit), spent = parseFloat(d.spent), rem = Math.max(0, parseFloat(d.lim_val) - spent);
            if (daily > 0) { pool += rem; dailyT += daily; sel.push({cid: d.cid, spent: spent, daily: daily}); }
        }
    });
    if (!sel.length) return alert("Выберите кампании!");
    var target = pool / dailyT; var bulk = {};
    sel.forEach(i => bulk[i.cid] = Math.round(i.spent + (target * i.daily))); sendBulk(bulk);
}

function sendBulk(d) { fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'bulk_save_limits=' + encodeURIComponent(JSON.stringify(d)) }).then(r => r.json()).then(res => { if (res.success) location.reload(); }); }
function toggleAllRows(m) { document.querySelectorAll('.row-selector').forEach(chk => { if (chk.closest('tr').style.display !== 'none') chk.checked = m.checked; }); updateTotals(); }
function quickAddBudget(cid, daily, cur) { var inp = document.getElementById('form_lim_' + cid).querySelector('input[name="save_limit"]'); inp.value = Math.round(cur + (daily * 30)); inp.form.submit(); }
function quickAdjustLimit(cid, act, cur) {
    var am = prompt("Сумма:"); if (!am || isNaN(am)) return;
    var inp = document.getElementById('form_lim_' + cid).querySelector('input[name="save_limit"]');
    inp.value = Math.round(act === 'add' ? cur + parseFloat(am) : cur - parseFloat(am)); inp.form.submit();
}

function sortByDays() {
    const tbody = document.getElementById('table-body'), rows = Array.from(tbody.querySelectorAll('tr.data-campaign-row'));
    rows.sort((a, b) => {
        const archA = parseInt(a.getAttribute('data-archived')), archB = parseInt(b.getAttribute('data-archived'));
        if (archA !== archB) return archA - archB;
        var vA = parseInt(a.getAttribute('data-days')), vB = parseInt(b.getAttribute('data-days'));
        return daysSortOrder === 'asc' ? vA - vB : vB - vA;
    });
    daysSortOrder = (daysSortOrder === 'asc') ? 'desc' : 'asc';
    rows.forEach(row => tbody.appendChild(row));
}

function saveWeekLimit(campaignId, btn) {
    var input = document.getElementById('week_limit_cell_' + campaignId).querySelector('input'), val = parseInt(input.value);
    if (isNaN(val) || val < 0) return alert('Число!');
    btn.disabled = true;
    fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'campaign_id=' + encodeURIComponent(campaignId) + '&week_limit=' + encodeURIComponent(val) }).then(r => r.json()).then(res => { if (res.success) location.reload(); });
}

function editWeekLimit(campaignId, current) {
    document.getElementById('week_limit_cell_' + campaignId).innerHTML = '<input type="number" value="'+current+'" style="width:95px;"> <button onclick="saveWeekLimit(\''+campaignId+'\', this)">OK</button>';
}

window.addEventListener('DOMContentLoaded', updateTotals);
</script>
</body>
</html>