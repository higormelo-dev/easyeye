import { describe, it, expect } from 'vitest';
import { DICTATION_SAMPLE_RATE, dictationToHtml, encodeWav } from '@/Support/dictation.js';

async function bytesOf(blob) {
    return new DataView(await blob.arrayBuffer());
}

const ascii = (view, offset, length) =>
    Array.from({ length }, (_, i) => String.fromCharCode(view.getUint8(offset + i))).join('');

describe('encodeWav — WAV PCM mono 16 bits para o provedor de transcrição', () => {
    it('monta o cabeçalho RIFF/WAVE/fmt/data com taxa, canais e tamanhos corretos', async () => {
        const samples = new Float32Array(16000); // 1 s
        const blob = encodeWav(samples);
        const view = await bytesOf(blob);

        expect(blob.type).toBe('audio/wav');
        expect(blob.size).toBe(44 + 16000 * 2);
        expect(ascii(view, 0, 4)).toBe('RIFF');
        expect(view.getUint32(4, true)).toBe(36 + 32000);
        expect(ascii(view, 8, 4)).toBe('WAVE');
        expect(ascii(view, 12, 4)).toBe('fmt ');
        expect(view.getUint16(20, true)).toBe(1); // PCM
        expect(view.getUint16(22, true)).toBe(1); // mono
        expect(view.getUint32(24, true)).toBe(DICTATION_SAMPLE_RATE);
        expect(view.getUint32(28, true)).toBe(DICTATION_SAMPLE_RATE * 2);
        expect(view.getUint16(34, true)).toBe(16);
        expect(ascii(view, 36, 4)).toBe('data');
        expect(view.getUint32(40, true)).toBe(32000);
    });

    it('converte as amostras para inteiros de 16 bits e satura o que passa de ±1', async () => {
        const view = await bytesOf(encodeWav(new Float32Array([0, 0.5, -0.5, 1, -1, 2, -2])));

        const sample = (i) => view.getInt16(44 + i * 2, true);
        expect(sample(0)).toBe(0);
        expect(sample(1)).toBe(Math.trunc(0.5 * 0x7fff));
        expect(sample(2)).toBe(-0x4000);
        expect(sample(3)).toBe(0x7fff);
        expect(sample(4)).toBe(-0x8000);
        expect(sample(5)).toBe(0x7fff); // saturado
        expect(sample(6)).toBe(-0x8000);
    });

    it('a duração que o servidor lê do cabeçalho bate com a das amostras', async () => {
        const view = await bytesOf(encodeWav(new Float32Array(24000), DICTATION_SAMPLE_RATE));

        expect(view.getUint32(40, true) / view.getUint32(28, true)).toBe(1.5);
    });
});

describe('dictationToHtml — texto ditado escapado, pronto para o cursor do editor', () => {
    it('frase única entra em linha (continua a frase do cursor), com espaço no fim', () => {
        expect(dictationToHtml('Cristalino transparente.')).toBe('Cristalino transparente. ');
    });

    it('quebra simples vira <br>; quebra dupla separa parágrafos', () => {
        expect(dictationToHtml('OD normal.\nOE normal.')).toBe('OD normal.<br>OE normal. ');
        expect(dictationToHtml('OD normal.\n\nOE com catarata.')).toBe('<p>OD normal.</p><p>OE com catarata.</p>');
    });

    it('[SEGURANÇA] marcação ditada/transcrita nunca vira HTML', () => {
        expect(dictationToHtml('<img src=x onerror=alert(1)> & "ok"')).toBe(
            '&lt;img src=x onerror=alert(1)&gt; &amp; "ok" ',
        );
        expect(dictationToHtml('a\n\n<script>x</script>')).toBe('<p>a</p><p>&lt;script&gt;x&lt;/script&gt;</p>');
    });

    it('vazio ou só espaço não insere nada', () => {
        expect(dictationToHtml('')).toBe('');
        expect(dictationToHtml('  \n ')).toBe('');
        expect(dictationToHtml(null)).toBe('');
    });
});
