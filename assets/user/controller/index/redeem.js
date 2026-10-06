!function () {
    const namespace = '.pickupCenter';
    let currentResult = null;
    let requestToken = '';

    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
    const codeValue = () => String($('#redeem-code').val() || '').trim().toUpperCase();
    const quantityValue = () => Math.max(1, Math.min(1000, Number.parseInt($('#redeem-quantity').val(), 10) || 1));
    const makeToken = () => window.crypto?.randomUUID?.().replaceAll('-', '') || `${Date.now()}_${Math.random().toString(36).slice(2)}`;
    const lines = secret => String(secret || '').split(/\r?\n/).map(line => line.trim()).filter(Boolean);

    const requireCode = () => {
        const code = codeValue();
        if (!code) message.error(i18n('请输入兑换码'));
        return code;
    };

    const setBusy = (button, busy, busyText, normalHtml) => {
        button.prop('disabled', busy).attr('aria-busy', busy ? 'true' : 'false');
        button.html(busy ? `<i class="fa-duotone fa-regular fa-spinner-third icon-spin me-2"></i>${busyText}` : normalHtml);
    };

    const renderSummary = data => {
        const total = Number(data?.totalQuantity || 0);
        const used = Number(data?.usedQuantity || 0);
        const remaining = Number(data?.remainingQuantity || 0);
        $('.summary-name').text(data?.productName || i18n('兑换商品'));
        $('[data-summary="total"]').text(total);
        $('[data-summary="used"]').text(used);
        $('[data-summary="remaining"]').text(remaining);
        $('.summary-progress span').css('width', `${total > 0 ? Math.min(100, used / total * 100) : 0}%`);
        $('.summary-status')
            .attr('class', `a-badge summary-status ${data?.locked ? 'a-badge-dark' : remaining > 0 ? 'a-badge-success' : 'a-badge-warning'}`)
            .text(data?.locked ? i18n('已锁定') : remaining > 0 ? i18n('可提取') : i18n('已用完'));
        $('#redeem-quantity').attr('max', Math.max(1, remaining));
        if (remaining > 0 && quantityValue() > remaining) $('#redeem-quantity').val(remaining);
        $('.pickup-submit').prop('disabled', remaining <= 0 || Boolean(data?.locked));
        $('.code-summary').stop(true, true).slideDown(160);
    };

    const checkCode = () => {
        const code = requireCode();
        if (!code) return;
        const button = $('.pickup-check');
        const normal = `<i class="fa-duotone fa-regular fa-chart-pie me-1"></i> ${i18n('查询余额')}`;
        setBusy(button, true, i18n('查询中'), normal);
        util.post({
            url: '/user/api/redeem/check', data: {code}, loader: false,
            done: response => { setBusy(button, false, '', normal); renderSummary(response?.data || {}); },
            error: response => { setBusy(button, false, '', normal); message.error(response?.msg || i18n('查询失败')); },
            fail: () => { setBusy(button, false, '', normal); message.error(i18n('网络错误，请稍后重试')); }
        });
    };

    const renderResult = data => {
        currentResult = data || {};
        renderSummary(currentResult);
        $('.result-product').text(currentResult.productName || i18n('兑换商品'));
        $('.result-order').text(`${i18n('订单号')}: ${currentResult.tradeNo || '-'}${currentResult.createTime ? ` · ${currentResult.createTime}` : ''}`);
        $('.result-quantity').text(Number(currentResult.quantity || 0));
        $('.result-repeat').toggle(Boolean(currentResult.repeated));

        const items = Array.isArray(currentResult.items) && currentResult.items.length ? currentResult.items : lines(currentResult.secret);
        // 本地卡密以换行拼接；若单张卡密本身也是多行文本，就无法可靠判断分隔边界。
        // 只有行数与本次数量一致时才按账号逐条展示，否则保留原文，避免把一张卡拆成多张。
        const canSplit = items.length === Number(currentResult.quantity || 0);
        $('.result-items').html(items.map((item, index) => `<div class="result-item"><span class="result-item__index">${index + 1}</span><span class="result-item__value">${esc(item)}</span><button type="button" class="result-item__copy" data-copy-index="${index}" title="${esc(i18n('复制'))}"><i class="fa-duotone fa-regular fa-copy"></i></button></div>`).join('')).toggle(canSplit);
        $('.result-raw').text(currentResult.secret || '').toggle(!canSplit);
        const note = String(currentResult.leaveMessage || '').trim();
        $('.result-note').text(note).toggle(note !== '');
        $('.redeem-result').stop(true, true).slideDown(180);
    };

    const renderHistory = payload => {
        renderSummary(payload?.summary || {});
        const list = Array.isArray(payload?.list) ? payload.list : [];
        $('.history-list').html(list.map(item => `<div class="history-item"><div class="history-item__head"><div><strong>${esc(item.productName || i18n('兑换商品'))}</strong><div class="text-muted small mt-1">${esc(item.createTime || '-')} · ${esc(i18n('数量'))} ${Number(item.quantity || 0)}</div></div><code>${esc(item.tradeNo || '-')}</code></div><div class="history-item__secret">${esc(item.secret || '')}</div><div class="text-end mt-2"><button type="button" class="btn btn-sm btn-outline-primary history-copy" data-secret="${esc(item.secret || '')}"><i class="fa-duotone fa-regular fa-copy me-1"></i>${esc(i18n('复制'))}</button></div></div>`).join(''));
        $('.history-empty').toggle(list.length === 0);
        $('.history-wrap').stop(true, true).slideDown(160);
    };

    const loadHistory = () => {
        const code = requireCode();
        if (!code) return;
        const button = $('.pickup-history-btn');
        button.prop('disabled', true);
        util.post({
            url: '/user/api/redeem/history', data: {code}, loader: false,
            done: response => { button.prop('disabled', false); renderHistory(response?.data || {}); },
            error: response => { button.prop('disabled', false); message.error(response?.msg || i18n('查询失败')); },
            fail: () => { button.prop('disabled', false); message.error(i18n('网络错误，请稍后重试')); }
        });
    };

    $('.redeem-form').off(namespace).on('submit' + namespace, function (event) {
        event.preventDefault();
        const code = requireCode();
        if (!code) return;
        const quantity = quantityValue();
        $('#redeem-code').val(code);
        $('#redeem-quantity').val(quantity);
        requestToken ||= makeToken();
        const button = $('.pickup-submit');
        const normal = `<i class="fa-duotone fa-regular fa-box-open me-2"></i>${i18n('立即提取账号')}`;
        setBusy(button, true, i18n('正在提取'), normal);
        util.post({
            url: '/user/api/redeem/submit',
            data: {code, quantity, request_token: requestToken},
            loader: false,
            done: response => {
                setBusy(button, false, '', normal);
                requestToken = '';
                renderResult(response?.data || {});
                message.success(response?.msg || i18n('提取成功'));
            },
            error: response => { setBusy(button, false, '', normal); message.error(response?.msg || i18n('提取失败')); },
            fail: () => { setBusy(button, false, '', normal); message.error(i18n('网络错误，请重试；系统不会重复扣除额度')); }
        });
    });

    $('.pickup-check').off(namespace).on('click' + namespace, checkCode);
    $('.pickup-history-btn').off(namespace).on('click' + namespace, loadHistory);
    $('#redeem-code').off(namespace).on('input' + namespace, () => {
        requestToken = '';
        $('.code-summary,.redeem-result,.history-wrap').hide();
        $('.pickup-submit').prop('disabled', false);
    });
    $('#redeem-quantity').off(namespace).on('input' + namespace, () => { requestToken = ''; });
    $('[data-quantity]').off(namespace).on('click' + namespace, function () {
        const input = $('#redeem-quantity');
        const max = Number.parseInt(input.attr('max'), 10) || 1000;
        const next = Math.max(1, Math.min(max, quantityValue() + ($(this).data('quantity') === 'plus' ? 1 : -1)));
        input.val(next);
        requestToken = '';
    });
    $('.result-items').off(namespace).on('click' + namespace, '[data-copy-index]', function () {
        const items = Array.isArray(currentResult?.items) && currentResult.items.length ? currentResult.items : lines(currentResult?.secret);
        const value = items[Number($(this).data('copy-index'))] || '';
        if (value) util.copyTextToClipboard(value, () => message.success(i18n('已复制')));
    });
    $('.history-list').off(namespace).on('click' + namespace, '.history-copy', function () {
        util.copyTextToClipboard(String($(this).data('secret') || ''), () => message.success(i18n('已复制')));
    });
    $('.result-copy').off(namespace).on('click' + namespace, () => {
        if (currentResult?.secret) util.copyTextToClipboard(currentResult.secret, () => message.success(i18n('商品内容已复制')));
    });
    $('.result-download').off(namespace).on('click' + namespace, () => {
        if (!currentResult?.secret) return;
        const blob = new Blob([currentResult.secret], {type: 'text/plain;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${String(currentResult.productName || 'redeem').replace(/[\\/:*?"<>|]/g, '_')}-${currentResult.tradeNo || 'result'}.txt`;
        document.body.appendChild(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });

    const renderOrders = orders => {
        $('.query-loading,.query-empty').hide();
        if (!orders.length) { $('.query-empty').show(); $('.order-list').empty(); return; }
        $('.order-list').html(orders.map(order => {
            const paid = Number(order.status) === 1;
            const secret = paid && order.password !== true ? `<div class="query-order__secret">${esc(order.secret || '')}</div>` : '';
            const password = paid && order.password === true ? `<div class="query-password"><input type="password" class="form-control" placeholder="${esc(i18n('请输入查询密码'))}"><button type="button" class="btn btn-outline-primary query-secret-btn" data-trade-no="${esc(order.trade_no)}"><i class="fa-duotone fa-regular fa-eye me-1"></i>${esc(i18n('查看卡密'))}</button></div>` : '';
            return `<article class="query-order"><div class="query-order__head"><div><div class="query-order__no">#${esc(order.trade_no)}</div><div class="query-order__meta">${esc(order.create_time || '-')} · ${esc(order?.commodity?.name || i18n('商品已删除'))} · ${esc(i18n('数量'))} ${Number(order.card_num || 0)}</div></div><span class="a-badge ${paid ? 'a-badge-success' : 'a-badge-warning'}">${paid ? esc(i18n('已付款')) : esc(i18n('待付款'))}</span></div>${secret}${password}</article>`;
        }).join(''));
    };

    $('.order-query-form').off(namespace).on('submit' + namespace, function (event) {
        event.preventDefault();
        const keywords = String($('#order-keywords').val() || '').trim();
        if (!keywords) { message.error(i18n('请输入订单号或联系方式')); return; }
        $('.order-list').empty().hide();
        $('.query-empty').hide();
        $('.query-loading').show();
        util.post({
            url: '/user/api/index/query', data: {keywords, page: 1, limit: 20}, loader: false,
            done: response => { $('.order-list').show(); renderOrders(response?.data?.list || []); },
            error: response => { $('.query-loading').hide(); $('.query-empty').show(); message.error(response?.msg || i18n('查询失败')); },
            fail: () => { $('.query-loading').hide(); $('.query-empty').show(); message.error(i18n('网络错误，请稍后重试')); }
        });
    });
    $('.order-list').off(namespace).on('click' + namespace, '.query-secret-btn', function () {
        const button = $(this);
        const tradeNo = String(button.data('trade-no') || '');
        const password = String(button.siblings('input').val() || '').trim();
        if (!password) { message.error(i18n('请输入查询密码')); return; }
        button.prop('disabled', true);
        util.post({
            url: '/user/api/index/secret', data: {tradeNo, password}, loader: false,
            done: response => { button.closest('.query-password').replaceWith(`<div class="query-order__secret">${esc(response?.data?.secret || '')}</div>`); },
            error: response => { button.prop('disabled', false); message.error(response?.msg || i18n('查询失败')); },
            fail: () => { button.prop('disabled', false); message.error(i18n('网络错误，请稍后重试')); }
        });
    });

    $('.pickup-tab').off(namespace).on('click' + namespace, function () {
        const panel = String($(this).data('panel'));
        $('.pickup-tab').removeClass('active'); $(this).addClass('active');
        $('.pickup-panel').removeClass('active'); $(`[data-panel-content="${panel}"]`).addClass('active');
    });

    const tradeNo = util.getParam('tradeNo');
    if (/^\d{18,19}$/.test(String(tradeNo || ''))) {
        $('.pickup-tab[data-panel="query"]').click();
        $('#order-keywords').val(tradeNo);
        $('.order-query-form').trigger('submit');
    }

    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, () => {
        $('.redeem-form,.pickup-check,.pickup-history-btn,#redeem-code,#redeem-quantity,[data-quantity],.result-items,.history-list,.result-copy,.result-download,.order-query-form,.order-list,.pickup-tab').off(namespace);
    });
}();
