# FieldFlow 2.2.88, Client Portal Campaign PDVs Stable

Base usada: 2.2.85, preservando rotas, relatórios, base, Growth Hub e analytics.

## Alterações
- Nova aba Campanha PDV no shortcode [fieldflow_client_portal].
- Listagem de PDVs por cliente/campanha com fallback para todas as campanhas permitidas no âmbito do utilizador.
- Edição inline das regras principais e avançadas de campanha PDV.
- Guardar alterações por REST, sem tocar no editor de rotas existente.
- Botão Ver rotas filtra a loja e abre a aba Rotas.
- KPIs de PDVs, ativos, owners, coordenadas, rotas já criadas e kms.
- Botão Atualizar PDVs recarrega a listagem.

## Segurança
A geração automática de rotas continua no BO Campanhas PDV para evitar alterações acidentais feitas pelo cliente.
