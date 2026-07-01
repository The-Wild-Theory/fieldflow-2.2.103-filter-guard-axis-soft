# FieldFlow 2.2.83, Portal Search + Partial Month Routing

## Updates

1. No shortcode `[fieldflow_client_portal]`, o filtro Loja / Local passou a ter pesquisa por escrita com datalist e seleção rápida, mantendo o select como fallback visual.
2. No planeamento mensal em Campanha PDV, a data base passa a ser o início operacional do período. Exemplo: 06.07.2026 gera sugestão de 06.07.2026 até ao fim do mês, não desde 01.07.2026.
3. A primeira semana passa a ser marcada como parcial quando a data base entra a meio da semana, permitindo ao motor aplicar melhor a lógica espelho sem carregar dias para compensar o início incompleto do mês.

## Notas técnicas

- `get_period_date_range()` para scope mensal devolve agora `[base_date, fim_do_mes]`.
- `get_month_business_calendar()` exclui dias anteriores à data base e recalcula semanas parciais pelo período operacional, não apenas pelo mês de calendário.
- O portal mantém compatibilidade com os endpoints e exports existentes, porque o valor final continua a ser `location_id`.
