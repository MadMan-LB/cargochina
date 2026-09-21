const assert=require('node:assert/strict');
// Isolated context: never replace the operator's session cookie with anonymous probes.
exports.run=async function(request,base){
 assert.match(base,/localhost:8099\/cargochina$/);
 const api=await request.newContext();
 try {
  let r=await api.get(base+'/api/v1/orders');assert.equal(r.status(),401);assert.match(r.headers()['set-cookie'],/HttpOnly; SameSite=Lax/);
  for(const data of ['{bad','[]','null','1']) {r=await api.post(base+'/api/v1/orders',{headers:{'content-type':'application/json'},data});assert.equal(r.status(),400);}
  r=await api.get(base+'/backend/api/index.php?path[]=orders');assert.equal(r.status(),400);
  r=await api.post(base+'/api/v1/orders',{headers:{origin:'https://untrusted.invalid'},data:{}});assert.equal(r.status(),403);
  r=await api.post(base+'/api/v1/auth/login',{data:{email:[],password:'invalid'}});assert.equal(r.status(),400);
  r=await api.post(base+'/login.php',{form:{email:'nobody',password:'invalid'}});assert.equal(r.status(),403);
  r=await api.post(base+'/login.php',{form:{logout:'1'}});assert.equal(r.status(),403);
  r=await api.get(base+'/login.php');const html=await r.text();assert.match(html,/name="csrf_token" value="[a-f0-9]{64}"/);
  console.log('PASS: session cookie policy, malformed JSON/path rejection, cross-origin denial, login/logout CSRF and credential shapes');
 } finally {await api.dispose();}
};
