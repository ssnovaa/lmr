<?php
require_once 'auth_check.php';
header('Content-Type: application/json');

// Читаем токен Яндекса из файла (без сессий)
$token_file = __DIR__ . '/../ya_access_token.txt';
if (!file_exists($token_file)) {
    echo json_encode(['error' => 'Файл токена не найден']);
    exit;
}
$token = trim(file_get_contents($token_file));
if (!$token) {
    echo json_encode(['error' => 'Токен пустой']);
    exit;
}

// Читаем логины клиентов из файла
$clients_file = __DIR__ . "/clients_ln.json";
if (!file_exists($clients_file)) {
    echo json_encode([]);
    exit;
}
$json = file_get_contents($clients_file);
$logins = json_decode($json, true) ?: [];
if (!$logins) {
    echo json_encode([]);
    exit;
}

// ----------- ДОБАВЬ ЭТО -------------------
// Читаем ручные имена клиентов из clients_ln_names.json
$manual_names_file = __DIR__ . "/clients_ln_names.json";
$manual_names = [];
if (file_exists($manual_names_file)) {
    $manual_names = json_decode(file_get_contents($manual_names_file), true) ?: [];
}
// ----------- КОНЕЦ ДОБАВКИ ----------------

$api_url_campaigns = "https://api.direct.yandex.com/json/v5/campaigns";
$api_url_clientinfo = "https://api.direct.yandex.com/json/v5/clients";

$clientRows = [];
foreach ($logins as $login) {
    // Получаем ClientId, ClientInfo (название) для логина
    $postInfo = [
        "method" => "get",
        "params" => [
            "FieldNames" => ["ClientId", "Login", "ClientInfo"]
        ]
    ];
    $headersInfo = [
        "Authorization: Bearer $token",
        "Accept-Language: ru",
        "Content-Type: application/json; charset=utf-8",
        "Client-Login: $login"
    ];
    $info = yandexApiRequest($api_url_clientinfo, $token, $postInfo, $headersInfo);

    $clientId = '';
    $clientName = '';
    if (!empty($info['result']['Clients'][0])) {
        $clientId = $info['result']['Clients'][0]['ClientId'];
        $clientName = $info['result']['Clients'][0]['ClientInfo'];
    }

    // Получаем кампании клиента
    $postCampaigns = [
        "method" => "get",
        "params" => [
            "SelectionCriteria" => (object)[], // все кампании
            "FieldNames" => ["Id", "Name", "State", "Status", "StatusPayment", "StatusClarification", "Type"]
        ]
    ];
    $headersCampaigns = [
        "Authorization: Bearer $token",
        "Accept-Language: ru",
        "Content-Type: application/json; charset=utf-8",
        "Client-Login: $login"
    ];
    $result = yandexApiRequest($api_url_campaigns, $token, $postCampaigns, $headersCampaigns);

    $active = 0;
    if (!empty($result['result']['Campaigns'])) {
        foreach ($result['result']['Campaigns'] as $c) {
            if ($c['State'] === 'ON') $active++;
        }
    }

    // ---- ЭТА СТРОКА ТЕПЕРЬ ЕСТЬ! ----
    $manual_name = isset($manual_names[$login]) && $manual_names[$login] !== '' ? $manual_names[$login] : null;

    $clientRows[] = [
        'id' => $clientId ?: $login,
        'login' => $login,
        'name' => $clientName ?: $login,
        'manual_name' => $manual_name, // <-- ЭТО ВАЖНО!
        'active_campaigns' => $active
    ];
}

usort($clientRows, function($a, $b) {
    return $b['active_campaigns'] <=> $a['active_campaigns'];
});

// --- Сохраняем расширенный массив ---
file_put_contents(__DIR__ . "/clients_ln_active.json", json_encode($clientRows, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

echo json_encode($clientRows);

// Универсальная функция для запросов к API Яндекс.Директ
function yandexApiRequest($url, $token, $postData, $extraHeaders = []) {
    $headers = [
        "Authorization: Bearer $token",
        "Accept-Language: ru",
        "Content-Type: application/json; charset=utf-8"
    ];
    if (!empty($extraHeaders)) $headers = $extraHeaders;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
