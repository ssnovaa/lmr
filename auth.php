<?php
require_once "auth_check.php"; // Не пускает без логина/пароля
require_once "config.php";

// session_start(); // Уже вызван в auth_check.php

if (!isset($_GET['code'])) {
    // Перенаправляем пользователя на страницу авторизации Яндекса
    $auth_url = 'https://oauth.yandex.ru/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id'     => YANDEX_CLIENT_ID,
        'redirect_uri'  => YANDEX_REDIRECT_URI,
        'force_confirm' => 'yes'
    ]);
    header('Location: ' . $auth_url);
    exit;
} else {
    // Пришёл code, меняем на токен
    $params = [
        'grant_type'    => 'authorization_code',
        'code'          => $_GET['code'],
        'client_id'     => YANDEX_CLIENT_ID,
        'client_secret' => YANDEX_CLIENT_SECRET,
    ];
    $ch = curl_init('https://oauth.yandex.ru/token');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($result, true);

    if (isset($data['access_token'])) {
        $_SESSION['ya_access_token'] = $data['access_token'];

        // --- Записываем access_token в файл для крон-скриптов ---
        file_put_contents(__DIR__ . '/ya_access_token.txt', $data['access_token']);

        header('Location: index.php'); // после авторизации на главную
        exit;
    } else {
        echo "<b>Ошибка авторизации Яндекс:</b><br><pre>" . htmlspecialchars($result) . "</pre>";
        echo '<br><a href="index.php">Вернуться назад</a>';
    }
}
