# FieldFlow 2.2.97 - Product Matrix Summary Column Fix

## Objetivo
Remover a coluna agregada "Resumo" das respostas do tipo tabela de produtos/referências.

## Alterações
- As respostas `product_matrix` continuam a expandir para uma coluna por produto/referência.
- A coluna extra `Pergunta | Resumo` deixa de aparecer no backoffice, no portal cliente e nas exportações baseadas no dataset de submissões.
- Mantém-se o armazenamento original da resposta em JSON, sem perda de histórico.
- Mantém-se compatibilidade com Cardex Produtos, importação CSV e associação por loja/insígnia.

## Impacto
A grelha fica mais limpa, especialmente quando existem cardex com muitas referências.
