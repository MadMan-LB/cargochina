const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const nodes=new Map();
function node(id){if(!nodes.has(id))nodes.set(id,{value:'',textContent:'',innerHTML:'',disabled:false});return nodes.get(id);}
const requests=[];
const window={API_BASE:'/api',location:{pathname:'/warehouse_stock.php'},history:{replaceState(){}}};
const sandbox={window,document:{getElementById:node,querySelectorAll:()=>[],addEventListener(){},createElement(){return {set textContent(v){this.innerHTML=String(v).replaceAll('&','&amp;').replaceAll('<','&lt;');}};}},URLSearchParams,
fetch:()=>new Promise((resolve,reject)=>requests.push({resolve,reject})),itemIdentifierText:()=>'',console};
vm.createContext(sandbox);
let source=fs.readFileSync('frontend/js/warehouse_stock.js','utf8');
source=source.replace(/\}\)\(\);\s*$/,'window.qa={renderStock,stockDimensionText,stockOrderExcelUrl};})();');
vm.runInContext(source,sandbox);
const row={order_id:1,ordered_quantity:100,item_actual_quantity:0,remaining_quantity:100,quantity:100,declared_cbm:1,order_actual_cbm:1,item_actual_cbm:0,item_actual_weight:0,warehouse_state:'InTransit'};
window.qa.renderStock([row]);
assert.match(node('stockTableBody').innerHTML,/<td>0<div/,'Zero received quantity must not fall back to ordered stock');
assert.equal(window.qa.stockDimensionText({item_height:99,item_width:99,item_length:99}),'—','Actual dimensions must not use declared dimensions');
assert.match(window.qa.stockOrderExcelUrl(1),/warehouse-stock\/export\?order_ids=1/,'Row downloads must use stock projection');
(async()=>{
  node('filterQ').value='old';const old=window.loadStock();
  node('filterQ').value='new';const recent=window.loadStock();
  requests[1].resolve({ok:true,json:async()=>({data:[{...row,description_en:'Current search'}],meta:{total:1}})});await recent;
  requests[0].resolve({ok:true,json:async()=>({data:[{...row,description_en:'Stale search'}],meta:{total:1}})});await old;
  assert.match(node('stockTableBody').innerHTML,/Current search/);assert.doesNotMatch(node('stockTableBody').innerHTML,/Stale search/);
  const failed=window.loadStock();requests[2].resolve({ok:false,json:async()=>({error:true,message:'Warehouse temporarily unavailable'})});await failed;
  assert.match(node('stockTableBody').innerHTML,/Warehouse temporarily unavailable/);assert.doesNotMatch(node('stockTableBody').innerHTML,/Current search/);assert.equal(node('stockNextPage').disabled,true);
  console.log('PASS: stock zero values, dimensions, downloads, stale responses, and failed-load cleanup');
})().catch(e=>{console.error(e);process.exitCode=1;});
