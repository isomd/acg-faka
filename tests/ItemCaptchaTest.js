'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/user/controller/index/item.js'), 'utf8');
let nonce = 0;

function page(initialId, enabled = true) {
    const requests = [], handlers = {};
    const fields = {captcha_id: initialId, captcha: '1234', num: '1'};
    const generic = {
        val() {return this;}, click() {}, on() {}, change() {}, remove() {}, after() {},
        data() {return 3;}, append() {}, html() {return this;},
        addClass() {return this;}, removeClass() {return this;}, fadeIn() {}, fadeOut() {}
    };
    const image = {length: enabled ? 1 : 0, src: 'initial',
        attr(key, value) {assert.equal(key, 'src'); this.src = value;},
        click(callback) {handlers.refresh = callback;}};
    const form = {
        serializeArray() {return Object.entries(fields).map(([name, value]) => ({name, value}));},
        find(selector) {
            const name = selector.match(/name=([^\]]+)/)[1];
            return {val(value) {if (value === undefined) return fields[name]; fields[name] = value;}};
        }
    };
    const doc = {on(event, selector, callback) {handlers.pay = callback;}};
    const document = {};
    const window = {
        crypto: {getRandomValues(bytes) {bytes.fill(++nonce);}},
        addEventListener(event, callback) {handlers[event] = callback;}, location: {}
    };
    vm.runInNewContext(source, {
        $, document, window, Uint8Array,
        getVar: () => ({id: 2, config: {}, minimum: 1, maximum: 10000}),
        i18n: v => v, message: {error() {}}, treasure: {show() {}},
        util: {
            isEmptyOrNotJson: () => true,
            arrayToObject: values => Object.fromEntries(values.map(({name, value}) => [name, value])),
            post(...args) {requests.push(args);}
        }
    });
    function $(selector) {
        if (selector === document) return doc;
        if (selector === '.vstack') return form;
        if (selector === '.captcha-img') return image;
        return generic;
    }
    return {fields, image, handlers, requests, pay() {
        handlers.pay.call(generic);
        return requests.at(-1);
    }};
}

const a = page('a'.repeat(32)), b = page('b'.repeat(32));
assert.equal(a.pay()[1].captcha_id, 'a'.repeat(32), 'initial server-rendered ID is submitted');
assert.equal(b.pay()[1].captcha_id, 'b'.repeat(32), 'second tab uses its own ID');
a.handlers.refresh();
assert.match(a.fields.captcha_id, /^[a-f0-9]{32}$/);
assert.equal(a.fields.captcha, '', 'refresh clears the obsolete input');
assert.equal(new URL(a.image.src, 'https://shop.test').searchParams.get('captcha_id'), a.fields.captcha_id);
assert.equal(new URL(a.image.src, 'https://shop.test').searchParams.get('previous'), 'a'.repeat(32));
assert.equal(b.fields.captcha_id, 'b'.repeat(32), 'refresh does not change other tabs');
assert.equal(b.fields.captcha, '1234');
const previous = a.fields.captcha_id;
a.fields.captcha = '4321';
const request = a.pay();
assert.equal(request[1].captcha_id, previous, 'submission serializes the current image ID');
request[3]({msg: 'test-only rejection'});
assert.notEqual(a.fields.captcha_id, previous, 'failed trade replaces consumed challenge');
assert.equal(a.fields.captcha, '');
const beforeSuccess = a.fields.captcha_id;
a.pay()[2]({data: {tradeNo: 'test', url: null}});
assert.notEqual(a.fields.captcha_id, beforeSuccess, 'successful non-redirect trade also replaces challenge');
const beforeRestore = b.fields.captcha_id;
b.handlers.pageshow({persisted: false});
assert.equal(b.fields.captcha_id, beforeRestore, 'ordinary pageshow keeps initial image');
b.handlers.pageshow({persisted: true});
assert.notEqual(b.fields.captcha_id, beforeRestore, 'back-forward restoration refreshes stale image');
const disabled = page('', false);
disabled.handlers.refresh();
assert.equal(disabled.image.src, 'initial', 'captcha-disabled shop makes no refresh request');

const template = fs.readFileSync(path.join(__dirname, '../app/View/User/Theme/Cartoon/Index/Item.html'), 'utf8');
assert.ok(template.includes('name="captcha_id" value="#{$item.captcha_id}"'));
assert.ok(template.includes('action=trade&amp;captcha_id=#{$item.captcha_id}'));
assert.ok(template.includes('item.js?rev=isolated-captcha-v1'), 'cached old controller is bypassed');
console.log('Item CAPTCHA binding and refresh tests passed');
