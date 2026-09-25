const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../frontend/js/app.js', 'utf8');
let requests = [], version = 1, fail = false;
const ctx = vm.createContext({ Map, API_BASE: '/api', t: s => s, fetch: async (url, opts) => {
  requests.push({url, opts});
  return {ok: !fail, status: fail ? 403 : 200, json: async () => fail ? {message:'Access revoked'} : {version}};
}});
vm.runInContext(source.slice(source.indexOf('const _failedApiRequests'), source.indexOf('if (typeof window !== "undefined") {', source.indexOf('async function api('))), ctx);
(async () => {
  for (const path of ['/roles', '/departments', '/orders', '/warehouse-stock', '/containers']) {
    const first = await ctx.api('GET', path); version++;
    assert.notEqual((await ctx.api('GET', path)).version, first.version);
  }
  fail = true; await assert.rejects(ctx.api('GET','/roles'), /Access revoked/);
  fail = false; assert.equal((await ctx.api('GET','/roles')).version, version);
  await ctx.api('DELETE','/procurement-drafts/1');
  assert.equal(requests.at(-1).opts.body, '{}');
  assert(requests.every(r => r.opts.cache === 'no-store' && r.opts.credentials === 'same-origin'));
  console.log('PASS: repeated reads observe mutations, permission revocation and recovery; no stale error/data cache');
})().catch(e => { console.error(e); process.exitCode = 1; });
