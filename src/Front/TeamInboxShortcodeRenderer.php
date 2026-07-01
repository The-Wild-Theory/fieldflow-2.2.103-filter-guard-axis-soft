<?php
namespace RoutesPro\Front;

class TeamInboxShortcodeRenderer {
    public static function team_inbox($atts = []) {
        $guard = \RoutesPro\Shortcodes::front_guard();
        if ($guard) return $guard;
        $theme = \RoutesPro\Shortcodes::front_theme();
        $scope = \RoutesPro\Support\Permissions::get_scope();
        $scope_client_ids = array_values(array_filter(array_map('absint', (array)($scope['client_ids'] ?? []))));
        $scope_project_ids = array_values(array_filter(array_map('absint', (array)($scope['project_ids'] ?? []))));
        $client_id = absint($atts['client_id'] ?? ($_GET['client_id'] ?? 0));
        $project_id = absint($atts['project_id'] ?? ($_GET['project_id'] ?? 0));
        if (!$client_id && count($scope_client_ids) === 1) $client_id = (int)$scope_client_ids[0];
        if (!$project_id && count($scope_project_ids) === 1) $project_id = (int)$scope_project_ids[0];
        $message_id = absint($_GET['routespro_message'] ?? 0);
        ob_start(); ?>
    <div class="rp-team-inbox" id="routespro-team-inbox"
         data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
         data-client-id="<?php echo esc_attr($client_id); ?>"
         data-project-id="<?php echo esc_attr($project_id); ?>"
         data-message-id="<?php echo esc_attr($message_id); ?>"
         data-scope='<?php echo esc_attr(wp_json_encode($scope)); ?>'>
<style>
.rp-team-inbox .rp-mail-toolbar{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.rp-team-inbox .rp-mail-toolbar select,.rp-team-inbox .rp-mail-toolbar button{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:14px;background:#fff;color:#0f172a}
.rp-team-inbox .rp-mail-grid{display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:14px}
.rp-team-inbox .rp-mail-list{border:1px solid #e2e8f0;border-radius:20px;background:#fff;max-height:620px;overflow:auto}
.rp-team-inbox .rp-mail-item{display:block;width:100%;text-align:left;background:#fff;border:0;border-bottom:1px solid #eef2f7;padding:14px 16px;cursor:pointer}
.rp-team-inbox .rp-mail-item.active{background:#eff6ff}
.rp-team-inbox .rp-mail-item strong{display:block;color:#0f172a;margin-bottom:4px}
.rp-team-inbox .rp-mail-item small{display:block;color:#64748b}
.rp-team-inbox .rp-mail-detail{border:1px solid #e2e8f0;border-radius:20px;background:#fff;padding:18px;min-width:0}
.rp-team-inbox .rp-badges{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
.rp-team-inbox .rp-badge{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eff6ff;color:#0f172a;font-weight:700;font-size:12px;border:1px solid #dbeafe}
.rp-team-inbox .rp-body{padding:14px;border:1px solid #e5e7eb;border-radius:16px;background:#f8fafc;color:#0f172a;line-height:1.6;overflow-wrap:anywhere}
.rp-team-inbox .rp-body p:first-child{margin-top:0}
.rp-team-inbox .rp-body p:last-child{margin-bottom:0}
.rp-team-inbox textarea,.rp-team-inbox input[type=file]{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:16px}.rp-team-inbox textarea{min-height:140px}.rp-team-inbox .rp-attachments{margin-top:12px;padding:12px;border:1px solid #e5e7eb;border-radius:14px;background:#fff}.rp-team-inbox .rp-attachments a{display:inline-flex;margin:4px 8px 4px 0}
.rp-team-inbox .rp-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.rp-team-inbox .rp-cta,.rp-team-inbox .rp-cta:visited{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:14px;padding:12px 16px;font-weight:700;cursor:pointer;transition:all .18s ease;border:1px solid <?php echo esc_attr($theme['primary']); ?>;background:<?php echo esc_attr($theme['primary']); ?>;color:#fff !important;text-decoration:none !important;-webkit-appearance:none;appearance:none}
.rp-team-inbox .rp-cta.alt,.rp-team-inbox .rp-cta.alt:visited{background:<?php echo esc_attr($theme['accent']); ?>;border-color:<?php echo esc_attr($theme['accent']); ?>;color:#fff !important}
.rp-team-inbox .rp-cta.ghost,.rp-team-inbox .rp-cta.ghost:visited{background:#fff;border-color:#cbd5e1;color:#0f172a !important}
.rp-team-inbox .rp-cta:hover,.rp-team-inbox .rp-cta:focus,.rp-team-inbox .rp-cta:active{background:<?php echo esc_attr($theme['primary']); ?> !important;border-color:<?php echo esc_attr($theme['primary']); ?> !important;color:#fff !important;text-decoration:none !important;outline:none;box-shadow:0 0 0 3px rgba(15,23,42,.10)}
.rp-team-inbox .rp-cta.alt:hover,.rp-team-inbox .rp-cta.alt:focus,.rp-team-inbox .rp-cta.alt:active{background:<?php echo esc_attr($theme['accent']); ?> !important;border-color:<?php echo esc_attr($theme['accent']); ?> !important;color:#fff !important}
.rp-team-inbox .rp-cta.ghost:hover,.rp-team-inbox .rp-cta.ghost:focus,.rp-team-inbox .rp-cta.ghost:active{background:#f1f5f9 !important;border-color:#cbd5e1 !important;color:#0f172a !important}
.rp-team-inbox .rp-status{margin-top:12px;padding:12px 14px;border-radius:14px;border:1px solid #cbd5e1;background:#f8fafc;color:#0f172a;font-weight:700;min-height:48px;display:flex;align-items:center}
.rp-team-inbox .rp-status.is-success{background:#ecfdf5;border-color:#86efac;color:#166534}
.rp-team-inbox .rp-status.is-error{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
.rp-team-inbox .rp-status.is-info{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
.rp-team-inbox select.ff-locked{background:#f8fafc!important;color:#475569!important;cursor:not-allowed!important;opacity:1!important}
@media (max-width:900px){.rp-team-inbox .rp-mail-grid,.rp-team-inbox .rp-mail-toolbar{grid-template-columns:1fr}}
</style>
      <div class="rp-mail-toolbar">
        <select id="rpti-client"><option value="">Cliente</option></select>
        <select id="rpti-project"><option value="">Campanha</option></select>
        <select id="rpti-filter-status" autocomplete="off"><option value="">Todos os estados</option><option value="novo">Novo</option><option value="em_analise">Em análise</option><option value="respondido">Respondido</option><option value="concluido">Concluído</option></select>
        <button type="button" class="rp-cta" id="rpti-refresh">Atualizar</button>
      </div>
      <div class="rp-mail-grid">
        <div class="rp-mail-list" id="rpti-list"><div style="padding:16px;color:#64748b">A carregar mensagens...</div></div>
        <div class="rp-mail-detail" id="rpti-detail"><div style="color:#64748b">Seleciona uma mensagem para abrir o detalhe.</div></div>
      </div>
      <div class="rp-status" id="rpti-status-note"></div>
    </div>
<script>
(function(){
  const root=document.getElementById('routespro-team-inbox');
  if(!root || root.dataset.rpInit==='1') return;
  root.dataset.rpInit='1';

  const ajax='<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
  const nonce=root.dataset.nonce||'';
  const scope=JSON.parse(root.dataset.scope||'{}');
  const initialClientId=String(root.dataset.clientId||'');
  const initialProjectId=String(root.dataset.projectId||'');
  const initialMessageId=String(root.dataset.messageId||'');
  const isAppContext=!!root.closest('.rp-app');

  const els={
    client:root.querySelector('#rpti-client'),
    project:root.querySelector('#rpti-project'),
    status:root.querySelector('#rpti-filter-status'),
    refresh:root.querySelector('#rpti-refresh'),
    list:root.querySelector('#rpti-list'),
    detail:root.querySelector('#rpti-detail'),
    note:root.querySelector('#rpti-status-note')
  };

  const state={items:[],selected:null,statusTouched:false,lastNote:{msg:'',type:''}};
  const esc=s=>(s==null?'':String(s)).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
  const allowedClientIds=()=>Array.isArray(scope?.client_ids)?scope.client_ids.map(v=>String(parseInt(v,10))).filter(Boolean):[];
  const allowedProjectIds=()=>Array.isArray(scope?.project_ids)?scope.project_ids.map(v=>String(parseInt(v,10))).filter(Boolean):[];

  function setNote(msg,type,remember=true){
    if(!els.note) return;
    els.note.textContent = msg || '';
    els.note.classList.remove('is-success','is-error','is-info');
    if(type==='success') els.note.classList.add('is-success');
    else if(type==='error') els.note.classList.add('is-error');
    else if(type==='info') els.note.classList.add('is-info');
    if(remember) state.lastNote={msg:msg||'',type:type||''};
  }

  const setLockedField=(el,locked)=>{ if(!el) return; el.disabled=!!locked; el.classList.toggle('ff-locked', !!locked); };
  const maybeLockSingleSelect=(el)=>{ if(!el) return false; const choices=Array.from(el.options||[]).filter(o=>String(o.value||'')!==''); const shouldLock=choices.length===1; if(shouldLock) el.value=String(choices[0].value||''); setLockedField(el, shouldLock); return shouldLock; };

  async function req(action, params={}, method='GET'){
    try{
      if(method==='GET'){
        const q=new URLSearchParams(Object.assign({action,_wpnonce:nonce},params));
        const res=await fetch(ajax+'?'+q.toString(),{credentials:'same-origin'});
        const text=await res.text();
        let data={};
        try{ data=text?JSON.parse(text):{}; }catch(_){ data={message:text}; }
        if(!res.ok) throw new Error(data.message||'Erro no pedido');
        return data;
      }
      const fd=new FormData();
      fd.append('action',action);
      fd.append('_wpnonce',nonce);
      Object.keys(params).forEach(k=>fd.append(k, params[k] ?? ''));
      const res=await fetch(ajax,{method:'POST',credentials:'same-origin',body:fd});
      const text=await res.text();
      let data={};
      try{ data=text?JSON.parse(text):{}; }catch(_){ data={message:text}; }
      if(!res.ok) throw new Error(data.message||'Erro no pedido');
      return data;
    }catch(e){
      console.error('RoutesPro AJAX error:', e);
      return {error:true,message:e.message||'Erro de comunicação com o servidor.'};
    }
  }

  function senderLabel(item){
    return item.sender_name || item?.meta?.selected_sender_name || item?.meta?.sender_name || item.sender_email || 'Sistema';
  }

  function prettyDate(value){
    if(!value) return '';
    const d=new Date(value.replace(' ', 'T'));
    if(Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleString('pt-PT');
  }


  function attachmentLinks(item){
    const raw=(item?.meta?.attachments || item?.meta?.last_reply_attachments || []);
    const arr=Array.isArray(raw)?raw:[];
    if(!arr.length) return '';
    return '<div class="rp-attachments"><strong>Anexos</strong><div>'+arr.map(a=>{
      const url=String(a?.url||'');
      if(!url) return '';
      return `<a href="${esc(url)}" target="_blank" rel="noopener">${esc(a?.name||'Anexo')}</a>`;
    }).join('')+'</div></div>';
  }

  function normalizeBody(body){
    const raw=String(body||'').trim();
    if(!raw) return '<p>Sem conteúdo disponível.</p>';
    if(raw.indexOf('<')===-1){
      return '<p>'+esc(raw).replace(/\n+/g,'</p><p>')+'</p>';
    }
    try{
      const parser=new DOMParser();
      const doc=parser.parseFromString(raw,'text/html');
      const selectors=[
        'div[style*="padding:14px;border:1px solid #e5e7eb"]',
        'div[style*="padding:14px;border:1px solid #e5e7eb;border-radius:14px;background:#f8fafc"]',
        'div[style*="padding:14px;border:1px solid #e5e7eb;border-radius:16px;background:#f8fafc"]',
        'div[style*="background:#f8fafc"]'
      ];
      let node=null;
      selectors.some(sel=>{ node=doc.querySelector(sel); return !!node; });
      if(!node){
        const bodyNode=doc.body || doc.documentElement;
        if(bodyNode){
          bodyNode.querySelectorAll('script,style,title,meta,link,img').forEach(el=>el.remove());
          bodyNode.querySelectorAll('h1,h2').forEach(el=>{ if((el.textContent||'').trim().toLowerCase()===(state.selected?.subject||'').trim().toLowerCase()) el.remove(); });
          node=bodyNode;
        }
      }
      const html=(node?.innerHTML||'').trim();
      return html || '<p>Sem conteúdo disponível.</p>';
    }catch(_){
      return raw;
    }
  }

  function renderList(){
    if(!state.items.length){
      els.list.innerHTML='<div style="padding:16px;color:#64748b">Sem mensagens no contexto atual.</div>';
      els.detail.innerHTML='<div style="color:#64748b">Sem detalhe disponível.</div>';
      return;
    }

    els.list.innerHTML=state.items.map(item=>`
      <button type="button" class="rp-mail-item ${String(state.selected?.id||'')===String(item.id)?'active':''}" data-id="${item.id}">
        <strong>${esc(item.subject||'Mensagem')}</strong>
        <small>${esc(senderLabel(item))} · ${esc(item.workflow_status||'novo')}</small>
        <small>${esc(item.client_name||'Cliente')} · ${esc(item.project_name||'Campanha')} · ${esc(prettyDate(item.created_at||''))}</small>
      </button>
    `).join('');

    els.list.querySelectorAll('[data-id]').forEach(btn=>{
      btn.addEventListener('click',()=>{
        state.selected=state.items.find(x=>String(x.id)===String(btn.dataset.id)) || null;
        renderDetail();
        renderList();
      });
    });

    if(!state.selected){
      state.selected=state.items.find(x=>String(x.id)===initialMessageId) || state.items[0] || null;
      renderDetail();
      renderList();
    }
  }

  function renderDetail(){
    const item=state.selected;
    if(!item){
      els.detail.innerHTML='<div style="color:#64748b">Seleciona uma mensagem para abrir o detalhe.</div>';
      return;
    }

    const bodyHtml=normalizeBody(item.body||'');
    const replyPrefill=esc(item?.meta?.last_reply_excerpt || '');

    els.detail.innerHTML=`
      <div class="rp-kicker">Mensagem #${esc(item.id||'')}</div>
      <h3 style="margin:0 0 8px;color:#0f172a">${esc(item.subject||'Mensagem')}</h3>
      <div class="rp-badges">
        <span class="rp-badge">${esc(item.workflow_status||'novo')}</span>
        <span class="rp-badge">${esc(item.message_kind||'geral')}</span>
        <span class="rp-badge">${esc(item.project_name||'Sem campanha')}</span>
      </div>
      <p style="margin:0 0 10px;color:#64748b">De ${esc(senderLabel(item))} para ${esc(item.recipient_user_name||item.recipient_name||item.recipient_email||'equipa')} · ${esc(prettyDate(item.created_at||''))}</p>
      <div class="rp-body">${bodyHtml}</div>
      ${attachmentLinks(item)}
      <div class="rp-actions">
        <button type="button" class="rp-cta ghost" data-status="em_analise">Marcar em análise</button>
        <button type="button" class="rp-cta alt" data-status="concluido">Marcar concluído</button>
        ${item.app_url?`<a class="rp-cta ghost" href="${item.app_url}" target="_blank" rel="noopener">Abrir link</a>`:''}
      </div>
      <div style="margin-top:16px">
        <label style="display:block;margin-bottom:8px;font-weight:700">Responder</label>
        <textarea id="rpti-reply" placeholder="Escreve uma resposta operacional...">${replyPrefill}</textarea>
        <label style="display:block;margin:10px 0 8px;font-weight:700">Anexar fotos ou ficheiros</label>
        <input type="file" id="rpti-reply-files" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip">
        <div class="rp-actions">
          <button type="button" class="rp-cta" id="rpti-send-reply">Enviar resposta</button>
        </div>
      </div>
    `;

    els.detail.querySelectorAll('[data-status]').forEach(btn=>btn.addEventListener('click',()=>changeStatus(btn.dataset.status)));
    const replyBtn=els.detail.querySelector('#rpti-send-reply');
    if(replyBtn) replyBtn.addEventListener('click',sendReply);
  }

  function enforceScopeLocks(){
    const allowedClients=allowedClientIds();
    const allowedProjects=allowedProjectIds();
    if(isAppContext && !scope?.is_manager){
      if(els.client){
        if(!els.client.value && initialClientId && allowedClients.includes(initialClientId)) els.client.value=initialClientId;
        if(!els.client.value && allowedClients.length===1) els.client.value=allowedClients[0];
        setLockedField(els.client, allowedClients.length <= 1 && allowedClients.length > 0);
      }
      if(els.project){
        if(!els.project.value && initialProjectId && allowedProjects.includes(initialProjectId) && Array.from(els.project.options).some(o=>String(o.value)===initialProjectId)) els.project.value=initialProjectId;
        if(!els.project.value && allowedProjects.length===1 && Array.from(els.project.options).some(o=>String(o.value)===allowedProjects[0])) els.project.value=allowedProjects[0];
        setLockedField(els.project, allowedProjects.length <= 1 && allowedProjects.length > 0);
      }
      return;
    }
    maybeLockSingleSelect(els.client);
    maybeLockSingleSelect(els.project);
  }

  async function loadClients(){
    const data=await req('routespro_front_clients');
    const rows=Array.isArray(data)?data:[];
    const current=String(els.client.value||initialClientId||'');
    els.client.innerHTML='<option value="">Cliente</option>' + rows.map(c=>`<option value="${c.id}">${esc(c.name)}</option>`).join('');
    if(current && Array.from(els.client.options).some(o=>String(o.value)===current)) els.client.value=current;
    enforceScopeLocks();
  }

  async function loadProjects(){
    const data=await req('routespro_front_projects',{client_id:els.client.value||''});
    const rows=Array.isArray(data)?data:[];
    const current=String(els.project.value||initialProjectId||'');
    els.project.innerHTML='<option value="">Campanha</option>' + rows.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('');
    if(current && Array.from(els.project.options).some(o=>String(o.value)===current)) els.project.value=current;
    enforceScopeLocks();
  }

  async function loadItems(preserveNote){
    if(!preserveNote) setNote('A carregar mensagens...', 'info');

    const selectedId=String(state.selected?.id || initialMessageId || '');
    const statusValue=(state.statusTouched ? (els.status.value||'') : '');
    const data=await req('routespro_get_team_messages',{
      client_id:els.client.value||'',
      project_id:els.project.value||'',
      status:statusValue,
      message_id:initialMessageId||'',
      only_direct:isAppContext?'1':''
    });

    if(data.error){
      setNote(data.message||'Erro ao carregar mensagens.', 'error');
      return;
    }

    state.items=Array.isArray(data.items)?data.items:[];
    state.selected=state.items.find(x=>String(x.id)===selectedId) || state.items.find(x=>String(x.id)===String(initialMessageId)) || state.items[0] || null;
    renderList();
    renderDetail();

    if(preserveNote && state.lastNote.msg){
      setNote(state.lastNote.msg, state.lastNote.type, false);
      return;
    }

    if(state.items.length){
      setNote('Mensagens carregadas.', 'success');
    }else{
      setNote(data.message || 'Sem mensagens no contexto atual.', 'info');
    }
  }

  async function changeStatus(status){
    if(!state.selected) return;
    setNote('A atualizar estado...', 'info');
    const data=await req('routespro_update_team_message',{
      log_id:state.selected.id,
      message_action:'status',
      status
    },'POST');
    if(data.error){
      setNote(data.message||'Erro ao atualizar estado.', 'error');
      return;
    }
    setNote(data.message || 'Estado atualizado.', 'success');
    await loadItems(true);
  }

  async function sendReply(){
    if(!state.selected) return;
    const textarea=els.detail.querySelector('#rpti-reply');
    const msg=(textarea?.value||'').trim();
    if(!msg){
      setNote('Escreve uma mensagem antes de enviar.', 'error');
      return;
    }
    setNote('A enviar resposta...', 'info');
    const files=els.detail.querySelector('#rpti-reply-files');
    const fd=new FormData();
    fd.append('action','routespro_update_team_message');
    fd.append('_wpnonce',nonce);
    fd.append('log_id',state.selected.id);
    fd.append('message_action','reply');
    fd.append('reply_message',msg);
    Array.from(files?.files||[]).forEach(file=>fd.append('attachments[]', file));
    const res=await fetch(ajax,{method:'POST',credentials:'same-origin',body:fd});
    const data=await res.json().catch(()=>({message:'Erro ao enviar resposta.'}));
    if(!res.ok || data.error || !data.ok){
      setNote(data.message||'Erro ao enviar resposta.', 'error');
      return;
    }
    if(textarea) textarea.value='';
    if(files) files.value='';
    setNote(data.message||'Resposta enviada com sucesso.', 'success');
    await loadItems(true);
  }

  [els.client,els.project,els.status].forEach(el=>el&&el.addEventListener('change',async()=>{
    if(el===els.status) state.statusTouched=true;
    if(el===els.client) await loadProjects();
    await loadItems();
  }));

  if(els.refresh) els.refresh.addEventListener('click',()=>loadItems());

  document.addEventListener('routespro:scope-change',async ev=>{
    const d=ev.detail||{};
    if(typeof d.client_id!=='undefined') els.client.value=String(d.client_id||'');
    await loadProjects();
    if(typeof d.project_id!=='undefined' && Array.from(els.project.options).some(o=>String(o.value)===String(d.project_id||''))){
      els.project.value=String(d.project_id||'');
    }
    if(typeof d.message_id!=='undefined' && d.message_id){
      root.dataset.messageId=String(d.message_id);
    }
    enforceScopeLocks();
    await loadItems();
  });

  loadClients().then(loadProjects).then(async()=>{
    if(els.status){ els.status.value=''; try{ els.status.selectedIndex=0; }catch(_){ } }
    enforceScopeLocks();
    await loadItems();
  });
})();
</script>
    <?php
        return ob_get_clean();
    }

}
