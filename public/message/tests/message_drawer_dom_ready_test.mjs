// Run with: node --test public/message/tests/message_drawer_dom_ready_test.mjs
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const template = readFileSync(new URL('../templates/message_drawer.mustache', import.meta.url), 'utf8');
const script = template.match(/\{\{#js\}\}([\s\S]*?)\{\{\/js\}\}/)?.[1].replaceAll('{{uniqid}}', 'synthetic-drawer');
assert.ok(script, 'Exercise the actual native template bootstrap, not a copied implementation');

function harness({ready = false, present = false} = {}) {
    const pending = [];
    const calls = [];
    const root = {length: 1, 0: {querySelectorAll: () => []}};
    const jquery = target => {
        if (typeof target === 'function') {
            if (ready) target(); else pending.push(target);
            return;
        }
        assert.equal(target, '#message-drawer-synthetic-drawer');
        return present ? root : {length: 0};
    };
    const drawer = {init: (...args) => {
        assert.equal(args[0].length, 1, 'The real native drawer needs its parsed root before init');
        calls.push(args);
    }};
    const context = {require: (dependencies, callback) => {
        assert.deepEqual(Array.from(dependencies), ['jquery', 'core_message/message_drawer']);
        callback(jquery, drawer);
    }};
    return {
        run() { vm.runInNewContext(script, context, {timeout: 1000}); },
        loaded(hasRoot = true) { ready = true; present = hasRoot; pending.splice(0).forEach(callback => callback()); },
        calls, root,
    };
}

test('warm AMD dependency before DOM parsing defers root lookup and init until ready', () => {
    const browser = harness();
    browser.run();
    assert.equal(browser.calls.length, 0);
    browser.loaded();
    assert.equal(browser.calls.length, 1);
    assert.equal(browser.calls[0][0], browser.root);
    assert.deepEqual(browser.calls[0].slice(1), ['synthetic-drawer', false]);
});

test('late AMD dependency after DOM readiness initializes the existing drawer once', () => {
    const browser = harness({ready: true, present: true});
    browser.run();
    assert.equal(browser.calls.length, 1);
    browser.loaded();
    assert.equal(browser.calls.length, 1);
});

test('DOM-ready scheduling does not suppress a genuinely missing drawer root', () => {
    const browser = harness();
    browser.run();
    assert.throws(() => browser.loaded(false), /parsed root/);
    assert.equal(browser.calls.length, 0);
});
