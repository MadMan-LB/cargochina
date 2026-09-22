const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
let click,reply,calls=0,files=[],errors=[],removed=0;
const attrs=new Map();const link={href:'http://local/cargochina/api/v1/draft-orders/2/export?format=xlsx',getAttribute:k=>attrs.get(k)??null,setAttribute:(k,v)=>attrs.set(k,v),removeAttribute:k=>attrs.delete(k)};
class TestURL extends URL { static createObjectURL(){return 'blob:download';} static revokeObjectURL(){removed++;} }
const context=vm.createContext({URL:TestURL,Set,console,document:{addEventListener:(name,fn)=>click=fn,body:{appendChild(){}},createElement:()=>({click(){files.push(this.download);},remove(){}})},fetch:async()=>{calls++;return reply;},window:{location:{href:'http://local/cargochina/orders.php'},showToast:m=>errors.push(m),setTimeout:fn=>fn()}});
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname,'../frontend/js/export_download.js'),'utf8'),context);
function event(){return {button:0,target:{closest:()=>link},preventDefault(){this.prevented=true;}};}
const response=(status,type,payload={})=>({ok:status<400,headers:{get:n=>n==='Content-Type'?type:'attachment; filename="cargo.xlsx"'},json:async()=>payload,blob:async()=>({})});
(async()=>{
 reply=response(500,'application/json',{message:'Download failed (ref: example)'});await click(event());assert.equal(files.length,0);assert.match(errors.pop(),/ref: example/);assert(!attrs.has('aria-busy'));
 reply=response(200,'application/json',{message:'Record unavailable'});await click(event());assert.equal(files.length,0);assert.equal(errors.pop(),'Record unavailable');
 reply=response(200,'text/html');await click(event());assert.equal(files.length,0);assert.match(errors.pop(),/sign in/);
 reply=response(200,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');let before=calls;await Promise.all([click(event()),click(event())]);assert.equal(calls,before+1);assert.deepEqual(files,['cargo.xlsx']);assert.equal(removed,1);assert(!attrs.has('aria-busy'));
 link.href='http://local/cargochina/api/v1/orders/2/export?format=csv';reply=response(200,'text/csv');await click(event());assert.equal(files.length,2);
 for(const href of ['http://external/api/v1/orders/2/export','blob:download','http://local/cargochina/orders.php']){link.href=href;const e=event();await click(e);assert(!e.prevented);}
 console.log('PASS: individual downloads reject HTTP/JSON/login errors, preserve server messages, suppress duplicate clicks, retain XLSX/CSV and ignore unrelated links');
})().catch(e=>{console.error(e);process.exitCode=1;});
