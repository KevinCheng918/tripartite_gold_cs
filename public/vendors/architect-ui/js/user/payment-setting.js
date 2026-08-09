(function () {
    // 監聽所有「支付管道設定」modal 的顯示事件
    document.addEventListener('shown.bs.modal', function (ev) {
        const modal = ev.target;
        if (!modal.id || !modal.id.startsWith('modal-setting-payment-')) return;

        const userId = modal.dataset.userId || modal.id.replace('modal-setting-payment-', '');
        const apiPrefix = modal.dataset.apiPrefix || '/ajax-user-setting-payment';
        const tbody = modal.querySelector(`#user-setting-payment-tbody-${userId}`);
        const loadingBox = modal.querySelector(`#loading-box-${userId}`);

        if (!tbody) return;

        // 清空 & 顯示 Loading
        tbody.innerHTML = '';
        loadingBox && loadingBox.classList.remove('d-none');

        // 偵測目前表頭欄位（決定 colspan 與是否有 floor/ceiling/weight/code）
        const theadTh = Array.from(modal.querySelectorAll('thead th'));
        const totalCols = theadTh.length || 2;
        const headerText = theadTh.map(th => th.textContent.trim());
        const hasCode = headerText.some(t => /代碼|code/i.test(t));
        const hasStatus = headerText.some(t => /狀態|status/i.test(t));
        const hasFloor = headerText.some(t => /下限|floor|min(imum)?/i.test(t));
        const hasCeil  = headerText.some(t => /上限|ceil(ing)?|max(imum)?/i.test(t));
        const hasWeight = headerText.some(t => /權重|weight/i.test(t));

        // 取得資料並渲染
        fetch(`${apiPrefix}/${encodeURIComponent(userId)}`, {headers: {'Accept': 'application/json'}})
            .then(r => r.json())
            .then(data => {
                // data: { '類型名稱': [ { id, bank_name, is_checked, status, setting_id, ... }, ... ] }
                var statusMap = {'0':'關閉','1':'啟用','2':'停用','3':'審核中'};
                var statusBadge = {'0':'bg-secondary','1':'bg-success','2':'bg-danger','3':'bg-warning text-dark'};
                var html = [];

                Object.entries(data).forEach(([type, payments]) => {
                    html.push(
                        '<tr class="table-primary">' +
                        '<td colspan="' + totalCols + '" class="text-start py-2 px-3 fw-semibold">' + type + '</td>' +
                        '</tr>'
                    );

                    payments.forEach(function (p) {
                        var checked = p.is_checked ? 'checked' : '';
                        var statusIcon = p.status === '1' ? 'on text-success' : 'off text-danger';
                        var disabledAttr = '';
                        var rowStyle = '';
                        var statusLabel = statusMap[p.status] || p.status;
                        var statusBadgeClass = statusBadge[p.status] || 'bg-secondary';

                        var row = '<tr style="' + rowStyle + '">';

                        // checkbox
                        row += '<td><input type="checkbox" name="payment[]" style="width:22px; height:22px;" value="' + p.id + '" ' + checked + ' ' + disabledAttr +
                            ' data-lower-limit="' + (p.lower_limit ?? '') + '"' +
                            ' data-upper-limit="' + (p.upper_limit ?? '') + '"' +
                            ' data-floor="' + (p.floor ?? '') + '"' +
                            ' data-ceiling="' + (p.ceiling ?? '') + '"></td>';

                        // 精簡模式
                        if (!hasCode && !hasFloor && !hasCeil && !hasWeight) {
                            row += '<td class="text-start" style="white-space:nowrap;">' +
                                '<i class="fa fa-toggle-' + statusIcon + '"></i> ' + p.bank_name + '</td>';
                            if (hasStatus) row += '<td><span class="badge ' + statusBadgeClass + '">' + statusLabel + '</span></td>';
                            row += '</tr>';
                            html.push(row);
                            return;
                        }

                        // 完整模式
                        if (hasCode) row += '<td>' + (p.setting_id ?? '-') + '</td>';

                        row += '<td class="text-start" style="white-space:nowrap;">' +
                            '<i class="fa fa-toggle-' + statusIcon + '"></i> ' + p.bank_name + '</td>';

                        if (hasStatus) row += '<td><span class="badge ' + statusBadgeClass + '">' + statusLabel + '</span></td>';

                        if (hasFloor) {
                            row += '<td style="white-space:nowrap; width:1%;"><input type="number" name="floor[' + p.id + ']"' +
                                ' value="' + ((p.floor ?? p.lower_limit) ?? '') + '"' +
                                ' class="form-control form-control-sm" style="width:100px;"' +
                                ' placeholder="' + (p.floor_placeholder ?? '') + '"' +
                                ' min="' + (p.lower_limit ?? '') + '" max="' + (p.upper_limit ?? '') + '" ' + disabledAttr + '></td>';
                        }
                        if (hasCeil) {
                            row += '<td style="white-space:nowrap; width:1%;"><input type="number" name="ceiling[' + p.id + ']"' +
                                ' value="' + ((p.ceiling ?? p.upper_limit) ?? '') + '"' +
                                ' class="form-control form-control-sm" style="width:100px;"' +
                                ' placeholder="' + (p.ceiling_placeholder ?? '') + '"' +
                                ' min="' + (p.lower_limit ?? '') + '" max="' + (p.upper_limit ?? '') + '" ' + disabledAttr + '></td>';
                        }
                        if (hasWeight) {
                            row += '<td style="white-space:nowrap; width:1%;"><input type="number" name="weight[' + p.id + ']"' +
                                ' value="' + (p.weight ?? '') + '"' +
                                ' class="form-control form-control-sm" style="width:80px;"' +
                                ' placeholder="' + (p.weight_placeholder ?? '') + '"' +
                                ' min="0" max="9" ' + disabledAttr + '></td>';
                        }

                        row += '</tr>';
                        html.push(row);
                    });
                });

                // 一次性寫入 DOM
                tbody.innerHTML = html.join('');
            })
            .catch(err => {
                console.error('[payment-setting] fetch error:', err);
            })
            .finally(() => {
                loadingBox && loadingBox.classList.add('d-none');
            });

        // 綁定送出（只綁一次）
        const form = modal.querySelector(`#form-setting-payment-${userId}`);
        if (form && !form.dataset.bound) {
            form.dataset.bound = '1';
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                // ✅ 加入：禁用提交按鈕
                const submitButton = form.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.classList.add('disabled'); // 可選：加上視覺效果
                    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>處理中...'; // 可選：顯示載入動畫
                }

                const fd = new FormData(form);
                const payload = [];

                // 以勾選的 payment[] 為主
                fd.forEach((val, key) => {
                    if (key === 'payment[]') {
                        const id = val;
                        payload.push({
                            id,
                            floor: fd.get(`floor[${id}]`),
                            ceiling: fd.get(`ceiling[${id}]`),
                            weight: fd.get(`weight[${id}]`),
                            status: '1'
                        });
                    }
                });

                // 沒勾但有欄位的也補上（status 0）
                form.querySelectorAll("input[name^='floor['], input[name^='ceiling['], input[name^='weight[']").forEach(inp => {
                    const id = (inp.name.match(/\[(\d+)\]/) || [])[1];
                    if (!id) return;
                    if (payload.some(x => x.id === id)) return;

                    payload.push({
                        id,
                        floor: fd.get(`floor[${id}]`),
                        ceiling: fd.get(`ceiling[${id}]`),
                        weight: fd.get(`weight[${id}]`),
                        status: '0'
                    });
                });

                // 鎖住其他輸入，避免一併送出
                form.querySelectorAll("input:not([name='_token']):not([name='_method']):not([name='templateSelect'])")
                    .forEach(i => i.disabled = true);

                // 寫入 hidden 後送出
                let hidden = form.querySelector("input[name='payment_setting']");
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'payment_setting';
                    hidden.id = `payment-setting-${userId}`;
                    form.appendChild(hidden);
                }
                hidden.value = JSON.stringify(payload);

                form.submit();
            });
        }

        // 動態：勾選即帶入上下限（事件委派在 modal 內）
        modal.addEventListener('change', function (e) {
            const cb = e.target;
            if (!(cb instanceof HTMLInputElement)) return;
            if (cb.type !== 'checkbox' || cb.name !== 'payment[]') return;

            const id = cb.value;
            const checked = cb.checked;

            const floor = modal.querySelector(`input[name='floor[${id}]']`);
            const ceiling = modal.querySelector(`input[name='ceiling[${id}]']`);

            const lower = cb.dataset.lowerLimit;
            const upper = cb.dataset.upperLimit;
            const existFloor = cb.dataset.floor ?? '';
            const existCeiling = cb.dataset.ceiling ?? '';

            if (checked) {
                if (floor && !existFloor) floor.value = lower ?? '';
                if (ceiling && !existCeiling) ceiling.value = upper ?? '';
            } else {
                if (floor && !existFloor) floor.value = '';
                if (ceiling && !existCeiling) ceiling.value = '';
            }
        });

        // 模板切換（只影響本 modal）
        const templateSelect = modal.querySelector('.templateSelect');
        if (templateSelect && !templateSelect.dataset.bound) {
            templateSelect.dataset.bound = '1';

            templateSelect.addEventListener('change', function () {
                const opt = this.selectedOptions[0];
                const raw = opt ? opt.getAttribute('data-payment') : null;
                if (!raw) return;

                let map;
                try { map = JSON.parse(raw); } catch (e) { console.error('[payment-setting] bad JSON', e); return; }

                // 1) 先全部清空（避免殘留）
                modal.querySelectorAll(`input[name="payment[]"]`).forEach(cb => {
                    cb.checked = false;
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                });

                // 2) 套用模板
                Object.entries(map).forEach(([id, info]) => {
                    const idStr = String(id);

                    // 勾選/取消
                    const cb = modal.querySelector(`input[name="payment[]"][value="${CSS.escape(idStr)}"]`);
                    if (cb) {
                        const isChecked = (info.status === true || info.status === 1 || info.status === '1');
                        cb.checked = isChecked;
                        cb.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    // 數值欄位
                    const f = modal.querySelector(`input[name="floor[${idStr}]"]`);
                    const c = modal.querySelector(`input[name="ceiling[${idStr}]"]`);
                    const w = modal.querySelector(`input[name="weight[${idStr}]"]`);

                    if (f) f.value = info.floor ?? '';
                    if (c) c.value = info.ceiling ?? '';
                    if (w) w.value = info.weight ?? '';
                });
            });
        }
    });

    // 搜尋通道
    document.addEventListener('input', function (e) {
        if (!e.target.classList.contains('payment-search-input')) return;
        var keyword = e.target.value.trim().toLowerCase();
        var tbody = document.querySelector(e.target.dataset.target);
        if (!tbody) return;

        tbody.querySelectorAll('tr').forEach(function (tr) {
            // 類型標題列（table-primary）永遠顯示
            if (tr.classList.contains('table-primary')) {
                tr.style.display = '';
                return;
            }
            var text = tr.textContent.toLowerCase();
            tr.style.display = (!keyword || text.indexOf(keyword) !== -1) ? '' : 'none';
        });

        // 隱藏底下沒有可見資料列的類型標題
        if (keyword) {
            tbody.querySelectorAll('tr.table-primary').forEach(function (header) {
                var next = header.nextElementSibling;
                var hasVisible = false;
                while (next && !next.classList.contains('table-primary')) {
                    if (next.style.display !== 'none') hasVisible = true;
                    next = next.nextElementSibling;
                }
                header.style.display = hasVisible ? '' : 'none';
            });
        }
    });
})();
