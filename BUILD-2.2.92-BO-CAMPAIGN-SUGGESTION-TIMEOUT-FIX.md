# Build 2.2.92 - BO Campaign Suggestion Timeout Fix

Correções:
- O BO Campanhas PDV deixa de calcular automaticamente a sugestão mensal pesada em cada filtro/refresh.
- Novo botão "Pré-visualizar sugestão" para calcular a rota apenas quando necessário.
- Botão "Aplicar sugestão e criar rotas" passa a declarar explicitamente a ação, evitando execuções acidentais.
- Planeador manual só é renderizado depois da pré-visualização.
- RouteCalculator ganha proteção de performance para dias com muitos PDVs, evitando 2-opt pesado acima de 14 paragens.

Motivo:
O 504 Gateway Time-out era causado pelo cálculo síncrono da sugestão mensal no carregamento da página do BO, sobretudo com campanhas com muitos PDVs, periodicidades mensais e estimativa de distância/carga.
