<?php
// --- ОТЛАДКА: показываем текущую папку, путь к токену, clients_ln.json ---
echo "Текущий __DIR__: " . __DIR__ . "\n";
$clients_file = __DIR__ . "/clients_ln.json";
echo "Ожидаемый файл логинов: $clients_file\n";

// Загружаем логины клиентов
$logins = file_exists($clients_file) ? json_decode(file_get_contents($clients_file), true) : [];
if (!$logins) {
    die("Нет логинов (clients_ln.json пуст или не найден)\n");
}
echo "Всего логинов: " . count($logins) . "\n";

// Получаем токен
$token_file = __DIR__ . "/ya_access_token.txt";
echo "Ожидаемый путь токена: $token_file\n";
if (!file_exists($token_file)) die("Нет токена (ya_access_token.txt не найден)\n");
$access_token = trim(file_get_contents($token_file));
if (!$access_token) die("Токен пустой\n");
echo "Токен найден (первые 10 символов): " . htmlspecialchars(substr($access_token, 0, 10)) . "...\n";

// API endpoints
$api_campaigns = "https://api.direct.yandex.com/json/v5/campaigns";
$api_adgroups = "https://api.direct.yandex.com/json/v5/adgroups";
$api_ads = "https://api.direct.yandex.com/json/v5/ads";

// Заголовок для вывода
header('Content-Type: text/plain; charset=utf-8');
foreach ($logins as $loginIdx => $login) {
    echo "\n==========\n";
    echo "Обработка клиента: $login (#" . ($loginIdx+1) . ")\n";
    // Получаем все кампании клиента
    $headers = [
        "Authorization: Bearer $access_token",
        "Accept-Language: ru",
        "Content-Type: application/json; charset=utf-8",
        "Client-Login: $login"
    ];
    $body = [
        "method" => "get",
        "params" => [
            "SelectionCriteria" => (object)[],
            "FieldNames" => ["Id"]
        ]
    ];
    $resp = yandexApiRequest($api_campaigns, $body, $headers);
    if (!isset($resp['result']['Campaigns'])) {
        echo "Нет кампаний для логина: $login\n";
        print_r($resp); // Отладка: показать ошибку API
        continue;
    }
    $camps = $resp['result']['Campaigns'];
    echo "Кампаний найдено: " . count($camps) . "\n";
    foreach ($camps as $campIdx => $camp) {
        $campaign_id = $camp['Id'];
        echo "  - Кампания $campaign_id (#" . ($campIdx+1) . ")\n";
        // Получаем все группы объявлений
        $body = [
            "method" => "get",
            "params" => [
                "SelectionCriteria" => ["CampaignIds" => [$campaign_id]],
                "FieldNames" => ["Id"]
            ]
        ];
        $resp = yandexApiRequest($api_adgroups, $body, $headers);
        if (!isset($resp['result']['AdGroups'])) {
            echo "    Нет групп объявлений (adgroups) в кампании $campaign_id\n";
            continue;
        }
        $groups = $resp['result']['AdGroups'];
        echo "    Групп объявлений: " . count($groups) . "\n";
        foreach ($groups as $groupIdx => $group) {
            $adgroup_id = $group['Id'];
            // Получаем все объявления (ads) в группе
            $body = [
                "method" => "get",
                "params" => [
                    "SelectionCriteria" => [
                        "CampaignIds" => [$campaign_id],
                        "AdGroupIds" => [$adgroup_id]
                    ],
                    "FieldNames" => ["Id"],
                    "TextAdFieldNames" => ["Href"]
                ]
            ];
            $resp = yandexApiRequest($api_ads, $body, $headers);
            if (!isset($resp['result']['Ads'])) {
                echo "      Нет объявлений (ads) в группе $adgroup_id\n";
                continue;
            }
            $ads = $resp['result']['Ads'];
            $count_links = 0;
            foreach ($ads as $ad) {
                if (!empty($ad['TextAd']['Href'])) {
                    // Выводим каждую ссылку на экран (каждая на новой строке)
                    echo $ad['TextAd']['Href'] . "\n";
                    $count_links++;
                }
            }
            echo "      Ссылок найдено: $count_links\n";
        }
    }
}
function yandexApiRequest($url, $body, $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $errstr = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        echo "CURL error ($errno): $errstr\n";
        return [];
    }

    if ($response === false) {
        echo "CURL вернул пустой ответ!\n";
        return [];
    }

    $data = json_decode($response, true);
    if ($data === null) {
        echo "JSON decode error! Исходный ответ:\n";
        echo $response . "\n";
        return [];
    }

    // Если есть ошибка API, выводим
    if (isset($data['error'])) {
        echo "Ошибка API:\n";
        print_r($data['error']);
    }
    return $data;
}
