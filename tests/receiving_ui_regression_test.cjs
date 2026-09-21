const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function harness(file) {
    const elements = new Map();
    const calls = [];
    const toasts = [];
    const element = id => {
        if (!elements.has(id)) {
            const classes = new Set();
            elements.set(id, {
                value: '', dataset: {}, textContent: '', innerHTML: '', disabled: false,
                classList: {
                    add: c => classes.add(c), remove: c => classes.delete(c),
                    contains: c => classes.has(c),
                    toggle(c, force) { if (force) classes.add(c); else classes.delete(c); },
                },
                addEventListener() {}, querySelector() { return null; },
                querySelectorAll() { return []; },
            });
        }
        return elements.get(id);
    };
    const ctx = vm.createContext({
        document: { addEventListener() {}, getElementById: element,
            querySelectorAll: () => [], createElement: () => ({ textContent: '', get innerHTML() { return this.textContent; } }) },
        URLSearchParams, console, setLoading() {}, escapeHtml:value=>String(value??''),
        showToast: (...args) => toasts.push(args),
        api: async (...args) => { calls.push(args); return { data: [] }; },
    });
    vm.runInContext('window = globalThis;', ctx);
    if (['assign_container.js','containers.js','consolidation.js'].includes(file)) {
        const appSource=fs.readFileSync(path.join(__dirname,'../frontend/js/app.js'),'utf8');
        vm.runInContext(appSource.match(/^async function loadShipmentEligibleOrders\(\) \{[\s\S]*?^\}/m)[0],ctx);
        vm.runInContext(appSource.match(/^async function loadAssignmentContainers\(\) \{[\s\S]*?^\}/m)[0],ctx);
        vm.runInContext(appSource.match(/^async function loadOpenShipmentDrafts\(\) \{[\s\S]*?^\}/m)[0],ctx);
    }
    let source=fs.readFileSync(path.join(__dirname, '../frontend/js', file), 'utf8');
    if(file==='receiving_receive.js') source=source.replace(/^resetReceiptFees\(\);$/m,'').replace(/^loadOrder\(\)\.catch\([\s\S]*$/m,'');
    vm.runInContext(source, ctx);
    return { ctx, element, calls, toasts, run: code => vm.runInContext(code, ctx) };
}

(async () => {
    const h = harness('receiving.js');
    const rendered = {};
    for (const name of ['renderWarehouseList', 'renderCalendar', 'renderSchedule', 'updateReceivingOverview']) {
        h.ctx[name] = () => { rendered[name] = h.run('warehouseQueueData.map(o => o.id).join(",")'); };
    }
    h.ctx.renderReceiveDropdown = () => {};
    h.ctx.syncReceivingUrl = () => {};
    h.ctx.getFilterParams = () => 'status=Approved,InTransitToWarehouse';
    h.ctx.getReceivingFocusFilters = () => ({ priorityOnly: true, alertsOnly: false });
    h.ctx.orderHasPriority = o => o.priority;
    let responses = [{ data: [{ id: 739, priority: true }, { id: 740, priority: false }], meta: { total: 2 } }];
    h.ctx.api = async (...args) => { h.calls.push(args); return responses.shift(); };
    await h.run('loadReceivableOrders()');
    assert.equal(h.run('warehouseQueueSourceData.length'), 2);
    for (const value of Object.values(rendered)) assert.equal(value, '739');
    responses = [{ data: [{ id: 740, priority: false }], meta: { total: 1 } }];
    await h.run('loadReceivableOrders()');
    for (const value of Object.values(rendered)) assert.equal(value, '');
    assert.equal(h.run('receivingQueueTotal'), 1);
    assert.equal(h.element('warehouseListEmpty').classList.contains('d-none'), false);

    // Receiving the final row on the last page returns to the preceding page.
    h.run('receivingQueueOffset = 50');
    responses = [{ data: [], meta: { total: 50 } }, { data: [{ id: 741, priority: true }], meta: { total: 50 } }];
    await h.run('loadReceivableOrders()');
    assert.equal(h.run('receivingQueueOffset'), 0);
    assert.match(h.calls.at(-2)[1], /offset=50/);
    assert.match(h.calls.at(-1)[1], /offset=0/);
    assert.equal(rendered.renderWarehouseList, '741');
    assert.equal(h.element('applyFiltersBtn').disabled, false);
    h.run('receivingQueueOffset = 50');
    responses = [{ data: [], meta: { total: 0 } }, { data: [], meta: { total: 0 } }];
    await h.run('loadReceivableOrders()');
    assert.equal(h.element('receivingPageSummary').textContent, '0 results');

    const pending = [];
    h.ctx.api = () => new Promise(resolve => pending.push(resolve));
    const earlier = h.run('applyFilters()');
    const latest = h.run('applyFilters()');
    pending[1]({ data: [{ id: 742, priority: true }], meta: { total: 1 } });
    await latest;
    pending[0]({ data: [{ id: 739, priority: true }], meta: { total: 1 } });
    await earlier;
    assert.equal(rendered.renderWarehouseList, '742');
    h.ctx.api = async () => { throw new Error('Queue unavailable'); };
    await h.run('applyFilters()');
    assert.equal(h.element('receivingPageSummary').textContent, '0 results');
    assert.equal(h.element('receivingPrevBtn').disabled, true);
    assert.equal(h.element('receivingNextBtn').disabled, true);

    h.element('receiveOrderId').value = '739';
    h.element('receiveOrderSearch').dataset.declaredCbm = '1';
    h.element('actualCbm').value = '0.5';
    h.element('condition').value = 'partial';
    h.run('updateVariancePhotoAlert()');
    assert.equal(h.element('variancePhotoAlert').classList.contains('d-none'), true);
    h.element('condition').value = 'damaged';
    h.run('updateVariancePhotoAlert()');
    assert.equal(h.element('variancePhotoAlert').classList.contains('d-none'), false);
    h.element('receiveOrderSearch').dataset.receivedCbm = '0.3';
    h.element('condition').value = 'good';
    h.element('actualCbm').value = '0.7';
    h.run('updateVariancePhotoAlert()');
    assert.equal(h.element('variancePhotoAlert').classList.contains('d-none'), true);
    delete h.element('receiveOrderSearch').dataset.receivedCbm;
    h.ctx.document.querySelectorAll = () => [{ value: 'partial' }];
    h.element('actualCbm').value = '0.5';
    h.run('updateVariancePhotoAlert()');
    assert.equal(h.element('variancePhotoAlert').classList.contains('d-none'), true);
    h.ctx.document.querySelectorAll = () => [{ value: 'damaged' }];
    h.run('updateVariancePhotoAlert()');
    assert.equal(h.element('variancePhotoAlert').classList.contains('d-none'), false);
    h.ctx.document.querySelectorAll = () => [];
    h.ctx.api = async () => ({ data: { item_level_receiving_enabled: 1, variance_threshold_percent: 25, variance_threshold_abs_cbm: 0.5 } });
    h.element('condition').value = 'good';
    h.element('actualCbm').value = '0.85';
    await h.run('loadReceivingConfig()');
    assert.equal(h.element('variancePhotoAlert').classList.contains('d-none'), true);
    h.element('actualCbm').value = '0.7';
    h.run('updateVariancePhotoAlert()');
    assert.equal(h.element('variancePhotoAlert').classList.contains('d-none'), false);

    // Server evidence rejection must remain an error, with the form retained.
    h.ctx.canRecordReceiving = () => true;
    h.ctx.collectReceiptFees = () => ({ fees: [] });
    h.ctx.api = async (...args) => { h.calls.push(args); throw new Error('Evidence photos required'); };
    await h.run('submitReceive()');
    assert.equal(h.calls.at(-1)[0], 'POST');
    assert.equal(h.toasts.at(-1)[1], 'danger');
    assert.match(h.toasts.at(-1)[0], /Evidence photos required/);
    assert.equal(h.element('receiveForm').classList.contains('d-none'), false);
    assert.equal(h.element('receiveOrderId').value, '739');

    h.ctx.resetReceiptFees = () => {};
    h.ctx.renderReceivePhotoPreview = () => {};
    h.ctx.refreshUnsavedBaseline = () => {};
    h.ctx.api = async (...args) => {
        h.calls.push(args);
        return args[0] === 'POST' ? { data: { variance_detected: false } } : { data: [], meta: { total: 0 } };
    };
    h.element('condition').value = 'partial';
    await h.run('submitReceive()');
    assert.equal(h.calls.at(-2)[0], 'POST');
    assert.equal(h.calls.at(-2)[2].condition, 'partial');
    assert.equal(h.calls.at(-1)[0], 'GET');
    assert.equal(h.element('receiveForm').classList.contains('d-none'), true);
    assert.equal(h.element('receiveOrderId').value, '');
    for (const value of Object.values(rendered)) assert.equal(value, '');

    h.ctx.canImportReceiving = () => true;
    for (const name of ['setReceivingImportBusy', 'startReceivingImportProgress', 'setReceivingImportProgress',
        'renderReceivingImportPreview', 'clearReceivingImportProgressTimers', 'setReceivingImportStatus']) h.ctx[name] = () => {};
    h.run('receivingImportPreviewToken = "valid-preview"');
    const importCallsBefore = h.calls.length;
    await h.run('commitReceivingImport()');
    assert.equal(h.calls.length - importCallsBefore, 2); // One commit and one queue refresh, without a race.
    assert.equal(h.calls.at(-2)[1], '/receiving/import/commit');
    assert.equal(h.calls.at(-1)[0], 'GET');

    const index = harness('receiving_index.js');
    await index.run('loadQueue()');
    assert.equal(index.calls.length, 1); // Shared api is not overwritten recursively.
    const statuses = new URLSearchParams(index.calls[0][1].split('?')[1]).getAll('status');
    assert.deepEqual(statuses, ['Approved,InTransitToWarehouse']);
    assert.equal(new URLSearchParams(index.run('buildQueueExportParams()')).get('status'), 'Approved,InTransitToWarehouse');
    index.element('histOrderId').value = '739';
    await index.run('loadHistory()');
    assert.match(index.calls.at(-1)[1], /order_id=739/);
    index.ctx.api = async () => ({ data: [{ id: 739, customer_name: 'Cedar Beirut Retail', status: 'Approved' }] });
    index.ctx.RECEIVING_CAN_RECORD = false;
    await index.run('loadQueue()');
    assert.equal(index.element('queueBody').innerHTML.includes('/receive.php'), false);
    index.ctx.RECEIVING_CAN_RECORD = true;
    await index.run('loadQueue()');
    assert.equal(index.element('queueBody').innerHTML.includes('/receive.php'), true);
    const historyPending = [];
    index.ctx.api = () => new Promise(resolve => historyPending.push(resolve));
    const oldHistory = index.run('loadHistory()');
    const newHistory = index.run('loadHistory()');
    historyPending[1]({ data: [{ id: 216, order_id: 739 }] });
    await newHistory;
    historyPending[0]({ data: [{ id: 217, order_id: 740 }] });
    await oldHistory;
    assert.match(index.element('historyBody').innerHTML, /#739/);
    assert.equal(index.element('historyBody').innerHTML.includes('#740'), false);
    const consolidation = harness('consolidation.js');
    assert.equal(consolidation.ctx.orderCbm({ cargo_totals: { cbm: 1.4 }, items: [{ declared_cbm: 1 }] }), 1.4);
    assert.equal(consolidation.ctx.orderWeight({ cargo_totals: { weight: 125 }, items: [{ declared_weight: 100 }] }), 125);
    assert.equal(consolidation.ctx.orderCbm({ cargo_totals: { cbm: 0 }, items: [{ declared_cbm: 1 }] }), 0);
    const assign = harness('assign_container.js');
    let visibleChecks = [];
    let html = '';
    Object.defineProperty(assign.element('eligibleOrdersTbody'), 'innerHTML', {
        get: () => html,
        set(value) {
            html = value;
            visibleChecks = [...value.matchAll(/<input[^>]*class="[^"]*order-cb[^"]*"[^>]*>/g)].map(([tag]) => ({
                checked: /\schecked(?:\s|>)/.test(tag),
                dataset: Object.fromEntries([...tag.matchAll(/data-(id|cbm|weight)="([^"]*)"/g)].map(match => [match[1], match[2]])),
            }));
        },
    });
    assign.ctx.document.querySelectorAll = selector => selector === '.order-cb' ? visibleChecks : selector === '.order-cb:checked' ? visibleChecks.filter(cb => cb.checked) : [];
    assign.ctx.updateCapacityPreview = () => {};
    assign.ctx.updateAssignBtn = () => {};
    assign.ctx.setContainerSelection = id => { assign.run(`_selContainerId=${id}`); assign.ctx.renderOrders(); };
    assign.run('_orders=[{id:739,customer_name:"Cedar",destination_country_id:1,total_cbm:.75,total_weight:155,status:"ReadyForConsolidation"}]; _containers=[{id:30,destination_country_id:1,max_cbm:28,max_weight:28000,used_cbm:4.27,used_weight:744},{id:31,destination_country_id:2}]');
    assign.ctx.renderOrders();
    visibleChecks[0].checked = true;
    assign.ctx.onSelectionChange();
    assert.equal(visibleChecks[0].checked, true); // Automatic container suggestion repaints.
    assert.equal(assign.ctx.getSelectedOrders().length, 1);
    assign.element('orderSearch').value = 'No matching order';
    assign.ctx.renderOrders();
    assert.equal(visibleChecks.length, 0);
    assert.equal(assign.ctx.getSelectedOrders().length, 1);
    assert.equal(assign.element('assignSelectedCbmStat').textContent, '0.750');
    assign.element('orderSearch').value = '';
    assign.ctx.renderOrders();
    assert.equal(visibleChecks[0].checked, true);
    assign.ctx.setContainerSelection(31);
    assert.equal(assign.ctx.getSelectedOrders().length, 0); // Incompatible country prunes selections.
    let eligiblePage=0;
    assign.ctx.fetch=async()=>({ok:true,json:async()=>({data:eligiblePage++===0?Array.from({length:100},(_,i)=>({id:i+1})):[{id:101}],meta:{limit:100,has_more:eligiblePage===1}})});
    assert.equal((await assign.ctx.loadShipmentEligibleOrders()).length,101,'Eligible cargo must not be truncated to the first API page');
    let containerPage=0;
    assign.ctx.fetch=async()=>({ok:true,json:async()=>({data:containerPage++===0?Array.from({length:200},(_,i)=>({id:i+1})):[{id:201}],meta:{limit:200,has_more:containerPage===1}})});
    assert.equal((await assign.ctx.loadAssignmentContainers()).length,201,'Container selectors must include older destinations beyond the first page');
    let draftPage=0;
    assign.ctx.api=async()=>({data:draftPage++===0?Array.from({length:200},(_,i)=>({id:i+1})):[{id:201}],meta:{limit:200,has_more:draftPage===1}});
    assert.equal((await assign.ctx.loadOpenShipmentDrafts()).length,201,'Open shipment picker must include later pages');
    assign.ctx.fetch = async () => ({ ok: false, json: async () => ({ error: true, message: 'Forbidden' }) });
    await assign.ctx.loadEligibleOrders();
    assert.match(html, /Forbidden/);
    assert.equal(assign.ctx.getSelectedOrders().length, 0);
    let finishPhotos;
    h.ctx.PHOTO_UPLOADER = { uploadPhotos: () => new Promise(resolve => { finishPhotos = resolve; }) };
    h.element('receiveOrderId').value = '739';
    const oldOrderUpload = h.run('handleReceivePhotos([{type:"image/jpeg"}])');
    h.element('receiveOrderId').value = '740';
    finishPhotos(['uploads/warehouse-evidence.jpg']);
    await oldOrderUpload;
    assert.equal(h.run('receivePhotoPaths.length'), 0);
    for (const script of ['receiving.js','receiving_receive.js']) {
        const zero = harness(script);
        const values = Object.fromEntries(['cartons','pieces-per-carton','quantity','unit-price','total-amount','cbm','weight-per-carton','weight'].map(key => ['.item-actual-'+key, { value: '10' }]));
        values['.item-actual-cartons'].value = '0';
        for (const key of ['.item-unit-price','.item-total-amount','.item-weight-per-carton']) values[key] = {value:'10'};
        const row={dataset:{orderItemId:'1'},querySelector:selector=>values[selector]||{value:''}};
        zero.ctx.updateReceiveItemSplitTotals=()=>{};
        zero.ctx.recalcReceiveItemRow(row,'cartons');
        for (const key of ['.item-actual-quantity','.item-total-amount','.item-actual-cbm','.item-actual-weight']) assert.equal(values[key].value,'0',script+' clears '+key);
    }
    const progress=harness('receiving.js');
    progress.run('receivingImportProgressState={percent:88,step:"preview",type:"warning",message:"Preview has errors"};renderReceivingImportProgress()');
    const progressHtml=progress.element('receivingImportStatus').innerHTML;
    assert.doesNotMatch(progressHtml,/OK Saving receipts|OK Done/);
    assert.match(progressHtml,/Next Saving receipts/);
    const invalidPreview=harness('receiving.js');
    invalidPreview.ctx.canImportReceiving=()=>true;
    invalidPreview.ctx.renderReceivingImportPreview=()=>{};
    invalidPreview.ctx.startReceivingImportProgress=()=>{};
    invalidPreview.ctx.clearReceivingImportProgressTimers=()=>{};
    invalidPreview.ctx.setReceivingImportProgress=()=>{};
    invalidPreview.ctx.setReceivingImportBusy=busy=>{invalidPreview.element('receivingImportCommitBtn').disabled=busy||!invalidPreview.run('receivingImportPreviewToken');};
    invalidPreview.ctx.uploadReceivingImportFile=async()=>({data:{is_valid:false,preview_token:'invalid-preview',errors:['Over receipt'],rows:[]}});
    await invalidPreview.ctx.processReceivingImportFile({name:'receiving.csv'});
    assert.equal(invalidPreview.element('receivingImportCommitBtn').disabled,true);
    assert.equal(invalidPreview.run('receivingImportPreviewToken'),null);
    console.log('PASS: receiving refresh, quantities, truthful import progress, filters, pagination, evidence, rejections, queue and history');
})().catch(error => { console.error(error); process.exitCode = 1; });
