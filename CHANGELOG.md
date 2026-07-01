# 2.2.103 - Filter Guard + Axis Soft

- Corrigido motor de sugestao para respeitar filtros visiveis do BO: pesquisa, categoria, estado, ativo e merchandiser.
- Corrigida chave de sugestao gravada para separar planos por filtros ativos.
- Suavizado Route Axis Lock quando a estrategia nao e `Corredor partida/chegada`.
- Ajustado editor direto para evitar sugestoes demasiado rigidas por eixo.

## 2.2.102, Route Axis Lock
- Adicionado bloqueio suave por eixo da morada base para evitar rotas que misturam norte/sul no mesmo dia.
- Quando partida e chegada são iguais, o motor passa a classificar lojas por lado da base, norte, sul, litoral e interior.
- Reforçada a penalização de corredores opostos no planeamento e no editor direto da sugestão.
- Cache interna atualizada para routing_intelligence_v10_route_axis_lock.


## 2.2.101, Geo Routing Brain

- Reforcado o motor de sugestao de rotas com score geografico transversal.
- Evitados mega-clusters criados por proximidade em cadeia.
- Fases finais de balanceamento passam a proteger dias geograficamente coerentes.
- Editor direto da sugestao passa a usar geografia no botao "Sugerir melhor dia".
- Cache interna do plano atualizada para routing_intelligence_v9_geo_routing_brain.

## 2.2.100 - Plan Editor Safe Rules Fix

- Corrige regressão da 2.2.99 que podia mostrar/usar apenas um dia no planeamento.
- Mantém o gerador automático mensal como na 2.2.98.
- Reforça validação do editor direto da sugestão sem quebrar a distribuição mensal.

## 2.2.91
- Corrige botões Atualizar PDVs e Guardar alterações na aba Campanha PDV do portal cliente.
- Adiciona feedback visual e fallback de gravação das linhas visíveis.

# 2.2.82, Routing Intelligence

- Adicionado Day Balancer v2 para distribuir melhor visitas dentro da mesma semana.
- Adicionado diagnostico de plano na aba Planeamento.
- Adicionados indicadores de dias leves, dias longos, mistura de zonas/corredores e visitas por encaixar.
- Classificacao automatica do tipo de rota por dia.
- Atualizada chave de cache do planeamento para regenerar sugestoes com o novo motor.

## 2.2.54 - Recovery Stable Build

- Build reconstruída a partir da versão 2.2.51 fornecida pelo utilizador, por ser a última versão confirmada como funcional.
- Sem alterações estruturais arriscadas no BO da Academy.
- Sintaxe PHP validada antes de empacotar.

## 2.2.32 - Dual PWA operativo e cliente
- Adicionado suporte PWA separado para [fieldflow_app] e [fieldflow_client_portal].
- Backoffice passou a configurar página, identidade, cores, ícones, textos e links da App Operativo e da App Cliente.
- Manifest dinâmico agora suporta profile operative/client com start_url e id próprios.
- O convite de instalação passa a aparecer também no portal cliente.


## 2.2.31 - PWA reference parity
- Refeita a mecânica PWA para replicar o padrão do plugin de referência funcional.
- Manifest sem crossorigin e com estrutura simples validada para Chrome.
- Service worker mínimo, network-first, sem fallback agressivo.
- Prompt de instalação simplificado para depender diretamente do beforeinstallprompt nativo.


## 2.2.31 - PWA installability hardening
- Registo antecipado do service worker no head para o Chrome validar a PWA antes do clique.
- Scope do service worker calculado a partir do site WordPress, compatível com instalações em subdiretório.
- Manifest ajustado com ícones any/maskable separados e id estável baseado na página da app.
- Fallback Android/Chrome mais correto quando o browser ainda não disponibiliza beforeinstallprompt.

# Changelog

## 2.2.29
- Corrige captura do prompt nativo PWA em Chrome/Edge, guardando o evento `beforeinstallprompt` cedo no `<head>`.
- Evita cair automaticamente nas instruções manuais em browsers Chromium quando o prompt nativo ainda está a inicializar.
- Adiciona diagnóstico visual discreto para Chrome quando a app ainda não cumpre os critérios de instalação.

## 2.2.28
- Ajustada mecânica PWA para ficar alinhada com o plugin de referência: botão mantém “Instalar app” também em Safari/iOS.
- Em browsers com suporte ao evento nativo, o botão chama o prompt de instalação.
- Em Safari/iOS, onde o browser não permite instalação automática por clique, o mesmo botão abre instruções manuais contextuais.
- Removida a alteração dinâmica para “Ver instruções”, evitando a sensação de comportamento diferente do plugin de referência.

# 2.2.24
- Estabilização de emergência do licenciamento remoto.
- O botão de validação deixa de chamar o endpoint Azure instável e confirma o estado local ativo.
- Erros técnicos remotos deixam de transformar uma licença ativa em inválida.
- Avisos administrativos remotos só aparecem quando a licença não está ativa.

# 2.2.23
- Limpeza visual do estado de licenciamento remoto: quando a licença está ativa por fallback válido, erros técnicos do endpoint validate deixam de aparecer como erro vermelho nas observações.
- Mantém o detalhe técnico em nota neutra para diagnóstico, sem assustar o BO com falso negativo.

# 2.2.22

- Licenciamento remoto: evita invalidar uma licença já ativa quando o endpoint Azure `/license/validate` devolve erro 500 ou resposta inválida.
- Licenciamento remoto: trata respostas de limite de ativações como licença operacionalmente ativa quando a ativação já existe.
- Licenciamento remoto: adiciona mensagem operacional para validação remota indisponível, preservando a última ativação válida.

# 2.2.21
- Adiciona Admin Secret separado ao licenciamento remoto Azure.
- Envia Admin Secret em headers e payload compatíveis ao gerar chave remota.
- Mantém Shared Secret apenas para assinatura HMAC.

## 2.2.19
- Corrige licenciamento remoto para preservar maiúsculas/minúsculas nas chaves remotas.
- Evita normalização em uppercase no modo remoto, prevenindo falhas HMAC e validações com tokens case-sensitive.
- Preserva o Shared Secret remoto exatamente como introduzido nas Settings.

# Changelog

## 2.2.29
- Corrige captura do prompt nativo PWA em Chrome/Edge, guardando o evento `beforeinstallprompt` cedo no `<head>`.
- Evita cair automaticamente nas instruções manuais em browsers Chromium quando o prompt nativo ainda está a inicializar.
- Adiciona diagnóstico visual discreto para Chrome quando a app ainda não cumpre os critérios de instalação.

## 2.2.17
- Analytics passa a ter dashboards configuráveis no Centro de Atribuições.
- Adicionados grupos manuais de lojas para métricas por loja, grupos de lojas ou total do âmbito.
- Widgets analíticos suportam KPI, gráfico, tabela, empty state, ordem e associação a dashboard.
- O portal cliente mostra dashboards e tabelas mesmo sem respostas no período selecionado.
- Exportações CSV, Excel e PDF passam a incluir a secção analítica configurada.

## 2.2.1-phase4-geo-cluster-engine
- Criado motor geográfico operacional com clusters por coordenadas, centro, dispersão, densidade e distância de acesso/regresso.
- Reforçada a capacidade dinâmica por cluster, separando acesso à zona, miolo local e regresso.
- Ajustado o scoring de alocação para favorecer rotas longas mas densas e penalizar rotas longas e dispersas.
- P1 passa a beneficiar de ordenação por cluster geográfico, mantendo a periodicidade e sem criar visitas extra quando a opção está desligada.
- Diagnóstico operacional passa a distinguir rota longa densa, rota longa dispersa e recomendações de divisão/agrupamento.

## 2.2.0
- camada remota de licenciamento Azure no plugin
- cliente remoto com assinatura HMAC
- ativação, validação e desativação remota
- modo local ou remoto configurável em Settings
- blueprint Azure com schema SQL e sample de Azure Function

## 2.1.1
- Hotfix da geração de chaves locais para ambientes sem `wp_generate_uuid4`, evitando erro crítico ao gerar licença no backoffice.

## 2.1.0
- Licenciamento local reforçado com geração de chave, ligação ao domínio atual e estado detalhado no backoffice.
- Settings com ações de gerar, ativar e desativar licença.
- Health check e notices de admin passam a validar mismatch de domínio da licença.


## 2.0.0
- adicionada base de licenciamento local para produto comercial
- adicionados notices internos de admin para configuração e health
- adicionada camada simples de feature flags
- reforçado health check com estado de licença e documentação comercial
- adicionados scripts de smoke test e release build
- adicionada documentação técnica e documentação de utilizador


## 1.4.3-phase10c
- Hotfix no shortcode `[fieldflow_client_portal]` > aba Equipa.
- O campo `Enviar para` volta a filtrar por operativos reais do projeto.
- Users apenas associados ao projeto deixam de aparecer por defeito, a menos que tenham perfil/capacidade operacional.
- Users novos com perfil operacional e campanha atribuída continuam visíveis no bloco de envio.

## 1.4.2-phase10b
- Corrigido o bloco "Enviar para" no shortcode `[fieldflow_client_portal]` / `[fieldflow_client_team_mail]` para incluir também utilizadores apenas associados à campanha, mesmo quando ainda não têm execução, rota ou assignment operacional registado.
- Mantida a lógica anterior de destinatários, mas sem excluir novos users recém-criados e já atribuídos ao cliente/projeto.

## 1.4.1-phase10a
- Corrigido o filtro da aba Equipa no shortcode fieldflow_client_portal para mostrar todos os operativos relevantes da campanha em "Enviar para".
- Corrigido o carregamento do histórico na aba Equipa, agora filtrado por campanha e operativo selecionado.

## 1.4.0-phase10
- centralizado o bootstrap do plugin com `RoutesPro\Support\Loader`
- adicionados checks de runtime e pacote comercial ao ecrã de Saúde do sistema
- criado `scripts/qa-smoke.php` para validação rápida e lint do pacote
- criado `scripts/release-build.sh` para gerar ZIP limpo de distribuição

# Changelog

## 2.2.29
- Corrige captura do prompt nativo PWA em Chrome/Edge, guardando o evento `beforeinstallprompt` cedo no `<head>`.
- Evita cair automaticamente nas instruções manuais em browsers Chromium quando o prompt nativo ainda está a inicializar.
- Adiciona diagnóstico visual discreto para Chrome quando a app ainda não cumpre os critérios de instalação.

## 1.3.0-phase9
- extraídos os shortcodes mais pesados do front para renderers dedicados, reduzindo acoplamento em `src/Shortcodes.php`
- criado `RouteAccessRepository` para centralizar a listagem e o scoping de rotas
- criado `CampaignLocationRepository` para isolar a query principal dos locais ligados a campanhas
- introduzida a infraestrutura de migrações versionadas em `src/Support/Migrations.php`
- adicionados índices de performance para rotas, assignments, campaign_locations, route_stops e system_logs

## 1.2.0-phase8
- Adicionada tabela de logs técnicos `routespro_system_logs` para troubleshooting e upgrades.
- Novo logger interno reutilizável para manutenção e suporte premium.
- Novo submenu admin `Logs técnicos` com limpeza manual de registos antigos.
- Saúde do sistema passou a validar a disponibilidade da camada de logging.
- Upgrade automático passou a registar eventos técnicos de atualização de schema.

## 1.1.1-phase7a
- Correção do filtro de rotas em `user_id=me`, para devolver apenas rotas do próprio utilizador ou atribuídas diretamente, sem alargar por cliente/campanha.
- Correção do filtro de descoberta de PDVs no menu Rotas do BO, passando a respeitar `client_id`, `project_id` e `owner_user_id`.

# Changelog

## 2.2.29
- Corrige captura do prompt nativo PWA em Chrome/Edge, guardando o evento `beforeinstallprompt` cedo no `<head>`.
- Evita cair automaticamente nas instruções manuais em browsers Chromium quando o prompt nativo ainda está a inicializar.
- Adiciona diagnóstico visual discreto para Chrome quando a app ainda não cumpre os critérios de instalação.

## 1.0.0-phase6
- Refactor do bootstrap principal para uma camada central `RoutesPro\Support\Plugin`, reduzindo acoplamento no ficheiro principal do plugin.
- Introdução da camada `RoutesPro\Support\Config` para centralizar defaults, constantes secretas, leitura e persistência de settings.
- Introdução da camada `RoutesPro\Support\Request` para normalização de leitura de inputs e nonces em pontos críticos do admin.
- Introdução da camada `RoutesPro\Support\AdminPage` para unificar abertura, fecho e estrutura base de páginas de admin.
- Refactor da página de Settings para depender da nova camada de configuração e request handling, mantendo a lógica existente.
- Refactor dos providers de IA e mapas para deixarem de depender diretamente da classe de admin `Settings`.
- Refactor da página principal de menu e importação legacy para wrappers de admin mais consistentes.

## 0.9.99-phase5
- Hardening do endpoint público de pesquisa PDV com controlo mínimo de abuso, validação de pesquisa e fallback seguro quando a tabela não existe.
- Remoção de ficheiros `.bak` e variantes de histórico da distribuição final.
- Endurecimento do upload de provas com verificação opcional de nonce, validação de tamanho e validação real de MIME.
- Remoção do fallback de token por query string na API de integrações.
- Inclusão de `readme.txt`, `uninstall.php`, `languages/` e `AUDITORIA-PRODUTO.md` para distribuição mais limpa.

## 1.1.0-phase7
- nova página de Saúde do sistema com checks críticos de ambiente, uploads, schema e configuração
- novo serviço de diagnóstico técnico centralizado em Support/SystemHealth
- novo repository para validação de schema e contagens de tabelas nucleares
- dashboard principal passa a mostrar estado técnico resumido
- base preparada para próximos refactors pesados em ficheiros monolíticos

## 1.4.4-phase10d
- Corrige a lista "Enviar para" no portal do cliente para usar apenas os utilizadores com acesso ao projeto definidos no Centro de Atribuições, aba Projetos, excluindo responsáveis ativos e outros utilizadores herdados.
- Corrige o histórico da aba Equipa para mostrar mensagens enviadas e recebidas do operativo selecionado.
- Mostra o estado efetivo da mensagem no histórico, refletindo alterações feitas na app operacional, como em análise e concluído.


## 2.2.26 - PWA Native Prompt alinhado
- Ajustada a PWA para usar a mecânica robusta do plugin de referência.
- Endpoints PWA disponíveis por query string para não dependerem só de permalinks.
- Service worker simplificado para maximizar compatibilidade com o prompt nativo.
- Prompt flutuante premium sem impacto no layout, com fallback Safari/iOS.
- Ícones PWA 192 e 512 gerados a partir do branding do plugin.

## 2.2.25 - App Mobile / PWA Premium
- Adicionado menu FieldFlow > App Mobile / PWA.
- Adicionado manifest dinâmico em /fieldflow-manifest.json.
- Adicionado service worker em /fieldflow-service-worker.js com cache seguro de assets e fallback offline.
- Adicionado fallback offline em /fieldflow-offline.
- Adicionado banner premium de instalação na página da app.
- Adicionado suporte específico para Safari/iOS com instruções de instalação via Partilhar > Adicionar ao Ecrã Principal.
- Adicionadas meta tags Apple/Safari e Android.
- Adicionada configuração de nome, ícones, cores, links, menu, aparência, offline e base técnica para push notifications.
- Mantida compatibilidade com shortcodes e funcionalidades existentes.

## 2.2.98
- Product Matrix com Frentes Antes/Depois com prefill automático por histórico.
- Analytics com crescimento por produto e loja.
- Tabelas e exportações adaptadas a valores before/after.
