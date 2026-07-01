# FieldFlow 2.2.94, Product Cardex por Insignia

## Novo
- Menu BO `Cardex Produtos`.
- Criacao de cardex por insignia/tipo de loja, exemplo Sonae, Pingo Doce, Intermarche.
- Importacao CSV com 60+ referencias por cardex.
- Template CSV descarregavel.
- Associacao manual de cardex a lojas existentes.
- Deteccao automatica por palavra-chave/insignia quando nao existe associacao direta.
- Pergunta `Tabela de produtos / referencias` agora aceita origem manual, cardex fixo ou cardex automatico pela loja.

## CSV
Cabecalhos suportados:
`cardex,insignia,client_id,project_id,referencia,produto,location_id,external_ref,ordem,ativo`

## Compatibilidade
- Formularios antigos continuam a usar `product_rows` manual.
- Exportacoes e analytics continuam a ler as respostas guardadas no mesmo formato `ref/name/qty`.
