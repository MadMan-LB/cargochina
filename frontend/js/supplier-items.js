let supplierItemsId=null, supplierItemsOffset=0, supplierItemsRequest=0;
async function openSupplierItems(id) {
    supplierItemsId=id;
    document.getElementById('supplierItemsKind').value='orders';
    document.getElementById('supplierItemsSearch').value='';
    document.getElementById('supplierItemsTitle').textContent='Supplier #'+id+' — Items & Orders';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('supplierItemsModal')).show();
    await loadSupplierItems(0);
}
async function loadSupplierItems(offset=0) {
    const token=++supplierItemsRequest;
    supplierItemsOffset=Math.max(0,offset);
    const results=document.getElementById('supplierItemsResults'),prev=document.getElementById('supplierItemsPrev'),next=document.getElementById('supplierItemsNext');
    prev.disabled=next.disabled=true;results.textContent='Loading…';document.getElementById('supplierItemsCount').textContent='';
    const kind=document.getElementById('supplierItemsKind').value;
    const params=new URLSearchParams({kind,q:document.getElementById('supplierItemsSearch').value,limit:25,offset:supplierItemsOffset});
    const esc=v=>escapeHtml(String(v??''));
    try {
        const res=await api('GET',`/suppliers/${supplierItemsId}/items-orders?${params}`);
        if(token!==supplierItemsRequest)return;
        if(res.meta.supplier)document.getElementById('supplierItemsTitle').textContent=res.meta.supplier.name+' ('+res.meta.supplier.code+') — Items & Orders';
        const total=res.meta.total;
        if(supplierItemsOffset>=total&&supplierItemsOffset>0)return loadSupplierItems(Math.max(0,Math.floor((total-1)/25)*25));
        const head=kind==='products'?'<th>Product ID</th><th>Description</th><th>HS code</th>':'<th>Order / customer</th><th>Status</th><th>I.I.N</th><th>Item Number</th><th>Item / matching contents</th><th>Quantity / cartons</th>';
        const rows=res.data.map(r=>{
            const desc=esc([r.description_en,r.description_cn].filter(Boolean).join(' / '));
            if(kind==='products')return `<tr><td>${esc(r.id)}</td><td>${desc}</td><td>${esc(r.hs_code)}</td></tr>`;
            const link=res.meta.can_open_orders?`<a href="/cargochina/orders.php?order_id=${encodeURIComponent(r.order_id)}" target="_blank" rel="noopener">Order #${esc(r.order_id)}</a>`:`Order #${esc(r.order_id)}`;
            const contents=(r.linked_contents||[]).map(c=>`<div class="small">${esc(c.item_no)} / ${esc(c.item_number)} — ${esc(c.description_en||c.description_cn||c.description)} · ${esc(c.quantity)} ${esc(c.unit)}</div>`).join('');
            return `<tr><td>${link}<div>${esc(r.customer_name)}</div></td><td>${esc(r.status)}</td><td>${esc(r.item_no)}</td><td>${esc(r.item_number)}</td><td>${desc}${r.shared_carton_link?'<div class="badge bg-secondary">Shared carton</div>':''}${contents}</td><td>${esc(r.quantity)} ${esc(r.unit)}<div>${esc(r.cartons??'—')} cartons</div></td></tr>`;
        }).join('');
        results.innerHTML=rows?`<table class="table table-sm"><thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table>`:'<p>No linked items match these filters.</p>';
        document.getElementById('supplierItemsCount').textContent=`${total?supplierItemsOffset+1:0}–${Math.min(supplierItemsOffset+25,total)} of ${total} items`;
        prev.disabled=supplierItemsOffset===0;next.disabled=supplierItemsOffset+25>=total;
    }catch(e){if(token===supplierItemsRequest)results.textContent=e.message;}
}
