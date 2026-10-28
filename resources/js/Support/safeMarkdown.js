/**
 * Markdown mínimo e seguro para respostas de IA: escapa TODO o HTML primeiro
 * e só então gera as poucas tags conhecidas — parágrafo, quebra de linha,
 * negrito, itálico, código, listas e títulos (como negrito). Links não viram
 * <a> e nada do texto vira HTML executável: o resultado pode ir para v-html.
 */
const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (char) => ESCAPES[char]);
}

function inline(text) {
    return text
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*(\S(?:[^*]*\S)?)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*\w])\*(\S(?:[^*]*\S)?)\*(?!\*)/g, '$1<em>$2</em>');
}

export function safeMarkdown(source) {
    const lines = escapeHtml(source).split(/\r?\n/);
    const html = [];
    let list = null; // 'ul' | 'ol'
    let paragraph = [];

    const flushParagraph = () => {
        if (paragraph.length) {
            html.push(`<p>${paragraph.map(inline).join('<br>')}</p>`);
            paragraph = [];
        }
    };
    const closeList = () => {
        if (list) {
            html.push(`</${list}>`);
            list = null;
        }
    };

    for (const raw of lines) {
        const line = raw.trimEnd();
        const bullet = line.match(/^\s*[-*•]\s+(.*)$/);
        const ordered = line.match(/^\s*\d+[.)]\s+(.*)$/);
        const heading = line.match(/^\s*#{1,6}\s+(.*)$/);

        if (bullet || ordered) {
            flushParagraph();
            const type = bullet ? 'ul' : 'ol';
            if (list !== type) {
                closeList();
                html.push(`<${type}>`);
                list = type;
            }
            html.push(`<li>${inline((bullet ?? ordered)[1])}</li>`);
            continue;
        }

        closeList();

        if (heading) {
            flushParagraph();
            html.push(`<p><strong>${inline(heading[1])}</strong></p>`);
        } else if (line.trim() === '') {
            flushParagraph();
        } else {
            paragraph.push(line);
        }
    }

    flushParagraph();
    closeList();

    return html.join('');
}
