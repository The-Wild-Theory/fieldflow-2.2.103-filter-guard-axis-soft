# FieldFlow 2.2.98 - Product Matrix Before/After Analytics

- Evolui a pergunta `product_matrix` para suportar frentes antes e frentes depois quando existe histórico por loja/produto.
- Na primeira visita, a tabela mantém apenas o campo Número de frentes.
- Em visitas seguintes, o valor anterior é apresentado como Frentes antes e o campo Frentes depois vem preenchido com o último valor, para o operativo só alterar quando houver mudança.
- A submissão guarda `before`, `after` e `qty`, mantendo compatibilidade com relatórios e analytics existentes.
- O cardex continua dinâmico por loja/insígnia, com merge do histórico por referência ou nome de produto.
- Relatórios, BO, portal e exportações passam a reconhecer colunas Antes/Depois quando a matriz tem histórico.
- Analytics passa a calcular e devolver crescimento por produto/loja (`growth`) e suporta agregação `growth`.
