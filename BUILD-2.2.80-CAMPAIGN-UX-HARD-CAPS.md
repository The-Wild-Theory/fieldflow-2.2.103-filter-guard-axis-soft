# FieldFlow 2.2.80, Campaign UX + Hard Daily Caps

## Objetivo
Melhorar a distribuicao de visitas por dia e tornar o BO de Campanhas PDVs mais leve e user friendly.

## Alteracoes principais
- Max. visitas/dia passa a funcionar como limite duro no planeamento semanal e mensal.
- O motor deixa de forcar cobertura acima do limite configurado, visitas sem encaixe ficam em nao atribuidas para validacao operacional.
- Nova passagem de rebalanceamento para distribuir melhor dias carregados e dias leves.
- Nova passagem final de seguranca no motor mensal para remover excesso quando um dia fica acima do maximo.
- Preferencia por mover visitas para dias compativeis na mesma semana, respeitando zona e corredor.
- BO Campanhas PDVs recebeu estilos compactos: filtro/simulacao com layout em grelha, KPIs mais pequenos e bloco de sugestao mais claro.

## Nota operacional
Se a periodicidade exigir mais visitas do que a capacidade permite, o sistema deixa visitas por atribuir em vez de criar dias com 10 ou 11 quando o maximo esta configurado para 8.
