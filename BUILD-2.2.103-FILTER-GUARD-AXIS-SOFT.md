# FieldFlow 2.2.103 - Filter Guard + Axis Soft

## Objetivo
Corrigir a regressao da build 2.2.102 onde o motor de rotas podia gerar sugestoes sem respeitar todos os filtros visiveis do BO e onde o bloqueio por eixo podia dominar demasiado a decisao geografica.

## Alteracoes

### 1. Filtros do BO passam a ser regra dura
A geracao de sugestoes, pre-visualizacao, gravacao, exportacao, importacao, criacao manual e aceitacao de plano passam a usar o mesmo conjunto de filtros visiveis:

- Merchandiser / owner
- Pesquisa por nome, morada, cidade, telefone ou codigo postal
- Categoria / subcategoria
- Estado da campanha
- Ligacao ativa / inativa

Antes, a tabela respeitava estes filtros, mas a sugestao de rota podia usar apenas o owner.

### 2. Cache e sugestoes gravadas isoladas por filtro
A chave da sugestao gravada passou a incluir a assinatura dos filtros. Isto evita carregar uma sugestao gravada de outro contexto, por exemplo uma rota de todos os PDVs quando o utilizador esta a ver apenas PDVs filtrados.

### 3. Axis Lock suavizado por defeito
O eixo norte/sul/interior/litoral continua a orientar a rota, mas deixa de dominar a decisao quando a estrategia escolhida nao e explicitamente `Corredor partida/chegada`.

Na estrategia `Corredor partida/chegada`, o eixo continua forte. Nas restantes estrategias, funciona como penalizacao suave para nao piorar carga, periodicidade ou filtros.

### 4. Editor direto
O botao `Sugerir melhor dia` tambem recebeu penalizacao de eixo mais suave para evitar sugestoes demasiado rigidas.

## Recomendacao de teste
1. Filtrar por merchandiser.
2. Filtrar por categoria ou estado ativo.
3. Gerar rota mensal.
4. Confirmar que a contagem de PDVs da sugestao bate com o filtro ativo.
5. Testar estrategia `Operacional equilibrado`.
6. Testar estrategia `Corredor partida/chegada` apenas quando o objetivo for forcar um lado/eixo.
