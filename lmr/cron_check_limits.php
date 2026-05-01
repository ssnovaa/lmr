<?php
// --- Параметры ---
$token_file = __DIR__ . '/ya_access_token.txt'; // Файл с токеном API

// --- Файлы ---
$stop_file = __DIR__ . "/ln_stop_by_budgets.json"; // Кампании на проверку
$log_file = __DIR__ . "/to_stop_campaigns.html"; // Лог остановленных кампаний
$campaign_logins_file = __DIR__ . "/campaign_logins.json"; // Маппинг campaign_id => client_login

// --- Обрезка лога при превышении лимита ---
function trim_log_file($log_file, $max_lines = 2000, $keep_lines = 500) {
    if (!file_exists($log_file)) return;
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $total = count($lines);
    if ($total > $max_lines) {
        // Оставить только последние $keep_lines строк
        $lines = array_slice($lines, -$keep_lines);
        file_put_contents($log_file, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    }
}
trim_log_file($log_file, 2000, 500);

// --- Получение токена ---
if (!file_exists($token_file)) {
    die("Нет файла ya_access_token.txt\n");
}
$access_token = trim(file_get_contents($token_file));
if (!$access_token) {
    die("Пустой токен\n");
}

// --- Поиск и загрузка лимитов ---
function find_campaign_limit($cid) {
    $search_paths = [
        __DIR__ . "/ln_budgets.json",              // основной (lmr/ln_budgets.json)
        __DIR__ . "/at/ln_budgets.json",           // запасной (lmr/at/ln_budgets.json)
        __DIR__ . "/lmr/ln_budgets.json",          // второй запасной (lmr/lmr/ln_budgets.json)
    ];
    foreach ($search_paths as $path) {
        if (file_exists($path)) {
            $budgets = json_decode(file_get_contents($path), true);
            if (is_array($budgets) && isset($budgets[$cid])) {
                return [$budgets[$cid], $path];
            }
        }
    }
    return [null, null];
}

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

// --- Остановка кампании через suspend ---
function stop_campaign($access_token, $client_login, $cid) {
    $url = 'https://api.direct.yandex.com/json/v5/campaigns';
    $headers = [
        "Authorization: Bearer $access_token",
        "Accept-Language: ru",
        "Content-Type: application/json; charset=utf-8",
        "Client-Login: $client_login"
    ];
    $body = [
        "method" => "suspend",
        "params" => [
            "SelectionCriteria" => [
                "Ids" => [$cid]
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
    return (empty($data['error']));
}
// --- Основной цикл проверки и остановки ---
$to_stop = [];
$log_entries = [];

foreach ($stop_campaigns as $idx => $cid) {
    $cid = (string)$cid;

    // --- Проверка лимита по всем вариантам
    list($limit, $limit_path) = find_campaign_limit($cid);
    if ($limit === null) {
        // Не пишем такие в финальный красивый лог
        continue;
    }
    if (!isset($campaign_logins[$cid])) {
        // Не пишем такие в финальный красивый лог
        continue;
    }

    $client_login = $campaign_logins[$cid];
    $camp = get_campaign_details($access_token, $client_login, $cid);
    if (!$camp) {
        // Не пишем такие в финальный красивый лог
        continue;
    }

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
    $spentNoVAT = $spentRur / 1.2;

    if ($spentNoVAT >= $limit) {
        $stopped = stop_campaign($access_token, $client_login, $cid);
        if ($stopped) {
            $to_stop[] = $cid;
            $link = "https://direct.yandex.ru/dna/campaigns-edit?ulogin=" . urlencode($client_login) . "&campaigns-ids=" . urlencode($cid);
            $log_entries[] = date('Y-m-d H:i:s') . " — <a href=\"$link\" target=\"_blank\">Кампания $cid ($client_login)</a> ОСТАНОВЛЕНА (расход: " . round($spentNoVAT, 2) . ", лимит: $limit)";
            unset($stop_campaigns[$idx]);
        } else {
            $log_entries[] = date('Y-m-d H:i:s') . " — Кампания $cid ($client_login) НЕ остановлена (ошибка API или статус не изменился)";
        }
    }
}

// --- Сохраняем обновлённый список на проверку ---
file_put_contents($stop_file, json_encode(array_values($stop_campaigns), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// --- Логирование итога ---
if ($log_entries) {
    $html = "<html><head><meta charset='utf-8'></head><body>\n";
    $html .= "<div style='font-family:monospace;font-size:15px;'>";
    $html .= "<b>Остановленные кампании:</b><br>";
    foreach ($log_entries as $entry) {
        $html .= $entry . "<br>\n";
    }
    $html .= "</div>\n";
    $html .= "</body></html>";
    file_put_contents($log_file, $html, FILE_APPEND | LOCK_EX);
} else {
    // Только итоговая запись: ничего не остановлено
    $now = date('Y-m-d H:i:s');
    $html = "<html><head><meta charset='utf-8'></head><body>\n";
    $html .= "<div style='font-family:monospace;font-size:15px;'>";
    $html .= "<b>Проверка завершена $now — останавливать нечего.</b>";
    $html .= "</div></body></html>";
    file_put_contents($log_file, $html, FILE_APPEND | LOCK_EX);
}

// --- Скрипт завершён без вывода ---
