const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=name=>fs.readFileSync(path.join(__dirname,'../frontend/js',name),'utf8');
function functionSource(text,start,indent='') { const a=text.indexOf(start);assert(a>=0);return text.slice(a,text.indexOf('\n'+indent+'}',a)+indent.length+2); }
function element(){return {innerHTML:'',textContent:'',value:'',classList:{toggle(){},add(){},remove(){}},querySelectorAll:()=>[],addEventListener(){}};}
async function race(name,start,counter,extra={}) {
 const pending=[],errors=[],nodes=new Map();let query='first',painted=[];
 const node=id=>{if(!nodes.has(id))nodes.set(id,element());return nodes.get(id);};
 const ctx=vm.createContext({console,URLSearchParams,Number,window:{APP_BASE_PATH:'/cargochina'},document:{getElementById:node,querySelector:node},api:()=>new Promise((resolve,reject)=>pending.push({resolve,reject})),showToast:m=>errors.push(m),escapeHtml:String,orderT:String,supplierT:String,
  buildOrderListQuery:()=>query,lastOrderFilterQuery:null,orderOffset:0,orderPageSize:50,orderDownloadSelection:null,updateOrderOverview:()=>painted.push(query),syncOrderListUrl(){},updateSelectAllState(){},
  productsOffset:0,productsLimit:50,getProductFilters:()=>({q:query}),updateProductsOverview:()=>painted.push(query),
  supplierOffset:0,getSupplierParams:()=>query,isBuyer:()=>false,
  ...extra});
 vm.runInContext(`let ${counter}=0;\n`+functionSource(source(name),start),ctx);
 const functionName=start.match(/function (\w+)/)[1];
 const old=ctx[functionName]();query='latest';const current=ctx[functionName]();
 pending[1].resolve({data:[],meta:{total:0,has_more:false}});await current;
 const before=[...nodes].map(([key,n])=>[key,n.innerHTML,n.textContent,n.disabled]);
 let staleReads=0;pending[0].resolve({get data(){staleReads++;return [{id:99}];},meta:{total:1}});await old;
 assert.equal(staleReads,0,`${name}: stale response data consumed`);assert.deepEqual([...nodes].map(([key,n])=>[key,n.innerHTML,n.textContent,n.disabled]),before,`${name}: stale response repainted`);assert.deepEqual(errors,[],`${name}: rendering failed`);
 const failing=ctx[functionName]();const successful=ctx[functionName]();pending[3].resolve({data:[],meta:{total:0,has_more:false}});await successful;pending[2].reject(Error('Old network failure'));await failing;assert.deepEqual(errors,[],`${name}: stale error replaced current results`);
 console.log(`PASS: ${name} ignores delayed results and delayed errors`);
}
(async()=>{
 await race('orders.js','async function loadOrders()','ordersListLoadVersion');
 await race('products.js','async function loadProducts(','productsListLoadVersion');
 await race('suppliers.js','async function loadSuppliers()','suppliersListLoadVersion');
 const calls=[];const ctx=vm.createContext({URLSearchParams,api:async(method,url)=>{calls.push(url);const offset=Number(new URLSearchParams(url.split('?')[1]).get('offset'));return offset===0?{data:[{id:1},{id:2}],meta:{limit:2,has_more:true}}:{data:[{id:2},{id:3}],meta:{limit:2,has_more:false}};}});
 vm.runInContext(functionSource(source('app.js'),'async function clmsLoadAllPages('),ctx);const rows=await ctx.clmsLoadAllPages('/orders?customer_id=17',2);assert.deepEqual(Array.from(rows,r=>r.id),[1,2,3]);assert(calls.every(url=>url.includes('customer_id=17')));assert(calls[1].includes('offset=2'));
 ctx.api=async()=>({data:[],meta:{has_more:true,limit:2}});await assert.rejects(ctx.clmsLoadAllPages('/orders'),/did not advance/);
 console.log('PASS: all-page consumers retain filters, fetch subsequent pages, deduplicate and reject stalled pagination');
 const timeline=vm.createContext({escapeLocal:String,statusBadgeClass:()=>'',statusLabel:String});
 vm.runInContext(functionSource(source('calendar.js'),'function renderTimelineTable(','    '),timeline);
 const input=[{id:2,expected_ready_date:'2026-10-12'},{id:3,expected_ready_date:'2026-10-01'},{id:1,expected_ready_date:'2026-10-01'}];
 const html=timeline.renderTimelineTable(input,'orders');assert(html.indexOf('order_id=1')<html.indexOf('order_id=3')&&html.indexOf('order_id=3')<html.indexOf('order_id=2'));assert.equal(input[0].id,2);
 console.log('PASS: calendar timeline sorts dates with stable ID ties and opens exact records');
 const app=source('app.js'),marker='// Remember navigation state, never business data.';
 const handlers={},windowHandlers={},stored={},fields=[{id:'filterQ',type:'search',value:'',addEventListener(){}},{id:'filterStockItemType',type:'select-one',value:''},{id:'stockStatusInWarehouse',type:'checkbox',checked:false}];
 stored['clms.filters.warehouse_stock.php']=JSON.stringify({filterQ:'Cotton towels',filterStockItemType:'normal',stockStatusInWarehouse:true});
 let refreshes=0;
 const restore=vm.createContext({console,JSON,Array,Object,setTimeout:fn=>fn(),location:{pathname:'/cargochina/warehouse_stock.php',search:''},sessionStorage:{getItem:k=>stored[k],setItem:(k,v)=>stored[k]=v},window:{loadStock:()=>refreshes++,addEventListener:(k,fn)=>windowHandlers[k]=fn},document:{addEventListener:(k,fn)=>handlers[k]=fn,querySelectorAll:selector=>fields.filter(f=>selector.includes('#'+f.id)||(f.type==='checkbox'&&selector.includes('.stock-status-filter')))}});
 vm.runInContext(app.slice(app.indexOf(marker)),restore);handlers.DOMContentLoaded();
 assert.equal(fields[0].value,'Cotton towels');assert.equal(fields[1].value,'normal');assert.equal(fields[2].checked,true);
 fields[0].value='Steel';handlers.input();assert.equal(JSON.parse(stored['clms.filters.warehouse_stock.php']).filterQ,'Steel');
 windowHandlers.pageshow({persisted:true});assert.equal(refreshes,1,'History restoration fetches fresh business data');
 restore.location.search='?q=Explicit';fields[0].value='Explicit';handlers.DOMContentLoaded();assert.equal(fields[0].value,'Explicit','Explicit links take precedence over saved filters');
 console.log('PASS: warehouse search/type/status persistence, explicit-link precedence and fresh Back/Forward requests');
})().catch(e=>{console.error(e);process.exitCode=1;});
