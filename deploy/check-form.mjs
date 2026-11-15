import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Запускаем настоящий sendForm с заменой только DOM и сетевого транспорта.
const source = readFileSync(new URL('../src/js/modules/send-form.js', import.meta.url), 'utf8')
    .replace(/^import .*;\s*/m, '')
    .replace('export function sendForm', 'function sendForm');

for (const scenario of [
    { status: 200, response: { result: 'success' }, success: true },
    { status: 200, response: { result: 'error' }, success: false },
    { status: 200, response: null, success: false },
    { status: 502, response: { result: 'error' }, success: false },
    { event: 'onerror', success: false },
    { event: 'ontimeout', success: false },
]) {
    const shown = [];
    let reset = false;
    let xhr;
    const context = vm.createContext({
        document: {
            querySelector(selector) {
                return selector === '.loader' ? { classList: { add() {}, remove() {} } } : selector;
            },
        },
        Modal: class {
            constructor(element) { this.element = element; }
            show() { shown.push(this.element); }
        },
        FormData: class {},
        XMLHttpRequest: class {
            constructor() { xhr = this; }
            open() {}
            setRequestHeader() {}
            send() {}
        },
        setTimeout(callback) { callback(); },
    });
    vm.runInContext(source, context);
    context.sendForm({ method: 'POST', action: '/mail.php', reset() { reset = true; }, closest() { return null; } });
    Object.assign(xhr, scenario);
    xhr[scenario.event || 'onload']();
    assert.deepEqual(shown, [scenario.success ? '.success-modal' : '.error-modal']);
    assert.equal(reset, scenario.success);
}
console.log('Форма: проверены успех, ошибка в JSON, неверный JSON, HTTP 502, ошибка сети и таймаут.');
