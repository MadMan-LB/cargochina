const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'../frontend/js/supplier-items.js'),'utf8');
async function main(){
 const nodes={};for(const id of ['Kind','Search','Title','Modal','Results','Prev','Next','Count'])nodes['supplierItems'+id]={value:'',textContent:'',innerHTML:'',disabled:false};
 let requests=[];
 const ctx=vm.createContext({URLSearchParams,Math,Number,String,encodeURIComponent,document:{getElementById:id=>nodes[id]},bootstrap:{Modal:{getOrCreateInstance:()=>({show(){}})}},escapeHtml:s=>s.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),api:(method,url)=>new Promise((resolve,reject)=>requests.push({method,url,resolve,reject}))});
 vm.runInContext(source,ctx);
 const response=(name,rows=[],total=rows.length)=>({data:rows,meta:{supplier:{name,code:'S1'},total,can_open_orders:true}});
 const first=ctx.openSupplierItems(1),second=ctx.openSupplierItems(2);
 requests[1].resolve(response('Current',[{order_id:21,item_no:'AUTO-1',item_number:'007',description_en:'<img onerror=bad>',linked_contents:[]},{order_id:22,item_no:'AUTO-2',item_number:'007',linked_contents:[]}],27));await second;
 requests[0].resolve(response('Old'));await first;
 assert.match(nodes.supplierItemsTitle.textContent,/Current/);assert.doesNotMatch(nodes.supplierItemsTitle.textContent,/Old/);
 assert.equal((nodes.supplierItemsResults.innerHTML.match(/007/g)||[]).length,2);assert.match(nodes.supplierItemsResults.innerHTML,/AUTO-1/);assert.match(nodes.supplierItemsResults.innerHTML,/&lt;img/);assert.doesNotMatch(nodes.supplierItemsResults.innerHTML,/<img/);assert.match(nodes.supplierItemsResults.innerHTML,/orders.php\?order_id=21/);assert.equal(nodes.supplierItemsNext.disabled,false);
 const next=ctx.loadSupplierItems(25);assert.match(requests.at(-1).url,/offset=25/);requests.at(-1).resolve(response('Current',[{order_id:23}],27));await next;assert.equal(nodes.supplierItemsPrev.disabled,false);assert.equal(nodes.supplierItemsNext.disabled,true);
 nodes.supplierItemsKind.value='products';nodes.supplierItemsSearch.value='竹';const products=ctx.loadSupplierItems(0);assert.match(requests.at(-1).url,/kind=products/);requests.at(-1).resolve(response('Current',[{id:3,description_cn:'竹托盘',hs_code:'4419'}]));await products;assert.match(nodes.supplierItemsResults.innerHTML,/Product ID/);assert.match(nodes.supplierItemsResults.innerHTML,/竹托盘/);
 const fail=ctx.loadSupplierItems(0);requests.at(-1).reject(Error('Permission denied'));await fail;assert.equal(nodes.supplierItemsResults.textContent,'Permission denied');assert.equal(nodes.supplierItemsNext.disabled,true);
 const retry=ctx.loadSupplierItems(0);requests.at(-1).resolve(response('Current'));await retry;assert.match(nodes.supplierItemsResults.innerHTML,/No linked items/);assert.equal(nodes.supplierItemsCount.textContent,'0–0 of 0 items');
 console.log('PASS supplier items: stale response, identifiers, duplicates, escaping, links, pagination, catalog switch, Unicode, error/retry and empty state');
}
main().catch(e=>{console.error(e);process.exitCode=1;});
