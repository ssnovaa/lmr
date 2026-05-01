<?php
require_once "auth_check.php";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Панель управления</title>
    <style>
        .btn {
            display: inline-block;
            padding: 10px 30px;
            margin: 8px 8px 16px 0;
            font-size: 18px;
            color: #fff;
            background: #4676d7;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn:hover { background: #314e8c; }
    </style>
</head>
<body>
    <h3>Добро пожаловать, <?=htmlspecialchars($_SESSION['my_user_logged'])?>!</h3>
    <a href="ln_report.php" class="btn">LN</a>
    <a href="/vkr/clients_table.php" class="btn">VKR</a>
    <a href="at/ln_report.php" class="btn">AT</a>
    <a href="lmr/ln_report.php" class="btn">LMR</a>
    <br><br>
<?php
if (isset($_SESSION['ya_access_token'])) {
    echo "<b>Авторизация в Яндексе успешна!</b><br>";
    echo "Ваш access_token:<br><pre>" . htmlspecialchars($_SESSION['ya_access_token']) . "</pre>";
    echo '<br><a href="logout.php">Выйти из панели</a>';
} else {
    echo '<a href="auth.php">Войти через Яндекс.Директ</a><br>';
    echo '<a href="logout.php">Выйти из панели</a>';
}
?>
</body>
</html>
