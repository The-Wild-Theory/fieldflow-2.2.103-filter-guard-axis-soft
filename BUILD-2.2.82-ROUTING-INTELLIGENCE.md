# FieldFlow 2.2.82, Routing Intelligence

Build focada em diagnostico e distribuicao operacional das rotas.

## Principais alteracoes

- Adicionado Day Balancer v2 para nivelar carga dentro da mesma semana.
- O balanceamento deixa de escolher apenas o dia mais leve global, evitando bloqueios quando esse dia pertence a outra semana.
- Mantem o maximo de visitas por dia como limite duro.
- Mantem periodicidade semanal/mensal como criterio forte, sem mover visitas para fora da semana alvo quando existe target_week_key.
- Adicionado diagnostico do plano na aba Planeamento.
- O diagnostico mostra alvo de visitas por dia, ideal minimo, dias leves, dias longos, mistura de zonas e visitas por encaixar.
- Adicionada classificacao por tipo de dia: urbana/densa, regional, longa/interior, dispersa, equilibrada ou sem rota.
- Adicionadas zonas dominantes e corredores dominantes no diagnostico.
- Atualizada a chave de cache do plano para forcar regeneracao com o novo motor.

## Nota operacional

Esta build nao altera o layout de abas criado na 2.2.81. O objetivo foi melhorar o motor e dar explicabilidade ao resultado.
