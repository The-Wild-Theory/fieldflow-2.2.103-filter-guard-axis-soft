# Build 2.2.85 - Portal dropdown pesquisavel

## Objetivo
Corrigir o filtro Loja / Local no shortcode [fieldflow_client_portal].

## Alteracoes
- O filtro passa a funcionar como dropdown pesquisavel no proprio campo.
- A lista de opcoes passa a ser renderizada em posicao fixa para nao ficar cortada pelo header/hero.
- A pesquisa passa a normalizar acentos e espacos, melhorando resultados em nomes longos ou compostos.
- A lista apresenta ate 120 resultados e ordena correspondencias que comecam pelo texto pesquisado antes das restantes.
- O select original continua oculto para manter compatibilidade com location_id, endpoints, relatorios e exportacoes.
- Mantida a correcao da data base mensal introduzida na 2.2.83.

## Ficheiros alterados
- fieldflow.php
- src/Front/ClientPortalShortcodeRenderer.php

## QA tecnico
- php -l src/Front/ClientPortalShortcodeRenderer.php
- php -l fieldflow.php
