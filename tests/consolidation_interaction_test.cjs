const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.resolve(__dirname, '..');

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: false });
  try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 900 } });
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

    await page.setContent(`<button id="open" onclick="openDraftModal(68)">Open</button><button id="refresh" onclick="openDraftModal(68,true)">Refresh</button><button id="other" onclick="openDraftModal(69)">Other</button>
      <div id="draftModal"><button id="close">Close</button><span id="draftModalId"></span><div id="draftAddOrderBody"></div><div id="draftRemoveOrderBody"></div><input id="draftContainer"><input id="draftContainerSearch"><span id="draftTotalCbm"></span><span id="draftTotalWeight"></span><div id="draftCapacityHint"></div><input id="draftContainerNumber"><input id="draftBookingNumber"><input id="draftTrackingUrl"></div>`);
    await page.evaluate(() => {
      window.shows=0;window.failures=[];window.waiting=[];window.delayDraft=false;
      window.bootstrap={Modal:{getOrCreateInstance:el=>({show(){window.shows++;el.classList.add('show')}})}};
      document.querySelector('#close').onclick=()=>{const el=document.querySelector('#draftModal');el.dispatchEvent(new Event('hide.bs.modal'));el.classList.remove('show')};
      window.showToast=(...a)=>window.failures.push(a);
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
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1});
