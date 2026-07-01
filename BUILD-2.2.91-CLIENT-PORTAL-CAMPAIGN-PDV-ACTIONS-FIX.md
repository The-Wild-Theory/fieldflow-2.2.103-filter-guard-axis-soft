# Build 2.2.91, Client Portal Campaign PDV Actions Fix

Correção dos botões da aba Campanha PDV no shortcode [fieldflow_client_portal].

## Ajustes
- Botão Atualizar PDVs com binding direto e delegado.
- Botão Guardar alterações com binding direto e delegado.
- Feedback visual durante atualização/gravação.
- Cache buster no refresh REST para evitar respostas presas.
- Fallback de gravação: se o estado dirty não existir, recolhe payloads das linhas visíveis.
- Erros REST passam a aparecer no estado do portal.

Base: 2.2.90.
