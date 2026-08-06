(function () {
    var root = document.getElementById('station-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var canCreate = root.dataset.canCreate === '1';
    var canUpdate = root.dataset.canUpdate === '1';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    var statusMap = {};
    statusMap[1] = { text: i18n.status_active, css: 'badge--active' };
    statusMap[2] = { text: i18n.status_frozen, css: 'badge--pending' };
    statusMap[0] = { text: i18n.status_disabled, css: 'badge--rejected' };

    var stationsData = [];
    var currentPage = 1;
    var totalPages = 1;
    var searchFilters = {
        keyword: '',
        system_id: '',
        status: '',
        credits_min: '',
        credits_max: '',
        support_shop: '',
        score_runner: '',
    };
    var perPage = 15;
    var systemsCache = [];

    function apiFetch(url, options) {
        options = options || {};
        options.headers = Object.assign({
            'X-CSRF-TOKEN': csrfToken,
            'Content-Type': 'application/json',
            Accept: 'application/json',
        }, options.headers || {});

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) { throw body; }
                return body;
            });
        });
    }

    function openModal(id) {
        var el = document.getElementById(id);
        if (el) { el.style.display = 'flex'; }
    }

    function closeModal(id) {
        var el = document.getElementById(id);
        if (el) { el.style.display = 'none'; }
    }

    function showMessage(message) {
        var el = document.getElementById('modal-station-msg-text');
        if (el) { el.textContent = message; }
        openModal('modal-station-msg');
    }

    function getErrorMessage(error) {
        if (error.errors) {
            var keys = Object.keys(error.errors);
            return error.errors[keys[0]][0];
        }
        return error.message || 'Failed';
    }

    // modal 關閉
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.closest('.modal-overlay').style.display = 'none';
        });
    });
    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { overlay.style.display = 'none'; }
        });
    });

    // ---------------------------------------------------------------
    //  列表
    // ---------------------------------------------------------------

    function loadStations(page) {
        page = page || 1;
        var url = '/admin/stations/ajax-list?per_page=' + perPage + '&page=' + page;

        Object.keys(searchFilters).forEach(function (key) {
            if (searchFilters[key] !== '') {
                url += '&' + key + '=' + encodeURIComponent(searchFilters[key]);
            }
        });

        apiFetch(url)
            .then(function (body) {
                stationsData = body.data || [];
                currentPage = body.meta ? body.meta.current_page : 1;
                totalPages = body.meta ? body.meta.last_page : 1;
                renderTable(stationsData);
            })
            .catch(function () {
                root.innerHTML = '<p>Failed to load stations.</p>';
            });
    }

    function renderTable(stations) {
        var rows = stations.map(function (s, idx) {
            var st = statusMap[s.status] || { text: '-', css: '' };
            var settings = s.settings || {};
            var depositRate = settings.system_rate ? (settings.system_rate * 100).toFixed(2) + '%' : '-';
            var withdrawRate = settings.system_rate_withdraw ? (settings.system_rate_withdraw * 100).toFixed(2) + '%' : '-';

            var statusHtml = canUpdate
                ? '<span class="badge ' + st.css + ' js-status-btn" data-id="' + s.id + '" data-status="' + s.status + '" style="cursor:pointer">' + st.text + '</span>'
                : '<span class="badge ' + st.css + '">' + st.text + '</span>';

            var syncedAt = s.synced_at ? s.synced_at.substring(0, 16) : '-';

            var actions = '<button class="btn-sm js-station-detail" data-id="' + s.id + '">' + (i18n.action_detail || '詳細') + '</button> ';
            if (canUpdate) {
                actions += '<button class="btn-sm js-edit-station" data-id="' + s.id + '">' + i18n.action_edit + '</button> ';
                actions += '<button class="btn-sm js-sync-credits" data-id="' + s.id + '">' + (i18n.action_sync || '同步') + '</button>';
            }

            return (
                '<tr>' +
                '<td>' + (idx + 1) + '</td>' +
                '<td>' + (s.system ? s.system.name : '-') + '</td>' +
                '<td><strong>' + s.name + '</strong>' + (s.domain ? '<br><span class="station-domain">' + s.domain + '</span>' : '') + '</td>' +
                '<td>' + s.credits + '</td>' +
                '<td>' + depositRate + ' / ' + withdrawRate + '</td>' +
                '<td>' + statusHtml + '</td>' +
                '<td>' + syncedAt + '</td>' +
                '<td class="td-actions">' + actions + '</td>' +
                '</tr>'
            );
        }).join('');

        // 搜尋列（多條件）
        var systemOptions = '<option value="">全部系統</option>';
        systemsCache.forEach(function (sys) {
            var sel = searchFilters.system_id == sys.id ? ' selected' : '';
            systemOptions += '<option value="' + sys.id + '"' + sel + '>' + sys.name + '</option>';
        });

        var toolbarActions = '';
        if (canCreate) {
            toolbarActions += '<button class="btn-primary" id="btn-create-station">' + i18n.action_create + '</button> ';
        }
        if (canUpdate) {
            toolbarActions += '<button class="btn-sm" id="btn-sync-all">' + (i18n.action_sync_all || '一鍵同步全部') + '</button>';
        }

        function filterField(label, html) {
            return '<div class="stn-field"><label class="stn-field__label">' + label + '</label>' + html + '</div>';
        }

        var toolbar =
            '<div class="stn-toolbar">' +
            '<div class="stn-toolbar__top">' +
            '<div class="stn-toolbar__actions">' + toolbarActions + '</div>' +
            '</div>' +
            '<div class="stn-toolbar__grid">' +
            filterField('關鍵字：', '<input type="text" id="stn-f-keyword" placeholder="名稱或域名" value="' + searchFilters.keyword + '">') +
            filterField('系統：', '<select id="stn-f-system">' + systemOptions + '</select>') +
            filterField('狀態：', '<select id="stn-f-status">' +
                '<option value="">全部</option>' +
                '<option value="1"' + (searchFilters.status === '1' ? ' selected' : '') + '>' + i18n.status_active + '</option>' +
                '<option value="2"' + (searchFilters.status === '2' ? ' selected' : '') + '>' + i18n.status_frozen + '</option>' +
                '<option value="0"' + (searchFilters.status === '0' ? ' selected' : '') + '>' + i18n.status_disabled + '</option>' +
                '</select>') +
            filterField('商城：', '<select id="stn-f-shop">' +
                '<option value="">全部</option>' +
                '<option value="true"' + (searchFilters.support_shop === 'true' ? ' selected' : '') + '>啟用</option>' +
                '<option value="false"' + (searchFilters.support_shop === 'false' ? ' selected' : '') + '>未啟用</option>' +
                '</select>') +
            filterField('跑分員：', '<select id="stn-f-runner">' +
                '<option value="">全部</option>' +
                '<option value="true"' + (searchFilters.score_runner === 'true' ? ' selected' : '') + '>啟用</option>' +
                '<option value="false"' + (searchFilters.score_runner === 'false' ? ' selected' : '') + '>未啟用</option>' +
                '</select>') +
            filterField('點數 ≥：', '<input type="number" id="stn-f-credits-min" placeholder="最小值" value="' + searchFilters.credits_min + '">') +
            filterField('點數 ≤：', '<input type="number" id="stn-f-credits-max" placeholder="最大值" value="' + searchFilters.credits_max + '">') +
            filterField('每頁：', '<select id="stn-per-page">' +
                [15, 30, 50, 75, 100].map(function (n) {
                    return '<option value="' + n + '"' + (perPage === n ? ' selected' : '') + '>' + n + ' 筆</option>';
                }).join('') +
                '</select>') +
            '</div>' +
            '<div class="stn-toolbar__bottom">' +
            '<div></div>' +
            '<div class="stn-toolbar__right">' +
            '<button class="btn-sm" id="btn-stn-reset">重置</button>' +
            '<button class="btn-primary" id="btn-stn-search">搜尋</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        // 分頁
        var pagination = '<div class="stn-pagination">';
        if (currentPage > 1) {
            pagination += '<button class="btn-sm js-stn-page" data-page="' + (currentPage - 1) + '">&lsaquo;</button>';
        }
        if (totalPages > 1) {
            pagination += '<span class="stn-pagination__info">' + currentPage + ' / ' + totalPages + '</span>';
        }
        if (currentPage < totalPages) {
            pagination += '<button class="btn-sm js-stn-page" data-page="' + (currentPage + 1) + '">&rsaquo;</button>';
        }
        pagination += '</div>';

        root.innerHTML = toolbar +
            '<table><thead><tr>' +
            '<th>#</th>' +
            '<th>系統</th>' +
            '<th>' + i18n.field_name + '</th>' +
            '<th>' + i18n.field_credits + '</th>' +
            '<th>費率（收/付）</th>' +
            '<th>' + i18n.field_status + '</th>' +
            '<th>同步</th>' +
            '<th></th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>' + pagination;

        // 搜尋事件
        function collectFilters() {
            searchFilters.keyword = (document.getElementById('stn-f-keyword').value || '').trim();
            searchFilters.system_id = document.getElementById('stn-f-system').value;
            searchFilters.status = document.getElementById('stn-f-status').value;
            searchFilters.support_shop = document.getElementById('stn-f-shop').value;
            searchFilters.score_runner = document.getElementById('stn-f-runner').value;
            searchFilters.credits_min = document.getElementById('stn-f-credits-min').value;
            searchFilters.credits_max = document.getElementById('stn-f-credits-max').value;
        }

        var searchBtn = document.getElementById('btn-stn-search');
        if (searchBtn) {
            searchBtn.addEventListener('click', function () {
                collectFilters();
                loadStations(1);
            });
        }

        var resetBtn = document.getElementById('btn-stn-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                Object.keys(searchFilters).forEach(function (k) { searchFilters[k] = ''; });
                loadStations(1);
            });
        }

        var kwInput = document.getElementById('stn-f-keyword');
        if (kwInput) {
            kwInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { collectFilters(); loadStations(1); }
            });
        }

        // 分頁事件
        root.querySelectorAll('.js-stn-page').forEach(function (btn) {
            btn.addEventListener('click', function () {
                loadStations(parseInt(btn.dataset.page, 10));
            });
        });

        var perPageEl = document.getElementById('stn-per-page');
        if (perPageEl) {
            perPageEl.addEventListener('change', function () {
                perPage = parseInt(perPageEl.value, 10);
                loadStations(1);
            });
        }

        var createBtnEl = document.getElementById('btn-create-station');
        if (createBtnEl) {
            createBtnEl.addEventListener('click', function () {
                openStationModal(null);
            });
        }

        var syncAllBtn = document.getElementById('btn-sync-all');
        if (syncAllBtn) {
            syncAllBtn.addEventListener('click', syncAll);
        }

        root.querySelectorAll('.js-station-detail').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.dataset.id, 10);
                var station = stationsData.filter(function (s) { return s.id === id; })[0];
                if (station) { showStationDetail(station); }
            });
        });

        root.querySelectorAll('.js-edit-station').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.dataset.id, 10);
                var station = stationsData.filter(function (s) { return s.id === id; })[0];
                if (station) { openStationModal(station); }
            });
        });

        root.querySelectorAll('.js-sync-credits').forEach(function (btn) {
            btn.addEventListener('click', function () {
                syncCredits(parseInt(btn.dataset.id, 10));
            });
        });

        root.querySelectorAll('.js-status-btn').forEach(function (badge) {
            badge.addEventListener('click', function () {
                openStatusModal(parseInt(badge.dataset.id, 10), parseInt(badge.dataset.status, 10));
            });
        });
    }

    // ---------------------------------------------------------------
    //  同步點數
    // ---------------------------------------------------------------

    function openStatusModal(stationId, currentStatus) {
        var statusEl = document.getElementById('modal-status-station-id');
        if (!statusEl) { return; }
        statusEl.value = stationId;

        document.querySelectorAll('#modal-station-status input[name="station_status"]').forEach(function (radio) {
            radio.checked = parseInt(radio.value, 10) === currentStatus;
        });

        openModal('modal-station-status');
    }

    function submitStationStatus() {
        var stationId = document.getElementById('modal-status-station-id').value;
        var selected = document.querySelector('#modal-station-status input[name="station_status"]:checked');
        if (!selected) { return; }

        apiFetch('/admin/stations/ajax-update/' + stationId, {
            method: 'PUT',
            body: JSON.stringify({ status: parseInt(selected.value, 10) }),
        })
            .then(function () {
                closeModal('modal-station-status');
                showMessage(i18n.msg.updated);
                loadStations(currentPage);
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    function syncAll() {
        if (stationsData.length === 0) { return; }
        var promises = stationsData.map(function (s) {
            return apiFetch('/admin/stations/ajax-sync-credits/' + s.id, { method: 'POST' }).catch(function () { return null; });
        });

        Promise.all(promises).then(function () {
            showMessage((i18n.action_sync_all_done || '全部同步完成'));
            loadStations();
        });
    }

    function detailRow(label, value) {
        return '<tr><td>' + label + '</td><td>' + value + '</td></tr>';
    }

    function showStationDetail(s) {
        var settings = s.settings || {};
        var on = '<span class="badge badge--active">啟用</span>';
        var off = '<span class="badge badge--disabled">未啟用</span>';

        var depositTypes = [];
        if (settings.usdt_deposit) { depositTypes.push('USDT'); }
        if (settings.atm_deposit) { depositTypes.push('ATM'); }
        if (settings.cvs_deposit) { depositTypes.push('超商'); }
        if (settings.cc_deposit) { depositTypes.push('信用卡'); }
        if (settings.qr_deposit) { depositTypes.push('QR'); }

        var depositRate = settings.system_rate ? (settings.system_rate * 100).toFixed(2) + '%' : '-';
        var withdrawRate = settings.system_rate_withdraw ? (settings.system_rate_withdraw * 100).toFixed(2) + '%' : '-';
        var depositBadges = depositTypes.length > 0
            ? depositTypes.map(function (t) { return '<span class="badge badge--active">' + t + '</span>'; }).join(' ')
            : '-';

        var html =
            '<div class="stn-detail">' +

            '<div class="stn-detail__section"><h4>基本資訊</h4>' +
            '<table class="stn-detail__table">' +
            detailRow('系統', s.system ? s.system.name : '-') +
            detailRow('站台名稱', s.name) +
            detailRow('域名', s.domain || '-') +
            detailRow('點數', s.credits) +
            detailRow('同步時間', s.synced_at ? s.synced_at.substring(0, 16) : '未同步') +
            '</table></div>' +

            '<div class="stn-detail__section"><h4>代收付</h4>' +
            '<table class="stn-detail__table">' +
            detailRow('代收', '費率 ' + depositRate + (depositTypes.length > 0 ? '&nbsp;&nbsp;' + depositBadges : '')) +
            detailRow('代付', settings.withdraw ? '費率 ' + withdrawRate : off) +
            '</table></div>' +

            '<div class="stn-detail__section"><h4>商城</h4>' +
            '<table class="stn-detail__table">' +
            (settings.support_shop
                ? detailRow('狀態', on) +
                  detailRow('帳號生成點數', settings.store_initial_credit || '-') +
                  detailRow('到期天數', settings.store_expired_days || '-') +
                  detailRow('保底費用', settings.store_guarantee_credit || '-')
                : detailRow('狀態', off)) +
            '</table></div>' +

            '<div class="stn-detail__section"><h4>跑分員</h4>' +
            '<table class="stn-detail__table">' +
            detailRow('狀態', settings.score_runner ? on : off) +
            '</table></div>' +

            '<div class="stn-detail__section"><h4>備註</h4>' +
            '<p class="stn-detail__note">' + (s.note || '-') + '</p>' +
            '</div>' +

            '</div>';

        var titleEl = document.querySelector('#modal-station-detail .modal-header h3');
        if (titleEl) { titleEl.textContent = s.name; }
        document.getElementById('station-detail-body').innerHTML = html;
        openModal('modal-station-detail');
    }

    function syncCredits(stationId) {
        apiFetch('/admin/stations/ajax-sync-credits/' + stationId, { method: 'POST' })
            .then(function (body) {
                showMessage(body.message || '同步成功');
                loadStations(currentPage);
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    }

    // ---------------------------------------------------------------
    //  新增/編輯 Modal
    // ---------------------------------------------------------------

    function openStationModal(station) {
        var isEdit = station !== null;
        var title = document.querySelector('#modal-station .modal-header h3');
        if (title) { title.textContent = isEdit ? i18n.action_edit : i18n.action_create; }

        document.getElementById('station-id').value = isEdit ? station.id : '';
        document.getElementById('station-name').value = isEdit ? station.name : '';
        document.getElementById('station-domain').value = isEdit ? (station.domain || '') : '';
        document.getElementById('station-api-url').value = isEdit ? (station.api_url || '') : '';
        document.getElementById('station-api-key').value = isEdit ? (station.api_key || '') : '';
        document.getElementById('station-note').value = isEdit ? (station.note || '') : '';
        document.getElementById('station-telegram-chat-id').value = isEdit ? (station.telegram_chat_id || '') : '';

        // 載入系統選單
        loadSystemSelect(isEdit ? station.system_id : null);

        openModal('modal-station');
    }

    document.getElementById('form-station').addEventListener('submit', function (e) {
        e.preventDefault();
        var id = document.getElementById('station-id').value;
        var isEdit = id !== '';

        var data = {
            name: document.getElementById('station-name').value,
            domain: document.getElementById('station-domain').value || null,
            system_id: document.getElementById('station-system').value ? parseInt(document.getElementById('station-system').value, 10) : null,
            api_url: document.getElementById('station-api-url').value || null,
            api_key: document.getElementById('station-api-key').value || null,
            telegram_chat_id: document.getElementById('station-telegram-chat-id').value || null,
            note: document.getElementById('station-note').value || null,
        };

        var url = isEdit ? '/admin/stations/ajax-update/' + id : '/admin/stations/ajax-store';
        var method = isEdit ? 'PUT' : 'POST';

        apiFetch(url, { method: method, body: JSON.stringify(data) })
            .then(function () {
                closeModal('modal-station');
                showMessage(isEdit ? i18n.msg.updated : i18n.msg.created);
                loadStations(currentPage);
            })
            .catch(function (error) { showMessage(getErrorMessage(error)); });
    });

    // 載入系統下拉選單
    function loadSystemSelect(selectedId) {
        var select = document.getElementById('station-system');
        if (!select) { return; }

        apiFetch('/admin/stations/ajax-systems')
            .then(function (body) {
                select.innerHTML = '<option value="">-- 選擇系統 --</option>' +
                    body.map(function (sys) {
                        var sel = selectedId && sys.id === selectedId ? ' selected' : '';
                        return '<option value="' + sys.id + '"' + sel + '>' + sys.name + '</option>';
                    }).join('');
            });
    }

    // 新增系統按鈕
    var addSysBtn = document.getElementById('btn-add-system');
    if (addSysBtn) {
        addSysBtn.addEventListener('click', function () {
            var name = prompt('輸入系統名稱');
            if (!name) { return; }

            apiFetch('/admin/stations/ajax-store-system', {
                method: 'POST',
                body: JSON.stringify({ name: name }),
            })
                .then(function (sys) {
                    loadSystemSelect(sys.id);
                })
                .catch(function (error) { showMessage(getErrorMessage(error)); });
        });
    }

    // 狀態彈窗確認按鈕
    var statusSubmitBtn = document.getElementById('btn-submit-station-status');
    if (statusSubmitBtn) {
        statusSubmitBtn.addEventListener('click', submitStationStatus);
    }

    // 讀取機器人群組
    var fetchGroupsBtn = document.getElementById('btn-fetch-bot-groups');
    if (fetchGroupsBtn) {
        fetchGroupsBtn.addEventListener('click', function () {
            var body = document.getElementById('bot-groups-body');
            body.innerHTML = '<p style="color:#6b7280">讀取中，請稍候…</p>';
            openModal('modal-bot-groups');

            apiFetch('/admin/stations/ajax-bot-groups')
                .then(function (groups) {
                    if (!groups || groups.length === 0) {
                        body.innerHTML = '<p style="color:#6b7280">未找到任何群組，請確認機器人已加入群組且有訊息互動。</p>';
                        return;
                    }

                    var html = '<table class="stn-detail__table">' +
                        '<thead><tr><th style="text-align:left;padding:0.25rem 0.5rem">群組名稱</th><th style="text-align:left;padding:0.25rem 0.5rem">Chat ID</th><th></th></tr></thead><tbody>' +
                        groups.map(function (g) {
                            return '<tr>' +
                                '<td>' + g.title + '</td>' +
                                '<td><code>' + g.chat_id + '</code></td>' +
                                '<td><button class="btn-sm js-pick-group" data-chat-id="' + g.chat_id + '">選用</button></td>' +
                                '</tr>';
                        }).join('') +
                        '</tbody></table>';

                    body.innerHTML = html;

                    body.querySelectorAll('.js-pick-group').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            document.getElementById('station-telegram-chat-id').value = btn.dataset.chatId;
                            closeModal('modal-bot-groups');
                        });
                    });
                })
                .catch(function (error) {
                    body.innerHTML = '<p style="color:#dc2626">' + getErrorMessage(error) + '</p>';
                });
        });
    }

    // 初始化：先載入系統列表，再載入站台
    apiFetch('/admin/stations/ajax-systems')
        .then(function (body) {
            systemsCache = body || [];
            loadStations();
        })
        .catch(function () {
            loadStations();
        });
})();
