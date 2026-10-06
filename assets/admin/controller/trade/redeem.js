!function () {
    const namespace = '.redeemAdmin';
    let active = true;
    let table = null, sourceTable = null, deliveryTable = null;
    const layers = new Set();
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
    if (typeof window.__redeemAdminDestroy === 'function') window.__redeemAdminDestroy();

    const openLayer = options => {
        const originalEnd = options.end;
        let index;
        index = layer.open({...options, end: function () {
            layers.delete(index);
            if (typeof originalEnd === 'function') originalEnd.apply(this, arguments);
        }});
        layers.add(index);
        return index;
    };

    const download = (content, filename) => {
        const blob = new Blob([content], {type: 'text/plain;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    const showGenerated = data => {
        const codes = String(data?.codes || '');
        if (!codes) return;
        openLayer({
            type: 1,
            title: `${util.icon('fa-duotone fa-regular fa-circle-check')} ${i18n('兑换码生成成功')}`,
            area: ['540px', 'auto'],
            shadeClose: false,
            content: `<div class="md-secret"><div class="md-secret__meta"><span class="a-badge a-badge-success">${i18n('成功')} ${Number(data.success || 0)} ${i18n('个')}</span><span class="a-badge a-badge-primary">${i18n('批次')} ${escapeHtml(data.batch_no || '')}</span></div><div class="alert alert-warning mt-3 mb-3">${i18n('系统不保存明文，请关闭前先复制或下载。')}</div><div class="md-secret__code">${escapeHtml(codes)}</div><div class="md-secret__bar"><button type="button" class="md-secret__btn" data-action="copy">${util.icon('fa-duotone fa-regular fa-copy')} ${i18n('复制')}</button><button type="button" class="md-secret__btn md-secret__btn--primary" data-action="download">${util.icon('fa-duotone fa-regular fa-download')} ${i18n('下载')}</button></div></div>`,
            success: layero => {
                layero.find('[data-action="copy"]').on('click', () => util.copyTextToClipboard(codes, () => message.success(i18n('兑换码已复制'))));
                layero.find('[data-action="download"]').on('click', () => download(codes + (codes.endsWith('\n') ? '' : '\n'), `redeem-${data.batch_no || 'codes'}.txt`));
            }
        });
    };

    const productField = products => ({
        title: '选择商品',
        name: 'commodity_id',
        type: 'select',
        dict: products,
        placeholder: '请选择无多规格的主站本地卡密或 Dola 商品',
        search: true,
        required: true
    });

    const withProducts = callback => util.post('/admin/api/redeem/products', {}, response => {
        const products = Array.isArray(response?.data) ? response.data : [];
        if (!products.length) {
            message.error(i18n('暂无可绑定的本地卡密或 Dola 单规格商品'));
            return;
        }
        callback(products);
    });

    const generate = () => withProducts(products => component.popup({
        submit: '/admin/api/redeem/generate',
        message: false,
        tab: [{name: util.icon('fa-duotone fa-regular fa-wand-magic-sparkles') + i18n(' 生成兑换码'), form: [
            productField(products),
            {title: '每码总额度', name: 'quantity', type: 'number', default: 1, required: true, placeholder: '买家可分多次提取，累计不超过此数量'},
            {title: '兑换码前缀', name: 'prefix', type: 'input', default: 'ACG-R', required: true, regex: {value: '^[A-Za-z0-9][A-Za-z0-9_-]{0,15}$', message: '前缀需为 1 到 16 位字母、数字、下划线或短横线'}},
            {title: '生成数量', name: 'count', type: 'number', default: 10, required: true, placeholder: '单次最多 1000 个'},
            {title: '备注', name: 'note', type: 'input', placeholder: '可填写渠道或用途，最多 64 字'}
        ]}],
        width: '620px', height: 'auto', autoPosition: true,
        done: response => { if (active) { table.refresh(); showGenerated(response?.data || {}); } }
    }));

    const importCodes = () => withProducts(products => component.popup({
        submit: '/admin/api/redeem/import',
        message: false,
        tab: [{name: util.icon('fa-duotone fa-regular fa-file-import') + i18n(' 导入兑换码'), form: [
            productField(products),
            {title: '每码总额度', name: 'quantity', type: 'number', default: 1, required: true, placeholder: '买家可分多次提取'},
            {title: '兑换码', name: 'codes', type: 'textarea', preserveLiteral: true, required: true, height: 240, placeholder: '一行一个，单次最多 1000 个；字母不区分大小写'},
            {title: '备注', name: 'note', type: 'input', placeholder: '可填写渠道或用途，最多 64 字'}
        ]}],
        width: '660px', height: 'auto', autoPosition: true,
        done: response => { if (active) { table.refresh(); message.success(response?.msg || i18n('导入完成')); } }
    }));

    const addDolaSources = () => component.popup({
        submit: '/admin/api/redeem/dolaAdd',
        message: false,
        tab: [{name: util.icon('fa-duotone fa-regular fa-key') + i18n(' 添加 Dola 上游 KEY'), form: [
            {title: 'Dola 货源', name: 'shared_id', type: 'select', dict: 'shared->type=3,id,name', search: true, required: true, placeholder: '请先在店铺共享中创建 Dola 提货货源'},
            {title: '提货 KEY', name: 'keys', type: 'textarea', preserveLiteral: true, required: true, height: 230, placeholder: '每行一个 KEY，也可以粘贴完整提货链接'},
            {title: false, name: '_notice', type: 'custom', submit: false, complete: (form, container) => container.html(`<div class="alert alert-info mb-0">${i18n('保存前只会查询 KEY 状态，不会携带提货数量，也不会扣减上游库存。')}</div>`)}
        ]}],
        width: '660px', height: 'auto', autoPosition: true,
        done: response => {
            if (!active) return;
            sourceTable && sourceTable.refresh();
            message.success(response?.msg || i18n('Dola 上游 KEY 已添加'));
        }
    });

    const removeDolaSource = row => message.ask(
        i18n('确认从该货源移除上游 KEY {key} 吗？').replace('{key}', String(row?.key_mask || '')),
        () => util.post('/admin/api/redeem/dolaRemove', {
            shared_id: row.shared_id,
            source_hash: row.source_hash
        }, response => {
            message.success(response?.msg || i18n('Dola 上游 KEY 已移除'));
            sourceTable && sourceTable.refresh();
        })
    );

    const initDolaSources = () => {
        if (sourceTable || !document.querySelector('#dola-source-table')) return;
        sourceTable = new Table('/admin/api/redeem/dolaSources', '#dola-source-table');
        sourceTable.setFloatMessage([
            {field: 'domain', title: '上游地址', formatter: value => escapeHtml(value || '-')},
            {field: 'error', title: '异常信息', formatter: value => escapeHtml(value || '-')}
        ]);
        sourceTable.setColumns([
            {field: 'store_name', title: 'Dola 货源', formatter: value => escapeHtml(value || '-')},
            {field: 'key_mask', title: '上游 KEY', formatter: value => `<code>${escapeHtml(value || '-')}</code>`},
            {field: 'source_name', title: '上游名称', formatter: value => escapeHtml(value || '-')},
            {field: 'remaining', title: '剩余可提', formatter: (value, row) => row?.online
                ? `<span class="a-badge a-badge-success">${Number(value || 0)}</span>`
                : `<span class="a-badge a-badge-danger">${i18n('查询失败')}</span>`},
            {field: 'taken', title: '已提 / 总数', formatter: (value, row) => row?.online
                ? `${Number(value || 0)} / ${Number(row.total || 0)}` : '-'},
            {field: 'delivered_times', title: '提货次数', formatter: value => value == null ? '-' : Number(value || 0)},
            {field: 'online', title: '状态', formatter: value => value
                ? `<span class="a-badge a-badge-primary">${i18n('正常')}</span>`
                : `<span class="a-badge a-badge-danger">${i18n('异常')}</span>`},
            {field: 'operation', title: '操作', type: 'button', buttons: [
                {icon: 'fa-duotone fa-regular fa-trash-can', class: 'text-danger', title: '移除', show: row => Boolean(row?.source_hash), click: (event, value, row) => removeDolaSource(row)}
            ]}
        ]);
        sourceTable.render();
    };

    const showDolaDelivery = row => util.post('/admin/api/redeem/dolaDeliveryDetail', {id: row.id}, response => {
        const data = response?.data || {};
        const secret = String(data.secret || '');
        openLayer({
            type: 1,
            title: `${util.icon('fa-duotone fa-regular fa-box-open')} ${i18n('Dola 提货批次')}`,
            area: ['680px', 'auto'],
            shadeClose: true,
            content: `<div class="md-secret"><div class="md-secret__meta"><span class="a-badge a-badge-primary">${i18n('批次')} #${Number(data.sequence || 0) + 1}</span><span class="a-badge a-badge-success">${Number(data.requested || 0)} ${i18n('个账号')}</span>${data.trade_no ? `<span class="a-badge a-badge-info">${i18n('订单')} ${escapeHtml(data.trade_no)}</span>` : ''}</div><div class="md-secret__code">${escapeHtml(secret)}</div><div class="md-secret__bar"><button type="button" class="md-secret__btn md-secret__btn--primary" data-action="copy">${util.icon('fa-duotone fa-regular fa-copy')} ${i18n('复制账号')}</button><button type="button" class="md-secret__btn" data-action="download">${util.icon('fa-duotone fa-regular fa-download')} ${i18n('下载')}</button></div></div>`,
            success: layero => {
                layero.find('[data-action="copy"]').on('click', () => util.copyTextToClipboard(secret, () => message.success(i18n('账号内容已复制'))));
                layero.find('[data-action="download"]').on('click', () => download(secret + (secret.endsWith('\n') ? '' : '\n'), `dola-delivery-${data.id || 'batch'}.txt`));
            }
        });
    });

    const deliveryOrder = row => row?.order_by_request || row?.orderByRequest || row?.order_by_trade_no || row?.orderByTradeNo || null;

    const initDolaDeliveries = () => {
        if (deliveryTable || !document.querySelector('#dola-delivery-table')) return;
        deliveryTable = new Table('/admin/api/redeem/dolaDeliveries', '#dola-delivery-table');
        deliveryTable.setFloatMessage([
            {field: 'request_no', title: '幂等请求号', formatter: value => escapeHtml(value || '-')},
            {field: 'request_tag', title: '上游请求标识', formatter: value => escapeHtml(value || '-')},
            {field: 'error', title: '最后错误', formatter: value => escapeHtml(value || '-')},
            {field: 'update_time', title: '更新时间', formatter: value => escapeHtml(value || '-')}
        ]);
        deliveryTable.setColumns([
            {field: 'id', title: 'ID'},
            {field: 'shared', title: 'Dola 货源', formatter: value => escapeHtml(value?.name || '-')},
            {field: 'request_no', title: '请求号', formatter: value => `<code>${escapeHtml(value || '-')}</code>`},
            {field: 'sequence', title: '批次', formatter: value => `#${Number(value || 0) + 1}`},
            {field: 'requested', title: '本批数量', formatter: value => `<span class="a-badge a-badge-primary">${Number(value || 0)}</span>`},
            {field: 'order_quantity', title: '订单总数', formatter: value => Number(value || 0)},
            {field: 'order', title: '关联订单', formatter: (value, row) => {
                const order = deliveryOrder(row);
                return order?.trade_no ? `<code>${escapeHtml(order.trade_no)}</code>` : `<span class="text-muted">${i18n('本地订单未找到')}</span>`;
            }},
            {field: 'status', title: '状态', formatter: value => Number(value) === 1
                ? `<span class="a-badge a-badge-success">${i18n('已交付')}</span>`
                : `<span class="a-badge a-badge-warning">${i18n('待恢复')}</span>`},
            {field: 'create_time', title: '创建时间', formatter: value => escapeHtml(value || '-')},
            {field: 'operation', title: '操作', type: 'button', buttons: [
                {icon: 'fa-duotone fa-regular fa-eye', class: 'text-primary', title: '查看账号', show: row => Number(row.status) === 1, click: (event, value, row) => showDolaDelivery(row)}
            ]}
        ]);
        deliveryTable.setSearch([
            {title: '请求号', name: 'search-request_no', type: 'input'},
            {title: 'Dola 货源', name: 'equal-shared_id', type: 'select', dict: 'shared->type=3,id,name', search: true},
            {title: '状态', name: 'equal-status', type: 'select', dict: [{id: 0, name: '待恢复'}, {id: 1, name: '已交付'}]},
            {title: '开始时间', name: 'betweenStart-create_time', type: 'date'},
            {title: '结束时间', name: 'betweenEnd-create_time', type: 'date'}
        ]);
        deliveryTable.render();
    };

    const selected = () => {
        const ids = table.getSelectionIds();
        if (!ids.length) message.error(i18n('请至少选择一个兑换码'));
        return ids;
    };
    const batch = (url, prompt, success) => {
        const ids = selected();
        if (!ids.length) return;
        message.ask(prompt.replace('{count}', ids.length), () => util.post(url, {list: ids}, response => {
            message.success(`${success} ${Number(response?.data?.count || 0)} ${i18n('个')}`);
            table.refresh();
        }));
    };

    table = new Table('/admin/api/redeem/data', '#redeem-table');
    table.setFloatMessage([
        {field: 'create_time', title: '创建时间', formatter: value => escapeHtml(value || '-')},
        {field: 'used_time', title: '使用时间', formatter: value => escapeHtml(value || '-')},
        {field: 'batch_no', title: '批次', formatter: value => escapeHtml(value || '-')}
    ]);
    table.setColumns([
        {checkbox: true},
        {field: 'code_mask', title: '兑换码掩码', formatter: value => `<code>${escapeHtml(value || '-')}</code>`},
        {field: 'commodity', title: '商品', formatter: value => value ? escapeHtml(value.name) : '<span class="text-danger">' + i18n('商品已删除') + '</span>'},
        {field: 'quantity', title: '提货额度', formatter: (value, row) => {
            const total = Number(value || 0), used = Number(row?.used_quantity || 0);
            return `<span class="a-badge a-badge-primary">${used} / ${total}</span>`;
        }},
        {field: 'note', title: '备注', formatter: value => escapeHtml(value || '-')},
        {field: 'batch_no', title: '批次', formatter: value => `<code>${escapeHtml(value || '-')}</code>`},
        {field: 'status', title: '状态', formatter: (value, row) => {
            if (Number(value) === 2) return '<span class="a-badge a-badge-dark">' + i18n('已锁定') + '</span>';
            if (Number(value) === 1) return '<span class="a-badge a-badge-primary">' + i18n('已用完') + '</span>';
            return Number(row?.used_quantity || 0) > 0
                ? '<span class="a-badge a-badge-warning">' + i18n('部分提取') + '</span>'
                : '<span class="a-badge a-badge-success">' + i18n('未使用') + '</span>';
        }},
        {field: 'order', title: '兑换订单', formatter: (value, row) => {
            const tradeNo = value?.trade_no || row?.result_trade_no || '';
            return tradeNo ? `<code>${escapeHtml(tradeNo)}</code>` : '-';
        }},
        {field: 'create_time', title: '创建时间', formatter: value => escapeHtml(value || '-')},
        {field: 'used_time', title: '使用时间', formatter: value => escapeHtml(value || '-')},
        {field: 'operation', title: '操作', type: 'button', buttons: [
            {icon: 'fa-duotone fa-regular fa-lock', class: 'text-dark', show: row => Number(row.status) === 0, click: (event, value, row) => util.post('/admin/api/redeem/lock', {list: [row.id]}, () => table.refresh())},
            {icon: 'fa-duotone fa-regular fa-lock-open', class: 'text-success', show: row => Number(row.status) === 2, click: (event, value, row) => util.post('/admin/api/redeem/unlock', {list: [row.id]}, () => table.refresh())},
            {icon: 'fa-duotone fa-regular fa-trash-can', class: 'text-danger', show: row => Number(row.status) !== 1 && Number(row.used_quantity || 0) === 0, click: (event, value, row) => message.ask(i18n('确认删除该无提货记录的兑换码吗？'), () => util.post('/admin/api/redeem/del', {list: [row.id]}, () => table.refresh()))}
        ]}
    ]);
    table.setSearch([
        {title: '兑换码掩码', name: 'search-code_mask', type: 'input'},
        {title: '备注', name: 'search-note', type: 'input'},
        {title: '批次', name: 'equal-batch_no', type: 'input'},
        {title: '商品', name: 'equal-commodity_id', type: 'select', dict: 'commodity,id,name', search: true},
        {title: '状态', name: 'equal-status', type: 'select', dict: [{id: 0, name: '可提取'}, {id: 1, name: '已用完'}, {id: 2, name: '已锁定'}]}
    ]);
    table.render();

    $('.btn-redeem-generate').off(namespace).on('click' + namespace, generate);
    $('.btn-redeem-import').off(namespace).on('click' + namespace, importCodes);
    $('.btn-redeem-lock').off(namespace).on('click' + namespace, () => batch('/admin/api/redeem/lock', i18n('确认锁定选中的 {count} 个兑换码吗？'), i18n('已锁定')));
    $('.btn-redeem-unlock').off(namespace).on('click' + namespace, () => batch('/admin/api/redeem/unlock', i18n('确认解锁选中的 {count} 个兑换码吗？'), i18n('已解锁')));
    $('.btn-redeem-delete').off(namespace).on('click' + namespace, () => batch('/admin/api/redeem/del', i18n('确认永久删除选中的 {count} 个未使用兑换码吗？'), i18n('已删除')));
    $('.btn-dola-source-add').off(namespace).on('click' + namespace, addDolaSources);
    $('.btn-dola-source-refresh').off(namespace).on('click' + namespace, () => sourceTable ? sourceTable.refresh() : initDolaSources());
    $('.btn-dola-delivery-refresh').off(namespace).on('click' + namespace, () => deliveryTable ? deliveryTable.refresh() : initDolaDeliveries());
    $('[data-bs-target="#redeem-dola-source-pane"]').off('shown.bs.tab' + namespace).on('shown.bs.tab' + namespace, initDolaSources);
    $('[data-bs-target="#redeem-dola-delivery-pane"]').off('shown.bs.tab' + namespace).on('shown.bs.tab' + namespace, initDolaDeliveries);

    const destroy = () => {
        if (!active) return;
        active = false;
        $('.btn-redeem-generate,.btn-redeem-import,.btn-redeem-lock,.btn-redeem-unlock,.btn-redeem-delete,.btn-dola-source-add,.btn-dola-source-refresh,.btn-dola-delivery-refresh').off(namespace);
        $('[data-bs-target="#redeem-dola-source-pane"],[data-bs-target="#redeem-dola-delivery-pane"]').off(namespace);
        layers.forEach(index => layer.close(index));
        layers.clear();
        if (table && typeof table.destroy === 'function') table.destroy();
        if (sourceTable && typeof sourceTable.destroy === 'function') sourceTable.destroy();
        if (deliveryTable && typeof deliveryTable.destroy === 'function') deliveryTable.destroy();
        table = sourceTable = deliveryTable = null;
        $(document).off('pjax:beforeReplace' + namespace);
        if (window.__redeemAdminDestroy === destroy) delete window.__redeemAdminDestroy;
    };
    window.__redeemAdminDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
