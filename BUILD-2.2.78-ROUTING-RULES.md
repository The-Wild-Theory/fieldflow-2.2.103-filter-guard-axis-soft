# FieldFlow 2.2.78, Routing Rules Performance-Safe

Build focada em melhorar a periodicidade por PDV dentro da mecânica existente de Campanhas e Rotas, sem criar BO paralelo pesado.

## Incluído

- Campos avançados de regra no vínculo Campanha + PDV.
- Painel colapsável "Regras" na tabela de PDVs associados.
- Export/import CSV com campos avançados.
- Serviço `VisitRuleResolver` para normalizar regras de visita.
- Serviço `PlanQualityScorer` para score leve de plano mensal.
- Respeito por dias bloqueados na escolha de dia fixo mensal.
- Bónus para dias preferenciais no motor de cadência fixa.
- Aviso simples quando o intervalo mínimo entre visitas pode ser quebrado.
- Nonce no AJAX de preview do plano.
- Correção para otimizar rotas usando os mesmos pontos de partida/chegada resolvidos usados no horário final.

## Campos novos em `routespro_campaign_locations`

- `min_gap_days`
- `max_gap_days`
- `preferred_weekdays`
- `blocked_weekdays`
- `time_window_start`
- `time_window_end`
- `allow_auto_reschedule`
- `allow_overtime`
- `rule_notes`

## Notas de performance

- A regra continua no vínculo Campanha + PDV para evitar nova página pesada.
- Não foram criadas tabelas permanentes de visit tasks.
- A geração continua virtual até à criação das rotas.
- Os dados novos são lidos no mesmo SELECT principal, evitando queries por PDV.
