# FieldFlow 2.2.96 - Form Return and Upload Required Fix

## Correções
- Corrige redirecionamento de submissões de formulários carregados via AJAX, evitando cair em `admin-ajax.php` com resposta `0`.
- Adiciona `routespro_return_url` ao formulário para preservar a página real de origem.
- O carregamento dinâmico do formulário passa a enviar `window.location.href` como URL de retorno.
- Campos obrigatórios de imagem/ficheiro passam a aceitar o valor já existente no histórico quando não é carregado um novo ficheiro.

## Motivo
Quando o formulário era carregado dentro do app/rota via AJAX, o `wp_get_referer()` podia apontar para `admin-ajax.php`. Em caso de erro de validação, o WordPress redirecionava para essa página e mostrava apenas `0`.
