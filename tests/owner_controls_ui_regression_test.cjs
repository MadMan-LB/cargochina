const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
function node(){return {children:[],value:'',textContent:'',hidden:true,disabled:false,classList:{toggle(){}},append(x){this.children.push(x);},replaceChildren(){this.children=[];},focus(){}};}
const nodes=new Proxy({}, {get(o,k){return o[k]??(o[k]=node());}}),events={},timers=[],pending=[];
const response=data=>({ok:true,json:async()=>data});
const sandbox={URLSearchParams,document:{getElementById:id=>nodes[id],createElement:node,hidden:false,addEventListener:(k,f)=>events[k]=f},window:{addEventListener:(k,f)=>events[k]=f},setTimeout:f=>{timers.push(f);return timers.length;},clearTimeout(){},fetch:async(url,opts)=>{
 if(url.endsWith('/summary'))return response({data:{open_incidents:[],recent_users:[],security_events:[],failed_login_attempts_24h:0,active_users:1}});
 if(url.endsWith('/users'))return response({data:[{id:7,email:'qa@example.invalid',full_name:'<img onerror=bad>',roles:'WarehouseStaff',is_active:1,recovery_status:'Available',can_reveal:true}]});
 if(url.includes('/incidents?'))return response({data:[],total:0});
 if(url.endsWith('/reveal'))return new Promise(resolve=>pending.push({resolve,body:JSON.parse(opts.body)}));
 throw new Error('Unexpected test request');
}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../frontend/js/owner_control.js'),'utf8'),sandbox);
const tick=()=>new Promise(r=>setImmediate(r));
(async()=>{
 await tick();await tick();const row=nodes.credentialRows.children[0];assert.match(row.children[0].textContent,/<img/);assert.equal(row.children[0].innerHTML,undefined,'Untrusted label rendered as HTML');
 const open=row.children[5].children[0].onclick;open();nodes.ownerReauth.value='ephemeral-qa';nodes.revealReason.value='Employee request';const event={preventDefault(){}};
 const first=nodes.revealForm.onsubmit(event);await nodes.revealForm.onsubmit(event);assert.equal(pending.length,1,'Double submission');assert.equal(nodes.ownerReauth.value,'');events.blur();pending[0].resolve(response({data:{credential:'QA-RECOVERED'}}));await first;assert.equal(nodes.revealedCredential.textContent,'','Late response after blur leaked credential');
 open();nodes.ownerReauth.value='ephemeral-qa';nodes.revealReason.value='Employee request';const next=nodes.revealForm.onsubmit(event);pending[1].resolve(response({data:{credential:'QA-RECOVERED'}}));await next;assert.equal(nodes.revealedCredential.textContent,'QA-RECOVERED');timers.at(-1)();assert.equal(nodes.revealedCredential.textContent,'');assert.equal(nodes.revealForm.hidden,true);
 open();nodes.ownerReauth.value='ephemeral-qa';nodes.revealReason.value='Employee request';const failed=nodes.revealForm.onsubmit(event);pending[2].resolve({ok:false,json:async()=>({message:'Audit unavailable'})});await failed;assert.equal(nodes.revealedCredential.textContent,'');assert.equal(nodes.ownerReauth.value,'');assert.equal(nodes.revealSubmit.disabled,false);assert.equal(nodes.ownerAlert.textContent,'Audit unavailable');
 console.log('PASS: credential UI double-submit, XSS-safe labels, late-response/blur protection, timed clearing and audit-failure recovery');
})().catch(e=>{console.error(e);process.exitCode=1;});
