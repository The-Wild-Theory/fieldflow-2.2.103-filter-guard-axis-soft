<?php
namespace RoutesPro\Front;

class AppShortcodeRenderer {
    public static function app($atts = []) {
        $guard = \RoutesPro\Shortcodes::front_guard();
        if ($guard) return $guard;
        if (class_exists('\\RoutesPro\\PWA\\InstallPrompt')) { \RoutesPro\PWA\InstallPrompt::markAppRendered(); }
        $theme = \RoutesPro\Shortcodes::front_theme();
        $visible_primary = ($theme['primary'] === 'transparent') ? '#7c3aed' : $theme['primary'];
        $visible_accent  = ($theme['accent'] === 'transparent') ? '#0ea5e9' : $theme['accent'];
        $user = wp_get_current_user();
        $today = date('Y-m-d');
        $today_label = date_i18n('d/m/Y');
        $can_manage = current_user_can('routespro_manage');
        $can_scope = \RoutesPro\Support\Permissions::can_access_front();
        $scope = \RoutesPro\Support\Permissions::get_scope();
        $scope_client_ids = array_values(array_filter(array_map('absint', (array)($scope['client_ids'] ?? []))));
        $scope_project_ids = array_values(array_filter(array_map('absint', (array)($scope['project_ids'] ?? []))));
        $initial_client_id = absint($atts['client_id'] ?? ($_GET['client_id'] ?? 0));
        $initial_project_id = absint($atts['project_id'] ?? ($_GET['project_id'] ?? 0));
        if (!$initial_client_id && count($scope_client_ids) === 1) $initial_client_id = (int)$scope_client_ids[0];
        if (!$initial_project_id && count($scope_project_ids) === 1) $initial_project_id = (int)$scope_project_ids[0];
        $message_id = absint($_GET['routespro_message'] ?? 0);
        ob_start(); ?>
    <style>
    .rp-premium-shell{--rp-primary:<?php echo esc_attr($theme['primary']); ?>;--rp-accent:<?php echo esc_attr($theme['accent']); ?>;--rp-primary-solid:<?php echo esc_attr($visible_primary); ?>;--rp-accent-solid:<?php echo esc_attr($visible_accent); ?>;--rp-font:<?php echo esc_attr($theme['font']); ?>;--rp-size:<?php echo esc_attr($theme['size']); ?>px;font-family:var(--rp-font);font-size:var(--rp-size);color:#0f172a}
    .rp-premium-shell *{box-sizing:border-box}
    .rp-premium-shell .rp-shell{max-width:min(1520px,96vw);margin:0 auto;padding:18px 16px 104px}
    .rp-premium-shell .rp-hero{position:relative;overflow:hidden;background:radial-gradient(circle at top right,rgba(255,255,255,.18),transparent 28%),linear-gradient(135deg,#081120 0%,#162338 52%,<?php echo esc_attr($theme['primary']); ?> 120%);color:#fff;border-radius:30px;padding:28px;box-shadow:0 28px 70px rgba(15,23,42,.2)}
    .rp-premium-shell .rp-hero-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:18px;align-items:end}
    .rp-premium-shell .rp-eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.09em;opacity:.82;margin-bottom:8px}
    .rp-premium-shell .rp-hero h2{margin:0 0 10px;font-size:36px;line-height:1.02;font-weight:800;color:#fff}
    .rp-premium-shell .rp-hero p{margin:0;max-width:760px;opacity:.92;font-size:16px;line-height:1.6;color:#fff}
    .rp-premium-shell .rp-mini{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    .rp-premium-shell .rp-mini span{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);color:#fff;border-radius:999px;padding:8px 12px;font-weight:700;border:1px solid rgba(255,255,255,.14)}
    .rp-premium-shell .rp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:18px}
    .rp-premium-shell .rp-stat{background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.14);border-radius:20px;padding:16px;min-height:108px}
    .rp-premium-shell .rp-stat span,.rp-premium-shell .rp-stat small{color:rgba(255,255,255,.8)}
    .rp-premium-shell .rp-stat strong{display:block;font-size:26px;line-height:1.04;margin:6px 0;color:#fff}
    .rp-premium-shell .rp-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:16px}
    .rp-premium-shell .rp-card{background:#fff;border:1px solid #e2e8f0;border-radius:24px;box-shadow:0 16px 40px rgba(15,23,42,.08);padding:20px}
    .rp-premium-shell .rp-kicker{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:8px}
    .rp-premium-shell .rp-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:14px;padding:12px 16px;background:var(--rp-primary);color:#fff !important;font-weight:700;text-decoration:none;cursor:pointer}
    .rp-premium-shell .rp-cta.alt{background:var(--rp-accent)}
    .rp-premium-shell .rp-cta.ghost{background:#fff;color:#0f172a !important;border:1px solid #cbd5e1}
    .rp-premium-shell .rp-pill{display:inline-flex;align-items:center;gap:8px;border-radius:999px;background:#eff6ff;color:#0f172a;padding:7px 12px;font-size:12px;font-weight:700;border:1px solid #dbeafe}
    .rp-premium-shell .rp-panels{margin-top:18px}
    .rp-premium-shell .rp-panel{display:none}
    .rp-premium-shell .rp-panel.active{display:block}
    .rp-premium-shell .rp-panel .rp-premium-card{background:#fff;border:1px solid #e2e8f0;border-radius:26px;box-shadow:0 16px 40px rgba(15,23,42,.08);padding:16px}
    .rp-premium-shell .rp-panel-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}
    .rp-premium-shell .rp-panel-head h3{margin:0;font-size:24px;color:#0f172a}
    .rp-premium-shell .rp-panel-head p{margin:6px 0 0;color:#64748b;max-width:720px}
    .rp-premium-shell .rp-tabs{position:sticky;bottom:14px;z-index:25;margin-top:18px;display:block;padding-bottom:max(0px, env(safe-area-inset-bottom))}
    .rp-premium-shell .rp-tabbar{display:flex;gap:8px;background:rgba(15,23,42,.94);padding:10px;border-radius:22px;box-shadow:0 20px 48px rgba(15,23,42,.3);overflow:auto;-webkit-overflow-scrolling:touch}
    .rp-premium-shell .rp-tabbar button{flex:1 1 0;min-width:118px;border:0;border-radius:16px;padding:12px 10px;background:transparent;color:#cbd5e1;font-weight:700;cursor:pointer;white-space:nowrap}
    .rp-premium-shell .rp-tabbar button.active{background:#fff;color:#0f172a}
    .rp-premium-shell .rp-shell .rp-panel[data-panel="route"] #routespro-my-daily-route{max-width:none;width:100%}
    .rp-premium-shell .rp-shell .rp-panel[data-panel="route"] .rp-wrap{border-radius:22px;box-shadow:none;border-color:#e2e8f0;background:#fff}
    .rp-premium-shell .rp-shell .rp-panel[data-panel="commercial"] .rp-front-commercial .rp-card,
    .rp-premium-shell .rp-shell .rp-panel[data-panel="discovery"] .rp-front-routes .rp-card,
    .rp-premium-shell .rp-shell .rp-panel[data-panel="report"] .rp-report-quick .rp-card,
    .rp-premium-shell .rp-shell .rp-panel[data-panel="analytics"] .routespro-dashboard .card{box-shadow:none}
    .rp-premium-shell .rp-panel[data-panel="academy"] .ffp-academy{margin:0;background:transparent;padding:0}.rp-premium-shell .rp-panel[data-panel="academy"] .ffp-academy-head{box-shadow:none}
    .rp-premium-shell .rp-cta,.rp-premium-shell .rp-shell .rp-btn{background:var(--rp-primary-solid);color:#fff !important;border-color:var(--rp-primary-solid)}
    .rp-premium-shell .rp-cta.alt,.rp-premium-shell .rp-shell .rp-btn.alt,.rp-premium-shell .rp-shell .rp-btn.secondary{background:var(--rp-accent-solid);border-color:var(--rp-accent-solid);color:#fff !important}
    .rp-premium-shell .rp-cta.ghost,.rp-premium-shell .rp-shell .rp-btn.ghost{background:#fff;color:#0f172a !important;border:1px solid #cbd5e1}
    .rp-premium-shell .rp-shell .rp-panel[data-panel="commercial"] #rpc-role{display:none !important}
    @media (max-width:1100px){.rp-premium-shell .rp-hero-grid,.rp-premium-shell .rp-grid,.rp-premium-shell .rp-stats{grid-template-columns:1fr 1fr}}
    @media (max-width:760px){.rp-premium-shell .rp-shell{padding:14px 12px 108px}.rp-premium-shell .rp-hero h2{font-size:28px}.rp-premium-shell .rp-hero-grid,.rp-premium-shell .rp-grid,.rp-premium-shell .rp-stats{grid-template-columns:1fr}}
    </style>
    <div class="rp-premium-shell rp-app" id="routespro-app"
         data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
         data-today="<?php echo esc_attr($today); ?>"
         data-client-id="<?php echo esc_attr($initial_client_id); ?>"
         data-project-id="<?php echo esc_attr($initial_project_id); ?>"
         data-message-id="<?php echo esc_attr($message_id); ?>">
      <div class="rp-shell">
        <section class="rp-hero">
          <div class="rp-hero-grid">
            <div>
              <div class="rp-eyebrow">FieldFlow App</div>
              <h2>Frontoffice premium, alinhado com rota, base comercial e reporte.</h2>
              <p>O objetivo aqui é simples, fechar o plugin com uma experiência final de produto. A operação diária fica numa shell premium, coerente com o backoffice, rápida em mobile e forte em execução.</p>
              <div class="rp-mini">
                <span>Minha Rota com foco de execução</span>
                <span>Base Comercial com autocomplete</span>
                <span>Relatórios e analytics no mesmo fluxo</span>
              </div>
            </div>
            <div class="rp-card" style="background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18);color:#fff">
              <div class="rp-kicker" style="color:rgba(255,255,255,.75)">Utilizador</div>
              <div style="font-size:22px;font-weight:800;margin-bottom:6px;color:#fff"><?php echo esc_html($user->display_name ?: $user->user_login); ?></div>
              <div style="opacity:.85;margin-bottom:16px;color:#fff"><?php echo esc_html($today_label); ?></div>
              <div class="rp-mini" style="margin-top:0">
                <a class="rp-cta" href="#rp-app-route">Começar rota</a>
                <a class="rp-cta alt" href="#rp-app-commercial">Abrir base</a>
              </div>
            </div>
          </div>
          <div class="rp-stats" id="rpa-kpis">
            <div class="rp-stat"><span>Rotas de hoje</span><strong id="rpa-routes"><?php echo esc_html($today); ?></strong><small id="rpa-routes-note">A carregar operação do dia.</small></div>
            <div class="rp-stat"><span>Paragens</span><strong id="rpa-stops">...</strong><small id="rpa-stops-note">Resumo da execução diária.</small></div>
            <div class="rp-stat"><span>Base comercial</span><strong id="rpa-commercial">...</strong><small id="rpa-commercial-note">PDVs disponíveis no teu âmbito.</small></div>
            <div class="rp-stat"><span>Modo</span><strong id="rpa-mode"><?php echo $can_manage ? 'Gestão e execução' : ($can_scope ? 'Âmbito restrito' : 'Execução'); ?></strong><small id="rpa-mode-note">Tabs prontas para frontoffice moderno.</small></div>
          </div>
        </section>
    
        <section class="rp-grid">
          <article class="rp-card">
            <div class="rp-kicker">Hoje</div>
            <h3 style="margin:0 0 8px;color:#0f172a">Minha Rota</h3>
            <p style="margin:0 0 14px;color:#475569">Agenda do dia, ordem das paragens, estado, navegação e ações rápidas.</p>
            <button class="rp-cta" data-target="route">Abrir rota</button>
          </article>
          <article class="rp-card">
            <div class="rp-kicker">Prospecção</div>
            <h3 style="margin:0 0 8px;color:#0f172a">Descobrir PDVs</h3>
            <p style="margin:0 0 14px;color:#475569">Descoberta, construção de rota e enriquecimento da base comercial.</p>
            <button class="rp-cta alt" data-target="discovery">Abrir descoberta</button>
          </article>
          <article class="rp-card">
            <div class="rp-kicker">Base Comercial</div>
            <h3 style="margin:0 0 8px;color:#0f172a">Pesquisar e editar</h3>
            <p style="margin:0 0 14px;color:#475569">Base frontal com mapa, filtros, autocomplete e edição imediata de PDVs.</p>
            <button class="rp-cta ghost" data-target="commercial">Abrir base</button>
          </article>
          <article class="rp-card">
            <div class="rp-kicker">Relatórios</div>
            <h3 style="margin:0 0 8px;color:#0f172a">Reporte rápido</h3>
            <p style="margin:0 0 14px;color:#475569">Check-in, notas, falhas e fecho da visita, tudo sem fricção.</p>
            <button class="rp-cta" data-target="report">Abrir reporte</button>
          </article>
          <article class="rp-card">
            <div class="rp-kicker">Mensagens</div>
            <h3 style="margin:0 0 8px;color:#0f172a">Inbox operacional</h3>
            <p style="margin:0 0 14px;color:#475569">Mensagens recebidas, resposta rápida e fecho do ciclo operacional sem sair da app.</p>
            <button class="rp-cta alt" data-target="messages">Abrir mensagens</button>
          </article>
        </section>
    
        <div class="rp-panels">
          <div class="rp-panel active" data-panel="route" id="rp-app-route">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Minha Rota</h3><p>Layout premium para a operação diária, mantendo toda a lógica já funcional do shortcode base.</p></div><span class="rp-pill">Execução do dia</span></div>
              <?php echo do_shortcode('[fieldflow_route_today]'); ?>
            </div>
          </div>
          <div class="rp-panel" data-panel="discovery" id="rp-app-discovery">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Descobrir</h3><p>Construção de rota, descoberta de novos PDVs e análise de cobertura, dentro da mesma shell visual.</p></div><span class="rp-pill">Prospecção</span></div>
              <?php echo do_shortcode('[fieldflow_discovery]'); ?>
            </div>
          </div>
          <div class="rp-panel" data-panel="report" id="rp-app-report">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Reportar</h3><p>Fecho de visita em poucos passos, desenhado para uso real em telemóvel no terreno.</p></div><span class="rp-pill">Check-in e fecho</span></div>
              <?php echo do_shortcode('[fieldflow_report_visit]'); ?>
            </div>
          </div>
          <div class="rp-panel" data-panel="commercial" id="rp-app-commercial">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Base Comercial</h3><p>Autocomplete ativo na morada e na pesquisa, mapa premium, edição rápida e gravação imediata na base.</p></div><span class="rp-pill">Autocomplete e mapa</span></div>
              <?php echo do_shortcode('[fieldflow_front_commercial]'); ?>
            </div>
          </div>

          <div class="rp-panel" data-panel="academy" id="rp-app-academy">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Academy e Missões</h3><p>Formação prática, cursos curtos e missões operacionais para a equipa de terreno.</p></div><span class="rp-pill">Academy</span></div>
              <?php echo do_shortcode(sprintf('[fieldflow_academy client_id="%d" project_id="%d"]', $initial_client_id, $initial_project_id)); ?>
            </div>
          </div>
          <div class="rp-panel" data-panel="messages" id="rp-app-messages">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Mensagens</h3><p>Inbox operacional para owners, com resposta, estados e ligação direta ao histórico no sistema.</p></div><span class="rp-pill">Workflow</span></div>
              <?php
                echo do_shortcode(sprintf(
                  '[fieldflow_team_inbox client_id="%d" project_id="%d"]',
                  $initial_client_id,
                  $initial_project_id
                ));
              ?>
            </div>
          </div>
          <?php if ($can_manage || $can_scope): ?>
          <div class="rp-panel" data-panel="analytics" id="rp-app-analytics">
            <div class="rp-premium-card">
              <div class="rp-panel-head"><div><h3>Analytics</h3><p>Leitura rápida de performance, execução e produtividade, sem sair do frontoffice premium.</p></div><span class="rp-pill">Gestão</span></div>
              <?php echo do_shortcode(sprintf('[fieldflow_dashboard client_id="%d" project_id="%d"]', $initial_client_id, $initial_project_id)); ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
    
        <div class="rp-tabs">
          <div class="rp-tabbar">
            <button type="button" class="active" data-panel="route">Minha Rota</button>
            <button type="button" data-panel="discovery">Descobrir</button>
            <button type="button" data-panel="report">Reportar</button>
            <button type="button" data-panel="commercial">Base</button>
            <button type="button" data-panel="academy">Academy</button>
            <button type="button" data-panel="messages">Mensagens</button>
            <?php if ($can_manage || $can_scope): ?><button type="button" data-panel="analytics">Analytics</button><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <script>
    (async function(){
      const root=document.getElementById('routespro-app');if(!root)return;
      const nonce=root.dataset.nonce||'';
      const api='<?php echo esc_url(rest_url('routespro/v1/')); ?>';
      const today=root.dataset.today||'<?php echo esc_js($today); ?>';
      const initialClientId=root.dataset.clientId||'';
      const initialProjectId=root.dataset.projectId||'';
      const initialMessageId=root.dataset.messageId||'';
      const $=sel=>root.querySelector(sel);
      const ensureTableScroll=(scope)=>{
        (scope||root).querySelectorAll('table').forEach(table=>{
          if(table.closest('.rp-table-wrap,.rp-table-scroll')) return;
          const wrap=document.createElement('div');
          wrap.className='rp-table-scroll';
          wrap.style.display='block';
          wrap.style.width='100%';
          wrap.style.maxWidth='100%';
          wrap.style.overflowX='auto';
          wrap.style.overflowY='hidden';
          wrap.style.webkitOverflowScrolling='touch';
          table.parentNode.insertBefore(wrap, table);
          wrap.appendChild(table);
          if(!table.style.minWidth) table.style.minWidth='980px';
        });
      };
      const panelScopeDetail=()=>({client_id:initialClientId||'',project_id:initialProjectId||'',message_id:initialMessageId||''});
const pingPanel=(target,key)=>{
  if(!target) return;

  const detail = Object.assign({panel:key}, panelScopeDetail());

  const ev = ()=>new CustomEvent('routespro:panel-open',{detail});

  // dispara no próprio painel
  target.dispatchEvent(ev());

  // dispara em filhos relevantes
  target.querySelectorAll('.rp-front-routes,.rp-front-commercial,.rp-client-portal,#routespro-my-daily-route,.routespro-dashboard,.rp-team-inbox').forEach(el=>{
    el.dispatchEvent(ev());
    el.dispatchEvent(new CustomEvent('routespro:scope-change',{detail}));
  });

  // fallback global
  document.dispatchEvent(new CustomEvent('routespro:panel-open',{detail}));
  document.dispatchEvent(new CustomEvent('routespro:scope-change',{detail}));

  ensureTableScroll(target);
};
      const validPanel=(key)=>!!root.querySelector('.rp-panel[data-panel="'+key+'"]');
      const rememberPanel=(key)=>{try{sessionStorage.setItem('fieldflow_active_panel',key);}catch(e){}};
      const openPanel=(key,opts={})=>{
        if(!validPanel(key)) key='route';
        root.querySelectorAll('.rp-tabbar button').forEach(btn=>btn.classList.toggle('active',btn.dataset.panel===key));
        root.querySelectorAll('.rp-panel').forEach(panel=>panel.classList.toggle('active',panel.dataset.panel===key));
        if(opts.remember!==false) rememberPanel(key);
        const target=root.querySelector('.rp-panel[data-panel="'+key+'"]');
        if(target){
          pingPanel(target,key);
          setTimeout(()=>target.scrollIntoView({behavior:'smooth',block:'start'}),10);
        }
      };
      const setMetric=(base,strongText,smallText)=>{
        const strong=$('#'+base);
        const note=$('#'+base+'-note');
        if(strong&&strongText!=null) strong.textContent=String(strongText);
        if(note&&smallText!=null) note.textContent=String(smallText);
      };
      const j=async(url,opts={})=>{
        const res=await fetch(url,Object.assign({credentials:'same-origin',headers:{'X-WP-Nonce':nonce}},opts));
        const text=await res.text();
        let data={};
        try{data=text?JSON.parse(text):{}}catch(_){data={message:text}}
        if(!res.ok) throw new Error(data.message||'Falha no pedido.');
        return data;
      };
      const loadOverview=async()=>{
        try{
          const [routesData,statsData,commercialData]=await Promise.all([
            j(api+'routes?user_id=me&date='+encodeURIComponent(today)).catch(()=>({routes:[]})),
            j(api+'stats?from='+encodeURIComponent(today)+'&to='+encodeURIComponent(today)).catch(()=>({})),
            j(api+'commercial-search?page=1&per_page=1'+(initialClientId?'&client_id='+encodeURIComponent(initialClientId):'')+(initialProjectId?'&project_id='+encodeURIComponent(initialProjectId):'')).catch(()=>({stats:{}}))
          ]);
          const routes=Array.isArray(routesData.routes)?routesData.routes:[];
          let totalStops=0,doneStops=0;
          const details=await Promise.all(routes.slice(0,12).map(route=>j(api+'routes/'+route.id).catch(()=>null)));
          details.forEach(route=>{
            const stops=Array.isArray(route&&route.stops)?route.stops:[];
            totalStops+=stops.length;
            doneStops+=stops.filter(stop=>['done','completed'].includes(String(stop.status||'').toLowerCase())).length;
          });
          setMetric('rpa-routes',routes.length,routes.length?('Rotas atribuídas para '+today+'.'):('Sem rotas atribuídas para hoje.'));
          setMetric('rpa-stops',totalStops?`${doneStops}/${totalStops}`:'0/0',totalStops?('Paragens concluídas dentro da tua operação diária.'):('Sem paragens carregadas neste momento.'));
          const cs=(commercialData&&commercialData.stats)||{};
          setMetric('rpa-commercial',cs.total_visible||0,`Base visível, ${cs.validated_count||0} validados e ${cs.with_coords||0} com coordenadas.`);
          const completion=(statsData&&typeof statsData.done_rate!=='undefined')?Number(statsData.done_rate||0):null;
          setMetric('rpa-mode',$('#rpa-mode')?$('#rpa-mode').textContent:'<?php echo $can_manage ? 'Gestão e execução' : ($can_scope ? 'Âmbito restrito' : 'Execução'); ?>',completion===null?'Tabs prontas para frontoffice moderno.':('Execução do dia em '+completion+'% no âmbito atual.'));
        }catch(_){
          setMetric('rpa-routes','n.d.','Não foi possível carregar o resumo operacional.');
          setMetric('rpa-stops','n.d.','Tenta atualizar a página para recarregar os dados.');
          setMetric('rpa-commercial','n.d.','Resumo comercial indisponível de momento.');
        }
      };
      root.querySelectorAll('.rp-tabbar button,[data-target]').forEach(btn=>btn.addEventListener('click',()=>openPanel(btn.dataset.panel||btn.dataset.target||'route')));
      const qs=new URLSearchParams(window.location.search);
      const panelFromQuery=qs.get('ffp_panel')||qs.get('panel')||'';
      const panelFromHash=(window.location.hash==='#rp-app-academy')?'academy':((window.location.hash==='#rp-app-messages')?'messages':((window.location.hash||'').replace('#rp-app-','')));
      let panelFromStorage='';
      try{ panelFromStorage=sessionStorage.getItem('fieldflow_active_panel')||''; }catch(e){}
      const initialPanel=panelFromQuery||panelFromHash||panelFromStorage||((initialMessageId)?'messages':'route');
      const applyInitial=()=>openPanel(initialPanel,{remember:false});
      setTimeout(applyInitial,60);
      setTimeout(applyInitial,240);
      window.addEventListener('load',()=>setTimeout(applyInitial,120));
      await loadOverview();
    })();
    </script>
    <?php
        return ob_get_clean();
    }


}
