const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const elements=new Map();
const element=id=>{if(!elements.has(id))elements.set(id,{value:'',disabled:false,classList:{},textContent:''});return elements.get(id);};
const buttons=['planning','to_go','on_route','arrived','available'].map(state=>({disabled:false,getAttribute:()=>`setContainerStatus('${state}')`}));
const pending=[];const toasts=[];
const context=vm.createContext({console,URLSearchParams,setTimeout,clearTimeout,
 document:{getElementById:element,addEventListener(){},querySelectorAll:()=>buttons},
 bootstrap:{Modal:{getOrCreateInstance:()=>({show(){},hide(){}})}},
 fetch:()=>new Promise(resolve=>pending.push(resolve)),showToast:(...args)=>toasts.push(args),
});
vm.runInContext('window=globalThis',context);
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname,'../frontend/js/containers.js'),'utf8'),context);
(async()=>{
 const first=context.openStatusModal(1);assert(buttons.every(b=>b.disabled),'Pending status fetch must disable all actions');
 const second=context.openStatusModal(2);
 pending[1]({ok:true,json:async()=>({data:{allowed_status_transitions:['arrived']}})});await second;
 pending[0]({ok:true,json:async()=>({data:{allowed_status_transitions:['planning','on_route']}})});await first;
 assert.deepEqual(buttons.map(b=>b.disabled),[true,true,true,false,true],'Late previous container response must not re-enable invalid transitions');
 const failed=context.openStatusModal(3);pending[2]({ok:false,json:async()=>({message:'Forbidden'})});await failed;
 assert(buttons.every(b=>b.disabled));assert.equal(toasts[0][0],'Forbidden');
 const edit=context.openContainerEditModal(4,'Finalized cargo');pending[3]({ok:true,json:async()=>({data:{assignment_locked:true,code:'SG-BEY-LOCKED',max_cbm:2,max_weight:200}})});await edit;
 for(const key of ['Code','MaxCbm','MaxWeight','Vessel','DestCountry','Dest'])assert.equal(element('containerEdit'+key).disabled,true);
 const earlier=context.openContainerEditModal(5,'Earlier');assert.equal(element('containerEditSaveBtn').disabled,true);
 const later=context.openContainerEditModal(6,'Latest');pending[5]({ok:true,json:async()=>({data:{code:'LATEST',revision:'current'}})});await later;
 pending[4]({ok:true,json:async()=>({data:{code:'STALE',revision:'old'}})});await earlier;assert.equal(element('containerEditCode').value,'LATEST');assert.equal(vm.runInContext('containerEditRevision',context),'current');
 const bad=context.openContainerEditModal(7,'Failed');pending[6]({ok:false});await bad;assert.equal(element('containerEditSaveBtn').disabled,true,'Failed edit load must remain unsaveable');
 console.log('PASS: status graph controls, stale responses, failed-load protection and locked cargo edit fields');
})().catch(e=>{console.error(e);process.exitCode=1});
