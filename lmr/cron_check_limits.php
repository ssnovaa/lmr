<?php
// --- Параметры ---
$token_file = __DIR__ . '/../ya_access_token.txt'; // Файл с токеном API
 // Файл с токеном API

// --- Файлы ---
$budgets_file = __DIR__ . "/ln_budgets.json"; // Лимиты кампаний
$stop_file = __DIR__ . "/ln_stop_by_budgets.json"; // Кампании на проверку
$log_file = __DIR__ . "/to_stop_campaigns.log"; // Лог остановленных кампаний
$campaign_logins_file = __DIR__ . "/campaign_logins.json"; // Маппинг campaign_id => client_login

// --- Получение токена ---
if (!file_exists($token_file)) {
    die("Нет файла ya_access_token.txt\n");
}
$access_token = trim(file_get_contents($token_file));
if (!$access_token) {
    die("Пустой токен\n");
}

// --- Получение лимитов ---
if (!file_exists($budgets_file)) die("Нет файла ln_budgets.json\n");
$budgets = json_decode(file_get_contents($budgets_file), true);

// --- Получение списка кампаний для проверки ---
if (!file_exists($stop_file)) die("Нет файла ln_stop_by_budgets.json\n");
$stop_campaigns = json_decode(file_get_contents($stop_file), true);
if (!is_array($stop_campaigns)) $stop_campaigns = [];

// --- Получение маппинга campaign_id => client_login ---
if (!file_exists($campaign_logins_file)) die("Нет файла campaign_logins.json\n");
$campaign_logins = json_decode(file_get_contents($campaign_logins_file), true);
if (!is_array($campaign_logins)) $campaign_logins = [];

// --- Получение деталей кампании через API ---
function get_campaign_details($access_token, $client_login, $cid) {
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
            'SelectionCriteria' => ['Ids' => [$cid]],
            'FieldNames' => ['Id', 'Name', 'State', 'Funds'],
        ]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (!empty($data['result']['Campaigns'][0])) {
        return $data['result']['Campaigns'][0];
    }
    return null;
}

// --- Остановка кампании (смена состояния на OFF) ---
function stop_campaign($access_token, $client_login, $cid) {
    $url = 'https://api.direct.yandex.com/json/v5/campaigns';
    $headers = [
        "Authorization: Bearer $access_token",
        "Accept-Language: ru",
        "Content-Type: application/json; charset=utf-8",
        "Client-Login: $client_login"
    ];
    $body = [
        "method" => "update",
        "params" => [
            "Campaigns" => [
                [
                    "Id" => $cid,
                    "State" => "OFF"
                ]
            ]
        ]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return !empty($data['result']['Campaigns'][0]['State']) && $data['result']['Campaigns'][0]['State'] === 'OFF';
}

// --- Основной цикл проверки и остановки ---
$to_stop = [];

foreach ($stop_campaigns as $cid) {
    $cid = (string)$cid;
    if (!isset($budgets[$cid])) continue;          // Нет лимита — пропускаем
    if (!isset($campaign_logins[$cid])) continue; // Нет логина клиента — пропускаем

    $client_login = $campaign_logins[$cid];
    $limit = $budgets[$cid];

    $camp = get_campaign_details($access_token, $client_login, $cid);
    if (!$camp) continue;

    // Расход (микроскопы → рубли без НДС)
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

    if ($spentNoVAT > $limit) {
        $stopped = stop_campaign($access_token, $client_login, $cid);
        if ($stopped) {
            $to_stop[] = $cid;
        }
    }
}

// --- Логирование ---
$log_message = date('Y-m-d H:i:s') . ' - ';
if ($to_stop) {
    $log_message .= "Остановлены кампании: " . implode(', ', $to_stop);
} else {
    $log_message .= "Перелимит не обнаружен, кампании не остановлены";
}
file_put_contents($log_file, $log_message . "\n", FILE_APPEND | LOCK_EX);

// --- Скрипт завершён без вывода ---
