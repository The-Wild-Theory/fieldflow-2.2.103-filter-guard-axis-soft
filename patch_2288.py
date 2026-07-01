from pathlib import Path
p=Path('/mnt/data/ff_fix/fieldflow_src/src/Front/ClientPortalShortcodeRenderer.php')
s=p.read_text()
# CSS insert
css_marker=".rp-client-premium .rp-route-layout{display:grid;grid-template-columns:minmax(460px,560px) minmax(0,1fr);gap:18px;align-items:start}"
css_insert="""
.rp-client-premium .rp-campaign-tools{display:grid;grid-template-columns:minmax(220px,1.3fr) minmax(150px,.8fr) minmax(150px,.8fr) minmax(170px,.9fr) auto auto;gap:10px;align-items:end;margin:14px 0}.rp-client-premium .rp-campaign-tools input,.rp-client-premium .rp-campaign-tools select{min-height:44px}.rp-client-premium .rp-campaign-savebar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin:12px 0;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px}.rp-client-premium .rp-campaign-dirty{display:none;font-size:12px;color:#b45309;background:#fffbeb;border:1px solid #fcd34d;border-radius:999px;padding:4px 10px;font-weight:800}.rp-client-premium .rp-campaign-dirty.active{display:inline-flex}.rp-client-premium .rp-campaign-rule{min-width:250px}.rp-client-premium .rp-campaign-rule details{min-width:240px}.rp-client-premium .rp-campaign-rule details>div{margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px}.rp-client-premium .rp-campaign-rule label{font-size:12px;color:#334155}.rp-client-premium .rp-campaign-rule textarea{min-width:220px}.rp-client-premium .rp-campaign-row.is-dirty{background:#fff7ed}.rp-client-premium .rp-campaign-table select,.rp-client-premium .rp-campaign-table input,.rp-client-premium .rp-campaign-table textarea{border-radius:10px;padding:8px;min-height:36px}.rp-client-premium .rp-campaign-table .mini-input{width:74px}.rp-client-premium .rp-campaign-table .owner-select{min-width:180px}.rp-client-premium .rp-campaign-empty{padding:20px;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#64748b}
""".strip()
if css_insert not in s:
    s=s.replace(css_marker, css_insert+"\n"+css_marker)
# HTML panel insert after routes panel before reports
reports_marker='''\n      <div class="rp-panel" data-panel="reports" id="rpcp-reports-panel">'''
panel='''

      <div class="rp-panel" data-panel="campaign-pdvs" id="rpcp-campaign-pdvs-panel">
        <div class="rp-panel-card">
          <div class="rp-panel-head"><div><h3>Campanha PDV</h3><p>Espelho operacional do BO Campanhas PDV: pontos de venda associados, owners, periodicidade, regras avançadas e ligação direta às rotas criadas.</p></div><span class="rp-pill">PDVs e regras</span></div>
          <div class="rp-kpi-grid" id="rpcp-campaign-kpis"></div>
          <div class="rp-campaign-tools">
            <input type="search" id="rpcp-campaign-q" placeholder="Pesquisar PDV, morada ou cidade">
            <select id="rpcp-campaign-status"><option value="">Todos os estados</option><option value="active">active</option><option value="paused">paused</option></select>
            <select id="rpcp-campaign-active"><option value="">Ativos e inativos</option><option value="1">Só ativos</option><option value="0">Só inativos</option></select>
            <select id="rpcp-campaign-owner"><option value="">Todos os operativos</option></select>
            <button type="button" class="rp-small" id="rpcp-campaign-refresh">Atualizar PDVs</button>
            <button type="button" class="rp-small primary" id="rpcp-campaign-save">Guardar alterações</button>
          </div>
          <div class="rp-campaign-savebar"><div><strong>Gravação em lote</strong><div class="rp-meta">Edita as linhas como no BO. A criação automática de rotas continua protegida no backoffice.</div></div><span class="rp-campaign-dirty" id="rpcp-campaign-dirty">Alterações por guardar</span></div>
          <div class="rp-table-wrap"><table class="rp-campaign-table"><thead><tr><th>PDV</th><th>Campanha</th><th>Localização</th><th>Owner</th><th>Periodicidade</th><th>Repetição</th><th>Visita</th><th>Prioridade</th><th>Regras</th><th>Ativo</th><th>Estado</th><th>Rotas</th></tr></thead><tbody id="rpcp-campaign-table"></tbody></table></div>
          <div class="rp-table-meta"><div class="rp-meta" id="rpcp-campaign-meta">0 PDVs</div><div class="rp-pagination" id="rpcp-campaign-pagination"></div></div>
        </div>
      </div>
'''
if 'id="rpcp-campaign-pdvs-panel"' not in s:
    s=s.replace(reports_marker, panel+reports_marker)
# tab button
if 'data-panel="campaign-pdvs"' not in s.split('<script>')[0]:
    s=s.replace('<button type="button" data-panel="reports">Relatórios</button>', '<button type="button" data-panel="campaign-pdvs">Campanha PDV</button>\n        <button type="button" data-panel="reports">Relatórios</button>')
# els and state insert
old="performanceFragment:root.querySelector('#rpcp-performance-fragment')}"
new="performanceFragment:root.querySelector('#rpcp-performance-fragment'),campaignKpis:root.querySelector('#rpcp-campaign-kpis'),campaignQ:root.querySelector('#rpcp-campaign-q'),campaignStatus:root.querySelector('#rpcp-campaign-status'),campaignActive:root.querySelector('#rpcp-campaign-active'),campaignOwner:root.querySelector('#rpcp-campaign-owner'),campaignRefresh:root.querySelector('#rpcp-campaign-refresh'),campaignSave:root.querySelector('#rpcp-campaign-save'),campaignDirty:root.querySelector('#rpcp-campaign-dirty'),campaignTable:root.querySelector('#rpcp-campaign-table'),campaignMeta:root.querySelector('#rpcp-campaign-meta'),campaignPagination:root.querySelector('#rpcp-campaign-pagination')}"
if old in s:
    s=s.replace(old,new)
old_state="routeCalendarMonth:'', routeListPage:1};"
new_state="routeCalendarMonth:'', routeListPage:1, campaign:{rows:[],users:[],summary:{},page:1,totalPages:1,dirty:{}}};"
s=s.replace(old_state,new_state)
# params function insert after paramsForCommercial
marker="  function selectedText(el, emptyLabel){ return (el && el.selectedIndex > 0) ? esc(el.options[el.selectedIndex].text) : emptyLabel; }"
insert=r'''
  function paramsForCampaignPdvs(){ const p=new URLSearchParams(); if(els.client&&els.client.value) p.set('client_id',els.client.value); if(els.project&&els.project.value) p.set('project_id',els.project.value); if(els.campaignOwner&&els.campaignOwner.value) p.set('owner_user_id',els.campaignOwner.value); else if(els.owner&&els.owner.value) p.set('owner_user_id',els.owner.value); if(els.campaignQ&&els.campaignQ.value) p.set('q',els.campaignQ.value); if(els.campaignStatus&&els.campaignStatus.value) p.set('status',els.campaignStatus.value); if(els.campaignActive&&els.campaignActive.value) p.set('active',els.campaignActive.value); p.set('page',String(state.campaign.page||1)); p.set('per_page','25'); return p; }
'''
if 'function paramsForCampaignPdvs' not in s:
    s=s.replace(marker, insert+marker)
# campaign functions insert before renderRouteList
marker="  function renderRouteList(){ if(!els.routeList) return;"
funcs=r'''
  function campaignRowPayload(rowEl){ const val=(sel)=>rowEl.querySelector(sel)?.value ?? ''; const checked=(sel)=>rowEl.querySelector(sel)?.checked ? 1 : 0; return {assigned_to:parseInt(val('[data-campaign-field="assigned_to"]')||'0',10)||0,visit_frequency:val('[data-campaign-field="visit_frequency"]')||'weekly',frequency_count:parseInt(val('[data-campaign-field="frequency_count"]')||'1',10)||1,visit_duration_min:parseInt(val('[data-campaign-field="visit_duration_min"]')||'45',10)||45,priority:parseInt(val('[data-campaign-field="priority"]')||'0',10)||0,min_gap_days:parseInt(val('[data-campaign-field="min_gap_days"]')||'0',10)||0,max_gap_days:parseInt(val('[data-campaign-field="max_gap_days"]')||'0',10)||0,preferred_weekdays:val('[data-campaign-field="preferred_weekdays"]')||'',blocked_weekdays:val('[data-campaign-field="blocked_weekdays"]')||'',time_window_start:val('[data-campaign-field="time_window_start"]')||'',time_window_end:val('[data-campaign-field="time_window_end"]')||'',allow_auto_reschedule:checked('[data-campaign-field="allow_auto_reschedule"]'),allow_overtime:checked('[data-campaign-field="allow_overtime"]'),rule_notes:val('[data-campaign-field="rule_notes"]')||'',is_active:checked('[data-campaign-field="is_active"]'),status:val('[data-campaign-field="status"]')||'active'}; }
  function markCampaignDirty(rowEl){ if(!rowEl) return; const id=rowEl.dataset.linkId||''; if(!id) return; state.campaign.dirty[id]=campaignRowPayload(rowEl); rowEl.classList.add('is-dirty'); if(els.campaignDirty) els.campaignDirty.classList.add('active'); }
  function usersOptions(selected){ const users=Array.isArray(state.campaign.users)?state.campaign.users:[]; return '<option value="0">Sem owner</option>'+users.map(u=>`<option value="${parseInt(u.ID||u.id||0,10)}" ${String(selected||0)===String(u.ID||u.id||0)?'selected':''}>${esc((u.display_name||u.label||('User #'+(u.ID||u.id||'')))+(u.user_login?' ['+u.user_login+']':''))}</option>`).join(''); }
  function renderCampaignOwnerFilter(){ if(!els.campaignOwner) return; const current=els.campaignOwner.value||''; const users=Array.isArray(state.campaign.users)?state.campaign.users:[]; els.campaignOwner.innerHTML='<option value="">Todos os operativos</option>'+users.map(u=>`<option value="${parseInt(u.ID||u.id||0,10)}">${esc(u.display_name||u.label||('User #'+(u.ID||u.id||'')))}</option>`).join(''); if(current && Array.from(els.campaignOwner.options).some(o=>String(o.value)===String(current))) els.campaignOwner.value=current; }
  function renderCampaignPdvs(){ if(!els.campaignTable) return; const rows=Array.isArray(state.campaign.rows)?state.campaign.rows:[]; const sum=state.campaign.summary||{}; if(els.campaignKpis){ els.campaignKpis.innerHTML=[metric('PDVs na campanha',sum.total||rows.length,'Âmbito atual'),metric('Ativos',sum.active||0,'Com estado ativo'),metric('Com operativo',sum.with_owner||0,'Atribuição individual'),metric('Com coordenadas',sum.with_coords||0,'Prontos para rota'),metric('Rotas já criadas',sum.routes_count||0,'No âmbito selecionado'),metric('Kms rotas criadas',fmtKm(sum.routes_km||0),'Estimativa atual')].join(''); }
    if(els.campaignMeta) els.campaignMeta.textContent=(sum.filtered||rows.length)+' PDVs · página '+(state.campaign.page||1)+'/'+(state.campaign.totalPages||1);
    if(!rows.length){ els.campaignTable.innerHTML='<tr><td colspan="12"><div class="rp-campaign-empty">Sem PDVs ligados à campanha ou aos filtros atuais. Confirma o cliente/campanha no topo ou associa PDVs no BO Campanhas PDV.</div></td></tr>'; renderPagination(els.campaignPagination,1,1,()=>{}); return; }
    els.campaignTable.innerHTML=rows.map(row=>{ const linkId=parseInt(row.link_id||0,10); const hasAdvanced=!!(row.min_gap_days||row.max_gap_days||row.preferred_weekdays||row.blocked_weekdays||row.time_window_start||row.time_window_end||parseInt(row.allow_auto_reschedule??1,10)!==1||parseInt(row.allow_overtime||0,10)||row.rule_notes); const loc=[row.address,row.postal_code,row.city].filter(Boolean).join(' · '); return `<tr class="rp-campaign-row" data-link-id="${linkId}" data-location-id="${escAttr(row.location_id||row.id||'')}"><td><strong>${esc(row.name||'')}</strong><div class="rp-meta">${esc(row.phone||'')}</div></td><td>${esc(row.project_name||'')}<div class="rp-meta">${esc(row.client_name||'')}</div></td><td>${esc(row.city||'')}<div class="rp-meta">${esc(loc)}</div></td><td><select class="owner-select" data-campaign-field="assigned_to">${usersOptions(row.assigned_to)}</select></td><td><select data-campaign-field="visit_frequency"><option value="weekly" ${String(row.visit_frequency||'weekly')==='weekly'?'selected':''}>Semanal</option><option value="monthly" ${String(row.visit_frequency||'')==='monthly'?'selected':''}>Mensal</option></select></td><td><input class="mini-input" type="number" min="1" max="7" data-campaign-field="frequency_count" value="${escAttr(row.frequency_count||1)}"></td><td><input class="mini-input" type="number" min="0" max="360" data-campaign-field="visit_duration_min" value="${escAttr(row.visit_duration_min||45)}"> min</td><td><input class="mini-input" type="number" min="0" max="999" data-campaign-field="priority" value="${escAttr(row.priority||0)}"></td><td class="rp-campaign-rule"><details ${hasAdvanced?'open':''}><summary>${hasAdvanced?'Avançada':'Padrão'}</summary><div><label>Intervalo mín.<br><input class="mini-input" type="number" min="0" max="31" data-campaign-field="min_gap_days" value="${escAttr(row.min_gap_days||0)}"> dias</label><label>Intervalo máx.<br><input class="mini-input" type="number" min="0" max="90" data-campaign-field="max_gap_days" value="${escAttr(row.max_gap_days||0)}"> dias</label><label style="grid-column:1/-1">Dias preferenciais<br><input type="text" data-campaign-field="preferred_weekdays" value="${escAttr(row.preferred_weekdays||'')}" placeholder="1,2,3,4,5"><span class="rp-meta">1=Seg, 7=Dom</span></label><label style="grid-column:1/-1">Dias bloqueados<br><input type="text" data-campaign-field="blocked_weekdays" value="${escAttr(row.blocked_weekdays||'')}" placeholder="6,7"></label><label>Janela início<br><input type="time" data-campaign-field="time_window_start" value="${escAttr(row.time_window_start||'')}"></label><label>Janela fim<br><input type="time" data-campaign-field="time_window_end" value="${escAttr(row.time_window_end||'')}"></label><label style="grid-column:1/-1"><input type="checkbox" data-campaign-field="allow_auto_reschedule" ${parseInt(row.allow_auto_reschedule??1,10)?'checked':''}> permitir reagendamento automático</label><label style="grid-column:1/-1"><input type="checkbox" data-campaign-field="allow_overtime" ${parseInt(row.allow_overtime||0,10)?'checked':''}> permitir horas extra</label><label style="grid-column:1/-1">Notas da regra<br><textarea rows="2" data-campaign-field="rule_notes">${esc(row.rule_notes||'')}</textarea></label></div></details></td><td><label><input type="checkbox" data-campaign-field="is_active" ${parseInt(row.campaign_active??row.is_active??1,10)?'checked':''}> ativo</label></td><td><select data-campaign-field="status"><option value="active" ${String(row.campaign_status||'active')==='active'?'selected':''}>active</option><option value="paused" ${String(row.campaign_status||'')==='paused'?'selected':''}>paused</option></select></td><td><button type="button" class="rp-small" data-campaign-routes="${escAttr(row.location_id||row.id||'')}">Ver rotas</button></td></tr>`; }).join('');
    els.campaignTable.querySelectorAll('[data-campaign-field]').forEach(el=>{ const ev=(el.type==='checkbox'||el.tagName==='SELECT')?'change':'input'; el.addEventListener(ev,()=>markCampaignDirty(el.closest('[data-link-id]'))); });
    els.campaignTable.querySelectorAll('[data-campaign-routes]').forEach(btn=>btn.addEventListener('click',async()=>{ const loc=btn.dataset.campaignRoutes||''; if(els.location){ els.location.value=loc; syncLocationSearchText(); renderLocationOptions(); } state.selectedRouteId=0; state.selectedRoute=null; state.routeListPage=1; switchPanel('routes'); await loadPortal(true); }));
    renderPagination(els.campaignPagination,state.campaign.page||1,state.campaign.totalPages||1,next=>{ state.campaign.page=next; loadCampaignPdvs(true); }); ensurePortalTableScroll(); }
  async function loadCampaignPdvs(withStatus=false){ if(!els.campaignTable) return; if(withStatus) setStatus('A atualizar PDVs da campanha...'); const data=await j(api+'campaign-pdvs?'+paramsForCampaignPdvs().toString()).catch(err=>({rows:[],users:[],summary:{error:err.message||'Erro'}})); state.campaign.rows=Array.isArray(data.rows)?data.rows:[]; state.campaign.users=Array.isArray(data.users)?data.users:[]; state.campaign.summary=data.summary||{}; state.campaign.page=parseInt(data.page||state.campaign.page||1,10)||1; state.campaign.totalPages=parseInt(data.total_pages||1,10)||1; state.campaign.dirty={}; if(els.campaignDirty) els.campaignDirty.classList.remove('active'); renderCampaignOwnerFilter(); renderCampaignPdvs(); if(withStatus) setStatus((state.campaign.summary&&state.campaign.summary.error)?state.campaign.summary.error:'PDVs da campanha atualizados.'); }
  async function saveCampaignPdvs(){ const ids=Object.keys(state.campaign.dirty||{}); if(!ids.length){ setStatus('Sem alterações por guardar nos PDVs.'); return; } setStatus('A guardar alterações dos PDVs...'); let ok=0; for(const id of ids){ await j(api+'campaign-pdvs/'+id,{method:'PATCH',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify(state.campaign.dirty[id])}); ok++; } state.campaign.dirty={}; if(els.campaignDirty) els.campaignDirty.classList.remove('active'); setStatus(ok+' PDVs atualizados.'); await loadCampaignPdvs(false); await loadRoutesRange(); }
'''
if 'function loadCampaignPdvs' not in s:
    s=s.replace(marker, funcs+marker)
# loadPortal includes campaign
s=s.replace("await loadRoutesRange(); if(!state.selectedRouteId) await loadReports();", "await loadRoutesRange(); await loadCampaignPdvs(false); if(!state.selectedRouteId) await loadReports();")
# event listeners for campaign after refresh
marker="  root.querySelector('#rpcp-refresh')?.addEventListener('click',()=>loadPortal(true));"
insert="""  root.querySelector('#rpcp-refresh')?.addEventListener('click',()=>loadPortal(true));
  if(els.campaignRefresh) els.campaignRefresh.addEventListener('click',()=>{ state.campaign.page=1; loadCampaignPdvs(true); });
  if(els.campaignSave) els.campaignSave.addEventListener('click',()=>saveCampaignPdvs().catch(err=>setStatus(err.message||'Falha ao guardar PDVs.')));
  [els.campaignQ,els.campaignStatus,els.campaignActive,els.campaignOwner].forEach(el=>{ if(!el) return; const ev=el.tagName==='SELECT'?'change':'input'; let t=null; el.addEventListener(ev,()=>{ clearTimeout(t); t=setTimeout(()=>{ state.campaign.page=1; loadCampaignPdvs(true); }, el.tagName==='SELECT'?0:350); }); });"""
s=s.replace(marker, insert)
# reset campaign page on scope changes - simple
s=s.replace("state.routeListPage=1; syncProjects();", "state.routeListPage=1; state.campaign.page=1; syncProjects();")
s=s.replace("state.routeListPage=1; await syncOwners();", "state.routeListPage=1; state.campaign.page=1; await syncOwners();")
s=s.replace("state.routeListPage=1; await syncLocations();", "state.routeListPage=1; state.campaign.page=1; await syncLocations();")
s=s.replace("state.routeListPage=1; await syncLocations(); refreshPerformanceDashboard();", "state.routeListPage=1; state.campaign.page=1; await syncLocations(); refreshPerformanceDashboard();")
s=s.replace("state.routeListPage=1; await syncLocations(); refreshPerformanceDashboard(); loadPortal(true);", "state.routeListPage=1; state.campaign.page=1; await syncLocations(); refreshPerformanceDashboard(); loadPortal(true);")
p.write_text(s)

# patch RoutesController
rp=Path('/mnt/data/ff_fix/fieldflow_src/src/Rest/RoutesController.php')
s=rp.read_text()
reg_marker="""        // /stops/{id} (apagar/atualizar)
        register_rest_route(self::NS, '/stops/(?P<id>\\d+)', ["""
reg_insert="""        register_rest_route(self::NS, '/campaign-pdvs', [[
            'methods' => 'GET',
            'callback' => [$this, 'list_campaign_pdvs'],
            'permission_callback' => [$this, 'can_access_campaign_pdvs'],
        ]]);
        register_rest_route(self::NS, '/campaign-pdvs/(?P<id>\\d+)', [[
            'methods' => 'PATCH',
            'callback' => [$this, 'update_campaign_pdv'],
            'permission_callback' => [$this, 'can_access_campaign_pdvs'],
        ]]);

"""
if "'/campaign-pdvs'" not in s:
    s=s.replace(reg_marker, reg_insert+reg_marker)
method_marker="""    public function can_create_stop(WP_REST_Request $req){"""
methods=r'''
    public function can_access_campaign_pdvs(WP_REST_Request $req){
        if (!is_user_logged_in()) return false;
        if (current_user_can('routespro_manage')) return true;
        return Permissions::can_access_front();
    }

    private function campaign_projects_for_request(WP_REST_Request $req): array {
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $client_id = absint($req->get_param('client_id') ?: 0);
        $project_id = absint($req->get_param('project_id') ?: 0);
        [$client_id, $project_id] = Permissions::sanitize_scope_selection($client_id, $project_id);
        if ($project_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT id, client_id, name FROM {$px}projects WHERE id=%d", $project_id), ARRAY_A);
            return $row ? [$row] : [];
        }
        $where = ['1=1']; $args = [];
        if ($client_id > 0) { $where[] = 'client_id=%d'; $args[] = $client_id; }
        if (!current_user_can('routespro_manage')) {
            $scope = Permissions::get_scope();
            $allowedProjects = array_values(array_filter(array_map('absint', (array)($scope['project_ids'] ?? []))));
            $allowedClients = array_values(array_filter(array_map('absint', (array)($scope['client_ids'] ?? []))));
            $parts = [];
            if ($allowedProjects) { $parts[] = 'id IN (' . implode(',', array_fill(0, count($allowedProjects), '%d')) . ')'; $args = array_merge($args, $allowedProjects); }
            if ($allowedClients) { $parts[] = 'client_id IN (' . implode(',', array_fill(0, count($allowedClients), '%d')) . ')'; $args = array_merge($args, $allowedClients); }
            $where[] = $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
        }
        $sql = "SELECT id, client_id, name FROM {$px}projects WHERE " . implode(' AND ', $where) . " ORDER BY name ASC";
        return $args ? ($wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: []) : ($wpdb->get_results($sql, ARRAY_A) ?: []);
    }

    public function list_campaign_pdvs(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $projects = $this->campaign_projects_for_request($req);
        $projectIds = array_values(array_filter(array_map(fn($r)=>absint($r['id'] ?? 0), $projects)));
        if (!$projectIds) return new WP_REST_Response(['rows'=>[], 'users'=>[], 'summary'=>['total'=>0,'filtered'=>0,'active'=>0,'with_owner'=>0,'with_coords'=>0,'routes_count'=>0,'routes_km'=>0], 'page'=>1, 'total_pages'=>1], 200);
        $where = ['cl.project_id IN (' . implode(',', array_fill(0, count($projectIds), '%d')) . ')'];
        $args = $projectIds;
        $q = sanitize_text_field((string)($req->get_param('q') ?: ''));
        if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $where[] = '(l.name LIKE %s OR l.address LIKE %s OR l.city LIKE %s OR l.phone LIKE %s OR l.postal_code LIKE %s OR p.name LIKE %s OR c.name LIKE %s)'; array_push($args, $like, $like, $like, $like, $like, $like, $like); }
        $status = sanitize_text_field((string)($req->get_param('status') ?: ''));
        if (in_array($status, ['active','paused'], true)) { $where[] = 'cl.status=%s'; $args[] = $status; }
        $active = (string)($req->get_param('active') ?? '');
        if ($active === '1' || $active === '0') { $where[] = 'cl.is_active=%d'; $args[] = (int)$active; }
        $owner = absint($req->get_param('owner_user_id') ?: 0);
        if ($owner > 0) { $where[] = 'cl.assigned_to=%d'; $args[] = $owner; }
        $page = max(1, absint($req->get_param('page') ?: 1));
        $perPage = max(5, min(100, absint($req->get_param('per_page') ?: 25)));
        $baseJoin = " FROM {$px}campaign_locations cl INNER JOIN {$px}locations l ON l.id=cl.location_id INNER JOIN {$px}projects p ON p.id=cl.project_id LEFT JOIN {$px}clients c ON c.id=p.client_id LEFT JOIN {$px}categories cat ON cat.id=l.category_id LEFT JOIN {$px}categories scat ON scat.id=l.subcategory_id LEFT JOIN {$wpdb->users} owner ON owner.ID=cl.assigned_to WHERE " . implode(' AND ', $where);
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*)" . $baseJoin, ...$args));
        $offset = ($page - 1) * $perPage;
        $select = "SELECT cl.id AS link_id, cl.project_id, cl.location_id, cl.status AS campaign_status, cl.priority, cl.visit_frequency, cl.frequency_count, cl.visit_duration_min, cl.min_gap_days, cl.max_gap_days, cl.preferred_weekdays, cl.blocked_weekdays, cl.time_window_start, cl.time_window_end, cl.allow_auto_reschedule, cl.allow_overtime, cl.rule_notes, cl.assigned_to, cl.is_active AS campaign_active, l.id, l.name, l.address, l.city, l.postal_code, l.phone, l.lat, l.lng, cat.name AS category_name, scat.name AS subcategory_name, p.name AS project_name, c.name AS client_name, owner.display_name AS assigned_to_name";
        $rows = $wpdb->get_results($wpdb->prepare($select . $baseJoin . " ORDER BY p.name ASC, cl.priority DESC, l.city ASC, l.name ASC LIMIT %d OFFSET %d", ...array_merge($args, [$perPage, $offset])), ARRAY_A) ?: [];
        $sumRows = $wpdb->get_results($wpdb->prepare("SELECT cl.is_active, cl.assigned_to, l.lat, l.lng" . $baseJoin, ...$args), ARRAY_A) ?: [];
        $summary = ['total'=>$total, 'filtered'=>$total, 'active'=>0, 'with_owner'=>0, 'with_coords'=>0, 'routes_count'=>0, 'routes_km'=>0];
        foreach ($sumRows as $r) { if (!empty($r['is_active'])) $summary['active']++; if (!empty($r['assigned_to'])) $summary['with_owner']++; if (($r['lat'] ?? '') !== '' && ($r['lng'] ?? '') !== '') $summary['with_coords']++; }
        $routeWhere = ['project_id IN (' . implode(',', array_fill(0, count($projectIds), '%d')) . ')']; $routeArgs = $projectIds;
        if ($owner > 0) { $routeWhere[] = 'owner_user_id=%d'; $routeArgs[] = $owner; }
        $routeSummary = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(distance_km),0) AS km FROM {$px}routes WHERE " . implode(' AND ', $routeWhere), ...$routeArgs), ARRAY_A) ?: [];
        $summary['routes_count'] = (int)($routeSummary['cnt'] ?? 0); $summary['routes_km'] = (float)($routeSummary['km'] ?? 0);
        $userIds = [];
        foreach ($projects as $pr) { foreach (Permissions::get_associated_user_ids((int)($pr['client_id'] ?? 0), (int)($pr['id'] ?? 0)) as $uid) $userIds[$uid] = $uid; }
        foreach ($rows as $r) { $uid = absint($r['assigned_to'] ?? 0); if ($uid) $userIds[$uid] = $uid; }
        $users = $userIds ? get_users(['include'=>array_values($userIds), 'orderby'=>'display_name', 'order'=>'ASC', 'fields'=>['ID','display_name','user_login']]) : [];
        return new WP_REST_Response(['rows'=>$rows, 'users'=>$users, 'summary'=>$summary, 'page'=>$page, 'total_pages'=>max(1, (int)ceil($total / $perPage))], 200);
    }

    public function update_campaign_pdv(WP_REST_Request $req){
        global $wpdb; $px = $wpdb->prefix . 'routespro_';
        $link_id = absint($req['id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare("SELECT cl.id, cl.project_id, p.client_id FROM {$px}campaign_locations cl INNER JOIN {$px}projects p ON p.id=cl.project_id WHERE cl.id=%d", $link_id), ARRAY_A);
        if (!$row) return new WP_Error('not_found', 'PDV de campanha não encontrado.', ['status'=>404]);
        $scope = Permissions::assert_scope_or_error((int)($row['client_id'] ?? 0), (int)($row['project_id'] ?? 0));
        if (is_wp_error($scope)) return $scope;
        $p = $req->get_json_params() ?: [];
        $freq = sanitize_text_field((string)($p['visit_frequency'] ?? 'weekly')); if (!in_array($freq, ['weekly','monthly'], true)) $freq = 'weekly';
        $status = sanitize_text_field((string)($p['status'] ?? 'active')); if (!in_array($status, ['active','paused'], true)) $status = 'active';
        $payload = [
            'assigned_to' => absint($p['assigned_to'] ?? 0),
            'visit_frequency' => $freq,
            'frequency_count' => max(1, min(7, absint($p['frequency_count'] ?? 1))),
            'visit_duration_min' => max(0, min(360, absint($p['visit_duration_min'] ?? 45))),
            'priority' => max(0, min(999, absint($p['priority'] ?? 0))),
            'min_gap_days' => max(0, min(31, absint($p['min_gap_days'] ?? 0))),
            'max_gap_days' => max(0, min(90, absint($p['max_gap_days'] ?? 0))),
            'preferred_weekdays' => sanitize_text_field((string)($p['preferred_weekdays'] ?? '')),
            'blocked_weekdays' => sanitize_text_field((string)($p['blocked_weekdays'] ?? '')),
            'time_window_start' => sanitize_text_field((string)($p['time_window_start'] ?? '')),
            'time_window_end' => sanitize_text_field((string)($p['time_window_end'] ?? '')),
            'allow_auto_reschedule' => !empty($p['allow_auto_reschedule']) ? 1 : 0,
            'allow_overtime' => !empty($p['allow_overtime']) ? 1 : 0,
            'rule_notes' => sanitize_textarea_field((string)($p['rule_notes'] ?? '')),
            'is_active' => !empty($p['is_active']) ? 1 : 0,
            'status' => $status,
        ];
        $wpdb->update($px . 'campaign_locations', $payload, ['id'=>$link_id], ['%d','%s','%d','%d','%d','%d','%d','%s','%s','%s','%s','%d','%d','%s','%d','%s'], ['%d']);
        return new WP_REST_Response(['ok'=>true, 'id'=>$link_id], 200);
    }

'''
if 'function list_campaign_pdvs' not in s:
    s=s.replace(method_marker, methods+method_marker)
rp.write_text(s)

# version and docs
fp=Path('/mnt/data/ff_fix/fieldflow_src/fieldflow.php')
s=fp.read_text().replace("FIELDFLOW_VERSION', '2.2.85'", "FIELDFLOW_VERSION', '2.2.88'")
fp.write_text(s)
readme=Path('/mnt/data/ff_fix/fieldflow_src/readme.txt')
s=readme.read_text()
s=s.replace('Stable tag: 2.2.85','Stable tag: 2.2.88')
readme.write_text(s)
Path('/mnt/data/ff_fix/fieldflow_src/BUILD-2.2.88-CLIENT-PORTAL-CAMPAIGN-PDVS-STABLE.md').write_text('''# FieldFlow 2.2.88, Client Portal Campaign PDVs Stable\n\nBase usada: 2.2.85, preservando rotas, relatórios, base, Growth Hub e analytics.\n\n## Alterações\n- Nova aba Campanha PDV no shortcode [fieldflow_client_portal].\n- Listagem de PDVs por cliente/campanha com fallback para todas as campanhas permitidas no âmbito do utilizador.\n- Edição inline das regras principais e avançadas de campanha PDV.\n- Guardar alterações por REST, sem tocar no editor de rotas existente.\n- Botão Ver rotas filtra a loja e abre a aba Rotas.\n- KPIs de PDVs, ativos, owners, coordenadas, rotas já criadas e kms.\n- Botão Atualizar PDVs recarrega a listagem.\n\n## Segurança\nA geração automática de rotas continua no BO Campanhas PDV para evitar alterações acidentais feitas pelo cliente.\n''')
