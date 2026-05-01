<?php
require_once "auth_check.php";

// Чтение лимитов
$budgets_file = __DIR__ . "/ln_budgets.json";
$budgets = file_exists($budgets_file) ? json_decode(file_get_contents($budgets_file), true) : [];

// Загружаем связи ID → логин
$logins_file = __DIR__ . "/campaign_logins.json";
$campaign_logins = file_exists($logins_file) ? json_decode(file_get_contents($logins_file), true) : [];

// Читаем список ID из ln_stop_by_budgets.json
$stop_file = __DIR__ . "/ln_stop_by_budgets.json";
if (!file_exists($stop_file)) exit(json_encode(['stopped' => 0, 'debug' => ['Файл stop_by_budgets не найден']]));
$stop_by_budgets = json_decode(file_get_contents($stop_file), true);
if (!is_array($stop_by_budgets)) $stop_by_budgets = [];

session_start();
$access_token = $_SESSION['ya_access_token'] ?? null;
if (!$access_token) exit(json_encode(['stopped' => 0, 'debug' => ['Нет access_token']]));

$count = 0;
$debug = [];

// Функция смены статуса кампании
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

// Получить детали кампаний пакетно
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
            'FieldNames' => ['Id', 'Name', 'State', 'Funds']
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
    if (!empty($data['error']) || $data === null) {
        return [];
    }
    return $data['result']['Campaigns'] ?? [];
}

// --- Собираем кампании для каждого логина, пакетно --- 
$login_campaigns = [];
foreach ($stop_by_budgets as $cid) {
    if (!isset($campaign_logins[$cid])) {
        $debug[] = "Не найден логин для кампании $cid";
        continue;
    }
    $login = $campaign_logins[$cid];
    if (!isset($login_campaigns[$login])) $login_campaigns[$login] = [];
    $login_campaigns[$login][] = $cid;
}

// --- Обрабатываем по логинам (batch-запросом) ---
foreach ($login_campaigns as $login => $ids) {
    $camps = get_campaigns_details_by_ids($access_token, $login, $ids);
    foreach ($camps as $camp) {
        $cid = $camp['Id'];
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

        $limit = isset($budgets[$cid]) ? intval($budgets[$cid]) : 0;
        if ($limit === 0) {
            $debug[] = "Кампания $cid: не задан лимит, пропуск";
            continue;
        }

        if ($spentNoVAT >= $limit) {
            change_campaign_status($access_token, $login, $cid, 'suspend');
            $count++;
            $debug[] = "Кампания $cid ОСТАНОВЛЕНА: расход $spentNoVAT, лимит $limit";
        } else {
            $debug[] = "Кампания $cid НЕ остановлена: расход $spentNoVAT, лимит $limit";
        }
    }
}

echo json_encode(['stopped' => $count, 'debug' => $debug]);
