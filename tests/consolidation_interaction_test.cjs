const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.resolve(__dirname, '..');

async function runInteractiveRegression(page) {
    // Isolated DOM fixtures: no application requests or business records are changed.
    await page.setContent('<style>.position-fixed{position:fixed}</style><input id="search"><button id="under" style="position:fixed;top:32px;left:8px;width:400px;height:200px">Underlying action</button>');
    await page.route('**/containers/search?**', r => r.fulfill({ json: { data: [{ id: 29, code: 'Demo container' }] } }));
    await page.evaluate(() => { window.API_BASE='http://127.0.0.1/cargochina/api/v1'; window.selected=[]; window.accidental=0; document.querySelector('#under').onclick=()=>window.accidental++; });
    await page.addScriptTag({ path: path.join(root, 'frontend/js/autocomplete.js') });
    await page.evaluate(() => Autocomplete.init(document.querySelector('#search'), { resource:'containers', debounceMs:0, onSelect:item=>window.selected.push(item.id) }));
    await page.locator('#search').fill('Demo');
    const option=page.locator('.autocomplete-dropdown button');await option.waitFor();
    const box=await option.boundingBox();
    await page.mouse.move(box.x+10,box.y+10);await page.mouse.down();
    assert.equal(await option.count(),1,'option must remain until mouseup/click');
    await page.mouse.up();
    assert.deepEqual(await page.evaluate(()=>({selected:window.selected,accidental:window.accidental})),{selected:[29],accidental:0});
    await page.locator('#search').fill('Demo again');await option.waitFor();await page.locator('#search').press('Enter');
    assert.equal(await page.evaluate(()=>window.selected.length),2,'keyboard selection');
    await page.locator('#search').fill('Demo refocus');await option.waitFor();
    await page.locator('#search').press('Tab');await page.locator('#search').focus();
    // Exercise the real 150ms blur timer after a rapid refocus.
    await page.waitForTimeout(180);
    assert.equal(await option.count(),1,'old blur timer must not dismiss refocused results');
    await option.click();assert.equal(await page.evaluate(()=>window.selected.length),3);
    console.log('PASS autocomplete pointer, keyboard and rapid refocus; no underlying action');

    await page.setContent('<div class="modal" id="modalRoot"><input id="modalSearch" style="position:fixed;bottom:8px;left:20px;width:300px"></div>');
    await page.evaluate(()=>{Autocomplete.init(document.querySelector('#modalSearch'),{resource:'containers',debounceMs:0,onSelect:item=>window.modalSelection=item.id});});
    await page.locator('#modalSearch').fill('Modal container');
    const modalOption=page.locator('#modalRoot .autocomplete-dropdown button');await modalOption.waitFor();
    const modalBox=await modalOption.boundingBox();assert.ok(modalBox.y>=0 && modalBox.y+modalBox.height<=page.viewportSize().height,'Modal options must fit the viewport');
    await modalOption.click();assert.equal(await page.evaluate(()=>window.modalSelection),29,'Pointer can select within modal focus boundary');
    await page.evaluate(()=>{window.searchRequests=[];window.fetch=()=>new Promise(resolve=>window.searchRequests.push(resolve));});
    await page.locator('#modalSearch').fill('Older query');await page.waitForFunction(()=>window.searchRequests.length===1);
    assert.equal(await page.locator('#modalSearch').getAttribute('data-selected-id'),null,'Typing invalidates the prior selection');
    await page.locator('#modalSearch').fill('Latest query');await page.waitForFunction(()=>window.searchRequests.length===2);
    await page.evaluate(()=>window.searchRequests[1]({ok:true,json:async()=>({data:[{id:31,code:'Latest cargo container'}]})}));
    await page.getByRole('button',{name:/^Latest cargo container/}).waitFor();
    await page.evaluate(async()=>{window.searchRequests[0]({ok:true,json:async()=>({data:[{id:30,code:'Old cargo container'}]})});await new Promise(r=>setTimeout(r,0));});
    assert.equal(await page.getByRole('button',{name:/^Latest cargo container/}).count(),1,'Late aborted response must not clear or replace current results');
    await page.locator('#modalSearch').press('Escape');assert.equal(await page.locator('.autocomplete-dropdown').count(),0);
    console.log('PASS modal pointer selection, viewport placement, stale autocomplete responses and selection invalidation');

    await page.setContent(`<button id="open" onclick="openDraftModal(68)">Open</button><button id="refresh" onclick="openDraftModal(68,true)">Refresh</button><button id="other" onclick="openDraftModal(69)">Other</button>
      <div id="draftModal"><button id="close">Close</button><span id="draftModalId"></span><div id="draftAddOrderBody"></div><div id="draftRemoveOrderBody"></div><input id="draftContainer"><input id="draftContainerSearch"><span id="draftTotalCbm"></span><span id="draftTotalWeight"></span><div id="draftCapacityHint"></div><input id="draftContainerNumber"><input id="draftBookingNumber"><input id="draftTrackingUrl"></div>`);
    await page.evaluate(() => {
      window.shows=0;window.failures=[];window.waiting=[];window.delayDraft=false;
      window.bootstrap={Modal:{getOrCreateInstance:el=>({show(){window.shows++;el.classList.add('show')}})}};
      document.querySelector('#close').onclick=()=>{const el=document.querySelector('#draftModal');el.dispatchEvent(new Event('hide.bs.modal'));el.classList.remove('show')};
      window.showToast=(...a)=>window.failures.push(a);
      window.loadShipmentEligibleOrders=async()=>[];
      window.loadAssignmentContainers=async()=>[];
      window.api=async(method,url)=>{
        if(url.startsWith('/shipment-drafts/')) {if(window.delayDraft)await new Promise(resolve=>window.waiting.push(resolve));return {data:{order_ids:[],total_cbm:0,total_weight:0,status:'draft'}};}
        return {data:[]};
      };
    });
    await page.addScriptTag({ path: path.join(root,'frontend/js/consolidation.js') });
    await page.evaluate(() => {
      renderDraftDocuments=()=>{};
      // Install the same close invalidation registered by application initialization.
      loadContainers=loadShipmentDrafts=loadReadyTotals=loadContainerPresets=()=>{};
      document.dispatchEvent(new Event('DOMContentLoaded'));
    });
    await page.locator('#open').click();await page.waitForFunction(()=>window.shows===1);
    await page.locator('#refresh').click();assert.equal(await page.evaluate(()=>window.shows),1,'refresh reuses visible modal');
    await page.evaluate(()=>window.delayDraft=true);
    await page.locator('#refresh').click();await page.waitForFunction(()=>window.waiting.length===1);
    await page.locator('#close').click();await page.evaluate(async()=>{window.waiting.shift()();await new Promise(r=>setTimeout(r,0));});
    assert.equal(await page.locator('#draftModal').getAttribute('class'),'','closed dialog stays closed');
    assert.equal(await page.evaluate(()=>window.shows),1);
    await page.locator('#refresh').click();assert.equal(await page.evaluate(()=>window.waiting.length),0,'closed modal does not refresh');
    await page.locator('#open').click();await page.waitForFunction(()=>window.waiting.length===1);
    await page.locator('#other').click();await page.waitForFunction(()=>window.waiting.length===2);
    await page.evaluate(async()=>{window.waiting[1]();await new Promise(r=>setTimeout(r,0));window.waiting[0]();await new Promise(r=>setTimeout(r,0));});
    assert.equal(await page.locator('#draftModalId').textContent(),'#69');assert.equal(await page.evaluate(()=>window.shows),2);
    assert.deepEqual(await page.evaluate(()=>window.failures),[]);
    console.log('PASS refresh reuse, close during loading, closed refresh, out-of-order draft responses');

    // Real shared form behavior: mouse selection must survive mouseup, while
    // search fields retain caret editing. No business request is made.
    const uxUrl='http://127.0.0.1/cargochina/__staff_ux_fixture';
    await page.route(uxUrl,route=>route.fulfill({contentType:'text/html',body:'<form><input id="quantity" type="number" value="12.0000"><input id="description" value="Cotton towels"><input id="searchText" type="search" value="Cotton"></form>'}));
    await page.goto(uxUrl);
    await page.addScriptTag({path:path.join(root,'frontend/js/app.js')});
    await page.locator('#quantity').click();await page.locator('#quantity').press('8');
    assert.equal(await page.locator('#quantity').inputValue(),'8','Mouse entry replaces the whole numeric value');
    await page.locator('#description').focus();
    await page.waitForFunction(()=>document.querySelector('#description').selectionEnd===13);
    await page.locator('#description').press('T');assert.equal(await page.locator('#description').inputValue(),'T','Keyboard entry replaces the current text');
    await page.locator('#searchText').click();await page.locator('#searchText').press('End');await page.locator('#searchText').press('s');
    assert.equal(await page.locator('#searchText').inputValue(),'Cottons','Search refinement must preserve existing text');
    console.log('PASS shared numeric/text selection and search caret editing');
}
module.exports={runInteractiveRegression};
if(require.main===module)(async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:false});
  try{await runInteractiveRegression(await browser.newPage({viewport:{width:1200,height:900}}));}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
