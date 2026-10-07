!function () {
    const $SwitchCategory = $(`.switch-category`), $ItemList = $(`.item-list`), categoryId = getVar("CAT_ID");
    let stockGeneration = 0;

    //复用详情页的权威库存接口；异步查询，避免上游慢响应拖住整个商品列表。
    //限制并发，切换分类/搜索后丢弃旧响应，也停止旧列表尚未发出的请求。
    function _RefreshCommodityStocks(data, generation) {
        let next = 0, active = 0;
        const pump = () => {
            while (generation === stockGeneration && active < 4 && next < data.length) {
                const item = data[next++];
                const $card = $ItemList.find(`a[data-id="${item.id}"]`);
                active++;
                let settled = false;
                const finish = (stock, state, failed = false) => {
                    if (settled) return;
                    settled = true;
                    if (generation === stockGeneration) {
                        $card.find('.item-stock-value').text(failed ? i18n('库存查询失败') : stock);
                        const soldOut = !failed && Number(state) === 0;
                        $card.attr('href', soldOut ? 'javascript:void(0);' : `/item/${item.id}`);
                        $card.find('.acg-card').toggleClass('soldout', soldOut);
                        $card.find('.soldout-ribbon').remove();
                        if (soldOut) $card.find('.acg-card').append(`<div class="soldout-ribbon">${i18n('售罄')}</div>`);
                    }
                    active--;
                    pump();
                };
                try {
                    util.post({
                        url: '/user/api/index/stock',
                        data: {item_id: item.id},
                        loader: false,
                        done: res => {
                            const stock = res?.data?.stock, state = Number(res?.data?.stock_state);
                            if (stock == null || !['string', 'number'].includes(typeof stock)
                                || res?.data?.stock_state == null || ![0, 1, 2, 3, 4].includes(state)) {
                                finish(null, null, true);
                                return;
                            }
                            finish(stock, state);
                        },
                        error: () => finish(null, null, true),
                        fail: () => finish(null, null, true)
                    });
                } catch (error) {
                    finish(null, null, true);
                }
            }
        };
        pump();
    }


    function _PushCommodityList(data) {
        const generation = ++stockGeneration;
        $ItemList.html("");

        if (data.length == 0) {
            layer.msg(i18n("没有商品"));
            $ItemList.html(`<div style="margin-right: 10px;margin-top:10px;font-size: 1.1rem;">${i18n('没有商品')}</div>`);
            return;
        }

        data.forEach(item => {
            const stockKnown = item.stock != null;
            const isSoldOut = stockKnown && Number(item.stock_state) === 0;
            const stockLabel = stockKnown ? item.stock : i18n('查询中…');
            $ItemList.append(`<a href="${!isSoldOut ? `/item/${item.id}` : `javascript:void(0);`}" class="col-12 col-md-6 col-lg-3 mb-3" data-id="${item.id}">
          <div class="acg-card ${isSoldOut ? `soldout` : ``} h-100">
            <div class="acg-thumb" style="background: url('${item.cover}') center/cover no-repeat;"></div>
            <div class="p-3">
              <div class="tags">
              ${_CommodityTags(item)}
              <span class="badge-soft badge-soft-success">${item.delivery_way === 0 ? i18n('自动发货') : i18n('在线发货')}</span>
              ${item.recommend == 1 ? `<span class="badge-soft badge-soft-primary">${i18n('推荐')}</span>` : ``}
              </div>
              <p class="goods-title">${i18n(item.name)}</p>
              <div class="stat-row mb-1">
                <div class="price"><span class="unit">${format.currencySymbol()}</span>${item.price}</div>
              </div>
              <div class="stat-bottom"><span>${i18n('库存：')}<span class="item-stock-value">${stockLabel}</span></span><span>${i18n('已售：')}${item.order_sold}</span></div>
            </div>
            ${isSoldOut ? `<div class="soldout-ribbon">${i18n('售罄')}</div>` : ``}
          </div>
        </a>`);
        });
        _RefreshCommodityStocks(data, generation);
    }

    //商品标签（#807）：后台配置的彩色标签，排在系统徽章之前
    function _CommodityTags(item) {
        const tags = Array.isArray(item && item.tags) ? item.tags : [];
        if (!tags.length) return '';
        const esc = v => $('<i>').text(String(v == null ? '' : v)).html();
        return tags.map(t => {
            const text = String((t && t.text) || '').trim();
            if (!text) return '';
            const color = String((t && t.color) || 'red');
            return `<span class="acg-tag acg-tag--${esc(color)}">${esc(text)}</span>`;
        }).join('');
    }

    function _SwitchCategory(id, link = false) {
        $SwitchCategory.removeClass("is-primary");
        $(`a[data-id=${id}]`).addClass("is-primary");
        if (link) {
            history.pushState(null, '', `/cat/${id}`);
        }
        trade.getCommodityList({
            categoryId: id,
            done: data => {
                _PushCommodityList(data);
            }
        });
    }


    function _Search(keywords) {
        if (keywords == '') {
            layer.msg(i18n("请输入要搜索的商品名称关键词"));
            return;
        }

        $SwitchCategory.removeClass("is-primary");

        trade.getCommodityList({
            keywords: keywords,
            done: data => {
                _PushCommodityList(data);
            }
        });
    }


    //初次加载
    _SwitchCategory(categoryId > 0 ? categoryId : $SwitchCategory.first().data("id"));


    $SwitchCategory.click(function () {
        if ($(this).hasClass("is-primary")) {
            return;
        }
        _SwitchCategory($(this).data("id"), true);
    });


    $('.item-search-input').on('keypress', function (e) {
        if (e.which === 13) { // 或者 e.key === "Enter"
            _Search($(this).val());
        }
    });
}();
