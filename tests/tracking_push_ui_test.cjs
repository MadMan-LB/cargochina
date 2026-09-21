const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
let checks = 0;
function harness(file, result, error) {
    const toasts = [];
    let reloads = 0;
    const list = { innerHTML: '' };
    const ctx = vm.createContext({
        document: {
            addEventListener() {},
            getElementById() { return list; },
            createElement() { return { textContent: '', get innerHTML() { return this.textContent; } }; },
        },
        bootstrap: { Modal: { getInstance: () => ({ hide() {} }) } },
        setLoading() {},
        showToast: (...args) => toasts.push(args),
        api: async () => { if (error) throw new Error(error); return { data: result }; },
        escapeHtml: String,
        console,
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../frontend/js', file), 'utf8'), ctx);
    return { ctx, toasts, list, replaceReload(name) { ctx[name] = () => { reloads++; }; }, get reloads() { return reloads; } };
}
async function check(file, action, data, expectedType, expectedText, error) {
    const h = harness(file, data, error);
    h.replaceReload(file === 'consolidation.js' ? 'loadShipmentDrafts' : 'loadPushLog');
    h.ctx.loadReadyTotals = () => {};
    await vm.runInContext(`${action}()`, h.ctx);
    assert.equal(h.toasts[0][1], expectedType);
    assert.match(h.toasts[0][0], expectedText);
    assert.equal(h.reloads, 1);
    checks++;
}
(async () => {
    const modeHarness=harness('consolidation.js',{});
    for(const [mode,label] of [['disabled','Finalize (tracking disabled)'],['dry_run','Finalize (tracking dry-run)'],['live','Finalize & Push to Tracking'],['unknown','Finalize']]){
        const presentation=vm.runInContext('trackingFinalizationPresentation('+JSON.stringify(mode)+')',modeHarness.ctx);
        assert.equal(presentation.label,label);if(mode==='disabled'||mode==='dry_run')assert.match(presentation.hint,/no external request will be sent/i);checks++;
    }
    for (const file of ['consolidation.js', 'admin_tracking_push.js']) {
        await check(file, 'retryPush', { success: true, message: 'Pushed to tracking' }, 'success', /Pushed/);
        await check(file, 'retryPush', { success: false, message: 'Tracking push disabled' }, 'warning', /disabled/);
        await check(file, 'retryPush', null, 'danger', /Tracking API error 503/, 'Tracking API error 503');
    }
    await check('consolidation.js', 'finalizeDraft', { tracking_result: { success: true, message: 'Pushed to tracking' } }, 'success', /finalized.*Pushed/);
    await check('consolidation.js', 'finalizeDraft', { tracking_result: { success: false, message: 'Push disabled' } }, 'warning', /finalized.*disabled/);
    await check('consolidation.js', 'finalizeDraft', { tracking_result: { success: false, message: 'API failed' } }, 'warning', /finalized.*failed/);
    await check('consolidation.js', 'finalizeDraft', { tracking_result: null }, 'warning', /Not pushed/);
    for (const [status, label, retry] of [['success','Pushed',false], ['failed','Failed',true], ['disabled','Not pushed',true], ['dry_run','Dry-run',true], ['pending','Not pushed',true], [null,'Not pushed',true]]) {
        const h = harness('consolidation.js', [{ id: 66, status: 'finalized', push_status: status, order_ids: [727] }]);
        await vm.runInContext('loadShipmentDrafts()', h.ctx);
        assert.ok(h.list.innerHTML.includes(`>${label}</span>`));
        assert.equal(h.list.innerHTML.includes('Retry Push'), retry);
        checks++;
    }
    console.log(`PASS: ${checks} frontend toast, refresh, tracking badge and retry checks`);
})().catch(e => { console.error(e); process.exitCode = 1; });
