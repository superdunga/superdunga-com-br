# Compatibilidade da hospedagem

## PHP

A hospedagem de producao pode rodar versao de PHP mais antiga que o ambiente local.

Evitar usar funcoes exclusivas do PHP 8+ em paginas do sistema sem confirmar a versao da hospedagem.

Exemplos que ja causaram erro 500 em producao:

- `str_starts_with`
- `str_contains`
- `fn` / `static fn` (arrow functions)

Preferir alternativas compativeis:

- `strpos($texto, $prefixo) === 0` no lugar de `str_starts_with`.
- `strpos($texto, $busca) !== false` no lugar de `str_contains`.
- `function (...) { ... }` no lugar de `fn (...) => ...`.

Antes de FTP, sempre rodar `php -l` nos arquivos alterados e procurar uso dessas funcoes quando o codigo novo for para producao.
