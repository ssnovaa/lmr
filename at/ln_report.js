document.addEventListener('DOMContentLoaded', function() {
    // --- Ключ локального кэша зависит от страницы ---
    var storageKey = 'clientsData_' + location.pathname;

    // --- Логика показа/скрытия поля добавления клиента ---
    var showAdd = document.getElementById('show-add');
    var addFields = document.getElementById('add-fields');
    var cancelAdd = document.getElementById('cancel-add');
    if (showAdd && addFields) {
        showAdd.onclick = function() {
            showAdd.style.display = 'none';
            addFields.style.display = 'inline';
            var input = document.querySelector('input[name="new_client"]');
            if (input) input.focus();
        };
    }
    if (cancelAdd && showAdd && addFields) {
        cancelAdd.onclick = function() {
            addFields.style.display = 'none';
            showAdd.style.display = 'inline';
        };
    }

    // --- Глобальная переменная для данных (чтобы легко перерисовывать после редактирования) ---
    var clientsData = [];
    var container = document.getElementById('clientsDataContainer');

    // --- Функция рендера таблицы клиентов ---
    function renderClientsTable(clients, container) {
        var active = clients.filter(function(c) { return c.active_campaigns > 0; });
        var inactive = clients.filter(function(c) { return c.active_campaigns === 0; });
        var all = active.concat(inactive);

        var html = '<table class="clients-table">';
        html += '<thead><tr>' +
            '<th>ID клиента</th>' +
            '<th>Login</th>' +
            '<th>Название</th>' +
            '<th>Активных кампаний</th>' +
            '</tr></thead><tbody>';

        all.forEach(function(client) {
            html += '<tr class="' + (client.active_campaigns > 0 ? 'client-active' : 'client-inactive') + '">';
            html += '<td>' + client.id + '</td>';
            html += '<td><a href="https://direct.yandex.ru/dna/grid/campaigns/?ulogin=' +
                encodeURIComponent(client.login) +
                '" target="_blank" class="client-link-table">' + client.login + '</a></td>';

            // --- Название с редактированием ---
            html += '<td>';
            if (client._editing) {
                var displayName = client.manual_name && client.manual_name !== '' ? client.manual_name : client.name;
                html += '<form class="edit-name-form" data-login="' + client.login + '" style="display:inline;">' +
                    '<input type="text" class="edit-name-input" value="' + escapeHtml(displayName) + '" style="width: 120px;">' +
                    '<button type="submit" class="save-name-btn" style="margin-left:5px;">Сохранить</button>' +
                    '<button type="button" class="cancel-edit-btn" style="margin-left:5px;">Отмена</button>' +
                    '</form>';
            } else {
                var displayName = client.manual_name && client.manual_name !== '' ? client.manual_name : client.name;
                var isManual = client.manual_name && client.manual_name !== '';

                html += '<a href="ln_report_view.php?client=' + encodeURIComponent(client.login) + '" target="_blank"'
                    + (isManual ? ' style="color:#5d4800;font-weight:bold;text-decoration:underline"' : '')
                    + '>'
                    + escapeHtml(displayName) + '</a> ';
                html += '<button class="edit-name-btn" title="Переименовать" data-login="' + client.login + '" style="background:none;border:none;cursor:pointer;vertical-align:middle;padding:2px 4px;">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="' + (isManual ? 'orange' : '#888') + '" viewBox="0 0 20 20"><path d="M17.7 7.7c.4-.4.4-1 0-1.4l-4-4a1 1 0 00-1.4 0l-9 9A1 1 0 003 12v4a1 1 0 001 1h4a1 1 0 00.7-.3l9-9zM5 16H4v-1l8.3-8.3 1 1L5 16zm10.7-9.3l-1-1 1.3-1.3 1 1-1.3 1.3z"/></svg>' +
                    '</button>';
            }
            html += '</td>';

            html += '<td>' + client.active_campaigns + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }
    // Экранирование HTML-символов
    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/[&<>"'`]/g, function (match) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
                '`': '&#96;'
            })[match];
        });
    }

    // --- Показываем таблицу из LocalStorage при загрузке страницы ---
    var savedClients = localStorage.getItem(storageKey);
    if (savedClients && container) {
        try {
            var data = JSON.parse(savedClients);
            if (Array.isArray(data) && data.length) {
                clientsData = data;
                renderClientsTable(clientsData, container);
            }
        } catch(e) {
            localStorage.removeItem(storageKey);
        }
    }

    // --- Логика подгрузки данных клиентов ---
    var fetchBtn = document.getElementById('fetchClientsBtn');
    if (fetchBtn) {
        fetchBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!container) return;
            container.innerHTML = '<div class="loading">Загрузка...</div>';

            fetch('get_clients.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!Array.isArray(data) || data.length === 0) {
                        container.innerHTML = '<div class="error-msg">Нет данных по клиентам</div>';
                        localStorage.removeItem(storageKey);
                        return;
                    }
                    // --- Сохраняем данные в LocalStorage ---
                    localStorage.setItem(storageKey, JSON.stringify(data));
                    clientsData = data;
                    renderClientsTable(clientsData, container);
                })
                .catch(function() {
                    container.innerHTML = '<div class="error-msg">Ошибка загрузки данных</div>';
                });
        });
    }

    // --- Редактирование имени клиента ---
    container.addEventListener('click', function(e) {
        // Карандаш
        if (e.target.closest('.edit-name-btn')) {
            var login = e.target.closest('.edit-name-btn').dataset.login;
            clientsData.forEach(function(client) { client._editing = (client.login === login); });
            renderClientsTable(clientsData, container);
            setTimeout(function() {
                var inp = container.querySelector('.edit-name-input');
                if (inp) inp.focus();
            }, 50);
            return;
        }
        // Отмена редактирования
        if (e.target.classList.contains('cancel-edit-btn')) {
            clientsData.forEach(function(client) { delete client._editing; });
            renderClientsTable(clientsData, container);
            return;
        }
    });

    // Сохранение имени (submit формы)
    container.addEventListener('submit', function(e) {
        if (e.target.classList.contains('edit-name-form')) {
            e.preventDefault();
            var input = e.target.querySelector('.edit-name-input');
            var login = e.target.getAttribute('data-login');
            var newName = input.value.trim();

            var formData = new URLSearchParams();
            formData.append('login', login);
            formData.append('new_name', newName);

            fetch('rename_client.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    // --- Моментально обновляем интерфейс без повторной загрузки всего списка ---
                    clientsData.forEach(function(client) {
                        if (client.login === login) {
                            client.manual_name = newName;
                            delete client._editing;
                        }
                    });
                    localStorage.setItem(storageKey, JSON.stringify(clientsData));
                    renderClientsTable(clientsData, container);
                } else {
                    alert(res.message || 'Ошибка переименования');
                }
            });
            return false;
        }
    });
    // --- Универсальный спиннер для всех нужных кнопок ---
    var progressBtns = document.querySelectorAll('.btn-progressable');
    progressBtns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (btn.querySelector('.spinner')) return;
            btn.dataset.text = btn.textContent;
            btn.textContent = '';
            btn.classList.add('btn-progress');
            var spinner = document.createElement('span');
            spinner.className = 'spinner';
            btn.appendChild(spinner);
        });
    });
});

// --- Сбрасываем спиннеры на всех progressable-кнопках при возврате назад ---
function resetProgressBtns() {
    var progressBtns = document.querySelectorAll('.btn-progressable');
    progressBtns.forEach(function(btn) {
        btn.classList.remove('btn-progress');
        var oldSpinner = btn.querySelector('.spinner');
        if (oldSpinner) oldSpinner.remove();
        var orig = btn.dataset.text || btn.textContent || '';
        if (btn.textContent.trim() === '' && orig) {
            btn.textContent = orig;
        }
    });
}
window.addEventListener('pageshow', resetProgressBtns);
