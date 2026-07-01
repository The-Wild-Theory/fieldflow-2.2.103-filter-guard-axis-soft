# Build 2.2.100 - Plan Editor Safe Rules Fix

Correção da build 2.2.99.

- Reverte a alteração agressiva que podia colapsar a sugestão para apenas um dia.
- Mantém o gerador automático mensal intacto.
- Aplica regras duras apenas no editor direto da sugestão.
- A opção Permitir exceções neste dia só permite excesso de carga/horas, não periodicidade/cadência.
- O botão Sugerir melhor dia passa a penalizar dias carregados sem bloquear a lista completa de dias.
