# Build 2.2.95, Fatal Install Fix

Correção da build 2.2.94 para garantir carregamento do módulo Product Cardex durante o bootstrap do plugin.

- Inclui `src/Forms/ProductCardex.php` no loader antes do renderer de formulários.
- Inclui `src/Admin/ProductCardex.php` no loader antes do menu de administração.
- Regista hooks admin do Cardex Produtos.
- Garante criação das tabelas de cardex na ativação/upgrade.
