
## 2.2.8-phase4h-no-repeat-anchor-geo-mirror

- Reforçada a regra de não repetir a mesma loja na mesma semana real, incluindo movimentos de balanceamento e cobertura forçada.
- Mantida a regra das semanas parciais: início parcial privilegia 2+4, fim parcial privilegia 1+3, com fuga apenas em saturação grave.
- Distribuição passa a tratar P4 como âncora, P1 como prioridade geográfica e P2/P3 como espelho calculado sobre a carga já existente.
- Reforçada a leitura de macro-zonas operacionais, norte, centro, sul, litoral e interior.

# FieldFlow, documentação do utilizador

## Objetivo
FieldFlow centraliza operação de rotas, reporting, comunicação com equipas de terreno e acompanhamento por cliente.

## Áreas principais
- Backoffice de clientes, projetos, rotas e campanhas.
- App operacional via shortcode `[fieldflow_app]`.
- Portal do cliente via shortcode `[fieldflow_client_portal]`.
- Gestão de mensagens, histórico e estados.

## Fluxo recomendado
1. Criar cliente.
2. Criar projeto.
3. Atribuir utilizadores no Centro de Atribuições.
4. Criar ou importar locais.
5. Construir rotas.
6. Acompanhar execução e reporting.

## Shortcodes principais
- `[fieldflow_app]`
- `[fieldflow_client_portal]`
- `[fieldflow_client_team_mail]`

## Boas práticas
- Validar permissões de utilizadores antes de abrir o portal a clientes.
- Usar a página Saúde do sistema antes de colocar em produção.
- Configurar chaves de mapas e IA em `wp-config.php` quando possível.


## Atualização 2.1.0
Esta versão acrescenta um modo de licenciamento local mais robusto, com geração de chave no backoffice, associação ao domínio atual e diagnóstico de mismatch para facilitar suporte e distribuição.

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


## 2.2.7-phase4g-anchor-first-geo-mirror

Esta versão ajusta o motor para usar P4 como âncora operacional, colocar P1 por prioridade geográfica antes de executar o espelho de P2/P3, e repõe a regra das semanas parciais no início e fim do mês.
