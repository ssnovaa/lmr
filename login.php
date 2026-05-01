<?php
require_once "config.php";
session_start();

if (isset($_SESSION['my_user_logged'])) {
    header("Location: index.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = $_POST['login'] ?? '';
    $pass  = $_POST['password'] ?? '';
    if (isset(USERS[$login]) && USERS[$login] === $pass) {
        $_SESSION['my_user_logged'] = $login;
        header("Location: index.php");
        exit;
    } else {
        $error = 'Неверный логин или пароль!';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Вход в панель</title>
</head>
<body>
<h2>Вход</h2>
<?php if ($error) echo '<p style="color:red">'.$error.'</p>'; ?>
<form method="post">
    <label>Логин: <input name="login" autofocus></label><br>
    <label>Пароль: <input type="password" name="password"></label><br>
    <button type="submit">Войти</button>
</form>
</body>
</html>
