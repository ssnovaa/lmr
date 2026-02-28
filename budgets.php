<?php
require_once "auth_check.php";

$access_token = $_SESSION['ya_access_token'] ?? null;
if (!$access_token) die("Нет access_token в сессии!");

// Проверяем clients_ln_active.json
$clients_file = __DIR__ . "/clients_ln_active.json";
if (!file_exists($clients_file)) die("Нет файла clients_ln_active.json!");
$clients_raw = file_get_contents($clients_file);
$clients = json_decode($clients_raw, true);
if ($clients === null) exit("Ошибка чтения clients_ln_active.json!");

// Лимиты
$budgets_file = __DIR__ . "/ln_budgets.json";
if (!file_exists($budgets_file)) file_put_contents($budgets_file, "{}");
$budgets_raw = file_get_contents($budgets_file);
$budgets = json_decode($budgets_raw, true);
if ($budgets === null) exit("Ошибка чтения ln_budgets.json!");

// Ручные недельные лимиты
$manual_week_file = __DIR__ . "/manual_week_limits.json";
if (!file_exists($manual_week_file)) file_put_contents($manual_week_file, "{}");
$manual_week_raw = file_get_contents($manual_week_file);
$manual_week_limits = json_decode($manual_week_raw, true);
if ($manual_week_limits === null) exit("Ошибка чтения manual_week_limits.json!");

// --- Работаем с кампаниями на исходе бюджета ---
$stop_file = __DIR__ . "/ln_stop_by_budgets.json";
if (!file_exists($stop_file)) file_put_contents($stop_file, "[]");
$stop_by_budgets_raw = file_get_contents($stop_file);
$stop_by_budgets = json_decode($stop_by_budgets_raw, true);
if (!is_array($stop_by_budgets)) $stop_by_budgets = [];

// --- POST-запросы ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_limit'], $_POST['cid'])) {
    $cid = $_POST['cid'];
    $val = intval($_POST['save_limit']);
    $budgets[$cid] = $val;
    file_put_contents($budgets_file, json_encode($budgets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    header("Location: ".$_SERVER['REQUEST_URI']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['campaign_id'], $_POST['week_limit'])) {
    $cid = $_POST['campaign_id'];
    $week_limit = max(0, intval($_POST['week_limit']));
    if ($week_limit == 0) {
        unset($manual_week_limits[$cid]);
    } else {
        $manual_week_limits[$cid] = $week_limit;
    }
    file_put_contents($manual_week_file, json_encode($manual_week_limits, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'week_limit'=>$week_limit,'day_limit'=>round($week_limit/7)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'], $_POST['cid'], $_POST['login'])) {
    $cid = $_POST['cid'];
    $login = $_POST['login'];
    $action = $_POST['change_status'];
    change_campaign_status($access_token, $login, $cid, $action);
    header("Location: ".$_SERVER['REQUEST_URI']); exit;
}

function change_campaign_status($access_token, $client_login, $campaign_id, $action) {
    $url = 'https://api.direct.yandex.com/json/v5/campaigns';
    $headers = [
        "Authorization: Bearer $access_token",
        "Accept-Language: ru",
        "Content-Type: application/json; charset=utf-8",
        "Client-Login: $client_login"
    ];
    $body = [
        "method" => $action,
        "params" => [
            "SelectionCriteria" => [
                "Ids" => [$campaign_id]
            ]
        ]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// --- ОБНОВЛЁННАЯ функция для проверки прав ---
function get_active_campaigns_ids($access_token, $client_login) {
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
            'SelectionCriteria' => (object)[],
            'FieldNames' => ['Id', 'Name', 'State']
        ]
    ];
    $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!empty($data['error'])) {
        // Нет прав — возвращаем false
        return false;
    }
    $ids = [];
    if (!empty($data['result']['Campaigns']) && is_array($data['result']['Campaigns'])) {
        foreach ($data['result']['Campaigns'] as $camp) {
            if (strtoupper($camp['State']) === 'ON') {
                $ids[] = $camp['Id'];
            }
        }
    }
    return $ids;
}
function get_campaigns_daily_spend($access_token, $client_login, $ids) {
    if (empty($ids) || $ids === false) return [];
    $url = 'https://api.direct.yandex.com/json/v5/reports';
    $headers = [
        "Authorization: Bearer $access_token",
        "Client-Login: $client_login",
        "Accept-Language: ru",
        "processingMode: auto",
        "returnMoneyInMicros: false",
        "skipReportHeader: true",
        "skipReportSummary: true",
        "Content-Type: application/json; charset=utf-8"
    ];
    $body = json_encode([
        "params" => [
            "SelectionCriteria" => [
                "Filter" => [
                    [
                        "Field" => "CampaignId",
                        "Operator" => "IN",
                        "Values" => $ids,
                    ]
                ]
            ],
            "FieldNames" => ["CampaignId", "Cost"],
            "ReportName" => "DailySpend_" . time(),
            "ReportType" => "CAMPAIGN_PERFORMANCE_REPORT",
            "DateRangeType" => "TODAY",
            "Format" => "TSV",
            "IncludeVAT" => "NO",
            "IncludeDiscount" => "NO"
        ]
    ]);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $result = curl_exec($ch);
    curl_close($ch);

    $lines = explode("\n", trim($result));
    $day_spent = [];
    foreach ($lines as $line) {
        if (!$line) continue;
        if (strpos($line, "\t") === false) continue;
        list($cid, $cost) = explode("\t", $line);
        $day_spent[$cid] = round((float)$cost);
    }
    return $day_spent;
}

function get_campaigns_details_by_ids($access_token, $client_login, $ids) {
    if (empty($ids) || $ids === false) return [];
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
            'SelectionCriteria' => [
                'Ids' => $ids
            ],
            'FieldNames' => ['Id', 'Name', 'State', 'Status', 'Type', 'Funds', 'DailyBudget', 'StartDate'],
            'TextCampaignFieldNames' => ["BiddingStrategy", "Settings"]
        ]
    ];
    $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!empty($data['error']) || $data === null) {
        return [];
    }
    return $data['result']['Campaigns'] ?? [];
}

// Вспомогательные
function state_icon($state) {
    $state = strtoupper($state);
    if ($state === 'ON') {
        return '<span style="color:green;font-size:1.2em;" title="Активна">&#9679;</span>';
    } elseif ($state === 'OFF') {
        return '<span style="color:#bb2c2c;font-size:1.2em;" title="Остановлена">&#9679;</span>';
    } else {
        return '<span style="color:gray;font-size:1.2em;" title="Архив/Снята">&#9679;</span>';
    }
}
function fake_days_left() { return rand(1, 4); }

// --- Собираем данные по всем клиентам ---
$all_spend_today = [];
$all_campaigns_by_client = [];
$jsData = [];
$clients_access = [];

// Для campaign_logins.json
$campaign_logins = [];

foreach ($clients as $client) {
    $login = $client['login'];
    $active_ids = get_active_campaigns_ids($access_token, $login);
    if ($active_ids === false) {
        $clients_access[$login] = false; // Нет прав
        $all_campaigns_by_client[$login] = [];
        $all_spend_today[$login] = [];
    } else {
        $clients_access[$login] = true;
        $all_campaigns_by_client[$login] = get_campaigns_details_by_ids($access_token, $login, $active_ids);
        $all_spend_today[$login] = get_campaigns_daily_spend($access_token, $login, $active_ids);
        // Сохраняем связь id -> login
        foreach ($active_ids as $cid) {
            $campaign_logins[$cid] = $login;
        }
    }
}
file_put_contents(__DIR__ . "/campaign_logins.json", json_encode($campaign_logins, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Работа с бюджетами</title>
    <link rel="stylesheet" href="ln_report.css?v=1">
    <style>
    .total-row { font-weight: bold; background: #f8f8fb; }
    .manual-client-name { color: orange; font-weight: bold; }
    .no-access-row { color: #b94634; background: #fff4f0; font-weight: bold; }
    .budget-over { background: #ffd7d7 !important; }
    .budget-warning1 { background: #ffebe0 !important; }
    .budget-warning2 { background: #fff6e0 !important; }
    .budget-warning3 { background: #fffae6 !important; }
    </style>
</head>
<body>
    <div class="top-row">
        <h2>Работа с бюджетами</h2>
        <a href="ln_report.php" class="get-btn">← К списку клиентов</a>
        <a href="javascript:history.back()" class="get-btn">Назад</a>
    </div>
    <div style="margin:32px 0;">
        <input type="text" id="searchInput" class="budget-search" placeholder="Поиск по имени кампании..." style="padding:6px 10px; font-size:1em; width:260px;">
        <button onclick="budgetSearch()" style="padding:7px 18px;">Найти</button>
    </div>

    <table class="budget-table" id="budgets-table">
        <tr>
            <th>Название кампании</th>
            <th>Дней / Статус</th>
            <th>Расход за день</th>
            <th>Общий расход</th>
            <th>Лимит на неделю / день</th>
            <th>Общий лимит</th>
        </tr>
        <?php 
        foreach ($clients as $client):
            $login = $client['login'];
            $name = $client['name'];
            $manual_name = isset($client['manual_name']) && $client['manual_name'] !== '' ? $client['manual_name'] : null;
            $spend_today = $all_spend_today[$login];
            $campaigns = $all_campaigns_by_client[$login];

            // Проверяем права доступа для этого клиента:
            if (isset($clients_access[$login]) && !$clients_access[$login]) {
                $display_name = $manual_name ? '<span class="manual-client-name">'.htmlspecialchars($manual_name).'</span>' : htmlspecialchars($name);
                echo '<tr class="no-access-row"><td colspan="6">Нет прав на просмотр кампаний клиента: ' . $display_name . ' (' . htmlspecialchars($login) . ')</td></tr>';
                continue;
            }

            if ($campaigns) {
                $display_name = $manual_name ? '<span class="manual-client-name">'.htmlspecialchars($manual_name).'</span>' : htmlspecialchars($name);

                echo '<tr class="client-header-row"><td colspan="6">'
                   . '<a href="ln_report_view.php?client=' . urlencode($login) . '" '
                   . 'target="_blank" style="font-weight:bold; color:#2457a7; text-decoration:underline">'
                   . $display_name . '</a> '
                   . '<a href="https://direct.yandex.ru/dna/grid/campaigns?ulogin=' . urlencode($login) . '" '
                   . 'target="_blank" style="color:#888; text-decoration:underline; font-size:0.98em; margin-left:8px;">'
                   . htmlspecialchars($login) . '</a></td></tr>';
                foreach ($campaigns as $camp) {
                    $state = strtoupper($camp['State']);
                    if ($state === 'ARCHIVED' || $state === 'SUSPENDED') continue;

                    $cid = $camp['Id'];
                    $lim_val = isset($budgets[$cid]) ? $budgets[$cid] : '';

                    // Общий расход
                    $spent = 0;
                    if (
                        isset($camp['Funds']['Mode']) &&
                        $camp['Funds']['Mode'] === 'SHARED_ACCOUNT_FUNDS' &&
                        isset($camp['Funds']['SharedAccountFunds']['Spend'])
                    ) {
                        $spent = $camp['Funds']['SharedAccountFunds']['Spend'];
                    }
                    $spentRur = $spent / 1000000;
                    $spentNoVAT = floor($spentRur / 1.2);

                    // === WEEK LIMITS (API, manual) ===
                    $week_limit = null;
                    if (
                        isset($camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit']) &&
                        $camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit'] > 0
                    ) {
                        $week_limit = floor($camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit'] / 1000000);
                    }
                    elseif (
                        isset($camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit']) &&
                        $camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit'] > 0
                    ) {
                        $week_limit = floor($camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit'] / 1000000);
                    }
                    elseif (
                        isset($camp['Funds']['WeeklySpendLimit']) &&
                        $camp['Funds']['WeeklySpendLimit'] > 0
                    ) {
                        $week_limit = floor($camp['Funds']['WeeklySpendLimit'] / 1000000);
                    }
                    // Если лимита нет в API — ручной лимит
                    $manual_limit_set = false;
                    if ($week_limit === null && isset($manual_week_limits[$cid]) && $manual_week_limits[$cid] > 0) {
                        $week_limit = intval($manual_week_limits[$cid]);
                        $manual_limit_set = true;
                    }

                    $day_limit = null;
                    if ($week_limit !== null) {
                        $day_limit = floor($week_limit / 7);
                    } elseif (isset($camp['DailyBudget']['Amount']) && $camp['DailyBudget']['Amount'] > 0) {
                        $day_limit = floor($camp['DailyBudget']['Amount'] / 1000000);
                    }

                    // === Подсветка по количеству дней ===
                    $row_class = "data-campaign-row";
                    if ($lim_val !== '' && $day_limit > 0) {
                        $days_left = floor( max(0, ($lim_val - $spentNoVAT) / $day_limit ) );
                        if (($lim_val - $spentNoVAT) < 0) {
                            $row_class .= ' budget-over';
                        } elseif ($days_left < 1) {
                            $row_class .= ' budget-warning1';
                        } elseif ($days_left < 2) {
                            $row_class .= ' budget-warning2';
                        } elseif ($days_left < 3) {
                            $row_class .= ' budget-warning3';
                        }
                    } else {
                        $days_left = '-';
                    }
                    $cost = isset($spend_today[$camp['Id']]) ? $spend_today[$camp['Id']] : 0;
                    $jsData[] = [
                        'cost' => $cost,
                        'spent' => $spentNoVAT,
                        'week_limit' => ($week_limit !== null) ? $week_limit : '',
                        'day_limit' => ($day_limit !== null) ? $day_limit : '',
                        'lim_val' => ($lim_val !== '') ? $lim_val : ''
                    ];

                    echo '<tr class="' . $row_class . '">';
                    echo '<td style="padding-left:18px;"><a href="https://direct.yandex.ru/dna/campaigns-edit?ulogin=' . urlencode($login) . '&campaigns-ids=' . urlencode($cid) . '" target="_blank" style="color:#7b288f; text-decoration:underline;">'
                        . htmlspecialchars($camp['Name']) . '</a></td>';
                    echo '<td>' . $days_left . ' &nbsp; ' . state_icon($camp['State']) .
                        ' <span style="color:#555;">' . htmlspecialchars($camp['State']) . '</span>';
                    if ($state === 'ON') {
                        echo '<form method="post" style="display:inline;margin-left:8px;">
                            <input type="hidden" name="cid" value="'.htmlspecialchars($cid).'">
                            <input type="hidden" name="login" value="'.htmlspecialchars($login).'">
                            <button name="change_status" value="suspend" title="Остановить" style="color:#bb2c2c;cursor:pointer;">⏸️</button>
                        </form>';
                    } elseif ($state === 'OFF') {
                        echo '<form method="post" style="display:inline;margin-left:8px;">
                            <input type="hidden" name="cid" value="'.htmlspecialchars($cid).'">
                            <input type="hidden" name="login" value="'.htmlspecialchars($login).'">
                            <button name="change_status" value="resume" title="Включить" style="color:green;cursor:pointer;">▶️</button>
                        </form>';
                    }
                    echo '</td>';

                    echo '<td class="cell-cost">' . $cost . '</td>';
                    echo '<td class="cell-spent">' . $spentNoVAT . '</td>';

                    // === Лимит на неделю / день (ручной ввод если нет) ===
                    echo '<td id="week_limit_cell_'.$cid.'" class="cell-weekday">';
                    if ($week_limit !== null) {
                        echo '<span style="'.($manual_limit_set ? 'color:#da8706;font-weight:bold;' : '').'">'
                            . number_format($week_limit, 0, ',', ' ') . ' ₽ / '
                            . number_format($day_limit, 0, ',', ' ') . ' ₽'
                            . ($manual_limit_set
                                ? ' <span title="Введено вручную">*</span>
                                    <a href="#" onclick="editWeekLimit(\''.$cid.'\', '.$week_limit.'); return false;" style="margin-left:5px;" title="Изменить"><span style="font-size:1.1em;">✎</span></a>'
                                : ''
                            )
                            . '</span>';
                    } else {
                        echo '<input type="number" min="1" style="width:95px;" placeholder="Лимит/неделя"> ';
                        echo '<button onclick="saveWeekLimit(\''.$cid.'\', this)">Сохранить</button>';
                    }
                    echo '</td>';

                    echo '<td class="cell-lim" style="vertical-align:top;">
                        <div style="font-weight:bold; font-size:1.15em; margin-bottom:3px;">'
                        . ($lim_val !== '' ? htmlspecialchars($lim_val) : '-') .
                        '</div>
                        <form method="post" style="display:flex;align-items:center;margin:0;">
                            <input name="save_limit" value="" placeholder="Изменить..." style="width:70px;text-align:right;">
                            <input type="hidden" name="cid" value="' . htmlspecialchars($cid) . '">
                            <button type="submit" title="Сохранить" style="margin-left:2px;cursor:pointer;">💾</button>
                        </form>
                    </td>';

                    echo '</tr>';
                }
            }
        endforeach; ?>
        <tr class="total-row" id="totals-row"><td colspan="2">Итого:</td>
            <td id="totals-cost">-</td>
            <td id="totals-spent">-</td>
            <td id="totals-weekday">-</td>
            <td id="totals-lim">-</td>
        </tr>
    </table>
<?php
// --- Поддержка ln_stop_by_budgets.json как массива ID ---
$stop_file = __DIR__ . "/ln_stop_by_budgets.json";
if (!file_exists($stop_file)) file_put_contents($stop_file, "[]");
$stop_by_budgets_raw = file_get_contents($stop_file);
$stop_by_budgets = json_decode($stop_by_budgets_raw, true);
if (!is_array($stop_by_budgets)) $stop_by_budgets = [];

// Новый массив с теми же ID — не удаляем чужие кампании!
$all_current_ids = [];
foreach ($clients as $client) {
    $login = $client['login'];
    $campaigns = $all_campaigns_by_client[$login];
    if (!$campaigns) continue;
    foreach ($campaigns as $camp) {
        $cid = $camp['Id'];
        $state = strtoupper($camp['State']);
        if ($state === 'ARCHIVED' || $state === 'SUSPENDED') continue;

        $lim_val = isset($budgets[$cid]) ? $budgets[$cid] : '';
        $spent = 0;
        if (
            isset($camp['Funds']['Mode']) &&
            $camp['Funds']['Mode'] === 'SHARED_ACCOUNT_FUNDS' &&
            isset($camp['Funds']['SharedAccountFunds']['Spend'])
        ) {
            $spent = $camp['Funds']['SharedAccountFunds']['Spend'];
        }
        $spentRur = $spent / 1000000;
        $spentNoVAT = floor($spentRur / 1.2);

        $week_limit = null;
        if (
            isset($camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit']) &&
            $camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit'] > 0
        ) {
            $week_limit = floor($camp['TextCampaign']['BiddingStrategy']['Search']['AverageCpa']['WeeklySpendLimit'] / 1000000);
        }
        elseif (
            isset($camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit']) &&
            $camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit'] > 0
        ) {
            $week_limit = floor($camp['TextCampaign']['BiddingStrategy']['Search']['WeeklySpendLimit'] / 1000000);
        }
        elseif (
            isset($camp['Funds']['WeeklySpendLimit']) &&
            $camp['Funds']['WeeklySpendLimit'] > 0
        ) {
            $week_limit = floor($camp['Funds']['WeeklySpendLimit'] / 1000000);
        }
        if ($week_limit === null && isset($manual_week_limits[$cid]) && $manual_week_limits[$cid] > 0) {
            $week_limit = intval($manual_week_limits[$cid]);
        }
        $day_limit = ($week_limit !== null) ? floor($week_limit / 7) : null;

        if ($lim_val !== '' && $day_limit > 0) {
            $days_left = floor(max(0, ($lim_val - $spentNoVAT) / $day_limit));
        } else {
            $days_left = '-';
        }

        // Логика обновления ID в stop_by_budgets
        if ($days_left !== '-') {
            if ($days_left <= 2 && !in_array($cid, $stop_by_budgets)) {
                $stop_by_budgets[] = $cid;
            }
            if ($days_left > 3 && in_array($cid, $stop_by_budgets)) {
                $stop_by_budgets = array_values(array_diff($stop_by_budgets, [$cid]));
            }
        }
        // Собираем все текущие ID чтобы потом не затереть чужие
        $all_current_ids[] = $cid;
    }
}
// Физически ничего не удаляем — только добавляем или убираем ID, относящиеся к актуальным кампаниям
file_put_contents($stop_file, json_encode(array_values($stop_by_budgets), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
?>

<script>
var jsData = <?php echo json_encode($jsData, JSON_UNESCAPED_UNICODE); ?>;

// --- JS для ручного недельного лимита ---
function saveWeekLimit(campaignId, btn) {
    var cell = document.getElementById('week_limit_cell_' + campaignId);
    var input = cell.querySelector('input');
    var val = parseInt(input.value);
    if (isNaN(val) || val < 0) {
        alert('Введите неотрицательное число');
        return;
    }
    btn.disabled = true;
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'campaign_id=' + encodeURIComponent(campaignId) + '&week_limit=' + encodeURIComponent(val)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            if (val === 0) {
                cell.innerHTML = '<input type="number" min="1" style="width:95px;" placeholder="Лимит/неделя"> ' +
                    '<button onclick="saveWeekLimit(\''+campaignId+'\', this)">Сохранить</button>';
            } else {
                cell.innerHTML = '<span style="color:#da8706;font-weight:bold;">' +
                    res.week_limit + ' ₽ / ' + res.day_limit + ' ₽ <span title="Введено вручную">*</span>' +
                    ' <a href="#" onclick="editWeekLimit(\''+campaignId+'\', '+res.week_limit+'); return false;" style="margin-left:5px;" title="Изменить"><span style="font-size:1.1em;">✎</span></a>' +
                    '</span>';
            }
        } else {
            alert('Ошибка: ' + (res.message || ''));
            btn.disabled = false;
        }
    })
    .catch(() => {
        alert('Ошибка соединения');
        btn.disabled = false;
    });
}

function editWeekLimit(campaignId, current) {
    var cell = document.getElementById('week_limit_cell_' + campaignId);
    cell.innerHTML =
        '<input type="number" min="0" value="'+current+'" style="width:95px;" placeholder="Лимит/неделя"> ' +
        '<button onclick="saveWeekLimit(\''+campaignId+'\', this)">Сохранить</button>';
}

function budgetSearch() {
    var val = document.getElementById('searchInput').value.toLowerCase();
    var rows = document.querySelectorAll('.budget-table tr.data-campaign-row');
    rows.forEach(function(tr) {
        var cell = tr.querySelector('td');
        if (!cell) return;
        var name = cell.innerText.toLowerCase();
        tr.style.display = (!val || name.indexOf(val) !== -1) ? '' : 'none';
    });
    updateTotals();
}
document.getElementById('searchInput').addEventListener('keyup', function(e){
    if (e.key === 'Enter') budgetSearch();
});
window.addEventListener('DOMContentLoaded', updateTotals);

function updateTotals() {
    var rows = document.querySelectorAll('.budget-table tr.data-campaign-row');
    var total_cost = 0, total_spent = 0, total_week = 0, total_day = 0, total_lim = 0;
    var cnt_week = 0, cnt_day = 0, cnt_lim = 0;
    rows.forEach(function(tr, idx){
        if (tr.style.display === 'none') return;
        var data = jsData[idx];
        var cost = parseInt(data.cost) || 0;
        total_cost += cost;
        var spent = parseInt(data.spent) || 0;
        total_spent += spent;
        var week = parseInt(data.week_limit);
        if (!isNaN(week) && week !== '') { total_week += week; cnt_week++; }
        var day = parseInt(data.day_limit);
        if (!isNaN(day) && day !== '') { total_day += day; cnt_day++; }
        var lim = parseInt(data.lim_val);
        if (!isNaN(lim) && lim !== '') { total_lim += lim; cnt_lim++; }
    });
    document.getElementById('totals-cost').innerText = total_cost;
    document.getElementById('totals-spent').innerText = total_spent;
    document.getElementById('totals-weekday').innerText =
        (cnt_week ? total_week : '-') + ' / ' + (cnt_day ? total_day : '-');
    document.getElementById('totals-lim').innerText = cnt_lim ? total_lim : '-';
}
</script>
</body>
</html>
