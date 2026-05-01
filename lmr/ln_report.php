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
    <style>
        .date-btn { padding: 4px 10px; font-size: 0.9em; cursor: pointer; background: #e0e0e0; border: 1px solid #ccc; border-radius: 4px; }
        .date-btn:hover { background: #d0d0d0; }
    </style>
</head>
<body>
    <div class="top-row">
        <h2>Lmr Клиенты </h2>
        <button id="fetchClientsBtn" class="get-btn" type="button">Получить данные клиентов</button>
        <a href="budgets.php" id="budgetsBtn" class="get-btn btn-progressable">Работа с бюджетами</a>
        <a href="ln_ending_campaigns.php" class="get-btn btn-progressable">Завершающиеся кампании</a>
        <a href="/lmr/" class="get-btn btn-progressable">На главную</a>
        <a href="/lmr/to_stop_campaigns.html" class="get-btn btn-progressable">Логи проверки</a>
        <a href="utm_litnet.html" class="get-btn btn-progressable">Рокет Ссылка</a>
        <form method="post" class="add-form" id="add-client-form" autocomplete="off" style="position:relative;">
            <span id="show-add" class="get-btn" style="cursor:pointer; font-weight:bold;">Добавить клиента</span>
            <span id="add-fields" style="display:none;">
                <input type="hidden" name="action" value="add">
                <input type="text" name="new_client" required autocomplete="off" style="margin-left:8px;">
                <button type="submit">Добавить</button>
                <span id="cancel-add" title="Отменить" style="cursor:pointer; color:#bb2c2c; font-size:1.25em; margin-left:9px;">&times;</span>
            </span>
        </form>
    </div>

    <details style="margin-bottom: 20px; border: 1px solid #ccc; border-radius: 6px; padding: 10px; background: #fafafa;">
        <summary style="cursor: pointer; font-weight: bold; outline: none; user-select: none; color: #333;">
            🗂️ Показать / скрыть список клиентов
        </summary>
        <ul class="client-list" style="margin-top: 15px;">
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
    </details>

    <!-- БЛОК ВЫБОРА ДАТЫ И СТАТИСТИКИ -->
    <div style="margin-bottom: 20px; padding: 12px; background: #eef2f5; border: 1px solid #cdd6de; border-radius: 6px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <label style="font-weight: bold;">Период:</label>
        <input type="date" id="filter_date_from" style="padding: 5px; border: 1px solid #ccc; border-radius: 4px;">
        <span>—</span>
        <input type="date" id="filter_date_to" style="padding: 5px; border: 1px solid #ccc; border-radius: 4px;">
        
        <button type="button" class="date-btn" onclick="setQuickDate('current_month')">Текущий месяц</button>
        <button type="button" class="date-btn" onclick="setQuickDate('last_month')">Прошлый месяц</button>
        <button type="button" class="date-btn" onclick="setQuickDate('all_time')">Весь период</button>

        <!-- НОВАЯ КНОПКА ПЕРЕХОДА К СТАТИСТИКЕ -->
        <button type="button" onclick="goToStatistics()" class="get-btn" style="margin-left: auto; background: #28a745; color: white; border-color: #218838; font-weight: bold;">
            Получить статистику 📊
        </button>
    </div>

    <script>
    function setQuickDate(period) {
        const fromInput = document.getElementById('filter_date_from');
        const toInput = document.getElementById('filter_date_to');
        const now = new Date();
        
        function formatDate(date) {
            const d = String(date.getDate()).padStart(2, '0');
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const y = date.getFullYear();
            return `${y}-${m}-${d}`;
        }

        if (period === 'current_month') {
            const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
            fromInput.value = formatDate(firstDay);
            toInput.value = formatDate(now);
        } else if (period === 'last_month') {
            const firstDay = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            const lastDay = new Date(now.getFullYear(), now.getMonth(), 0);
            fromInput.value = formatDate(firstDay);
            toInput.value = formatDate(lastDay);
        } else if (period === 'all_time') {
            fromInput.value = '';
            toInput.value = '';
        }
    }

    // НОВАЯ ФУНКЦИЯ ДЛЯ СБОРА ДАТ И ПЕРЕХОДА
    function goToStatistics() {
        const dateFrom = document.getElementById('filter_date_from').value;
        const dateTo = document.getElementById('filter_date_to').value;
        
        let url = 'ln_statistics.php';
        const params = new URLSearchParams();
        
        if (dateFrom) params.append('from', dateFrom);
        if (dateTo) params.append('to', dateTo);
        
        if (params.toString()) {
            url += '?' + params.toString();
        }
        
        window.location.href = url;
    }
    </script>
    <!-- КОНЕЦ БЛОКА ВЫБОРА ДАТЫ -->

    <br>
    <div id="clientsDataContainer"></div>
    <a href="/lmr/" class="client-link" style="display: inline-block;margin-top: 10px;">Назад</a>
    <script src="ln_report.js"></script>
</body>
</html>