(() => {
  let offset=0, version=0, rows=[], total=0;
  const limit=25, busy=new Set(), esc=v=>escapeHtml(String(v??''));
  const form=()=>document.getElementById('recycleFilters');
  async function load() {
    const request=++version, params=new URLSearchParams(new FormData(form()));
    params.set('limit',limit);params.set('offset',offset);
    document.getElementById('recycleRows').innerHTML='<tr><td colspan="6">Loading…</td></tr>';
    try {
      const res=await api('GET','/recycle-bin?'+params);
      if(request!==version)return;
      rows=res.data;total=res.meta.total;
      if(Array.isArray(res.meta.allowed_types)) {
        const type=document.getElementById('recycleType'),selectedType=type.value;
        type.innerHTML='<option value="">All permitted types</option>'+res.meta.allowed_types.map(t=>`<option value="${esc(t)}">${esc(t.replace(/_/g,' '))}</option>`).join('');
        type.value=res.meta.allowed_types.includes(selectedType)?selectedType:'';
      }
      const actor=document.getElementById('recycleActor'),selected=actor.value,actors=res.meta.deleted_users||[];
      if(selected&&!actors.some(u=>String(u.id)===selected))actors.push({id:selected,name:'User'});
      actor.innerHTML='<option value="">All users</option>'+actors.map(u=>`<option value="${esc(u.id)}">${esc(u.name||'Unknown')} #${esc(u.id)}</option>`).join('');actor.value=selected;
      if(offset>=total&&offset>0){offset=Math.max(0,Math.floor((total-1)/limit)*limit);return load();}
      document.getElementById('recycleRows').innerHTML=rows.map((r,i)=>`<tr><td>${esc(r.reference)}<br><small>${esc(r.record_type.replace(/_/g,' '))}</small></td><td>${esc(r.deleted_by_name||'Unknown')} <small>#${esc(r.deleted_by)}</small></td><td>${esc(r.deleted_at)}</td><td>${esc(r.delete_reason||'—')}</td><td>${esc(r.original_status)}</td><td>${r.can_restore?`<button class="btn btn-sm btn-outline-primary" data-index="${i}" data-action="restore">Restore</button>`:''} ${r.can_purge?`<button class="btn btn-sm btn-outline-danger" data-index="${i}" data-action="purge">Permanently delete</button>`:'<small class="text-muted">Retention protected</small>'}</td></tr>`).join('')||'<tr><td colspan="6">No deleted records match these filters.</td></tr>';
      document.getElementById('recycleCount').textContent=`${total?offset+1:0}–${Math.min(offset+limit,total)} of ${total}`;
      if(res.meta.allowed_types?.length===0)document.getElementById('recycleRows').innerHTML='<tr><td colspan="6">Recycle Bin access is enabled. Access to Draft an Order or shipment drafts is also required to view their deleted records.</td></tr>';
      document.getElementById('recyclePrev').disabled=offset===0;
      document.getElementById('recycleNext').disabled=offset+limit>=total;
    } catch(e){if(request===version){document.getElementById('recycleRows').innerHTML=`<tr><td colspan="6" class="text-danger">${esc(e.message)}</td></tr>`;document.getElementById('recyclePrev').disabled=true;document.getElementById('recycleNext').disabled=true;document.getElementById('recycleCount').textContent='Unable to load';}}
  }
  document.addEventListener('DOMContentLoaded',()=>{
    form().addEventListener('submit',e=>{e.preventDefault();offset=0;load();});
    form().addEventListener('reset',()=>{offset=0;setTimeout(load,0);});
    document.getElementById('recyclePrev').onclick=()=>{offset=Math.max(0,offset-limit);load();};
    document.getElementById('recycleNext').onclick=()=>{offset+=limit;load();};
    document.getElementById('recycleRows').addEventListener('click',async e=>{
      const button=e.target.closest('[data-action]');if(!button)return;
      const row=rows[Number(button.dataset.index)], action=button.dataset.action, key=row.record_type+':'+row.id;
      if(busy.has(key))return;
      let confirmation='';
      if(action==='purge') {confirmation=prompt(`This cannot be undone. Type DELETE ${row.record_type} ${row.id} to permanently delete ${row.reference}.`);if(confirmation===null)return;}
      else if(!confirm(`Restore ${row.reference}?${row.record_type==='shipment_draft'?' Cargo will not be reassigned automatically.':''}`))return;
      busy.add(key);button.disabled=true;
      try{await api('POST',`/recycle-bin/${row.id}/${action}`,{type:row.record_type,version:row.version,confirmation});document.getElementById('recycleNotice').textContent=action==='restore'?'Record restored.':'Record permanently deleted.';await load();}
      catch(error){document.getElementById('recycleNotice').textContent=error.message;button.disabled=false;}
      finally{busy.delete(key);}
    });
    load();
  });
})();
