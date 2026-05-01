<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['campaign_id']) && isset($_POST['week_limit'])) {
    $campaignId = trim($_POST['campaign_id']);
    $weekLimit = intval($_POST['week_limit']);

    if ($weekLimit <= 0) {
        echo json_encode(['success' => false, 'message' => 'Некорректный лимит']);
        exit;
    }

    $file = __DIR__ . '/manual_week_limits.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    $data[$campaignId] = $weekLimit;
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    echo json_encode([
        'success' => true,
        'week_limit' => $weekLimit,
        'day_limit' => round($weekLimit / 7)
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ошибка запроса']);
exit;
