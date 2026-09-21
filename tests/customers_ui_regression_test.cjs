const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const elements=new Map(),calls=[],errors=[];
const element=id=>{if(!elements.has(id))elements.set(id,{id,value:'',textContent:'',innerHTML:'',disabled:false,dataset:{canManageCustomers:'1',canFinanceCustomers:'1'},classList:{add(){},remove(){},toggle(){}},querySelector(){return element('child')},querySelectorAll(){return[]},addEventListener(){},focus(){},setAttribute(){}});return elements.get(id)};
let sequence=0;
const sandbox=vm.createContext({console,URLSearchParams,setTimeout:()=>0,clearTimeout(){},decodeURIComponent,encodeURIComponent,document:{addEventListener(){},getElementById:element,querySelector:element,querySelectorAll:()=>[],createElement:()=>({set textContent(v){this.innerHTML=String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}})},clmsRequestKey:p=>p+'-'+(++sequence),showToast:m=>errors.push(m),setLoading(){},refreshUnsavedBaseline(){},registerUnsavedChangesGuard(){},bootstrap:{Modal:class{show(){}hide(){}static getInstance(){return new this}}},api:async(method,url,body)=>{calls.push({method,url,body});throw Error('Simulated lost response')}});
vm.runInContext('window=globalThis',sandbox);
vm.runInContext(fs.readFileSync(path.join(__dirname,'../frontend/js/customers.js'),'utf8'),sandbox);
(async()=>{
  const hostile="O'Brien \\');globalThis.executed=true;//\n<customer>";
  assert.equal(vm.runInContext(sandbox.customerNameArgument(hostile),sandbox),hostile);assert.equal(sandbox.executed,undefined);
  sandbox.openDepositModal(12,'Cedar');element('depAmount').value='100';element('depCurrency').value='USD';
  vm.runInContext('depOrderAc={getSelectedId:()=>42}',sandbox);
  await sandbox.submitDeposit();await sandbox.submitDeposit();assert.equal(calls.length,2);assert.equal(calls[0].body.order_id,42);assert.equal(calls[0].body.idempotency_key,calls[1].body.idempotency_key);
  sandbox.openDepositModal(12,'Cedar');element('depAmount').value='100';await sandbox.submitDeposit();assert.notEqual(calls[0].body.idempotency_key,calls[2].body.idempotency_key);
  let resolveFirst,resolveSecond;sandbox.api=()=>new Promise(r=>{if(!resolveFirst)resolveFirst=r;else resolveSecond=r});
  const first=sandbox.loadCustomers(),second=sandbox.loadCustomers();resolveSecond({data:[{id:2,name:'Current buyer'}],meta:{total:1}});await second;resolveFirst({data:[{id:1,name:'Stale buyer'}],meta:{total:1}});await first;assert.match(element('#customersTable tbody').innerHTML,/Current buyer/);assert.doesNotMatch(element('#customersTable tbody').innerHTML,/Stale buyer/);
  let pages=0;sandbox.api=async()=>++pages===1?{data:[{id:1}],meta:{has_more:true}}:{data:[{id:2}],meta:{has_more:false}};sandbox.renderOrders=rows=>{sandbox.loadedOrders=rows};await sandbox.showOrders(12,'Cedar');assert.equal(pages,2);assert.equal(sandbox.loadedOrders.length,2);
  console.log('PASS: safe buyer names, numeric order selection, deposit retry keys, stale search responses and complete order pagination');
})().catch(e=>{console.error(e);process.exitCode=1});
