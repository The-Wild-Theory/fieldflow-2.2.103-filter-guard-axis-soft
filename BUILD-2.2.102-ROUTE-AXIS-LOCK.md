# FieldFlow 2.2.102, Route Axis Lock

## Objetivo

Refinar o Geo Routing Brain para impedir que uma rota diária vagueie entre lados opostos da morada base, por exemplo começar a sul da base e terminar a norte, quando devia trabalhar apenas o corredor sul.

## Alterações

- Novo eixo base para pontos de partida/chegada iguais ou quase iguais.
- Classificação dinâmica por `base|norte|sul|litoral|interior` quando a rota parte e regressa à mesma morada.
- Penalização forte para misturar norte e sul no mesmo dia.
- Penalização adicional para misturar litoral e interior no mesmo dia.
- O botão `Sugerir melhor dia` passou a aplicar a mesma lógica de eixo no editor direto.
- Cache interna atualizada para `routing_intelligence_v10_route_axis_lock`.

## Resultado esperado

- Dias mais coerentes por lado da morada base.
- Menos rotas em ziguezague.
- Menos mistura de norte/sul e litoral/interior no mesmo dia.
- Melhor comportamento quando a partida e chegada são a mesma morada.
