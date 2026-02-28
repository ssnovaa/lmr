<?php
/**
 * VK Ads API
 * Загрузка кампании целиком: AdPlan → AdGroups → Banners
 *
 * Проектный стиль: простой PHP + curl
 */

header('Content-Type: application/json; charset=utf-8');

/** ================= НАСТРОЙКИ ================= */

$ACCESS_TOKEN = trim(file_get_contents(__DIR__ . '/../ya_access_token.txt'));
// если токен VK хранится отдельно — укажи путь явно
// $ACCESS_TOKEN = trim(file_get_contents(__DIR__ . '/vk_access_token.txt'));

$AD_PLAN_ID = 16083565;

$API_URL = 'https://ads.vk.com/api/v2';

/** ================= CURL ================= */

function vk_api_get($endpoint, $params = [])
{
    global $ACCESS_TOKEN, $API_URL;

    $url = $API_URL . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $ACCESS_TOKEN,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        return [
            'error' => true,
            'message' => curl_error($ch)
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode >= 400) {
        return [
            'error' => true,
            'http_code' => $httpCode,
            'response' => $data
        ];
    }

    return $data;
}

/** ================= ЗАПРОСЫ ================= */

// 1. Кампания
$campaign = vk_api_get('/ad_plans/' . $AD_PLAN_ID);
if (!empty($campaign['error'])) {
    echo json_encode($campaign, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 2. Группы
$groups = vk_api_get('/ad_groups', [
    'ad_plan_id' => $AD_PLAN_ID,
    'limit' => 200,
    'offset' => 0
]);

$groupsItems = $groups['items'] ?? [];

// 3. Баннеры
foreach ($groupsItems as &$group) {
    $banners = vk_api_get('/banners', [
        'ad_group_id' => $group['id'],
        'limit' => 200,
        'offset' => 0
    ]);

    $group['banners'] = $banners['items'] ?? [];
}
unset($group);

/** ================= РЕЗУЛЬТАТ ================= */

$result = [
    'campaign' => $campaign,
    'ad_groups' => $groupsItems
];

// debug-файл, как в проекте
file_put_contents(
    __DIR__ . '/vk_campaign_' . $AD_PLAN_ID . '.json',
    json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
