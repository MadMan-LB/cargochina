(() => {
  const labels={created:'Order / draft created',approved:'Approved',expected_receipt:'Expected ready / receipt',received:'Received / Warehouse',fully_received:'Fully received',assigned:'Container assigned',planned_departure:'Planned departure',departed:'Departed',eta:'ETA',arrived:'Arrived',finalized:'Shipment finalized'};
  const colors={created:'secondary',approved:'success',expected_receipt:'warning',received:'info',fully_received:'success',assigned:'primary',planned_departure:'warning',departed:'primary',eta:'warning',arrived:'success',finalized:'dark'};
  const esc=v=>escapeHtml(String(v??'')), byId=id=>document.getElementById(id);
  const iso=d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  const parse=s=>{const [y,m,d]=s.split('-').map(Number);return new Date(y,m-1,d);};
  let generation=0, events=[];
  function bounds() {
    const value=byId('calendarDate').value;if(!value)throw new Error('Choose a valid date.');
    const d=parse(value),view=byId('calendarView').value;let start=new Date(d),end=new Date(d);
    if(view==='month'||view==='timeline'){start=new Date(d.getFullYear(),d.getMonth(),1);end=new Date(d.getFullYear(),d.getMonth()+1,1);}
    else if(view==='week'){start.setDate(d.getDate()-d.getDay());end=new Date(start);end.setDate(end.getDate()+7);}
    else end.setDate(end.getDate()+1);
    return {start,end,view};
  }
  function eventButton(e,index){return `<button type="button" data-event="${index}" class="btn btn-sm text-start w-100 mb-1 border border-${colors[e.event_type]}" style="white-space:normal;overflow-wrap:anywhere"><strong>${esc(e.label)}</strong><br>${esc(e.reference)}${e.customer?'<br>'+esc(e.customer):''}</button>`;}
  function render(b) {
    const grid=byId('calendarGrid');
    if(b.view==='timeline'){grid.style.display='block';grid.innerHTML=events.map((e,i)=>`<div class="card mb-2"><div class="card-body py-2"><small>${esc(e.date)}</small>${eventButton(e,i)}</div></div>`).join('')||'<p>No events in this range.</p>';return;}
    grid.style.display='grid';grid.style.gridTemplateColumns=b.view==='day'?'minmax(0,1fr)':'repeat(7,minmax(0,1fr))';
    const grouped=new Map();events.forEach((e,i)=>{const day=e.date.slice(0,10);if(!grouped.has(day))grouped.set(day,[]);grouped.get(day).push([e,i]);});
    let html='';if(b.view!=='day')html=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(d=>`<div class="fw-bold text-center">${d}</div>`).join('');
    if(b.view==='month')for(let i=0;i<b.start.getDay();i++)html+='<div aria-hidden="true"></div>';
    for(let day=new Date(b.start);day<b.end;day.setDate(day.getDate()+1)){
      const key=iso(day),rows=grouped.get(key)||[],shown=b.view==='day'?rows:rows.slice(0,3);
      html+=`<section class="border rounded p-1" style="min-width:0;min-height:100px"><div class="fw-bold mb-1">${esc(key.slice(5))}</div>${shown.map(([e,i])=>eventButton(e,i)).join('')}${rows.length>shown.length?`<button class="btn btn-sm btn-link" type="button" data-day="${key}">Show all ${rows.length} events</button>`:''}</section>`;
    }
    grid.innerHTML=html;
  }
  async function load() {
    const request=++generation;byId('calendarError').textContent='';byId('calendarCount').textContent='Loading…';byId('calendarGrid').setAttribute('aria-busy','true');
    try {
      const b=bounds(),params=new URLSearchParams(new FormData(byId('calendarFilters')));params.set('from',iso(b.start));params.set('to',iso(b.end));
      const res=await api('GET','/calendar?'+params);if(request!==generation)return;
      events=res.data;render(b);const last=new Date(b.end);last.setDate(last.getDate()-1);byId('calendarRange').textContent=iso(b.start)+' → '+iso(last);byId('calendarCount').textContent=`${events.length} events`;
      if(!res.meta.orders_visible&&!res.meta.containers_visible)byId('calendarError').textContent='No underlying order or container pages are authorized for this account.';
    }catch(e){if(request!==generation)return;events=[];byId('calendarGrid').innerHTML='';byId('calendarError').textContent=e.message;byId('calendarCount').textContent='Unable to load events';}
    finally{if(request===generation)byId('calendarGrid').removeAttribute('aria-busy');}
  }
  document.addEventListener('DOMContentLoaded',()=>{
    byId('calendarDate').value=iso(new Date());
    byId('calendarEvent').innerHTML='<option value="">All events</option>'+Object.entries(labels).map(([key,label])=>`<option value="${key}">${esc(label)}</option>`).join('');
    byId('calendarLegend').innerHTML=Object.entries(labels).map(([key,label])=>`<span class="badge bg-light text-dark border border-${colors[key]}">${esc(label)}</span>`).join('');
    const order=new URLSearchParams(location.search).get('order_id');if(order&&/^\d+$/.test(order)){byId('calendarOrder').value=order;byId('calendarView').value='timeline';}
    byId('calendarFilters').onsubmit=e=>{e.preventDefault();load();};
    byId('calendarFilters').onreset=e=>{e.preventDefault();for(const name of ['calendarEvent','calendarCustomer','calendarOrder','calendarContainer','calendarStatus'])byId(name).value='';load();};
    byId('calendarView').onchange=load;byId('calendarDate').onchange=load;byId('calendarTodayBtn').onclick=()=>{byId('calendarDate').value=iso(new Date());load();};
    const move=delta=>{const b=bounds(),d=parse(byId('calendarDate').value);if(b.view==='month'||b.view==='timeline'){d.setDate(1);d.setMonth(d.getMonth()+delta);}else d.setDate(d.getDate()+delta*(b.view==='week'?7:1));byId('calendarDate').value=iso(d);load();};
    byId('calendarPrevBtn').onclick=()=>move(-1);byId('calendarNextBtn').onclick=()=>move(1);
    byId('calendarGrid').onclick=e=>{
      const day=e.target.closest('[data-day]');if(day){byId('calendarDate').value=day.dataset.day;byId('calendarView').value='day';load();return;}
      const button=e.target.closest('[data-event]');if(!button)return;const event=events[Number(button.dataset.event)];
      byId('calendarEventTitle').textContent=event.label;
      byId('calendarEventDetail').innerHTML=`<dl>${[['Reference',event.reference],['Customer',event.customer],['Date / time',event.date],['Current status',event.status],['Container',event.container],['Container context',event.container_context],['Receipt condition',event.condition],['Source',event.source]].filter(([,v])=>v).map(([k,v])=>`<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}</dl>`;
      byId('calendarEventLink').href=event.link||'#';byId('calendarEventLink').hidden=!event.link;bootstrap.Modal.getOrCreateInstance(byId('calendarEventModal')).show();
    };
    load();
  });
})();
