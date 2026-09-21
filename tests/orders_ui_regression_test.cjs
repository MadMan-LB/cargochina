const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const fields=values=>({querySelector:s=>({value:values[s]??''}),querySelectorAll:()=>[]});
const row={...fields({'.item-cartons':'2','.item-qty-per-ctn':'5','.item-qty':'10','.item-cbm':'.1','.item-weight':'10','.item-unit-price':'8.5','.item-sell-price':'11'}),dataset:{existingItemId:'52'}};
const cn='竹制托盘'.repeat(35),en='Bamboo serving trays with protective dividers and moisture resistant packaging. '.repeat(3);
const values={'.item-desc':cn,'.item-item-no':'CEDAR-1-1','.item-supplier-id':'1'};
const card={...fields(values),dataset:{originalDescription:cn,descriptionCn:cn,descriptionEn:en,dimensionsScope:'carton'},querySelectorAll:s=>s==='.order-item-packaging-row'?[row]:[]};
const sandbox=vm.createContext({console,URLSearchParams,document:{addEventListener(){},querySelectorAll:()=>[card]},showToast:m=>{throw Error(m)}});
vm.runInContext('window=globalThis',sandbox);
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname,'../frontend/js/orders.js'),'utf8'),sandbox);
let result=sandbox.collectOrderItems()[0];
assert.equal(result.existing_item_id,52);assert.equal(result.quantity,10);assert.equal(result.unit,'pieces');assert.equal(result.declared_cbm,.2);assert.equal(result.declared_weight,20);assert.equal(result.total_amount,110);assert.equal(result.description_cn,cn);assert.equal(result.description_en,en);
values['.item-desc']='Updated Chinese description';result=sandbox.collectOrderItems()[0];assert.equal(result.description_en,en);assert.equal(result.description_cn,values['.item-desc']);
const a={description_en:en,description_cn:cn,item_no:'CEDAR-1-1'};
for(const b of [{...a,description_en:en+'different'},{...a,brand:'Other brand'},{...a,item_no:'CEDAR-1-2'}])assert.notEqual(sandbox.orderItemGroupingKey(a),sandbox.orderItemGroupingKey(b),'Distinct item metadata must not be merged during copy/edit');
console.log('PASS: order edit retains identity and full bilingual text; piece units, carton metrics, customer pricing and distinct item grouping');

assert.equal(result.dimensions_scope,'carton');
const totals={orderCurrency:{value:'USD'},orderTotalAmount:{},orderTotalCbm:{},orderTotalWeight:{}};
const totalCard={dataset:{totalAmount:'1200.75',totalCbm:'1000.123456',totalWeight:'0.125'}};
sandbox.document.getElementById=id=>totals[id];sandbox.document.querySelectorAll=()=>[totalCard];sandbox.updateOrderTotals();
assert.equal(totals.orderTotalAmount.textContent,'$1200.75');assert.equal(totals.orderTotalCbm.textContent,'1000.123456');assert.equal(totals.orderTotalWeight.textContent,'0.125');
console.log('PASS: summary calculations never parse rounded or comma-formatted display values');

assert.equal(Number(sandbox.getItemWeightPerQty({quantity:3,declared_weight:.1001,dimensions_scope:'piece'}))*3,.1001);
assert.equal(sandbox.roundCbm6((.333333/7)*7),.333333);
console.log('PASS: per-piece input retains precision across unchanged order edits');

const builderSource=fs.readFileSync(require('node:path').join(__dirname,'../frontend/js/procurement_drafts.js'),'utf8');
const builderElements=Object.fromEntries(['draftOrderCurrency','draftOrderTotalCurrency','draftOrderTotalAmount','draftOrderTotalQty','draftOrderTotalCbm','draftOrderTotalWeight'].map(id=>[id,{value:'USD'}]));
const builderCard={dataset:{totalAmount:'1200.5678',totalQty:'12000',totalCbm:'.333333',totalWeight:'.1001'}};
const builderSection={querySelectorAll:()=>[builderCard],querySelector:()=>({textContent:''})};
const builderContext=vm.createContext({document:{getElementById:id=>builderElements[id],querySelectorAll:()=>[builderSection]},fmtAmount:String,fmtQty:String,fmtCbm:String,fmtWeight:String,syncDraftSectionCollapse(){},refreshDraftPresentation(){}});
vm.runInContext(builderSource.slice(builderSource.indexOf('function updateDraftOrderTotals()'),builderSource.indexOf('async function populateFromProduct(')),builderContext);builderContext.updateDraftOrderTotals();
assert.equal(builderElements.draftOrderTotalAmount.textContent,'1200.5678');assert.equal(builderElements.draftOrderTotalCbm.textContent,'0.333333');assert.equal(builderElements.draftOrderTotalWeight.textContent,'0.1001');
console.log('PASS: builder aggregate totals use numeric state, never formatted text');
