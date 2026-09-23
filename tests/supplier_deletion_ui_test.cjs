const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../frontend/js/suppliers.js'), 'utf8');
const code = source.slice(source.indexOf('const supplierDeletesInFlight'), source.indexOf('window.openImportModal'));
async function main() {
    let calls = [], toasts = [], loads = [], release, fail = false, consent = true;
    const ctx = vm.createContext({Set, String, confirm:()=>consent, showToast:(...args)=>toasts.push(args), loadSuppliers:reset=>loads.push(reset), api:async(method,url,body)=>{
        calls.push({method,url,body});
        if (method==='GET') { if (release===undefined) await new Promise(r=>release=r); return {data:{revision:'current'}}; }
        if (fail) throw Error('Cannot delete this supplier: it is linked to orders.');
        return {message:'Deleted'};
    }});
    vm.runInContext(code, ctx);
    const first=ctx.deleteSupplier(42,'Bamboo supplier'); await ctx.deleteSupplier('42','Bamboo supplier');
    assert.equal(calls.length,1,'double click must not start another GET/DELETE'); release(); await first;
    assert.equal(calls.filter(c=>c.method==='DELETE').length,1); assert.equal(calls[1].body.revision,'current');
    assert.deepEqual(loads,[false],'successful deletion retains search/pagination');
    fail=true; calls=[];toasts=[];loads=[]; await ctx.deleteSupplier(42,'Bamboo supplier');
    assert.match(toasts[0][0],/linked to orders/);assert.equal(toasts[0][1],'danger');assert.equal(loads.length,0);
    fail=false;await ctx.deleteSupplier(42,'Bamboo supplier');assert.equal(loads.length,1,'failed request must release the retry guard');
    consent=false;calls=[];await ctx.deleteSupplier(43,'Bamboo supplier');assert.equal(calls.length,1,'cancel must not delete');
    consent=true;await ctx.deleteSupplier(43,'Bamboo supplier');assert.equal(calls.filter(c=>c.method==='DELETE').length,1,'cancel must release the guard');
    console.log('PASS: supplier delete double click, revision, filters, readable errors, retry and cancel');
}
main().catch(e=>{console.error(e);process.exitCode=1;});
