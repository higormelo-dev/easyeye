import { describe, it, expect } from 'vitest';
import { escapeHtml, safeMarkdown } from '@/Support/safeMarkdown';

/** Markdown das respostas de IA: formata o básico e nunca deixa HTML do texto passar. */
describe('safeMarkdown', () => {
    it('[SEGURANÇA] escapa qualquer HTML do texto antes de formatar', () => {
        const html = safeMarkdown('<script>alert(1)</script> <img src=x onerror="alert(2)"> **ok**');

        expect(html).not.toContain('<script');
        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;script&gt;');
        expect(html).toContain('&quot;alert(2)&quot;');
        expect(html).toContain('<strong>ok</strong>');
    });

    it('formata negrito, itálico, código, listas, títulos e quebras de linha', () => {
        const html = safeMarkdown(
            '### Resumo\n**Lucro** caiu *bem*\nlinha 2\n\n- um\n- `dois`\n\n1. primeiro\n2) segundo',
        );

        expect(html).toBe(
            '<p><strong>Resumo</strong></p>' +
                '<p><strong>Lucro</strong> caiu <em>bem</em><br>linha 2</p>' +
                '<ul><li>um</li><li><code>dois</code></li></ul>' +
                '<ol><li>primeiro</li><li>segundo</li></ol>',
        );
    });

    it('links não viram <a> e conta com asterisco não vira itálico', () => {
        expect(safeMarkdown('[clique](javascript:alert(1))')).not.toContain('<a');
        expect(safeMarkdown('2 * 3 * 4 = 24')).toBe('<p>2 * 3 * 4 = 24</p>');
        expect(escapeHtml(null)).toBe('');
    });
});
