# FieldFlow 2.2.101, Geo Routing Brain

Build focada no motor de criacao de rotas e sugestoes geograficas.

## O que mudou

- Reforco do agrupamento geografico para evitar mega-clusters por efeito corrente.
- Novo score geografico transversal no planeamento mensal.
- O motor passa a valorizar mais:
  - mesmo cluster geografico;
  - mesma cidade/zona;
  - mesma macro-zona;
  - mesmo corredor de rota;
  - distancia ao centro do dia;
  - distancia a loja mais proxima;
  - raio e dispersao final do dia.
- Protecao das fases finais de balanceamento para nao desfazerem dias geograficamente bons.
- Dias vazios deixam de ser preenchidos a custa de partir um bloco geografico coerente.
- Botao "Sugerir melhor dia" no editor direto passa a considerar geografia, alem de carga, horas e periodicidade.
- Versao interna do motor de plano atualizada para `routing_intelligence_v9_geo_routing_brain`, evitando reaproveitar cache/plano antigo.

## Objetivo operacional

A prioridade passa a ser:

1. Cumprir regras duras de periodicidade, semana, maximo de visitas e horas.
2. Manter coerencia geografica.
3. Equilibrar carga dentro da coerencia geografica.
4. Reduzir km e tempo sem criar rotas dispersas.

## Ficheiros alterados

- `fieldflow.php`
- `src/Admin/CampaignLocations.php`

## QA efetuado

- `php -l` em todos os ficheiros PHP do plugin, sem erros de sintaxe.

## Testes recomendados

1. Gerar rota mensal com campanha real.
2. Confirmar que lojas da mesma cidade/zona ficam mais juntas.
3. Confirmar que dias carregados ja nao despejam lojas para dias leves se isso quebrar geografia.
4. No Editor direto da sugestao, retirar uma loja e usar "Sugerir melhor dia".
5. Ativar "Permitir excecoes neste dia" e confirmar que a excecao continua apenas a aliviar carga/horas, sem ignorar regras de periodicidade.
