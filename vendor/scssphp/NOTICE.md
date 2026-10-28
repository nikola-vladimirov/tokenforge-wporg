# scssphp (bundled)

TokenForge bundles [scssphp](https://scssphp.github.io/scssphp) 1.12.1, MIT licensed, see `LICENSE.md` (Copyright (c) 2015 Leaf Corcoran).

Modification: the PHP namespace `ScssPhp\ScssPhp` was renamed to `TokenForge\Vendor\ScssPhp\ScssPhp` so this copy cannot clash with other plugins that bundle their own scssphp. No other changes.

## Local patch (TokenForge)

`src/Compiler.php` gains `tfKeepLiteralSlashes()` / `tfIsLiteralSlashOperand()` /
`tfSlashToString()`, called before the shorthand handling of each declaration: a
slash between literal numbers (`aspect-ratio: 16/9`, `grid-column: 1 / -1`,
`grid-area: 1/2/3`) is kept as a separator, as dart-sass does, instead of being
divided. Division still happens in parentheses or with variables/functions.

Removed: `src/Exception/ServerException.php` (deprecated upstream, unused here; it was the only file that ran code when opened on its own).
