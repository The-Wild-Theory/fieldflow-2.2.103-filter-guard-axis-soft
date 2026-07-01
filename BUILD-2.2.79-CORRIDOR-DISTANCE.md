# FieldFlow 2.2.79, Corridor Distance Allocation

Esta build melhora a alocacao espacial das visitas mensais para equipas de terreno com muitos PDVs e periodicidades elevadas.

## O que mudou

- Nova estrategia de geracao: `Corredor partida/chegada`.
- O motor passa a classificar cada PDV por corredor calculado entre ponto de partida e ponto de chegada.
- O corredor divide as visitas por:
  - segmento do eixo, partida, meio ou chegada;
  - lado geografico do eixo, norte, sul ou eixo;
  - natureza geografica, litoral ou interior.
- A escolha do dia da cadencia mensal passa a dar bonus forte a PDVs do mesmo corredor e penalizacao a mistura de corredores diferentes.
- A distribuicao mensal tambem passa a preferir dias com o mesmo corredor dominante.
- A ordenacao final da rota testa tres candidatos, nearest neighbor, ordem por projecao no eixo partida/chegada e ordem inversa, aplicando 2-opt e escolhendo o menor percurso estimado.

## Resultado esperado

Para comerciais com cerca de 60 PDVs e 130 visitas/mes, o plano deve evitar melhor os saltos entre zonas distantes e formar dias mais coerentes, por exemplo Norte, Sul, Interior/Litoral, em vez de misturar PDVs apenas por carga.

## Como testar

1. Ir a Campanhas PDVs.
2. Escolher modo Mensal.
3. Definir ponto de partida e ponto de chegada com coordenadas.
4. Selecionar estrategia `Corredor partida/chegada`.
5. Usar sensibilidade a distancia `Alta` se a zona for dispersa.
6. Gerar a sugestao e comparar km/dia e coerencia das zonas.

## Nota

A estrategia usa distancia geodesica interna como camada leve de decisao. Google Routes continua recomendado para validar metricas finais quando disponivel.
