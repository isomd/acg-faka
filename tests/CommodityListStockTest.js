'use strict';

// Execute the actual homepage controller with a tiny DOM/network test double.
// No browser, database, credentials or upstream requests are needed.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/user/controller/index/index.js'), 'utf8');

function product(id = 2, stock = null, stockState = 0) {
    return {id, stock, stock_state: stockState, name: 'Dola', price: 0.95,
        cover: '/cover.png', delivery_way: 0, order_sold: 0, tags: []};
}

function homepage(products) {
    const cards = new Map(), requests = [];
    let listCallback, pending = 0, maxPending = 0;
    const empty = {removeClass() {return this;}, addClass() {return this;},
        first() {return this;}, data() {return 2;}, click() {}, on() {}};
    const list = {
        html() {cards.clear();},
        append(html) {
            const id = Number(html.match(/data-id="(\d+)"/)[1]);
            const card = {
                href: html.match(/href="([^"]+)"/)[1],
                label: html.match(/class="item-stock-value">([^<]*)/)[1],
                soldOut: /acg-card soldout/.test(html), ribbon: html.includes('soldout-ribbon'),
                attr(key, value) {assert.equal(key, 'href'); this.href = value;},
                find(selector) {
                    if (selector === '.item-stock-value') return {text(value) {card.label = String(value);}};
                    if (selector === '.soldout-ribbon') return {remove() {card.ribbon = false;}};
                    assert.equal(selector, '.acg-card');
                    return {toggleClass(name, value) {assert.equal(name, 'soldout'); card.soldOut = value;},
                        append() {card.ribbon = true;}};
                }
            };
            cards.set(id, card);
        },
        find(selector) {return cards.get(Number(selector.match(/data-id="(\d+)"/)[1]));}
    };
    vm.runInNewContext(source, {
        $: selector => selector === '.item-list' ? list : empty,
        getVar: () => 0, i18n: value => value,
        layer: {msg() {}}, history: {pushState() {}},
        format: {currencySymbol: () => '¥'},
        trade: {getCommodityList(options) {listCallback = options.done; options.done(products);}},
        util: {post(options) {
            assert.equal(options.url, '/user/api/index/stock');
            assert.equal(options.loader, false);
            assert.deepEqual(Object.keys(options.data), ['item_id']);
            pending++;
            maxPending = Math.max(maxPending, pending);
            let resolved = false;
            requests.push({id: options.data.item_id, resolve(type, payload) {
                assert.equal(resolved, false);
                resolved = true; pending--;
                options[type](payload);
            }});
        }}
    });
    return {cards, requests, render(data) {listCallback(data);},
        get maxPending() {return maxPending;}, get pending() {return pending;}};
}

let h = homepage([product()]);
assert.equal(h.cards.get(2).label, '查询中…', 'null never appears on the card');
assert.equal(h.cards.get(2).soldOut, false, 'unknown stock is not sold out');
h.requests[0].resolve('done', {data: {stock: '66', stock_state: 3}});
assert.equal(h.cards.get(2).label, '66');
assert.equal(h.cards.get(2).href, '/item/2');

h = homepage([product(2, 20, 2)]);
h.requests[0].resolve('done', {data: {stock: '0', stock_state: 0}});
assert.equal(h.cards.get(2).label, '0');
assert.equal(h.cards.get(2).soldOut, true);
assert.equal(h.cards.get(2).ribbon, true);
assert.equal(h.cards.get(2).href, 'javascript:void(0);');

h = homepage([product(2, 0, 0)]);
h.requests[0].resolve('done', {data: {stock: '充足', stock_state: 3}});
assert.equal(h.cards.get(2).label, '充足', 'hidden inventory remains hidden');
assert.equal(h.cards.get(2).soldOut, false, 'replenished stock restores the link');
assert.equal(h.cards.get(2).ribbon, false);
assert.equal(h.cards.get(2).href, '/item/2');

for (const type of ['error', 'fail']) {
    h = homepage([product()]);
    h.requests[0].resolve(type, {msg: 'upstream unavailable'});
    assert.equal(h.cards.get(2).label, '库存查询失败');
    assert.equal(h.cards.get(2).soldOut, false, 'failure is not a zero-stock claim');
}
for (const data of [{stock: null, stock_state: 0}, {stock: '66'},
    {stock: '66', stock_state: null}, {stock: '66', stock_state: -1},
    {stock: {}, stock_state: 3}]) {
    h = homepage([product()]);
    h.requests[0].resolve('done', {data});
    assert.equal(h.cards.get(2).label, '库存查询失败', 'malformed stock fails explicitly');
}

h = homepage(Array.from({length: 7}, (_, i) => product(i + 1)));
assert.equal(h.requests.length, 4, 'at most four initial queries');
for (let i = 0; i < 7; i++) h.requests[i].resolve(i === 0 ? 'fail' : 'done', {data: {stock: '1', stock_state: 1}});
assert.equal(h.requests.length, 7, 'failure does not stall the queue');
assert.equal(h.maxPending, 4);
assert.equal(h.pending, 0);

h = homepage([product(2), product(3), product(4), product(5), product(6)]);
const oldCard = h.cards.get(2);
h.render([product(2)]);
h.requests[0].resolve('done', {data: {stock: '999', stock_state: 4}});
assert.equal(h.cards.get(2).label, '查询中…', 'late category response cannot overwrite the new card');
assert.equal(oldCard.label, '查询中…');
assert.equal(h.requests.length, 5, 'old category queue stops issuing requests');
h.requests[4].resolve('done', {data: {stock: '66', stock_state: 3}});
assert.equal(h.cards.get(2).label, '66');
h.render([]);
assert.equal(h.cards.size, 0);

console.log('Homepage live stock tests passed');
