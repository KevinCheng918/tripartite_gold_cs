/**
 * 傳圖前的圖片編輯器：裁切、旋轉、畫筆、箭頭、方框、文字、馬賽克、塗黑
 *
 * 需求方 2026-11-20：「我想要可以讓他裁切、塗鴉這些動作，跟 Telegram 對話一樣。」
 *
 * ⚠⚠ **馬賽克／塗黑是這裡最重要的工具，不是裝飾。** 客服截圖常常整個後台
 * 畫面都拍進去，裡面有別的客戶的帳號、金額、手機 —— 沒有遮擋工具就只有
 * 兩條路：不傳（客人得不到說明），或把別人的資料一起送出去。
 *
 * ⚠ **零第三方依賴。** 這個專案沒有 npm／webpack（package.json 與
 * webpack.mix.js 都不存在），第三方是「下載檔案丟 public/vendor/」——
 * 為了一個拖拉選框卡在等人下載 Cropper.js 不划算，所以全部自己畫。
 *
 * 詳見 .claude/knowledge/features/chat-image-editor.md
 */
(function (window, document) {
    'use strict';

    /*
     * ⚠ 全域物件叫 **`TgChat`**（`state.js` 建的），不是 `TelegramChat`。
     * 第一版寫錯名字 ⇒ `T.openImageEditor` 掛在一個沒人看的物件上 ⇒
     * 點圖片完全沒反應，而且因為呼叫端寫了「沒有就 return」而**靜靜失敗**。
     */
    var T = window.TgChat;

    if (!T) { return; }

    /** 顏色固定幾個就好 —— 客服要的是「圈起來看得見」，不是配色 */
    var COLORS = ['#ff3b30', '#ffcc00', '#34c759', '#0a84ff', '#ffffff', '#000000'];

    /** 筆畫粗細（也決定文字大小與箭頭頭部大小） */
    var WIDTHS = [4, 8, 16];

    /** 馬賽克的格子大小，相對於筆畫粗細 */
    var MOSAIC_SCALE = 2.5;

    /** 輸出超過這個大小就降級成 JPEG 再試一次 */
    var MAX_OUTPUT_BYTES = 5 * 1024 * 1024;

    /** 目前這一次編輯的狀態；關閉時整個丟掉 */
    var editor = null;

    /**
     * 取語系字串
     *
     * @param {string} key
     * @param {string} fallback
     * @returns {string}
     */
    function t(key, fallback) {
        return (T.i18n && T.i18n[key]) || fallback;
    }

    /**
     * 取 msg 底下的語系字串
     *
     * @param {string} key
     * @param {string} fallback
     * @returns {string}
     */
    function tMsg(key, fallback) {
        return (T.i18n && T.i18n.msg && T.i18n.msg[key]) || fallback;
    }

    /**
     * 開啟編輯器
     *
     * @param {File}     file     要編輯的圖片
     * @param {Function} onDone   編輯完成時收到新的 File
     * @param {Function} [onError] 出錯時收到訊息字串
     */
    T.openImageEditor = function (file, onDone, onError) {
        var img = new Image();

        img.onload = function () {
            URL.revokeObjectURL(img.src);
            start(file, img, onDone);
        };

        /*
         * ⚠ 讀不到圖要走 onError 而不是靜靜不動：使用者點了「編輯」卻什麼
         * 都沒發生的話，他只會一直點。
         */
        img.onerror = function () {
            URL.revokeObjectURL(img.src);
            if (onError) { onError(tMsg('editor_failed', '圖片編輯器開啟失敗')); }
        };

        img.src = URL.createObjectURL(file);
    };

    /**
     * 建立編輯狀態並開啟視窗
     *
     * @param {File}     file
     * @param {Image}    img
     * @param {Function} onDone
     */
    function start(file, img, onDone) {
        var base = document.createElement('canvas');
        base.width = img.naturalWidth;
        base.height = img.naturalHeight;
        base.getContext('2d').drawImage(img, 0, 0);

        editor = {
            file: file,
            /*
             * 底圖。裁切與旋轉會把「目前畫面」烙印成新的底圖 ——
             * 那兩個動作會改變畫布尺寸，沒辦法用重畫表示。
             */
            source: base,
            // 剛進來的原圖，「重設」要用
            original: base,
            // 每一筆標註 {tool, points, color, width, text}
            ops: [],
            tool: 'pen',
            color: COLORS[0],
            width: WIDTHS[1],
            // 拖曳中的那一筆
            drawing: null,
            // 裁切模式的選框（原圖座標）
            crop: null,
            onDone: onDone,
        };

        render();
        document.body.classList.add('tg-editor-open');
    }

    // ---------------------------------------------------------------
    //  版面
    // ---------------------------------------------------------------

    /**
     * 畫出整個編輯器（全螢幕蓋版）
     */
    function render() {
        var root = document.createElement('div');
        root.id = 'tg-image-editor';
        root.className = 'tg-editor';
        root.innerHTML =
            '<div class="tg-editor-bar tg-editor-top">' +
            '<button type="button" class="tg-editor-btn" id="tg-editor-cancel">' +
            '<i class="fas fa-times"></i></button>' +
            '<span class="tg-editor-title">' + escapeHtml(t('editor_title', '編輯圖片')) + '</span>' +
            '<button type="button" class="tg-editor-btn tg-editor-primary" id="tg-editor-done">' +
            escapeHtml(t('editor_done', '完成')) + '</button>' +
            '</div>' +
            '<div class="tg-editor-stage" id="tg-editor-stage">' +
            '<div class="tg-editor-canvas-wrap" id="tg-editor-wrap">' +
            '<canvas id="tg-editor-base"></canvas>' +
            '<canvas id="tg-editor-overlay"></canvas>' +
            '</div>' +
            '</div>' +
            '<div class="tg-editor-bar tg-editor-bottom" id="tg-editor-bottom"></div>';

        document.body.appendChild(root);

        editor.root = root;
        editor.baseCanvas = document.getElementById('tg-editor-base');
        editor.overlay = document.getElementById('tg-editor-overlay');

        sizeCanvases();
        redraw();
        renderBottom();
        bindCanvas();

        document.getElementById('tg-editor-cancel').addEventListener('click', close);
        document.getElementById('tg-editor-done').addEventListener('click', finish);
    }

    /**
     * 兩層 canvas 都設成原圖尺寸
     *
     * ⚠ **畫布用原圖尺寸、顯示用 CSS 縮放**：直接用顯示尺寸當畫布的話，
     * 送出去的圖會跟著螢幕大小變糊。座標換算在 `posOf()`。
     */
    function sizeCanvases() {
        [editor.baseCanvas, editor.overlay].forEach(function (canvas) {
            canvas.width = editor.source.width;
            canvas.height = editor.source.height;
        });
    }

    /**
     * 下方工具列（裁切模式有自己的一組按鈕）
     */
    function renderBottom() {
        var bar = document.getElementById('tg-editor-bottom');

        if (editor.crop) {
            bar.innerHTML =
                '<button type="button" class="tg-editor-btn" id="tg-crop-cancel">' +
                escapeHtml(t('editor_crop_cancel', '取消裁切')) + '</button>' +
                '<span class="tg-editor-hint">' + escapeHtml(t('editor_flatten_hint', '')) + '</span>' +
                '<button type="button" class="tg-editor-btn tg-editor-primary" id="tg-crop-apply">' +
                escapeHtml(t('editor_crop_apply', '套用裁切')) + '</button>';

            document.getElementById('tg-crop-cancel').addEventListener('click', function () {
                editor.crop = null;
                clearOverlay();
                renderBottom();
            });
            document.getElementById('tg-crop-apply').addEventListener('click', applyCrop);

            return;
        }

        bar.innerHTML = toolsHtml() + colorsHtml() + widthsHtml() + actionsHtml();
        bindBottom();
    }

    /**
     * 工具按鈕
     *
     * @returns {string}
     */
    function toolsHtml() {
        var tools = [
            { key: 'pen', icon: 'fa-pen' },
            { key: 'arrow', icon: 'fa-long-arrow-alt-right' },
            { key: 'rect', icon: 'fa-square' },
            { key: 'text', icon: 'fa-font' },
            { key: 'mosaic', icon: 'fa-th' },
            { key: 'blackout', icon: 'fa-eraser' },
            { key: 'crop', icon: 'fa-crop-alt' },
            { key: 'rotate', icon: 'fa-redo' },
        ];

        var html = '<div class="tg-editor-tools">';

        tools.forEach(function (tool) {
            var active = tool.key === editor.tool ? ' tg-editor-active' : '';
            html += '<button type="button" class="tg-editor-tool' + active + '" data-tool="' + tool.key + '" ' +
                'title="' + escapeHtml(t('editor_' + tool.key, tool.key)) + '">' +
                '<i class="fas ' + tool.icon + '"></i></button>';
        });

        return html + '</div>';
    }

    /**
     * 顏色（遮擋工具用不到顏色，所以那兩個選起來時整排收起來）
     *
     * @returns {string}
     */
    function colorsHtml() {
        if (editor.tool === 'mosaic' || editor.tool === 'crop' || editor.tool === 'rotate') {
            return '';
        }

        var html = '<div class="tg-editor-colors">';

        COLORS.forEach(function (color) {
            var active = color === editor.color ? ' tg-editor-active' : '';
            html += '<button type="button" class="tg-editor-color' + active + '" data-color="' + color + '" ' +
                'style="background:' + color + '"></button>';
        });

        return html + '</div>';
    }

    /**
     * 粗細
     *
     * @returns {string}
     */
    function widthsHtml() {
        if (editor.tool === 'crop' || editor.tool === 'rotate') { return ''; }

        var html = '<div class="tg-editor-widths">';

        WIDTHS.forEach(function (width) {
            var active = width === editor.width ? ' tg-editor-active' : '';
            html += '<button type="button" class="tg-editor-width' + active + '" data-width="' + width + '" ' +
                'title="' + escapeHtml(t('editor_width', '粗細')) + '">' +
                '<span style="width:' + Math.min(width, 16) + 'px;height:' + Math.min(width, 16) + 'px"></span></button>';
        });

        return html + '</div>';
    }

    /**
     * 復原與重設
     *
     * @returns {string}
     */
    function actionsHtml() {
        return '<div class="tg-editor-actions">' +
            '<button type="button" class="tg-editor-tool" id="tg-editor-undo" ' +
            'title="' + escapeHtml(t('editor_undo', '復原')) + '"><i class="fas fa-undo"></i></button>' +
            '<button type="button" class="tg-editor-tool" id="tg-editor-reset" ' +
            'title="' + escapeHtml(t('editor_reset', '重設')) + '"><i class="fas fa-trash-restore"></i></button>' +
            '</div>';
    }

    /**
     * 綁工具列的事件
     */
    function bindBottom() {
        var bar = document.getElementById('tg-editor-bottom');

        bar.querySelectorAll('[data-tool]').forEach(function (btn) {
            btn.addEventListener('click', function () { selectTool(btn.getAttribute('data-tool')); });
        });

        bar.querySelectorAll('[data-color]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                editor.color = btn.getAttribute('data-color');
                renderBottom();
            });
        });

        bar.querySelectorAll('[data-width]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                editor.width = parseInt(btn.getAttribute('data-width'), 10);
                renderBottom();
            });
        });

        document.getElementById('tg-editor-undo').addEventListener('click', undo);
        document.getElementById('tg-editor-reset').addEventListener('click', reset);
    }

    /**
     * 切換工具
     *
     * 旋轉不是「模式」而是當場做掉的動作，所以按下去就轉。
     *
     * @param {string} tool
     */
    function selectTool(tool) {
        if (tool === 'rotate') {
            rotate();

            return;
        }

        editor.tool = tool;
        editor.crop = tool === 'crop' ? defaultCrop() : null;

        clearOverlay();
        renderBottom();

        if (editor.crop) { drawCropOverlay(); }
    }

    // ---------------------------------------------------------------
    //  繪製
    // ---------------------------------------------------------------

    /**
     * 底層重畫：原圖 ＋ 每一筆已確定的標註
     *
     * ⚠ 重畫而不是存圖片快照，是因為一張 4000×3000 的 `ImageData` 是 48MB，
     * 存十步就把瀏覽器吃光。
     */
    function redraw() {
        var ctx = editor.baseCanvas.getContext('2d');

        ctx.clearRect(0, 0, editor.baseCanvas.width, editor.baseCanvas.height);
        ctx.drawImage(editor.source, 0, 0);

        editor.ops.forEach(function (op) { paint(ctx, op); });
    }

    /**
     * 畫一筆
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {object}                   op
     */
    function paint(ctx, op) {
        if (op.tool === 'mosaic') { paintMosaic(ctx, op); return; }
        if (op.tool === 'blackout') { paintBlackout(ctx, op); return; }
        if (op.tool === 'text') { paintText(ctx, op); return; }

        ctx.save();
        ctx.strokeStyle = op.color;
        ctx.fillStyle = op.color;
        ctx.lineWidth = op.width;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        if (op.tool === 'pen') { paintPen(ctx, op); }
        if (op.tool === 'arrow') { paintArrow(ctx, op); }
        if (op.tool === 'rect') { paintRect(ctx, op); }

        ctx.restore();
    }

    /**
     * @param {CanvasRenderingContext2D} ctx
     * @param {object}                   op
     */
    function paintPen(ctx, op) {
        if (op.points.length < 2) { return; }

        ctx.beginPath();
        ctx.moveTo(op.points[0].x, op.points[0].y);
        op.points.forEach(function (point) { ctx.lineTo(point.x, point.y); });
        ctx.stroke();
    }

    /**
     * 實心箭頭：一條線 ＋ 一個三角形頭
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {object}                   op
     */
    function paintArrow(ctx, op) {
        var from = op.points[0];
        var to = op.points[op.points.length - 1];
        var head = Math.max(op.width * 3, 12);
        var angle = Math.atan2(to.y - from.y, to.x - from.x);

        ctx.beginPath();
        ctx.moveTo(from.x, from.y);
        // 線收在箭頭底部，不然線頭會從三角形尖端戳出來
        ctx.lineTo(to.x - Math.cos(angle) * head * 0.8, to.y - Math.sin(angle) * head * 0.8);
        ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(to.x, to.y);
        ctx.lineTo(to.x - Math.cos(angle - Math.PI / 7) * head, to.y - Math.sin(angle - Math.PI / 7) * head);
        ctx.lineTo(to.x - Math.cos(angle + Math.PI / 7) * head, to.y - Math.sin(angle + Math.PI / 7) * head);
        ctx.closePath();
        ctx.fill();
    }

    /**
     * @param {CanvasRenderingContext2D} ctx
     * @param {object}                   op
     */
    function paintRect(ctx, op) {
        var box = boxOf(op.points);

        ctx.strokeRect(box.x, box.y, box.w, box.h);
    }

    /**
     * 文字。字級跟著筆畫粗細走，不另外做字級選單
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {object}                   op
     */
    function paintText(ctx, op) {
        var size = op.width * 6;

        ctx.save();
        ctx.fillStyle = op.color;
        ctx.font = '600 ' + size + 'px system-ui, -apple-system, "Noto Sans TC", sans-serif';
        ctx.textBaseline = 'top';
        /*
         * 白字打在亮背景上會看不見，所以一律描一圈反差邊 ——
         * 比讓使用者自己注意背景色可靠。
         */
        ctx.lineWidth = Math.max(size / 10, 2);
        ctx.strokeStyle = op.color === '#000000' ? '#ffffff' : '#000000';
        ctx.strokeText(op.text, op.points[0].x, op.points[0].y);
        ctx.fillText(op.text, op.points[0].x, op.points[0].y);
        ctx.restore();
    }

    /**
     * 馬賽克：把那一塊縮小再放大
     *
     * ⚠ 這是**疊在當下畫面上**的操作，所以它在 `ops` 裡的順序有意義 ——
     * 先畫箭頭再馬賽克，箭頭也會被糊掉，那是對的。
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {object}                   op
     */
    function paintMosaic(ctx, op) {
        var box = boxOf(op.points);

        if (box.w < 2 || box.h < 2) { return; }

        var block = Math.max(Math.round(op.width * MOSAIC_SCALE), 4);
        var small = document.createElement('canvas');
        small.width = Math.max(Math.round(box.w / block), 1);
        small.height = Math.max(Math.round(box.h / block), 1);

        var smallCtx = small.getContext('2d');
        smallCtx.drawImage(ctx.canvas, box.x, box.y, box.w, box.h, 0, 0, small.width, small.height);

        ctx.save();
        // 放大回去時不要補間，才會是一格一格的馬賽克而不是模糊
        ctx.imageSmoothingEnabled = false;
        ctx.drawImage(small, 0, 0, small.width, small.height, box.x, box.y, box.w, box.h);
        ctx.restore();
    }

    /**
     * 遮色塊：敏感欄位用這個，完全蓋掉
     *
     * ⚠ **填的是「目前選的顏色」，不是固定黑色**（實測截圖是紅色塊才發現
     * 工具名寫「塗黑」會誤導）。這樣更有用：深色底的截圖用白色遮、
     * 淺色底用黑色遮，都看得出「這裡被蓋住了」而不是「這裡本來就空的」。
     * 工具名因此改叫**遮色塊**。
     *
     * @param {CanvasRenderingContext2D} ctx
     * @param {object}                   op
     */
    function paintBlackout(ctx, op) {
        var box = boxOf(op.points);

        ctx.save();
        ctx.fillStyle = op.color;
        ctx.fillRect(box.x, box.y, box.w, box.h);
        ctx.restore();
    }

    /**
     * 兩點換成正規化的矩形（往左上拉也要是正的寬高）
     *
     * @param {Array} points
     * @returns {{x: number, y: number, w: number, h: number}}
     */
    function boxOf(points) {
        var from = points[0];
        var to = points[points.length - 1];

        return {
            x: Math.min(from.x, to.x),
            y: Math.min(from.y, to.y),
            w: Math.abs(to.x - from.x),
            h: Math.abs(to.y - from.y),
        };
    }

    /**
     * 清掉上層（拖曳預覽用的那一層）
     */
    function clearOverlay() {
        editor.overlay.getContext('2d').clearRect(0, 0, editor.overlay.width, editor.overlay.height);
    }

    // ---------------------------------------------------------------
    //  輸入
    // ---------------------------------------------------------------

    /**
     * 綁畫布上的指標事件
     *
     * ⚠ 一律用 Pointer Events：滑鼠與手指同一套程式碼。
     * 後台是 PWA、對話視窗本來就有滑動手勢，手機上一定會有人用。
     */
    function bindCanvas() {
        var overlay = editor.overlay;

        overlay.addEventListener('pointerdown', onDown);
        overlay.addEventListener('pointermove', onMove);
        overlay.addEventListener('pointerup', onUp);
        overlay.addEventListener('pointercancel', onUp);
    }

    /**
     * 顯示座標換成原圖座標
     *
     * ⚠ **最容易出錯的一段**：畫布是原圖尺寸、顯示是縮放過的，
     * 比例算錯的話筆跡會跟手指差一段，而且離中心越遠偏得越多。
     *
     * @param {PointerEvent} e
     * @returns {{x: number, y: number}}
     */
    function posOf(e) {
        var rect = editor.overlay.getBoundingClientRect();

        return {
            x: (e.clientX - rect.left) * (editor.overlay.width / rect.width),
            y: (e.clientY - rect.top) * (editor.overlay.height / rect.height),
        };
    }

    /**
     * @param {PointerEvent} e
     */
    function onDown(e) {
        e.preventDefault();
        editor.overlay.setPointerCapture(e.pointerId);

        var pos = posOf(e);

        if (editor.crop) { startCropDrag(pos); return; }

        if (editor.tool === 'text') {
            openTextInput(pos, e);

            return;
        }

        editor.drawing = {
            tool: editor.tool,
            points: [pos],
            color: editor.color,
            width: editor.width,
        };
    }

    /**
     * @param {PointerEvent} e
     */
    function onMove(e) {
        if (editor.crop && editor.cropDrag) { moveCropDrag(posOf(e)); return; }
        if (!editor.drawing) { return; }

        e.preventDefault();
        editor.drawing.points.push(posOf(e));
        previewDrawing();
    }

    /**
     * 放開才算一筆 —— 這時才進堆疊、才重畫底層
     *
     * @param {PointerEvent} e
     */
    function onUp(e) {
        if (editor.cropDrag) { editor.cropDrag = null; return; }
        if (!editor.drawing) { return; }

        var op = editor.drawing;
        editor.drawing = null;
        clearOverlay();

        // 點一下沒有拖曳：畫筆之外的工具都需要一個範圍，沒範圍就當作沒畫
        if (op.tool !== 'pen' && op.points.length < 2) { return; }

        editor.ops.push(op);
        redraw();
    }

    /**
     * 拖曳中的預覽畫在上層
     *
     * ⚠ **分層是為了不要每一幀都重畫整張原圖**：4000×3000 的截圖每幀重畫
     * 會掉到十幾 fps，拉箭頭會頓得很明顯。
     */
    function previewDrawing() {
        clearOverlay();

        var ctx = editor.overlay.getContext('2d');

        /*
         * 馬賽克要讀「底下現在長什麼樣」才算得出來，而上層是透明的 ——
         * 所以預覽時改用半透明方框示意，放開才真的套用。
         */
        if (editor.drawing.tool === 'mosaic') {
            var box = boxOf(editor.drawing.points);
            ctx.save();
            ctx.fillStyle = 'rgba(255,255,255,0.45)';
            ctx.fillRect(box.x, box.y, box.w, box.h);
            ctx.restore();

            return;
        }

        paint(ctx, editor.drawing);
    }

    /**
     * 文字輸入框：浮在點下去的位置
     *
     * ⚠ **不能用 `prompt()`**（專案規則：所有輸入與回饋都走自己的 UI）——
     * 而且 prompt 在手機上會把整個畫面蓋掉，使用者看不到自己點在哪。
     *
     * @param {{x: number, y: number}} pos 原圖座標
     * @param {PointerEvent}           e
     */
    function openTextInput(pos, e) {
        var existing = document.getElementById('tg-editor-text-input');
        if (existing) { existing.remove(); }

        var input = document.createElement('input');
        input.id = 'tg-editor-text-input';
        input.className = 'tg-editor-text-input';
        input.type = 'text';
        input.maxLength = 60;
        input.placeholder = t('editor_text_placeholder', '輸入文字後按 Enter');
        input.style.left = e.clientX + 'px';
        input.style.top = e.clientY + 'px';
        input.style.color = editor.color;

        document.body.appendChild(input);
        input.focus();

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { input.remove(); return; }
            if (event.key !== 'Enter') { return; }

            var text = input.value.trim();
            input.remove();

            if (!text) { return; }

            editor.ops.push({
                tool: 'text',
                points: [pos],
                color: editor.color,
                width: editor.width,
                text: text,
            });
            redraw();
        });

        // 點到別處就取消，不要留一個孤兒輸入框在畫面上
        input.addEventListener('blur', function () { input.remove(); });
    }

    // ---------------------------------------------------------------
    //  裁切與旋轉
    // ---------------------------------------------------------------

    /**
     * 預設選框：中間八成
     *
     * @returns {{x: number, y: number, w: number, h: number}}
     */
    function defaultCrop() {
        var w = editor.source.width;
        var h = editor.source.height;

        return { x: w * 0.1, y: h * 0.1, w: w * 0.8, h: h * 0.8 };
    }

    /**
     * 按在選框的哪裡：角落＝改大小，框內＝整個移動
     *
     * @param {{x: number, y: number}} pos
     */
    function startCropDrag(pos) {
        var box = editor.crop;
        // 手把的判定範圍跟著圖的大小走，小圖上才不會整張都是手把
        var grip = Math.max(editor.source.width, editor.source.height) * 0.05;

        var corners = {
            nw: { x: box.x, y: box.y },
            ne: { x: box.x + box.w, y: box.y },
            sw: { x: box.x, y: box.y + box.h },
            se: { x: box.x + box.w, y: box.y + box.h },
        };

        var hit = null;

        Object.keys(corners).forEach(function (key) {
            var corner = corners[key];
            if (Math.abs(pos.x - corner.x) < grip && Math.abs(pos.y - corner.y) < grip) { hit = key; }
        });

        editor.cropDrag = {
            corner: hit,
            start: pos,
            origin: { x: box.x, y: box.y, w: box.w, h: box.h },
        };
    }

    /**
     * @param {{x: number, y: number}} pos
     */
    function moveCropDrag(pos) {
        var drag = editor.cropDrag;
        var origin = drag.origin;
        var dx = pos.x - drag.start.x;
        var dy = pos.y - drag.start.y;
        var box = { x: origin.x, y: origin.y, w: origin.w, h: origin.h };

        if (!drag.corner) {
            box.x = origin.x + dx;
            box.y = origin.y + dy;
        } else {
            if (drag.corner.indexOf('n') === 0) { box.y = origin.y + dy; box.h = origin.h - dy; }
            if (drag.corner.indexOf('s') === 0) { box.h = origin.h + dy; }
            if (drag.corner.indexOf('w') > 0) { box.x = origin.x + dx; box.w = origin.w - dx; }
            if (drag.corner.indexOf('e') > 0) { box.w = origin.w + dx; }
        }

        editor.crop = clampCrop(box);
        drawCropOverlay();
    }

    /**
     * 選框不能跑出圖外，也不能小到看不見
     *
     * @param {object} box
     * @returns {object}
     */
    function clampCrop(box) {
        var min = 24;
        var maxW = editor.source.width;
        var maxH = editor.source.height;

        box.w = Math.max(box.w, min);
        box.h = Math.max(box.h, min);
        box.x = Math.min(Math.max(box.x, 0), maxW - box.w);
        box.y = Math.min(Math.max(box.y, 0), maxH - box.h);
        box.w = Math.min(box.w, maxW - box.x);
        box.h = Math.min(box.h, maxH - box.y);

        return box;
    }

    /**
     * 框外變暗 ＋ 四角手把
     */
    function drawCropOverlay() {
        var ctx = editor.overlay.getContext('2d');
        var box = editor.crop;
        var w = editor.overlay.width;
        var h = editor.overlay.height;

        ctx.clearRect(0, 0, w, h);
        ctx.save();
        ctx.fillStyle = 'rgba(0,0,0,0.55)';
        ctx.fillRect(0, 0, w, h);
        ctx.clearRect(box.x, box.y, box.w, box.h);

        var line = Math.max(w, h) * 0.004;
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = line;
        ctx.strokeRect(box.x, box.y, box.w, box.h);

        var grip = line * 6;
        ctx.fillStyle = '#ffffff';
        [[box.x, box.y], [box.x + box.w, box.y], [box.x, box.y + box.h], [box.x + box.w, box.y + box.h]]
            .forEach(function (point) {
                ctx.fillRect(point[0] - grip / 2, point[1] - grip / 2, grip, grip);
            });

        ctx.restore();
    }

    /**
     * 套用裁切
     *
     * ⚠ **會把目前的標註烙印進底圖、並清空堆疊** —— 裁切改變了畫布尺寸，
     * 舊的座標對不回去。所以裁切之後復原不回裁切前，工具列上有一句提醒。
     */
    function applyCrop() {
        var box = editor.crop;
        var next = document.createElement('canvas');
        next.width = Math.round(box.w);
        next.height = Math.round(box.h);

        next.getContext('2d').drawImage(
            editor.baseCanvas,
            Math.round(box.x), Math.round(box.y), next.width, next.height,
            0, 0, next.width, next.height
        );

        flatten(next);
    }

    /**
     * 右轉 90°
     *
     * ⚠ 同裁切：寬高互換，所以也要烙印。
     */
    function rotate() {
        var next = document.createElement('canvas');
        next.width = editor.baseCanvas.height;
        next.height = editor.baseCanvas.width;

        var ctx = next.getContext('2d');
        ctx.translate(next.width / 2, next.height / 2);
        ctx.rotate(Math.PI / 2);
        ctx.drawImage(editor.baseCanvas, -editor.baseCanvas.width / 2, -editor.baseCanvas.height / 2);

        flatten(next);
    }

    /**
     * 把新畫布當成底圖，堆疊清空
     *
     * @param {HTMLCanvasElement} canvas
     */
    function flatten(canvas) {
        editor.source = canvas;
        editor.ops = [];
        editor.crop = null;
        editor.tool = 'pen';

        sizeCanvases();
        clearOverlay();
        redraw();
        renderBottom();
    }

    // ---------------------------------------------------------------
    //  復原、重設、輸出
    // ---------------------------------------------------------------

    /**
     * 退掉最後一筆
     */
    function undo() {
        if (!editor.ops.length) { return; }

        editor.ops.pop();
        redraw();
    }

    /**
     * 回到剛進來的原圖
     */
    function reset() {
        editor.source = editor.original;
        editor.ops = [];
        editor.crop = null;

        sizeCanvases();
        clearOverlay();
        redraw();
        renderBottom();
    }

    /**
     * 完成：輸出成 File 交回呼叫端
     */
    function finish() {
        var file = editor.file;
        var onDone = editor.onDone;
        var canvas = editor.baseCanvas;
        var isPng = (file.type || '').indexOf('png') >= 0;

        toBlob(canvas, isPng ? 'image/png' : 'image/jpeg', 0.92, function (blob) {
            /*
             * ⚠ **PNG 截圖畫幾筆之後常常比原檔還大**，而上傳上限是 5MB。
             * 超過就降級成 JPEG 再試一次 —— 總比讓他按了發送才失敗好。
             */
            if (blob && blob.size > MAX_OUTPUT_BYTES) {
                toBlob(canvas, 'image/jpeg', 0.85, function (smaller) {
                    deliver(smaller, file, onDone, 'image/jpeg');
                });

                return;
            }

            deliver(blob, file, onDone, isPng ? 'image/png' : 'image/jpeg');
        });
    }

    /**
     * 把結果交回去並關掉編輯器
     *
     * @param {Blob|null} blob
     * @param {File}      source
     * @param {Function}  onDone
     * @param {string}    type
     */
    function deliver(blob, source, onDone, type) {
        if (!blob) { close(); return; }

        if (blob.size > MAX_OUTPUT_BYTES) {
            /*
             * 連降級成 JPEG 都塞不下。
             *
             * ⚠ **不關掉編輯器**：他現在就能裁一塊再按一次完成，
             * 關掉等於把剛才畫的全丟了。訊息顯示在畫面上而不是 alert
             * （專案規則：所有回饋都走自己的 UI）。
             */
            showError(tMsg('edited_too_large', '編輯後的圖片太大，請先裁切一部分再試'));

            return;
        }

        close();
        onDone(new File([blob], renameFor(source.name, type), { type: type }));
    }

    /**
     * 格式換了就換副檔名 —— 客人看到的檔名要跟內容對得起來
     *
     * @param {string} name
     * @param {string} type
     * @returns {string}
     */
    function renameFor(name, type) {
        var wantPng = type === 'image/png';
        var base = name.replace(/\.[^.]+$/, '');

        if (wantPng) { return base + '.png'; }

        return /\.(jpe?g)$/i.test(name) ? name : base + '.jpg';
    }

    /**
     * `canvas.toBlob` 的小包裝（舊瀏覽器沒有就退 dataURL）
     *
     * @param {HTMLCanvasElement} canvas
     * @param {string}            type
     * @param {number}            quality
     * @param {Function}          done
     */
    function toBlob(canvas, type, quality, done) {
        if (canvas.toBlob) {
            canvas.toBlob(done, type, quality);

            return;
        }

        var parts = canvas.toDataURL(type, quality).split(',');
        var binary = window.atob(parts[1]);
        var bytes = new Uint8Array(binary.length);

        for (var i = 0; i < binary.length; i++) { bytes[i] = binary.charCodeAt(i); }

        done(new Blob([bytes], { type: type }));
    }

    /**
     * 在編輯器裡顯示一行錯誤（不是 alert，也不關掉編輯器）
     *
     * @param {string} message
     */
    function showError(message) {
        var box = document.getElementById('tg-editor-error');

        if (!box) {
            box = document.createElement('div');
            box.id = 'tg-editor-error';
            box.className = 'tg-editor-error';
            document.getElementById('tg-editor-stage').appendChild(box);
        }

        box.textContent = message;
        box.style.display = 'block';
    }

    /**
     * 關掉並清乾淨
     */
    function close() {
        var input = document.getElementById('tg-editor-text-input');
        if (input) { input.remove(); }

        if (editor && editor.root) { editor.root.remove(); }

        document.body.classList.remove('tg-editor-open');
        editor = null;
    }

    /**
     * @param {string} text
     * @returns {string}
     */
    function escapeHtml(text) {
        return String(text == null ? '' : text).replace(/[&<>"']/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
        });
    }
})(window, document);
