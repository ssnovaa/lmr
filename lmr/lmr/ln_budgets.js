function saveWeekLimit(campaignId, btn) {
    var cell = document.getElementById('week_limit_cell_' + campaignId);
    var input = cell.querySelector('input');
    var val = parseInt(input.value);
    if (!val || val <= 0) {
        alert('Введите положительное число');
        return;
    }
    btn.disabled = true;

    fetch('save_week_limit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'campaign_id=' + encodeURIComponent(campaignId) + '&week_limit=' + encodeURIComponent(val)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            cell.innerHTML = res.week_limit + ' ₽ / ' + res.day_limit + ' ₽';
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
