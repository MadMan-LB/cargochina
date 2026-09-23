const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function harness(file){
  const nodes=new Map(),callbacks={},calls=[];let resolveApi;
  const node=id=>{if(!nodes.has(id))nodes.set(id,{id,value:'',innerHTML:'',textContent:'',style:{},attrs:{},listeners:{},addEventListener(e,fn){this.listeners[e]=fn;},setAttribute(k,v){this.attrs[k]=v;},removeAttribute(k){delete this.attrs[k];}});return nodes.get(id);};
  const fields=file==='calendar.js'?{event_type:'calendarEvent',customer:'calendarCustomer',order:'calendarOrder',container:'calendarContainer',status:'calendarStatus'}:{q:'recycleSearch',type:'recycleType',deleted_by:'recycleActor',from:'recycleFrom',to:'recycleTo'};
  const ctx=vm.createContext({console,Date,String,Number,Map,Set,URLSearchParams,setTimeout,location:{search:''},confirm:()=>true,prompt:()=>null,escapeHtml:s=>s.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;'),FormData:class{*[Symbol.iterator](){for(const [k,id] of Object.entries(fields))yield[k,node(id).value];}},document:{getElementById:node,addEventListener:(e,fn)=>callbacks[e]=fn},bootstrap:{Modal:{getOrCreateInstance:()=>({show(){}})}},api:(method,url,body)=>new Promise((resolve,reject)=>calls.push({method,url,body,resolve,reject}))});
  vm.runInContext(fs.readFileSync(path.join(__dirname,'../frontend/js',file),'utf8'),ctx);
  node('calendarView').value='month';callbacks.DOMContentLoaded();return {node,calls,ctx};
}
async function run(){
  const h=harness('calendar.js'),{node,calls}=h;
  assert.equal(calls[0].method,'GET');const first=new URL('http://qa'+calls[0].url);assert.ok(first.searchParams.get('from'));assert.ok(first.searchParams.get('to'));
  node('calendarCustomer').value='Bamboo';node('calendarView').value='week';node('calendarView').onchange();
  assert.equal(new URL('http://qa'+calls[1].url).searchParams.get('customer'),'Bamboo','View switches preserve filters');
  calls[1].resolve({data:[],meta:{orders_visible:true,containers_visible:true}});await tick();
  calls[0].resolve({data:[{date:'2026-09-01',event_type:'created',label:'stale',reference:'Wrong',record_id:1}],meta:{orders_visible:true}});await tick();
  assert.equal(node('calendarCount').textContent,'0 events','Old async result cannot repaint new filter');
  node('calendarFilters').onsubmit({preventDefault(){}});calls[2].reject(Error('Temporary failure'));await tick();
  assert.equal(node('calendarGrid').innerHTML,'');assert.equal(node('calendarError').textContent,'Temporary failure');
  node('calendarDate').value='2026-09-30';node('calendarView').value='day';node('calendarNextBtn').onclick();assert.equal(node('calendarDate').value,'2026-10-01','Day navigation crosses month correctly');
  calls[3].resolve({data:[{date:'2026-10-01',event_type:'received',label:'Received / Warehouse',reference:'<script>unsafe</script>',customer:'Buyer',record_id:1}],meta:{orders_visible:true}});await tick();
  assert.match(node('calendarGrid').innerHTML,/&lt;script>/);assert.doesNotMatch(node('calendarGrid').innerHTML,/<script>/);
  node('calendarView').value='month';node('calendarView').onchange();calls[4].resolve({data:Array.from({length:4},(_,i)=>({date:'2026-10-01',event_type:'created',label:'Created',reference:'Order #'+i})),meta:{orders_visible:true}});await tick();
  assert.match(node('calendarGrid').innerHTML,/Show all 4 events/);
  node('calendarGrid').onclick({target:{closest:selector=>selector==='[data-day]'?{dataset:{day:'2026-10-01'}}:null}});assert.equal(node('calendarView').value,'day');assert.equal(node('calendarCustomer').value,'Bamboo');
  const r=harness('recycle-bin.js'),row={id:7,record_type:'shipment_draft',reference:'Cargo planning',original_status:'draft',deleted_at:'2026-09-23',can_restore:true,can_purge:false,version:'v1'};
  r.calls[0].resolve({data:[row],meta:{total:1,allowed_types:['shipment_draft']}});await tick();assert.match(r.node('recycleRows').innerHTML,/Retention protected/);
  assert.match(r.node('recycleType').innerHTML,/shipment_draft/);assert.doesNotMatch(r.node('recycleType').innerHTML,/procurement_draft/,'Only authorized types offered');
  const button={dataset:{index:'0',action:'restore'}},event={target:{closest:()=>button}};
  const a=r.node('recycleRows').listeners.click(event);const b=r.node('recycleRows').listeners.click(event);await tick();assert.equal(r.calls.length,2,'Double restore only sends one POST');assert.equal(r.calls[1].body.version,'v1');assert.equal(r.calls[1].method,'POST');
  r.calls[1].reject(Error('Record changed'));await a;await b;assert.equal(button.disabled,false,'Failure releases retry guard');assert.equal(r.node('recycleNotice').textContent,'Record changed');
  r.node('recycleFilters').listeners.submit({preventDefault(){}});r.calls[2].resolve({data:[],meta:{total:0,allowed_types:[],deleted_users:[]}});await tick();
  assert.match(r.node('recycleRows').innerHTML,/Recycle Bin access is enabled/);assert.doesNotMatch(r.node('recycleType').innerHTML,/shipment_draft/);
  console.log('PASS calendar range, retained filters, stale response, failure recovery, navigation, escaping; recycle double-submit, revision and failure controls');
}
module.exports=run;
if(require.main===module)run().catch(e=>{console.error(e);process.exitCode=1;});
