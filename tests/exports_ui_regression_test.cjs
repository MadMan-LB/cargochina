const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
let boxes=[],calls=[],toasts=[],downloads=0,reply;
const nodes={button:{textContent:'Download selected',addEventListener(){}},count:{},all:{}};
const context=vm.createContext({console,Set,document:{getElementById:id=>nodes[id],querySelectorAll:()=>boxes,createElement:()=>({click(){downloads++},remove(){}}),body:{appendChild(){}}},URL:{createObjectURL:()=>'',revokeObjectURL(){}},fetch:async(url,options)=>{calls.push(JSON.parse(options.body));return reply;},window:{setTimeout:fn=>fn(),showToast:m=>toasts.push(m),t:(s,r)=>r?s.replace('{count}',r.count):s}});
vm.runInContext(fs.readFileSync(path.join(__dirname,'../frontend/js/bulk_excel_download.js'),'utf8'),context);
const selection=context.window.ClmsBulkExcelDownload.create({buttonId:'button',countId:'count',selectAllId:'all',checkboxSelector:'.cb',endpoint:'/orders/bulk-export'});
const checkbox=id=>({dataset:{downloadId:String(id)},checked:false});
(async()=>{
 boxes=[checkbox(11),checkbox(12)];selection.bind();boxes[0].checked=true;boxes[0].onchange();
 boxes=[checkbox(13)];selection.bind();assert(selection.selected.has('11'));boxes[0].checked=true;boxes[0].onchange();assert.equal(nodes.count.textContent,'2 selected');
 boxes=[checkbox(11),checkbox(12)];selection.bind();assert(boxes[0].checked);assert(!boxes[1].checked);
 reply={ok:false,json:async()=>({message:'Record no longer exists'})};await selection.download();assert.equal(downloads,0);assert.equal(toasts.pop(),'Record no longer exists');assert(selection.selected.has('11'));assert(!nodes.button.disabled);
 reply={ok:true,headers:{get:()=> 'application/json'}};await selection.download();assert.equal(downloads,0);assert.match(toasts.pop(),/did not return an Excel/);
 reply={ok:true,headers:{get:name=>name==='Content-Type'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':null},blob:async()=>({})};await Promise.all([selection.download(),selection.download()]);assert.equal(downloads,1);assert.deepEqual(calls.at(-1).ids,['11','13']);
 console.log('PASS: report selection survives repaint/filter/page changes; failures create no file; duplicate clicks download once');
})().catch(e=>{console.error(e);process.exitCode=1;});
