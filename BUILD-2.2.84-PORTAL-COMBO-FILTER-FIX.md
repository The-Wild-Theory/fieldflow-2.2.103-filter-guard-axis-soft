# Build 2.2.84, Portal combo filter fix

## Correção aplicada

- Substituído o filtro duplicado de Loja / Local no shortcode `[fieldflow_client_portal]` por um único campo pesquisável.
- O campo abre uma lista tipo dropdown e permite escrever para filtrar os itens carregados.
- O `select` original continua no DOM, mas fica oculto e mantém o `location_id` para compatibilidade com KPIs, relatórios, exportações, rotas e Growth Hub.
- Corrigido o layout do header, removendo o campo extra que empurrava o formulário e criava a lista visual duplicada.

## Nota técnica

A solução não depende de bibliotecas externas. O dropdown pesquisável é renderizado em JavaScript sobre os dados já devolvidos pelo endpoint `routespro_portal_locations`.
