const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const nodes={containerCode:{value:'BEY-2026-001'},containerMaxCbm:{},containerMaxWeight:{},containerCreateSave:{}};
const buttons=['20GP','40GP','45GP'].map(size=>({dataset:{containerPreset:size},classList:{toggle(){}},setAttribute(key,value){this[key]=value}}));
const sandbox={document:{addEventListener(){},getElementById(id){return nodes[id]},querySelectorAll(){return buttons}},el:id=>nodes[id],showToast(){},api:async()=>({data:{presets:{'20GP':{max_cbm:29,max_weight:28000},'40GP':{max_cbm:68,max_weight:28000},'45GP':{max_cbm:78,max_weight:28000}}}})};
vm.createContext(sandbox);vm.runInContext(fs.readFileSync('frontend/js/consolidation.js','utf8'),sandbox);
(async()=>{
    await sandbox.loadContainerPresets();
    assert.equal(nodes.containerMaxCbm.value,29,'Configured capacity must override default');
    assert.equal(nodes.containerCreateSave.disabled,false);
    for(const button of buttons){button.onclick();assert.equal(nodes.containerCode.value,'BEY-2026-001');assert.equal(button['aria-pressed'],'true');}
    assert.equal(nodes.containerMaxCbm.value,78);assert.equal(nodes.containerMaxWeight.value,28000);
    sandbox.api=async()=>{throw Error('Unavailable')};await sandbox.loadContainerPresets();
    assert.equal(nodes.containerCreateSave.disabled,true);assert.equal(nodes.containerMaxCbm.value,'');
    const html=fs.readFileSync('consolidation.php','utf8');
    for(const id of ['containerMaxCbm','containerMaxWeight'])assert.match(html,new RegExp('id="'+id+'" readonly'));
    assert.doesNotMatch(html,/data-container-preset="\d+HQ"/);
    console.log('PASS: GP choices, configured capacities, manual code preserved, locked capacity fields, failed config blocks saving');
})().catch(e=>{console.error(e);process.exitCode=1});
