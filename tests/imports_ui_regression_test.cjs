const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
let csv='',errors=[];
const sandbox=vm.createContext({console,URLSearchParams,document:{addEventListener(){},getElementById:id=>id==='pasteCsvData'?{value:csv}:null},showToast:m=>errors.push(m)});
vm.runInContext('window=globalThis',sandbox);vm.runInContext(fs.readFileSync(path.join(__dirname,'../frontend/js/orders.js'),'utf8'),sandbox);
csv='description_cn,description_en,cartons,qty_per_carton,qty,weight,cbm,dimensions_scope\n竹托盘,"Bamboo trays\nwith dividers",2,6,12,8,.12,carton';
let item=sandbox.parseOrderItemsCsv()[0];assert.equal(item.description_en,'Bamboo trays\nwith dividers');assert.equal(item.quantity,12);assert.equal(item.declared_cbm,.24);assert.equal(item.declared_weight,16);assert.equal(item.dimensions_scope,'carton');
csv='description,qty,cbm,weight\nLinen runners,12,.01,.2';item=sandbox.parseOrderItemsCsv()[0];assert.equal(item.cartons,null);assert.equal(item.declared_cbm,.12);assert.equal(item.declared_weight,2.4);
for(csv of ['description,qty,qty,cbm\nTrays,12,12,.1','description,qty,cbm\nTrays,12oops,.1','description,cartons,qty_per_carton,qty,cbm\nTrays,2,6,11,.1','description,qty,cbm\n"Unclosed,12,.1','description,qty,cbm\nTrays,12,.1,extra','description,qty,cbm,dimensions_scope\nTrays,12,.1,carton'])assert.equal(sandbox.parseOrderItemsCsv(),null,'Malformed CSV must not partially mutate item cards');
assert.equal(sandbox.getItemPerUnitDenom({dimensions_scope:'carton',product_dimensions_scope:'piece',cartons:2,quantity:12}),2);
console.log('PASS: strict multiline classic CSV, sparse headers, canonical measurement totals, scope precedence and malformed input');
(async()=>{
 const events=new Map();let opening=true;
 const modal={classList:{contains:()=>true},addEventListener:(name,fn)=>events.set(name,fn),removeEventListener:name=>events.delete(name)};
 const transition=vm.createContext({document:{getElementById:()=>modal},draftOrderImportGuideModal:{hide(){if(!opening)events.get('hidden.bs.modal')?.();}}});
 const builderSource=fs.readFileSync(path.join(__dirname,'../frontend/js/procurement_drafts.js'),'utf8');
 vm.runInContext(builderSource.slice(builderSource.indexOf('async function closeDraftOrderImportGuide()'),builderSource.indexOf('async function processDraftOrderImportFile(')),transition);
 const closing=transition.closeDraftOrderImportGuide();opening=false;events.get('shown.bs.modal')();await closing;assert(!events.has('shown.bs.modal'));
 console.log('PASS: fast import waits for opening animation and closes guide before opening builder');
 for(const name of ['products','suppliers']){
  const elements=Object.fromEntries(['importCsvData','importCsvFile','importResult','importBtn'].map(id=>[id,{value:'',textContent:'',classList:{add(){},remove(){}}}]));let calls=[],counter=0,fail=false;
  const ctx=vm.createContext({console,URLSearchParams,window:{},document:{addEventListener(){},getElementById:id=>elements[id]},clmsRequestKey:()=>`key-${++counter}`,setLoading(){},showToast(){},api:async(method,url,body)=>{calls.push(body);if(fail)throw Error('Invalid row');return {data:{created:0,skipped:1}};}});
  vm.runInContext(fs.readFileSync(path.join(__dirname,`../frontend/js/${name}.js`),'utf8'),ctx);ctx.window.openImportModal(name);elements.importCsvData.value='first';await ctx.window.doImport();await ctx.window.doImport();assert.equal(calls[0].idempotency_key,calls[1].idempotency_key);elements.importCsvData.value='second';fail=true;await ctx.window.doImport();assert.notEqual(calls[0].idempotency_key,calls[2].idempotency_key);assert.equal(elements.importResult.textContent,'Invalid row');
 }
 console.log('PASS: product/supplier import retries keep keys, changed CSV gets new key and validation remains visible');
})().catch(e=>{console.error(e);process.exitCode=1;});
