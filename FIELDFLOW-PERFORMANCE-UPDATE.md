## 2.2.45 Premium Certificate Layout
- Refeito o template PDF de certificados com grelha premium, logos controlados, selo central e assinatura estabilizada.
- Melhor hierarquia visual, margens e alinhamentos para clientes enterprise.

# FieldFlow Performance Update 2.2.33

Este update adiciona uma camada opcional de performance ao FieldFlow, sem alterar o funcionamento atual de rotas, reportes, clientes, campanhas ou portal existente.

## Backoffice

Novo menu:

FieldFlow > Performance

Inclui:

- Dashboard de performance
- Academy
- Missões Operacionais
- Shortcodes

## Shortcodes

Dashboard premium front:

```text
[fieldflow_performance_dashboard]
```

Dashboard filtrado por cliente e campanha:

```text
[fieldflow_performance_dashboard client_id="1" project_id="2"]
```

Academy front para equipas de terreno:

```text
[fieldflow_academy]
```

Academy filtrada por campanha:

```text
[fieldflow_academy project_id="2"]
```

## Base de dados

Foram adicionadas tabelas próprias:

- routespro_perf_courses
- routespro_perf_lessons
- routespro_perf_missions
- routespro_perf_mission_users
- routespro_perf_lesson_progress

## Filosofia da integração

O FieldFlow continua focado em rotas e reporting. O módulo Performance funciona como camada opcional:

- Pode ser usado por cliente e campanha.
- Pode viver em páginas front específicas.
- Pode evoluir para integração com formulários de reporte.
- Não substitui dashboards existentes, acrescenta uma vista premium.

## Próximas fases recomendadas

1. Integrar missões com submissões de formulários.
2. Adicionar templates de email para missões e Academy.
3. Adicionar certificações verificáveis.
4. Adicionar widgets Performance dentro do portal cliente existente.
5. Adicionar automações do tipo: se resposta X no reporte, atribuir missão Y.


## 2.2.34, Portal tabs + demo course

- Integra as abas Performance e Academy diretamente no portal cliente existente.
- Mantém os shortcodes standalone para uso em páginas isoladas.
- Adiciona botão no BO para criar curso demo: Negociação Comercial no PDV.
- O curso demo inclui lições práticas e uma missão comercial atribuível aos utilizadores da primeira campanha disponível.
- Mantém rotas, reportes, dashboard, clientes e campanhas existentes sem alteração estrutural.


## 2.2.41 Academy Video Ratio Fix
- Corrige embeds YouTube na Academy para proporção responsiva 16:9.
- Evita vídeos demasiado baixos em desktop.
- Mantém comportamento mobile/tablet/desktop.
- PDF passa a usar altura dedicada maior, sem afetar vídeos.


## 2.2.42 Certificate Builder
- Adiciona aba Certificados no BO Performance.
- Permite configurar template por cliente/campanha com cores, texto, logos e assinatura URL.
- Gera certificado PDF A4 horizontal quando o curso está 100% concluído.
- Mostra botão Descarregar certificado PDF na Academy do [fieldflow_app].
- Regista certificados emitidos com ID único.

## 2.2.43 Certificate PDF and Lesson Completion Gate

- Corrigido o PDF de certificados que podia abrir em branco em alguns leitores.
- Corrigida a conversao de cores PDF para valores validos.
- Melhorada compatibilidade de texto acentuado no PDF.
- O botao de conclusao da licao desaparece depois de concluida e passa a mostrar badge de concluido.
- As licoes com conteudo YouTube, Canva, PDF ou link exigem um tempo minimo de consumo antes de desbloquear a conclusao.
- Validacao tambem no servidor para evitar conclusao imediata por clique direto.


## 2.2.44 Certificate Layout & Logo Fix
- Corrigido suporte a logos e assinatura no PDF do certificado.
- O gerador tenta resolver URLs locais de uploads e URLs remotos.
- PNGs são convertidos internamente para JPEG para compatibilidade PDF.
- Layout do certificado mais estável, com header, margens, linhas e área de assinatura controlada.


## 2.2.46 Certificate Encoding + Seal
- Corrigida codificacao WinAnsi no PDF para acentos portugueses.
- Substituido bloco Verified por selo vectorial FieldFlow Verified Certificate.
- Mantido layout premium sem dependencia de imagem externa para o selo.

## 2.2.47 Certificate Seal + Completion State Fix
- Replaced basic square verified mark with a stronger premium vector FieldFlow seal.
- Course completion now counts distinct completed lessons only.
- Academy lesson buttons are hidden whenever the lesson is completed or the course is already at 100%.
- Lesson status query now prioritises completed records to avoid duplicate-progress edge cases.


## 2.2.48 Certificate Campaign Seal
- Adicionado campo de selo por cliente/campanha nos certificados.
- O PDF usa imagem de selo configurada no BO quando disponível.
- Suporte recomendado para PNG transparente, JPG e WebP. SVG é suportado quando o servidor tem Imagick.
- O selo vetorial FieldFlow passa a fallback.


## 2.2.49 - Performance Room v2
- Dashboard premium no portal cliente com Readiness Index, Academy Score, Missões Pendentes e Certificados Emitidos.
- Filtros herdados do portal: cliente, campanha, operacional/equipa e datas.
- Top operacionais, equipas em risco e evolução de conclusões.
- Métricas isoladas por utilizadores associados ao cliente/campanha.


## 2.2.50 Automation Engine v1

- Novo separador Performance > Automações.
- Regras por cliente, campanha, formulário e pergunta.
- Condições: igual, diferente, contém, não contém, maior que, menor que.
- Ações: criar missão, atribuir curso via missão, enviar email ao operacional.
- Histórico de execuções por submissão.
- Hook não intrusivo após submissão de formulário: `fieldflow_form_submitted`.
- Mantém reportes, rotas e submissões existentes intactos.


## 2.2.51, Academy UX Pro + Course Rules

- Reforçada a experiência da Academy no `[fieldflow_app]` com layout premium, KPIs, estados de curso e bloco "Continuar agora".
- Lições agora têm ordem, obrigatoriedade e tempo mínimo de consumo configurável no BO.
- Lições obrigatórias passam a funcionar como percurso sequencial: a seguinte fica bloqueada até a anterior ser concluída.
- Cursos em rascunho/arquivados deixam de aparecer no front, mas continuam visíveis no BO.
- Adicionada ação de duplicar curso para acelerar criação de programas por campanha.
- Mantido o core de rotas/reportes intacto.

## 2.2.57 Academy Preview & Templates
- Added admin course preview card for Academy courses.
- Added safe duplicate-to-client/campaign workflow for courses.
- Added move lesson up/down controls in course editing.
- Kept frontend unchanged.
- Validated PHP syntax before packaging.


## 2.2.65 Academy Play Gate

- O desbloqueio de lições com YouTube passa a iniciar apenas após clique no botão Play.
- O tempo mínimo definido no BO continua a ser respeitado.
- Canva, PDF e links externos usam fallback: o contador inicia ao abrir/carregar o conteúdo.
- Mantém a aba Academy ativa após concluir lições.


## 2.2.69 Public Certificate Route Fix
- Corrige hooks públicos do certificado e da caderneta, que estavam registados apenas em admin_init.
- Link/QR Code do certificado passam a abrir a página pública de validação e Badge Wallet.
- Remove dupla codificação do certificado na URL pública.


## 2.2.70 Badge Modal + Font Controls
- Modal premium de badges com layout responsivo desktop/tablet/mobile.
- Certificate Studio permite escrever/selecionar fontes para títulos e corpo.
- Badge Wallet passa fontes e cores do template para o modal.
- Página pública de validação usa fontes configuradas.


## 2.2.71 Growth Hub

- Renomeia Performance para Growth Hub nas áreas visíveis.
- Mantém shortcodes e slugs internos para compatibilidade.
- Adiciona Skill Matrix simples ao dashboard front.
- Adiciona Activity Feed filtrado por cliente/campanha/operacional/data.
- Reforça Risk Center e leitura executiva sem tocar em Academy, certificados, Reset Engine ou rotas/reportes.


## 2.2.72 Skill Assignment

- Adicionado separador Growth Hub > Skills.
- Permite criar competências e atribuir pontos a cursos e missões.
- Skill Matrix do portal usa regras reais quando configuradas, com fallback para cálculo simples.
- Slugs e shortcodes mantidos para compatibilidade.


## 2.2.74 Growth Hub Mobile Feed Fix
- Corrige overflow horizontal no Growth Hub do client portal em mobile.
- Adiciona wrappers com scroll horizontal nas tabelas.
- Adiciona paginação visual da Activity Feed.
- Adiciona botão Limpar feed para administradores, ocultando eventos antigos sem apagar progresso/certificados/missões.
- Melhora estabilidade visual da Skill Matrix em mobile.


## 2.2.75 Growth Hub Activity Admin Fix
- Adiciona aba BO Growth Hub > Activity Feed.
- Permite limpar feed por âmbito cliente/campanha no BO.
- Adiciona paginação administrativa do Activity Feed.
- Mantém limpeza segura sem apagar progresso, certificados, badges, cursos ou missões.

## 2.2.76 Mission Quiz
- Adicionado conteúdo multimédia opcional por missão: YouTube, Canva, PDF e link externo.
- Adicionado questionário final simples por missão, com até 3 perguntas.
- Tipos suportados: escolha múltipla, verdadeiro/falso e resposta curta.
- Nota mínima configurável no BO.
- Resultado do quiz guardado por utilizador/missão.
- Submissão bloqueada se a nota mínima não for atingida.
