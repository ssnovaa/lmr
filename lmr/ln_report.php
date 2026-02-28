<?php
require_once "auth_check.php";

$clients_file = __DIR__ . "/clients_ln.json";
$clients = [];
if (file_exists($clients_file)) {
    $json = file_get_contents($clients_file);
    $clients = json_decode($json, true) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add' && !empty($_POST['new_client'])) {
        $new_client = trim($_POST['new_client']);
        if ($new_client && !in_array($new_client, $clients)) {
            $clients[] = $new_client;
            file_put_contents($clients_file, json_encode($clients, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        }
        header("Location: ln_report.php");
        exit;
    }
    if ($_POST['action'] === 'delete' && isset($_POST['client'])) {
        $client = $_POST['client'];
        $index = array_search($client, $clients);
        if ($index !== false) {
            array_splice($clients, $index, 1);
            file_put_contents($clients_file, json_encode($clients, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        }
        header("Location: ln_report.php");
        exit;
    }
    if ($_POST['action'] === 'edit' && isset($_POST['client'], $_POST['new_name'])) {
        $client = $_POST['client'];
        $new_name = trim($_POST['new_name']);
        $index = array_search($client, $clients);
        if ($index !== false && $new_name && !in_array($new_name, $clients)) {
            $clients[$index] = $new_name;
            file_put_contents($clients_file, json_encode($clients, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        }
        header("Location: ln_report.php");
        exit;
    }
}

$edit_client = $_GET['edit'] ?? null;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Lmr — клиенты</title>
    <link rel="stylesheet" href="ln_report.css?v=1">
</head>
<body>
    <div class="top-row">
        <h2>Lmr Клиенты </h2>
        <button id="fetchClientsBtn" class="get-btn" type="button">Получить данные клиентов</button>
        <a href="budgets.php" id="budgetsBtn" class="get-btn btn-progressable" class="get-btn">Работа с бюджетами</a>
        <a href="ln_ending_campaigns.php" class="get-btn btn-progressable" class="get-btn">Завершающиеся кампании</a>
        <a href="/lmr/" class="get-btn btn-progressable" class="get-btn">На главную</a>
        <a href="/lmr/to_stop_campaigns.html" class="get-btn btn-progressable" class="get-btn">Логи проверки</a>
        <a href="utm_litnet.html" class="get-btn btn-progressable" class="get-btn">Рокет Ссылка</a>
        <form method="post" class="add-form" id="add-client-form" autocomplete="off" style="position:relative;">
            <span id="show-add" class="get-btn" style="cursor:pointer;  font-weight:bold;">Добавить клиента</span>
            <span id="add-fields" style="display:none;">
                <input type="hidden" name="action" value="add">
                <input type="text" name="new_client" required autocomplete="off" style="margin-left:8px;">
                <button type="submit">Добавить</button>
                <span id="cancel-add" title="Отменить" style="cursor:pointer; color:#bb2c2c; font-size:1.25em; margin-left:9px;">&times;</span>
            </span>
        </form>
    </div>

    <ul class="client-list">
        <?php foreach($clients as $client): ?>
            <li>
                <?php if ($edit_client === $client): ?>
                    <form method="post" class="edit-form">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="client" value="<?=htmlspecialchars($client)?>">
                        <input type="text" name="new_name" value="<?=htmlspecialchars($client)?>" required>
                        <button type="submit">Сохранить</button>
                        <a href="ln_report.php">Отмена</a>
                    </form>
                <?php else: ?>
                    <span class="client-wrap">
                        <a href="ln_report_view.php?client=<?=urlencode($client)?>" class="client-link" target="_blank"><?=$client?></a>
                        <span class="client-actions">
                            <a href="ln_report.php?edit=<?=urlencode($client)?>" class="client-action-link">Редактировать</a>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="client" value="<?=htmlspecialchars($client)?>">
                                <button type="submit" class="client-action-link" onclick="return confirm('Удалить <?=$client?>?')">Удалить</button>
                            </form>
                        </span>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <br>
    <div id="clientsDataContainer"></div>
    <a href="/lmr/" class="client-link" style="display: inline-block;margin-top: 10px;">Назад</a>
    <script src="ln_report.js"></script>
</body>
</html>
