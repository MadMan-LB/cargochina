const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
function harness(file){const nodes=new Map(),pending=[],requests=[];const element=id=>{if(!nodes.has(id))nodes.set(id,{value:'',innerHTML:'',textContent:'',disabled:false,checked:false});return nodes.get(id)};const ctx=vm.createContext({console,URLSearchParams,document:{addEventListener(){},getElementById:element,createElement(){return {textContent:'',get innerHTML(){return this.textContent}}}},showToast(){},escapeHtml:String,api:(method,url)=>{requests.push(url);return new Promise((resolve,reject)=>pending.push({resolve,reject}))}});vm.runInContext(fs.readFileSync(path.join(__dirname,'../frontend/js',file),'utf8'),ctx);return {ctx,element,pending,requests};}
(async()=>{
 for(const [file,fn,list,filter,next] of [['consolidation.js','loadShipmentDrafts','shipmentDraftsList','shipmentFilter','shipmentNext'],['admin_tracking_push.js','loadPushLog','pushLogBody','filterDraft','pushLogNext']]){
  const h=harness(file);h.element(filter).value='42';const old=h.ctx[fn]();const fresh=h.ctx[fn]();h.pending[1].resolve({data:[{id:42,entity_id:42,status:'finalized',push_status:'pending',order_ids:[]}],meta:{total:51,has_more:true}});await fresh;
  const html=h.element(list).innerHTML;h.pending[0].resolve({data:[],meta:{total:0,has_more:false}});await old;assert.equal(h.element(list).innerHTML,html,'Late response overwrote latest list');assert.equal(h.element(next).disabled,false);assert.match(h.requests[1],/42/);
  const failed=h.ctx[fn]();h.pending[2].reject(Error('Unavailable'));await failed;assert.doesNotMatch(h.element(list).innerHTML,/#42/);assert.equal(h.element(next).disabled,true);
 }
 console.log('PASS: shipment and tracking list filters, pagination, stale responses and failure cleanup');
})().catch(e=>{console.error(e);process.exitCode=1});
