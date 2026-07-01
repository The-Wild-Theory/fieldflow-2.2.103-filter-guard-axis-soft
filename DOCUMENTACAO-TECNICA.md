
## 2.2.8-phase4h-no-repeat-anchor-geo-mirror

- Reforçada a regra de não repetir a mesma loja na mesma semana real, incluindo movimentos de balanceamento e cobertura forçada.
- Mantida a regra das semanas parciais: início parcial privilegia 2+4, fim parcial privilegia 1+3, com fuga apenas em saturação grave.
- Distribuição passa a tratar P4 como âncora, P1 como prioridade geográfica e P2/P3 como espelho calculado sobre a carga já existente.
- Reforçada a leitura de macro-zonas operacionais, norte, centro, sul, litoral e interior.

# FieldFlow, documentação técnica

## Bootstrap
O plugin é carregado a partir de `fieldflow.php`, que delega a carga de classes para `src/Support/Loader.php`.

## Componentes principais
- `src/Admin`, ecrãs e fluxos do backoffice.
- `src/Rest`, endpoints REST.
- `src/Front`, renderers de shortcodes front.
- `src/Support`, runtime, config, health, licenciamento e notices.
- `src/Repositories`, acesso estruturado a dados críticos.

## Operação
- A versão é controlada por `FIELDFLOW_VERSION` e `ROUTESPRO_VERSION`.
- As migrações passam por `RoutesPro\Activator::activate()` e `src/Support/Migrations.php`.
- Logging técnico usa `routespro_system_logs`.

## Release
- `php scripts/qa-smoke.php`
- `bash scripts/release-build.sh`

## Recomendação
- Manter segredos no `wp-config.php`.
- Validar o health check depois de cada upgrade.
- Empacotar apenas artefactos de produção.


## Atualização 2.1.0
Esta versão acrescenta um modo de licenciamento local mais robusto, com geração de chave no backoffice, associação ao domínio atual e diagnóstico de mismatch para facilitar suporte e distribuição.


## 2.2.7-phase4g-anchor-first-geo-mirror

- Reordenada a alocação mensal: P4 cria a âncora, P1 entra logo a seguir por prioridade geográfica, depois P2 e P3 executam a cadência espelho já contando com essa carga.
- Reposta a regra das semanas parciais: início parcial privilegia Semana 2 + Semana 4; fim parcial privilegia Semana 1 + Semana 3, fugindo apenas em saturação grave.
- Reforçada a leitura geográfica macro para litoral/interior/norte/centro/sul, complementando cidade, distrito e distância real.
- P1 recebe maior peso de cluster, proximidade e macro-zona, para funcionar como oportunidade geográfica e não como simples enchimento de calendário.

## 2.2.6-phase4f-mirror-capacity-balancer

- Adicionada capacidade semanal antes da distribuição diária, para evitar que as semanas 2 e 4 absorvam carga em excesso só por serem o par espelho preferido.
- P2 passa a usar fallback controlado de par espelho quando o par principal fica saturado.
- P3 passa a escolher terceira semana com base na carga semanal saudável.
- P1 passa a evitar semanas acima de 90% da capacidade, salvo necessidade de cobertura.
- Ordem de alocação ajustada para P4, P2, P3 e P1, mantendo P4 como âncora e usando P2 como base operacional antes das camadas flexíveis.


- Motor mensal ajustado para cobertura-first: evita transformar excesso de carga em lojas sem alocação quando ainda existe alternativa operacional.
- Rebalanceamento mais leve e rápido, com menos passes internos.
- Capacidade dinâmica deixou de remover visitas apenas por target de distância, remove/move apenas perante excesso real de horas ou máximo de visitas.
- Recuperação final de visitas por encaixar para o melhor dia disponível, com marcação de exceção operacional quando for necessária validação manual.
- Mantém a cadência espelho, mas privilegia cobertura mínima antes de deixar lojas fora do plano.

## 2.2.4-phase4d-performance-hotfix

- Hotfix de performance para a página de Campanhas PDVs, evitando que o motor fique preso no carregamento ao atualizar a sugestão.
- Rebalanceamento mensal limitado por passes mais curtos e cálculo rápido de distância durante a simulação.
- A ordenação final da rota mantém-se no fecho do plano, mas as tentativas internas deixam de reordenar a rota centenas de vezes.

## 2.2.3-phase4c-mirror-calendar-balancer

- Introduz grelha espelho operacional: P2 privilegia 2+4 quando a primeira semana é parcial e 1+3 quando a última semana é parcial.
- P3 passa a usar base P2 + terceira visita em semana livre mais leve.
- P4 mantém âncora semanal sem criar 5.ª visita quando as visitas extra estão desligadas.
- Reforça guardrails duros antes do output: máximo de visitas/dia, horas úteis com almoço e teto absoluto de 10h.
- Adiciona rebalance obrigatório de dias críticos para dias leves, priorizando mover P1 e a terceira visita de P3 antes de tocar em P2/P4.
- Se uma visita não couber sem rebentar limites, fica como não alocada com motivo operacional em vez de gerar dia Frankenstein.

## 2.2.2-phase4-load-balance-guardrails

- Corrige a interpretação de horas úteis no motor de planeamento: o almoço passa a consumir capacidade real na alocação, não apenas no diagnóstico final.
- Reforça as constraints de Máx. visitas/dia e Média alvo/dia com penalização forte para dias acima do target dinâmico.
- Limita dias operacionais a um teto defensivo de 10h totais, incluindo almoço, salvo ajustes manuais posteriores no editor.
- Melhora o rebalanceamento entre dias pesados e dias subaproveitados, usando carga total com almoço incluído.
- Mantém a lógica de clusters geográficos, mas impede que densidade geográfica justifique dias com carga operacional excessiva.
