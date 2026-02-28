<?php
require_once "auth_check.php";

/*
    Универсальный ln_ending_campaigns.php — работает и из браузера, и из крон (CLI или ?cron=1)
    - Если CLI или GET-параметр cron=1 — только JSON-лог без HTML
    - Вся логика сбора/обновления вынесена в функцию process_ending_campaigns()
*/

// ======== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ========
function get_active_campaigns_ids($access_token, $client_login) {
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
            if (strtoupper($camp['State']) === 'ON') {
                $ids[] = $camp['Id'];
            }
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
    } else {
        return '<span style="color:gray;font-size:1.2em;" title="Архив/Снята">&#9679;</span>';
    }
}

// ======== ГЛАВНАЯ ЛОГИКА ========
function process_ending_campaigns() {
    $token_file = __DIR__ . '/../ya_access_token.txt';
    if (!file_exists($token_file)) die("Нет файла токена!");
    $access_token = trim(file_get_contents($token_file));
    if (!$access_token) die("Пустой токен!");
    $clients_file = __DIR__ . "/clients_ln_active.json";
    if (!file_exists($clients_file)) die("Нет файла clients_ln_active.json!");
    $clients = json_decode(file_get_contents($clients_file), true);

    $budgets_file = __DIR__ . "/ln_budgets.json";
    
    if (!file_exists($budgets_file)) file_put_contents($budgets_file, "{}");
    $budgets = json_decode(file_get_contents($budgets_file), true);

    $manual_week_file = __DIR__ . "/manual_week_limits.json";
    if (!file_exists($manual_week_file)) file_put_contents($manual_week_file, "{}");
    $manual_week_limits = json_decode(file_get_contents($manual_week_file), true);

    // === ВАЖНО: единый файл для всего проекта (корень lmr) ===
    $stop_file = dirname(__DIR__) . "/ln_stop_by_budgets.json";
    if (!file_exists($stop_file)) file_put_contents($stop_file, "[]");
    $stop_by_budgets = json_decode(file_get_contents($stop_file), true);
    if (!is_array($stop_by_budgets)) $stop_by_budgets = [];

    $ending_campaigns = [];
    $changed = false;

    // Для сбора id => login для campaign_logins.json (остается локально!)
    $new_campaign_logins = [];

    foreach ($clients as $client) {
        $login = $client['login'];
        $name = $client['name'];
        $active_ids = get_active_campaigns_ids($access_token, $login);
        $campaigns = get_campaigns_details_by_ids($access_token, $login, $active_ids);
        $spend_today = get_campaigns_daily_spend($access_token, $login, $active_ids);

        foreach ($campaigns as $camp) {
            $cid = $camp['Id'];
            $state = strtoupper($camp['State']);
            if ($state === 'ARCHIVED' || $state === 'SUSPENDED') continue;

            $lim_val = isset($budgets[$cid]) ? $budgets[$cid] : '';

            // --- Лимит на неделю ---
            $week_limit = null;
            $manual_limit_set = false;
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

            // --- Если нет API-лимита — берем ручной
            if ($week_limit === null && isset($manual_week_limits[$cid]) && $manual_week_limits[$cid] > 0) {
                $week_limit = intval($manual_week_limits[$cid]);
                $manual_limit_set = true;
            }

            // --- Если нет недельного лимита — не обрабатываем
            if ($week_limit === null) {
                continue;
            }

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

            $day_limit = floor($week_limit / 7);

            // --- Остаток дней до конца бюджета ---
            if ($lim_val !== '' && $day_limit > 0) {
                $days_left = floor( max(0, ($lim_val - $spentNoVAT) / $day_limit ) );
            } else {
                $days_left = '-';
            }

            // --- Обновляем массив ID в ln_stop_by_budgets.json (корень lmr) ---
            if ($days_left !== '-') {
                if ($days_left <= 2 && !in_array($cid, $stop_by_budgets)) {
                    $stop_by_budgets[] = $cid;
                    $changed = true;
                }
                if ($days_left > 3 && in_array($cid, $stop_by_budgets)) {
                    $stop_by_budgets = array_diff($stop_by_budgets, [$cid]);
                    $changed = true;
                }
            }

            // --- Собираем нужные кампании для вывода/лога ---
            if ($days_left !== '-' && $days_left < 3) {
                $cost = isset($spend_today[$camp['Id']]) ? $spend_today[$camp['Id']] : 0;
                $ending_campaigns[] = [
                    'login' => $login,
                    'name' => $name,
                    'cid' => $cid,
                    'camp_name' => $camp['Name'],
                    'state' => $camp['State'],
                    'days_left' => $days_left,
                    'cost' => $cost,
                    'spent' => $spentNoVAT,
                    'week_limit' => $week_limit,
                    'day_limit' => $day_limit,
                    'lim_val' => $lim_val
                ];
                // Для обновления campaign_logins.json
                $new_campaign_logins[$cid] = $login;
            }
        }
    }

    // --- Если были изменения, сохраняем ln_stop_by_budgets.json (корень lmr) ---
    if ($changed) {
        file_put_contents(dirname(__DIR__) . "/ln_stop_by_budgets.json", json_encode(array_values($stop_by_budgets), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // --- Обновляем campaign_logins.json (локально в своей папке) ---
   // $campaign_logins_file = __DIR__ . "/campaign_logins.json";
    // campaign_logins.json — всегда в папке lmr!
    $campaign_logins_file = dirname(__DIR__) . "/campaign_logins.json";
    $campaign_logins = file_exists($campaign_logins_file) ? json_decode(file_get_contents($campaign_logins_file), true) : [];
    if (!is_array($campaign_logins)) $campaign_logins = [];
    $campaign_logins = $new_campaign_logins + $campaign_logins;
    file_put_contents($campaign_logins_file, json_encode($campaign_logins, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return [
        'ending_campaigns' => $ending_campaigns,
        'changed' => $changed,
        'stop_by_budgets' => $stop_by_budgets
    ];
}

// --- Определяем режим ---
$is_cron = (php_sapi_name() === 'cli') || (isset($_GET['cron']) && $_GET['cron'] == 1);

// --- Запуск основной логики ---
session_start();
$result = process_ending_campaigns();

if ($is_cron) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ending_count' => count($result['ending_campaigns']),
        'changed' => $result['changed'],
        'ids' => array_column($result['ending_campaigns'], 'cid')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Завершающиеся кампании</title>
    <link rel="stylesheet" href="ln_report.css?v=1">
    <style>
    .total-row { font-weight: bold; background: #f8f8fb; }
    </style>
</head>
<body>
    <div class="top-row">
        <h2>Завершающиеся кампании (меньше 3 дней до лимита)</h2>
        <a href="ln_report.php" class="get-btn">← К списку клиентов</a>
        <button id="stopAllBtn" class="get-btn btn-red">Остановить перелимит</button>
        <a href="javascript:history.back()" class="get-btn">Назад</a>
    </div>
    <table class="budget-table" id="budgets-table">
        <tr>
            <th>Login</th>
            <th>Имя клиента</th>
            <th>Название кампании</th>
            <th>Дней до лимита</th>
            <th>Статус</th>
            <th>Расход за день</th>
            <th>Общий расход</th>
            <th>Лимит на неделю / день</th>
            <th>Общий лимит</th>
        </tr>
            <?php foreach ($result['ending_campaigns'] as $item): ?>
            <tr>
                <td>
                    <a href="https://direct.yandex.ru/dna/grid/campaigns?ulogin=<?=urlencode($item['login'])?>" target="_blank">
                        <?=htmlspecialchars($item['login'])?>
                    </a>
                </td>
                <td>
                    <a href="ln_report_view.php?client=<?=urlencode($item['login'])?>" target="_blank">
                        <?=htmlspecialchars($item['name'])?>
                    </a>
                </td>
                <td>
                    <a href="https://direct.yandex.ru/dna/campaigns-edit?ulogin=<?=urlencode($item['login'])?>&campaigns-ids=<?=urlencode($item['cid'])?>" target="_blank" style="color:#7b288f; text-decoration:underline;">
                        <?=htmlspecialchars($item['camp_name'])?>
                    </a>
                </td>
                <td><?=$item['days_left']?></td>
                <td><?=state_icon($item['state'])?> <span style="color:#555;"><?=htmlspecialchars($item['state'])?></span></td>
                <td class="cell-cost"><?=$item['cost']?></td>
                <td class="cell-spent"><?=$item['spent']?></td>
                <td class="cell-weekday"><?=($item['week_limit'] !== null ? $item['week_limit'] : '-')?> / <?=($item['day_limit'] !== null ? $item['day_limit'] : '-')?></td>
                <td class="cell-lim"><?=($item['lim_val'] !== '' ? $item['lim_val'] : '-')?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($result['ending_campaigns'])): ?>
            <tr><td colspan="9" style="text-align:center;color:#555;">Нет завершающихся кампаний</td></tr>
        <?php endif; ?>
    </table>
    <script>
        document.getElementById('stopAllBtn').onclick = function() {
            if (!confirm('Вы уверены, что хотите остановить все эти кампании?')) return;
            this.disabled = true;
            this.textContent = 'Останавливаем...';

            fetch('stop_by_budget.php', { method: 'POST' })
                .then(r => r.json())
                .then(data => {
                    alert('Остановлено: ' + data.stopped + ' кампаний');
                    location.reload();
                })
                .catch(() => {
                    alert('Ошибка запроса');
                    this.disabled = false;
                    this.textContent = 'Остановить перелимит';
                });
        };
    </script>
</body>
</html>
