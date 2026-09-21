(() => {
  const recent=new Map();let pending=null;
  function report(code){
    const now=Date.now();if(now-(recent.get(code)||0)<60000)return;recent.set(code,now);
    const body={code,page:location.pathname.replace(/^\/cargochina\//,'')};
    // Never collect exception text/stack, input values, query strings, headers or credentials.
    if(!navigator.onLine){pending=body;return;}
    fetch('/cargochina/api/v1/client-incidents',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body),keepalive:true}).catch(()=>{pending=body;});
  }
  window.addEventListener('error',e=>{try{const source=new URL(e.filename,location.href);if(source.origin===location.origin&&source.pathname.startsWith('/cargochina/frontend/js/'))report('UI_RUNTIME_FAILURE');}catch{}});
  window.addEventListener('unhandledrejection',()=>report('UI_UNHANDLED_FAILURE'));
  window.addEventListener('online',()=>{if(pending){const code=pending.code;pending=null;recent.delete(code);report(code);}});
  window.clmsReportNetworkFailure=()=>report('UI_NETWORK_FAILURE');
})();
