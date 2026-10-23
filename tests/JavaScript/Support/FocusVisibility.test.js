import { readFileSync } from 'node:fs';
import { expect, it } from 'vitest';

it('o reset global não pode anular o foco de links e botões com outline none important', () => {
    const source = readFileSync(
        'resources/css/system/components/_reboot.scss',
        'utf8',
    );
    const suppressedFocus =
        /(?:^|[}\n])\s*(?:button\s*,\s*a|a\s*,\s*button)\s*\{[^}]*\boutline\s*:\s*(?:none|0)\s*!important\b/im;

    // A regra retirada é a mutação que esta sentinela deve detectar.
    expect('button,\na {\n    outline: none !important;\n}').toMatch(suppressedFocus);
    expect(source).not.toMatch(suppressedFocus);
});
