<?php
require_once 'auth_check.php'; // Проверка авторизации

header('Content-Type: application/json');

$names_file = __DIR__ . "/clients_ln_names.json";

// Только POST-запросы!
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = $_POST['login'] ?? '';
    $new_name = trim($_POST['new_name'] ?? '');

    if (!$login) {
        echo json_encode(['success' => false, 'message' => 'Нет логина']);
        exit;
    }

    // Чтение текущих ручных имён
    $manual_names = [];
    if (file_exists($names_file)) {
        $manual_names = json_decode(file_get_contents($names_file), true) ?: [];
    }

    // Если поле пустое, удаляем ручное имя (вернуть на API-имя)
    if ($new_name === '' || $new_name === null) {
        unset($manual_names[$login]);
    } else {
        $manual_names[$login] = $new_name;
    }

    // Сохраняем
    file_put_contents($names_file, json_encode($manual_names, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['success' => true]);
    exit;
}

// Неверный метод
echo json_encode(['success' => false, 'message' => 'Некорректный запрос']);
