# Build 2.2.90, Client Portal Campaign PDV Compact Table

Base: 2.2.89, sem mexer no motor de rotas.

## Ajustes
- Compactação visual da aba Campanha PDV no shortcode [fieldflow_client_portal].
- Tabela premium com linhas mais baixas e campos inline.
- Regras avançadas ficam recolhidas por defeito para evitar linhas gigantes.
- Colunas repetição e periodicidade combinadas.
- Estado e ativo combinados.
- Novo seletor de PDVs por página: 10, 25, 50 ou 100.
- Checkboxes corrigidas para não herdarem width 100%.
- Scroll horizontal mantido, mas com tabela mais controlada.
- Botão Ver rotas e restantes funções existentes mantidos.

## Validação
- PHP lint nos ficheiros do plugin.
- Sem alterações destrutivas ao editor de rotas.
