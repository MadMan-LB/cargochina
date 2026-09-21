(() => {
  const badge=document.getElementById('ownerIncidentNotice');
  let busy=false;
  async function check(){if(busy||document.hidden)return;busy=true;try{const r=await fetch('/cargochina/api/v1/owner-control/summary?background=1',{cache:'no-store',credentials:'same-origin'});if(!r.ok)return;const j=await r.json();const n=j.data.open_incidents.filter(x=>['CRITICAL','HIGH'].includes(x.severity)).reduce((n,x)=>n+Number(x.n),0);badge.textContent=n?`Owner: ${n} Critical/High incidents`:'Owner operations';badge.classList.toggle('btn-danger',n>0);badge.classList.toggle('btn-dark',n===0);}catch{}finally{busy=false;}}
  check();const timer=setInterval(check,30000);window.addEventListener('pagehide',()=>clearInterval(timer),{once:true});
})();
