/**
 * Ditado por voz do laudo (Fase 5) — partes do navegador e conversões puras.
 *
 * O áudio NUNCA passa pelo reconhecimento de voz do navegador (o Chrome manda
 * o áudio ao Google: transferência internacional de dado de saúde sem
 * contrato). Aqui só se GRAVA (MediaRecorder) e se converte para WAV PCM mono
 * 16 kHz — o formato que todo provedor de transcrição aceita, ao contrário do
 * webm/opus que cada navegador escolhe —, e o servidor faz o resto.
 */

export const DICTATION_SAMPLE_RATE = 16000;

function writeString(view, offset, text) {
    for (let i = 0; i < text.length; i += 1) view.setUint8(offset + i, text.charCodeAt(i));
}

/**
 * WAV PCM 16 bits mono a partir de amostras Float32 (-1..1).
 *
 * @param {Float32Array} samples
 * @param {number} sampleRate
 * @returns {Blob}
 */
export function encodeWav(samples, sampleRate = DICTATION_SAMPLE_RATE) {
    const dataBytes = samples.length * 2;
    const view = new DataView(new ArrayBuffer(44 + dataBytes));

    writeString(view, 0, 'RIFF');
    view.setUint32(4, 36 + dataBytes, true);
    writeString(view, 8, 'WAVE');
    writeString(view, 12, 'fmt ');
    view.setUint32(16, 16, true); // tamanho do chunk fmt
    view.setUint16(20, 1, true); // PCM
    view.setUint16(22, 1, true); // mono
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * 2, true); // bytes por segundo
    view.setUint16(32, 2, true); // alinhamento do bloco
    view.setUint16(34, 16, true); // bits por amostra
    writeString(view, 36, 'data');
    view.setUint32(40, dataBytes, true);

    for (let i = 0; i < samples.length; i += 1) {
        const s = Math.max(-1, Math.min(1, samples[i]));
        view.setInt16(44 + i * 2, s < 0 ? s * 0x8000 : s * 0x7fff, true);
    }

    return new Blob([view], { type: 'audio/wav' });
}

/**
 * Texto ditado → HTML para inserir no cursor do editor. Texto puro escapado:
 * sem quebra dupla entra em linha (continua a frase do cursor); com parágrafos,
 * vira <p>.
 */
export function dictationToHtml(text) {
    const clean = String(text ?? '').trim();
    if (!clean) return '';

    const escape = (value) => value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const paragraphs = clean.split(/\n{2,}/);

    if (paragraphs.length === 1) return escape(paragraphs[0]).replace(/\n/g, '<br>') + ' ';

    return paragraphs.map((p) => `<p>${escape(p).replace(/\n/g, '<br>')}</p>`).join('');
}

/** O navegador grava áudio? (getUserMedia + MediaRecorder + decodificação). */
export function dictationSupported() {
    if (typeof window === 'undefined' || typeof navigator === 'undefined') return false;

    return Boolean(
        navigator.mediaDevices?.getUserMedia &&
        typeof window.MediaRecorder !== 'undefined' &&
        (window.AudioContext || window.webkitAudioContext) &&
        typeof window.OfflineAudioContext !== 'undefined',
    );
}

/** Gravação decodificada, reamostrada para mono 16 kHz. */
async function toMono16k(blob) {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    const context = new AudioContextClass();

    try {
        const decoded = await context.decodeAudioData(await blob.arrayBuffer());
        const offline = new window.OfflineAudioContext(
            1,
            Math.max(1, Math.ceil(decoded.duration * DICTATION_SAMPLE_RATE)),
            DICTATION_SAMPLE_RATE,
        );
        const source = offline.createBufferSource();
        source.buffer = decoded;
        source.connect(offline.destination); // vários canais descem para mono na saída
        source.start();

        return (await offline.startRendering()).getChannelData(0);
    } finally {
        context.close?.();
    }
}

/** Peças do navegador que o composable usa — trocáveis nos testes. */
export const browserDictationDeps = {
    getStream: () =>
        navigator.mediaDevices.getUserMedia({
            audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true },
        }),
    createRecorder: (stream) => new window.MediaRecorder(stream),
    toWav: async (blob) => {
        const samples = await toMono16k(blob);

        return { wav: encodeWav(samples), seconds: samples.length / DICTATION_SAMPLE_RATE };
    },
};
