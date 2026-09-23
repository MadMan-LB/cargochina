const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'../frontend/js/app.js'),'utf8');
const code=source.slice(source.indexOf('async function api('),source.indexOf('\nif (typeof window !== "undefined")',source.indexOf('async function api(')));
async function main() {
    let calls=[];
    const ctx=vm.createContext({API_BASE:'/api/v1',_cachePaths:[],_failedApiRequests:new Map(),JSON,Error,t:x=>x,fetch:async(url,opts)=>{calls.push(opts);return {ok:true,json:async()=>({})};}});
    vm.runInContext(code,ctx);
    for(const method of ['POST','PUT','DELETE']) {
        await ctx.api(method,'/shipment-drafts/1');assert.equal(calls.at(-1).body,'{}');
        await ctx.api(method,'/shipment-drafts/1',{revision:'current'});assert.equal(calls.at(-1).body,'{"revision":"current"}');
    }
    await ctx.api('GET','/shipment-drafts');assert.equal(calls.at(-1).body,undefined);
    console.log('PASS: bodyless write actions send JSON objects; explicit payloads and GET unchanged');
    if(process.argv[2]) {
        const base=process.argv[2];assert.match(base,/^http:\/\/(localhost|127\.0\.0\.1):8099\/cargochina$/);
        for(const method of ['POST','PUT','DELETE']) {
            for(const body of ['', '{}']) {
                const r=await fetch(base+'/api/v1/shipment-drafts/1',{method,headers:{'Content-Type':'application/json'},body});
                assert.equal(r.status,401,method+' '+body+' should pass parser and require authentication');
            }
        }
        for(const body of ['null','[]','1','"text"','{bad',' ']) {
            const r=await fetch(base+'/api/v1/shipment-drafts/1',{method:'DELETE',headers:{'Content-Type':'application/json'},body});
            assert.equal(r.status,400,'Invalid JSON body must remain rejected: '+body);
        }
        console.log('PASS: HTTP empty/object bodies reach authorization; malformed/scalar/array/null bodies rejected');
    }
}
main().catch(e=>{console.error(e);process.exitCode=1;});
