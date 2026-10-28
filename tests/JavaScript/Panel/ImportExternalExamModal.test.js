import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ImportExternalExamModal from '@/Components/Panel/ImportExternalExamModal.vue';

// Pedal USB (Fase 5 / 7b) — mesmo padrão de mock de
// tests/JavaScript/Panel/Dashboard/DashboardPage.test.js: troca o
// composable inteiro por um test-double simples em vez de depender da
// reatividade do usePage() global (que é um objeto plano, não reativo,
// neste setup.js — ver DashboardPage.test.js).
const pedalPrefs = vi.hoisted(() => ({ values: {}, saved: [] }));
vi.mock('@/composables/useUserPreferences.js', () => ({
    useUserPreferences: () => ({
        getPreference: (key, fallback) => pedalPrefs.values[key] ?? fallback,
        savePreference: (key, value) => pedalPrefs.saved.push([key, value]),
    }),
}));

/**
 * Captura pela câmera do navegador (Fase 5 / 6a). getUserMedia/MediaRecorder/
 * canvas.toBlob não existem de verdade no JSDOM — mockados aqui (1º teste
 * deste tipo no projeto). form.content-style: bindings do topo do <script
 * setup> acessados via wrapper.vm (mesmo padrão de ExamManagerReportModal.test.js).
 */
function fakeTrack() {
    return { stop: vi.fn(), getSettings: () => ({ deviceId: 'cam-1' }) };
}

// JSDOM's <video>.srcObject setter checks `instanceof MediaStream` de
// verdade (achado nesta rodada) — um objeto plain {} throw em
// startCamera() e cai no catch como erro genérico. Object.create entra na
// cadeia de prototype sem precisar do construtor real (que exige tracks).
function fakeStream() {
    const track = fakeTrack();
    const stream = Object.create(MediaStream.prototype);
    stream.getTracks = () => [track];
    stream.getVideoTracks = () => [track];
    return stream;
}

class FakeMediaRecorder {
    constructor(stream, options) {
        this.stream = stream;
        this.mimeType = options?.mimeType ?? 'video/webm';
        this.state = 'recording';
        this.ondataavailable = null;
        this.onstop = null;
        FakeMediaRecorder.instances.push(this);
    }

    start() {
        this.state = 'recording';
    }

    stop() {
        this.state = 'inactive';
        this.ondataavailable?.({ data: new Blob(['x'], { type: this.mimeType }) });
        this.onstop?.();
    }

    static isTypeSupported(type) {
        return type === 'video/webm';
    }
}
FakeMediaRecorder.instances = [];

const baseProps = {
    open: true,
    patient: { id: 'pat-1', code: '123', person: { full_name: 'Paciente Teste' } },
    doctors: [],
    examTypes: [{ id: 'type-1', name: 'Retinografia' }],
    equipments: [],
    urls: { import_store: '/import', diagnosis_search: '/d-search', diagnosis_store: '/d-store' },
    t: {},
};

function mountModal(propsOverride = {}) {
    return mount(ImportExternalExamModal, {
        attachTo: document.body,
        props: { ...baseProps, ...propsOverride },
    });
}

beforeEach(() => {
    document.body.innerHTML = '';
    FakeMediaRecorder.instances = [];
    pedalPrefs.values = {};
    pedalPrefs.saved = [];

    globalThis.window.axios = { get: vi.fn(() => Promise.resolve({ data: [] })) };
    globalThis.window.MediaRecorder = FakeMediaRecorder;

    vi.stubGlobal('navigator', {
        ...globalThis.navigator,
        mediaDevices: {
            getUserMedia: vi.fn(() => Promise.resolve(fakeStream())),
            enumerateDevices: vi.fn(() =>
                Promise.resolve([
                    { kind: 'videoinput', deviceId: 'cam-1', label: 'Webcam 1' },
                    { kind: 'audioinput', deviceId: 'mic-1', label: 'Mic' },
                ]),
            ),
        },
    });

    HTMLCanvasElement.prototype.getContext = vi.fn(() => ({ drawImage: vi.fn() }));
    HTMLCanvasElement.prototype.toBlob = vi.fn((cb) => cb(new Blob(['fake'], { type: 'image/jpeg' })));

    // <video>.srcObject = stream não decodifica nada de verdade no JSDOM —
    // sem isto, videoFrameReady nunca viraria true (o fix depende só do
    // evento nativo loadeddata, que o JSDOM nunca dispara por conta
    // própria) e capturePhoto() pararia de funcionar em TODOS os testes
    // já existentes deste arquivo. Dispara sincronamente aqui, pra manter
    // o comportamento "câmera pronta = usável na hora" que os testes já
    // assumiam; o teste específico do guard (abaixo) finge o atraso real
    // sobrescrevendo wrapper.vm.videoFrameReady direto.
    Object.defineProperty(HTMLVideoElement.prototype, 'srcObject', {
        configurable: true,
        get() {
            return this._fakeSrcObject ?? null;
        },
        set(value) {
            this._fakeSrcObject = value;
            if (value) this.dispatchEvent(new Event('loadeddata'));
        },
    });
});

afterEach(() => {
    delete window.MediaRecorder;
    vi.unstubAllGlobals();
});

describe('ImportExternalExamModal — captura pela câmera (Fase 5 / 6a)', () => {
    it('inicia no modo "Enviar arquivo"; câmera só ativa ao clicar', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(wrapper.vm.captureMode).toBe('upload');
        expect(navigator.mediaDevices.getUserMedia).not.toHaveBeenCalled();
    });

    it('ativar câmera chama getUserMedia e enumerateDevices, sem erro', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        expect(navigator.mediaDevices.getUserMedia).toHaveBeenCalled();
        expect(navigator.mediaDevices.enumerateDevices).toHaveBeenCalled();
        expect(wrapper.vm.cameraStream).not.toBeNull();
        expect(wrapper.vm.cameraError).toBe('');
        wrapper.unmount();
    });

    it('permissão negada mostra erro visível e não quebra o resto do form', async () => {
        navigator.mediaDevices.getUserMedia = vi.fn(() => {
            const error = new Error('denied');
            error.name = 'NotAllowedError';
            return Promise.reject(error);
        });

        const wrapper = mountModal({ open: false, t: { capture_permission_denied: 'Permissão negada.' } });
        await wrapper.setProps({ open: true });
        await flushPromises();

        await wrapper.vm.startCamera();
        await flushPromises();

        expect(wrapper.vm.cameraError).toBe('Permissão negada.');
        expect(wrapper.vm.cameraStream).toBeNull();
        // Resto do form continua funcional — não travou em erro global.
        wrapper.vm.form.patient_id = 'pat-1';
        expect(wrapper.vm.form.patient_id).toBe('pat-1');
        wrapper.unmount();
    });

    it('câmera não encontrada mostra mensagem específica', async () => {
        navigator.mediaDevices.getUserMedia = vi.fn(() => {
            const error = new Error('none');
            error.name = 'NotFoundError';
            return Promise.reject(error);
        });

        const wrapper = mountModal({ open: false, t: { capture_not_found: 'Sem câmera.' } });
        await wrapper.setProps({ open: true });
        await flushPromises();

        await wrapper.vm.startCamera();
        await flushPromises();

        expect(wrapper.vm.cameraError).toBe('Sem câmera.');
        wrapper.unmount();
    });

    it('sem getUserMedia no navegador (sem HTTPS), mostra "não suportado" sem travar', async () => {
        vi.stubGlobal('navigator', { ...globalThis.navigator, mediaDevices: undefined });

        const wrapper = mountModal({ open: false, t: { capture_not_supported: 'Sem suporte.' } });
        await wrapper.setProps({ open: true });
        await flushPromises();

        await wrapper.vm.startCamera();
        await flushPromises();

        expect(wrapper.vm.cameraError).toBe('Sem suporte.');
        wrapper.unmount();
    });

    it('foto capturada entra em fileItems/form.files com origem browser_capture', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        // captureMode precisa estar em 'camera' pro <video>/<canvas> (e os
        // refs videoEl/canvasEl) existirem no DOM antes de capturar.
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.capturePhoto();
        await flushPromises();

        expect(wrapper.vm.fileItems).toHaveLength(1);
        expect(wrapper.vm.fileItems[0].file.type).toBe('image/jpeg');
        expect(wrapper.vm.form.files).toHaveLength(1);
        expect(wrapper.vm.form.files_source).toEqual(['browser_capture']);
        wrapper.unmount();
    });

    it('[GAP] capturePhoto() não captura antes do 1º frame do vídeo carregar (loadeddata) — botão "Foto" também fica desabilitado (achado via revisão Codex)', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        // Simula a janela real entre srcObject= stream (getUserMedia()
        // resolvido, cameraStarting já virou false) e o navegador de fato
        // decodificar o 1º frame: videoWidth/videoHeight continuam 0 até
        // loadeddata disparar. Sem o guard, "Foto" clicável aqui chamava
        // drawImage() com dimensão 0 (lança InvalidStateError num browser
        // de verdade) ou produzia um blob inútil sem nenhum aviso.
        wrapper.vm.videoFrameReady = false;
        await flushPromises();

        const fotoBtn = Array.from(document.body.querySelectorAll('button')).find((b) =>
            b.textContent.includes('Foto'),
        );
        expect(fotoBtn.disabled).toBe(true);

        wrapper.vm.capturePhoto();
        await flushPromises();
        expect(wrapper.vm.fileItems).toHaveLength(0); // nada capturado ainda

        // SÓ AGORA o 1º frame "carrega" (loadeddata de verdade, disparado
        // pelo mock de srcObject já usado nos outros testes).
        wrapper.vm.videoEl.dispatchEvent(new Event('loadeddata'));
        await flushPromises();

        expect(fotoBtn.disabled).toBe(false);
        wrapper.vm.capturePhoto();
        await flushPromises();
        expect(wrapper.vm.fileItems).toHaveLength(1);
        wrapper.unmount();
    });

    it('gravar vídeo e parar entra em fileItems com extensão .webm', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.startRecording();
        expect(wrapper.vm.isRecording).toBe(true);

        wrapper.vm.stopRecording(true);
        await flushPromises();

        expect(wrapper.vm.isRecording).toBe(false);
        expect(wrapper.vm.fileItems).toHaveLength(1);
        expect(wrapper.vm.fileItems[0].name).toMatch(/\.webm$/);
        expect(wrapper.vm.fileItems[0].isVideo).toBe(true);
        wrapper.unmount();
    });

    it('descartar gravação (save=false) NÃO entra em fileItems', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.startRecording();
        wrapper.vm.stopRecording(false);
        await flushPromises();

        expect(wrapper.vm.fileItems).toHaveLength(0);
        wrapper.unmount();
    });

    it('[GAP] dataavailable final de uma gravação descartada chegando DEPOIS de outra já ter começado não contamina o vídeo novo', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.startRecording(); // gravação A
        const recorderA = FakeMediaRecorder.instances[0];
        const staleDataAvailable = recorderA.ondataavailable;
        // No browser de verdade, stop() não dispara dataavailable/onstop na
        // hora — o fake dispara os dois juntos e síncrono. Pra reproduzir o
        // atraso real (o dataavailable FINAL de A só chega depois que B já
        // começou), sobrescreve o stop() desta instância pra não disparar
        // nada ainda; o dataavailable de A é guardado acima e disparado manualmente mais abaixo.
        recorderA.stop = vi.fn(() => {
            recorderA.state = 'inactive';
        });

        wrapper.vm.stopRecording(false); // descarta A
        expect(recorderA.onstop).toBeNull(); // onstop já anulado — só dataavailable pode vazar

        wrapper.vm.startRecording(); // B começa IMEDIATAMENTE depois, mesma câmera

        // SÓ AGORA o dataavailable atrasado de A chega — depois de B já ter
        // começado. Buffer compartilhado (bug original) receberia estes
        // bytes no lugar errado; buffer por gravação (fix) os perde no vácuo
        // do closure de A, que ninguém mais lê.
        staleDataAvailable({ data: new Blob(['bytes-da-gravacao-A'], { type: 'video/webm' }) });

        wrapper.vm.stopRecording(true); // B termina normalmente
        await flushPromises();

        expect(wrapper.vm.fileItems).toHaveLength(1); // só o vídeo de B, nunca o de A
        const bytes = await wrapper.vm.fileItems[0].file.text();
        expect(bytes).not.toContain('bytes-da-gravacao-A');
        wrapper.unmount();
    });

    it('[GAP] gravação em andamento bloqueia o "Importar" (nunca descarta o vídeo em silêncio)', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        // Já tem um arquivo no lote (upload manual) ANTES de começar a
        // gravar outro vídeo — mesmo cenário do achado: fileItems não está
        // vazio, então só o guard de isRecording pode bloquear o submit aqui.
        wrapper.vm.onFilesChange({
            target: { files: [new File(['x'], 'exame.jpg', { type: 'image/jpeg' })], value: '' },
        });
        await wrapper.vm.startCamera();
        await flushPromises();
        wrapper.vm.startRecording();

        wrapper.vm.form.patient_id = 'pat-1';
        wrapper.vm.form.exam_id = 'type-1';
        wrapper.vm.submit();

        // Gravação em andamento NUNCA está em fileItems/form.files (só entra
        // no onstop, assíncrono) — importar agora mandaria o lote sem esse
        // vídeo, e o sucesso fechando o painel descartaria a gravação ainda
        // ativa sem nenhum aviso (achado via revisão Codex).
        expect(wrapper.vm.clientError).toBe('Pare a gravação antes de importar.');
        expect(wrapper.vm.form.post).not.toHaveBeenCalled();
        expect(wrapper.vm.isRecording).toBe(true); // continua gravando, nada foi descartado
        wrapper.unmount();
    });

    it('[GAP] gravação recém-parada (onstop ainda pendente) também bloqueia o "Importar" — isRecording já virou false antes do vídeo entrar no lote', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.onFilesChange({
            target: { files: [new File(['x'], 'exame.jpg', { type: 'image/jpeg' })], value: '' },
        });
        await wrapper.vm.startCamera();
        await flushPromises();
        wrapper.vm.startRecording();
        const recorder = FakeMediaRecorder.instances[0];

        // O fake dispara onstop SÍNCRONO dentro de stop() — no browser de
        // verdade isso é assíncrono. Intercepta o stop() desta instância pra
        // reproduzir o atraso: onstop só dispara quando o teste chamar
        // manualmente abaixo, não durante stopRecording().
        const realOnstop = recorder.onstop;
        recorder.stop = vi.fn(() => {
            recorder.state = 'inactive';
        });

        wrapper.vm.stopRecording(true); // "Parar gravação" clicado
        expect(wrapper.vm.isRecording).toBe(false); // já virou false...
        expect(wrapper.vm.finalizingCapture).toBe(true); // ...mas onstop ainda não rodou

        wrapper.vm.form.patient_id = 'pat-1';
        wrapper.vm.form.exam_id = 'type-1';
        wrapper.vm.submit();

        // isRecording sozinho não detectaria mais nada aqui (achado via
        // revisão Codex) — finalizingCapture é quem segura o submit nesta
        // janela entre "parou" e "o vídeo de fato entrar no lote". Mensagem
        // "aguarde" (não "pare"): a gravação já foi parada, só falta terminar.
        expect(wrapper.vm.clientError).toBe('Aguarde a captura terminar antes de importar.');
        expect(wrapper.vm.form.post).not.toHaveBeenCalled();

        realOnstop(); // onstop de verdade roda agora
        expect(wrapper.vm.finalizingCapture).toBe(false);
        wrapper.unmount();
    });

    it('[GAP] foto recém-capturada (toBlob ainda pendente) também bloqueia o "Importar" — toBlob() é assíncrono, sem flag própria (achado via revisão Codex)', async () => {
        let capturedCallback;
        // O mock padrão (beforeEach) chama o callback SÍNCRONO — aqui
        // intercepta pra reproduzir o atraso real do encode: o callback só
        // dispara quando o teste chamar manualmente abaixo.
        HTMLCanvasElement.prototype.toBlob = vi.fn((cb) => {
            capturedCallback = cb;
        });

        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.onFilesChange({
            target: { files: [new File(['x'], 'exame.jpg', { type: 'image/jpeg' })], value: '' },
        });
        wrapper.vm.captureMode = 'camera'; // videoEl/canvasEl só existem no DOM neste modo
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.capturePhoto(); // obturador clicado
        expect(wrapper.vm.finalizingCapture).toBe(true); // toBlob() ainda não respondeu

        wrapper.vm.form.patient_id = 'pat-1';
        wrapper.vm.form.exam_id = 'type-1';
        wrapper.vm.submit();

        // isRecording nunca detectaria isto (foto não é gravação) — só
        // finalizingCapture cobre a janela entre o obturador e o blob pronto.
        expect(wrapper.vm.clientError).toBe('Aguarde a captura terminar antes de importar.');
        expect(wrapper.vm.form.post).not.toHaveBeenCalled();

        capturedCallback(new Blob(['fake'], { type: 'image/jpeg' })); // encode termina agora
        expect(wrapper.vm.finalizingCapture).toBe(false);
        wrapper.unmount();
    });

    it('[GAP] câmera para por conta própria (ex.: desconectada) sem passar por stopRecording(): isRecording e o contador se corrigem mesmo assim', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.startRecording();
        const recorder = FakeMediaRecorder.instances[0];
        expect(wrapper.vm.isRecording).toBe(true);

        // Simula o MediaRecorder parando por conta própria (câmera
        // desconectada, permissão revogada, track termina) — chama stop() do
        // recorder DIRETO, nunca wrapper.vm.stopRecording(), que é exatamente
        // o caminho que nunca roda neste cenário real.
        recorder.stop();
        await flushPromises();

        // Sem a correção: isRecording ficava travado em true (só
        // stopRecording() zerava) e o contador ia pra -1 (onstop decrementa
        // um incremento que nunca existiu, já que antes ele só acontecia
        // dentro de stopRecording(true)) — achado via revisão Codex.
        expect(wrapper.vm.isRecording).toBe(false);
        expect(wrapper.vm.finalizingCapture).toBe(false);
        expect(wrapper.vm.fileItems).toHaveLength(1); // chunk já coletado não se perde
        wrapper.unmount();
    });

    it('[GAP] foto ainda pendente não é cancelada por uma gravação que para por conta própria em seguida (contador negativo mascarava isto)', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        let resolveToBlob;
        HTMLCanvasElement.prototype.toBlob = vi.fn((cb) => {
            resolveToBlob = cb;
        });

        wrapper.vm.capturePhoto();
        expect(wrapper.vm.finalizingCapture).toBe(true); // foto pendente

        // Com a foto ainda pendente, uma gravação começa e para por conta
        // própria (câmera cai) — startRecording() só olha isRecording, nunca
        // o contador, então isto é permitido.
        wrapper.vm.startRecording();
        const recorder = FakeMediaRecorder.instances[0];
        recorder.stop(); // autônomo — nunca passa por wrapper.vm.stopRecording()
        await flushPromises();

        // Sem a correção, o onstop do vídeo decrementava um incremento que
        // nunca existiu (contador de 1 ia pra 0), fazendo a foto ainda
        // pendente parecer finalizada — "Importar" seria liberado antes do
        // blob da foto entrar em fileItems (achado via revisão Codex).
        expect(wrapper.vm.finalizingCapture).toBe(true); // foto AINDA pendente

        resolveToBlob(new Blob(['fake'], { type: 'image/jpeg' }));
        await flushPromises();

        expect(wrapper.vm.finalizingCapture).toBe(false); // só agora, depois do blob
        expect(wrapper.vm.fileItems).toHaveLength(2); // vídeo autônomo + foto
        wrapper.unmount();
    });

    it('[GAP] descartar gravação (save=false) desfaz o incremento feito no início — contador não fica travado positivo', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.startRecording();
        // Somado no início agora (onstop sempre dispara exatamente 1x por
        // gravação, seja por stopRecording() ou autônomo) — ver comentário
        // em startRecording().
        expect(wrapper.vm.finalizingCapture).toBe(true);

        wrapper.vm.stopRecording(false); // descarta — onstop é anulado, nunca roda
        await flushPromises();

        // Sem decrementar aqui (onstop nunca vai rodar pra fazer isso),
        // finalizingCapture ficaria travado em true pro resto da sessão do
        // modal, bloqueando qualquer importação futura por engano.
        expect(wrapper.vm.finalizingCapture).toBe(false);
        wrapper.unmount();
    });

    it('[GAP] recorder.start() lança (ex.: track do stream terminou entre construir e iniciar): nada é somado/trocado, próxima tentativa funciona normalmente', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.vm.startCamera();
        await flushPromises();

        // O construtor funciona (mock padrão), só start() falha — mesmo
        // cenário da câmera caindo no intervalo entre os dois. try/finally
        // pra SEMPRE restaurar o prototype, mesmo se startRecording() não
        // engolir a exceção (exatamente o cenário sem a correção) — senão
        // o mock vazava pros próximos testes do arquivo.
        const originalStart = FakeMediaRecorder.prototype.start;
        FakeMediaRecorder.prototype.start = function () {
            throw new DOMException('mock', 'NotSupportedError');
        };
        try {
            wrapper.vm.startRecording();
        } finally {
            FakeMediaRecorder.prototype.start = originalStart;
        }

        // Sem a correção, o contador já tinha sido somado e mediaRecorder já
        // apontava pro recorder morto ANTES de start() rodar — nada nunca
        // decrementava de volta, já que onstop nunca dispara pra um recorder
        // que nunca chegou a gravar (achado via revisão Codex).
        expect(wrapper.vm.isRecording).toBe(false);
        expect(wrapper.vm.finalizingCapture).toBe(false);
        expect(wrapper.vm.cameraError).toBe('Não foi possível acessar a câmera.');

        // Nada ficou travado — tentar de novo funciona normalmente.
        wrapper.vm.startRecording();
        expect(wrapper.vm.isRecording).toBe(true);
        wrapper.unmount();
    });

    it('[GAP] capturePhoto() não captura enquanto a importação está em voo (form.processing) — a foto se perderia em silêncio', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        // form.post() já mandou um retrato do form pro servidor — uma
        // captura feita AGORA nunca entra naquela requisição, e o onSuccess
        // (clearFileItems()) perdia ela em silêncio quando a resposta
        // chegasse (achado via revisão Codex).
        wrapper.vm.form.processing = true;
        wrapper.vm.capturePhoto();
        await flushPromises();

        expect(wrapper.vm.fileItems).toHaveLength(0);
        wrapper.unmount();
    });

    it('[GAP] startRecording() não inicia enquanto a importação está em voo (form.processing)', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.form.processing = true;
        wrapper.vm.startRecording();

        expect(wrapper.vm.isRecording).toBe(false);
        expect(FakeMediaRecorder.instances).toHaveLength(0);
        wrapper.unmount();
    });

    it('trocar pra aba de upload para a câmera (chama track.stop())', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();
        const track = wrapper.vm.cameraStream.getTracks()[0];

        wrapper.vm.captureMode = 'upload';
        await flushPromises();

        expect(track.stop).toHaveBeenCalled();
        expect(wrapper.vm.cameraStream).toBeNull();
        wrapper.unmount();
    });

    it('fechar o modal também para a câmera', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.vm.startCamera();
        await flushPromises();
        const track = wrapper.vm.cameraStream.getTracks()[0];

        await wrapper.setProps({ open: false });
        await flushPromises();

        expect(track.stop).toHaveBeenCalled();
        expect(wrapper.vm.cameraStream).toBeNull();
        wrapper.unmount();
    });

    it('submit com uma captura manda a origem POR ARQUIVO (files_source) — e nenhum campo único de origem do lote', async () => {
        globalThis.window.axios.post = vi.fn(() => Promise.resolve({ data: {} }));
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();
        wrapper.vm.capturePhoto();
        await flushPromises();

        wrapper.vm.form.patient_id = 'pat-1';
        wrapper.vm.form.exam_id = 'type-1';
        wrapper.vm.submit();

        expect(wrapper.vm.form.files_source).toEqual(['browser_capture']);
        // O servidor RECUSA o campo `source` do lote (versão antiga do formulário):
        // o formulário novo nem o declara.
        expect(wrapper.vm.form).not.toHaveProperty('source');
        wrapper.unmount();
    });

    it('[PROVENIÊNCIA] lote misto: o scan externo continua external_import e só a captura vira browser_capture (achado via revisão Codex)', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        // 1º um scan externo (upload), depois uma foto da câmera no MESMO lote.
        wrapper.vm.onFilesChange({
            target: { files: [new File(['x'], 'scan-externo.jpg', { type: 'image/jpeg' })], value: '' },
        });
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();
        wrapper.vm.capturePhoto();
        await flushPromises();

        wrapper.vm.form.patient_id = 'pat-1';
        wrapper.vm.form.exam_id = 'type-1';
        wrapper.vm.submit();

        expect(wrapper.vm.form.files).toHaveLength(2);
        expect(wrapper.vm.form.files[0].name).toBe('scan-externo.jpg');
        expect(wrapper.vm.form.files_source).toEqual(['external_import', 'browser_capture']);
        wrapper.unmount();
    });

    it('[PROVENIÊNCIA] a origem acompanha o ARQUIVO: remover um item e adicionar outro mantém files_source alinhado com files', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        wrapper.vm.onFilesChange({
            target: { files: [new File(['x'], 'scan-1.jpg', { type: 'image/jpeg' })], value: '' },
        });
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();
        wrapper.vm.capturePhoto();
        await flushPromises();
        expect(wrapper.vm.form.files_source).toEqual(['external_import', 'browser_capture']);

        // Tira o scan do começo: a captura passa a ser o índice 0 — e continua captura.
        wrapper.vm.removeFile(wrapper.vm.fileItems[0].id);
        expect(wrapper.vm.form.files.map((f) => f.name.startsWith('captura-'))).toEqual([true]);
        expect(wrapper.vm.form.files_source).toEqual(['browser_capture']);

        // Um upload novo entra DEPOIS da captura.
        wrapper.vm.onFilesChange({
            target: { files: [new File(['y'], 'scan-2.jpg', { type: 'image/jpeg' })], value: '' },
        });
        expect(wrapper.vm.form.files_source).toEqual(['browser_capture', 'external_import']);
        expect(wrapper.vm.form.files_source).toHaveLength(wrapper.vm.form.files.length);
        wrapper.unmount();
    });

    it('[GAP] capturar e depois remover a captura: submit com só upload manda só external_import (nunca browser_capture)', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();
        wrapper.vm.capturePhoto();
        await flushPromises();
        expect(wrapper.vm.form.files_source).toEqual(['browser_capture']); // captura entrou no lote

        wrapper.vm.removeFile(wrapper.vm.fileItems[0].id); // médico desiste da foto
        wrapper.vm.onFilesChange({
            target: { files: [new File(['x'], 'exame.jpg', { type: 'image/jpeg' })], value: '' },
        });

        wrapper.vm.form.patient_id = 'pat-1';
        wrapper.vm.form.exam_id = 'type-1';
        wrapper.vm.submit();

        // Sem nenhuma captura SOBRANDO no lote, nada pode seguir marcado como
        // captura — origem errada afeta DoctorPayoutProductionService::
        // examRows() (browser_capture conta como produção pagável, diferente
        // de external_import) — achado via revisão Codex.
        expect(wrapper.vm.form.files_source).toEqual(['external_import']);
        wrapper.unmount();
    });

    it('submit sem nenhuma captura manda só external_import', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        wrapper.vm.form.patient_id = 'pat-1';
        wrapper.vm.form.exam_id = 'type-1';
        wrapper.vm.onFilesChange({
            target: {
                files: [
                    new File(['x'], 'exame.jpg', { type: 'image/jpeg' }),
                    new File(['y'], 'laudo.pdf', { type: 'application/pdf' }),
                ],
                value: '',
            },
        });
        wrapper.vm.submit();

        expect(wrapper.vm.form.files_source).toEqual(['external_import', 'external_import']);
        wrapper.unmount();
    });

    it('reabrir o painel zera a origem dos arquivos do lote anterior', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.onFilesChange({
            target: { files: [new File(['x'], 'exame.jpg', { type: 'image/jpeg' })], value: '' },
        });
        expect(wrapper.vm.form.files_source).toEqual(['external_import']);

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(wrapper.vm.form.files).toEqual([]);
        expect(wrapper.vm.form.files_source).toEqual([]);
        wrapper.unmount();
    });

    it('[GAP] fechar o painel ENQUANTO getUserMedia() ainda pende não deixa a câmera acesa quando a permissão chega depois', async () => {
        let resolveGetUserMedia;
        navigator.mediaDevices.getUserMedia = vi.fn(
            () =>
                new Promise((resolve) => {
                    resolveGetUserMedia = resolve;
                }),
        );

        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';

        const startPromise = wrapper.vm.startCamera(); // fica pendente — getUserMedia() não resolveu ainda
        await flushPromises();

        // Fecha o painel ENQUANTO ainda espera a decisão do navegador (ex.:
        // usuário demorou no diálogo nativo de permissão e desistiu/fechou).
        await wrapper.setProps({ open: false });
        await flushPromises();

        const stream = fakeStream();
        const track = stream.getTracks()[0];
        resolveGetUserMedia(stream); // a permissão só "chega" agora
        await startPromise;
        await flushPromises();

        expect(track.stop).toHaveBeenCalled(); // descartado na hora, nunca fica ativo
        expect(wrapper.vm.cameraStream).toBeNull();
        wrapper.unmount();
    });

    it('[GAP] cancelar startCamera() pendente (fechar o painel) não deixa "Ativar câmera" desabilitado pra sempre', async () => {
        let resolveGetUserMedia;
        navigator.mediaDevices.getUserMedia = vi.fn(
            () =>
                new Promise((resolve) => {
                    resolveGetUserMedia = resolve;
                }),
        );

        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';

        const startPromise = wrapper.vm.startCamera(); // fica pendente — getUserMedia() não resolveu ainda
        await flushPromises();
        expect(wrapper.vm.cameraStarting).toBe(true);

        // Fecha o painel ENQUANTO ainda espera a decisão do navegador (mesmo
        // cenário do teste acima) — stopCamera() roda e invalida a requisição.
        await wrapper.setProps({ open: false });
        await flushPromises();

        // cameraStarting não pode ficar travado em true: invalidar a
        // requisição pendente já significa "não estamos mais tentando
        // iniciar", mesmo antes da promise do navegador resolver.
        expect(wrapper.vm.cameraStarting).toBe(false);

        // A permissão só "chega" agora, bem depois do cancelamento — stale,
        // o finally de startCamera() vai pular o reset (requestId não bate
        // mais), então nada além de stopCamera() poderia ter zerado isto.
        const staleStream = fakeStream();
        resolveGetUserMedia(staleStream);
        await startPromise;
        await flushPromises();

        expect(wrapper.vm.cameraStarting).toBe(false);
        expect(wrapper.vm.cameraStream).toBeNull();

        // Reabre a aba de câmera e confirma que o botão "Ativar câmera"
        // continua operante (não ficou desabilitado pra sempre).
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        navigator.mediaDevices.getUserMedia = vi.fn(() => Promise.resolve(fakeStream()));
        await wrapper.vm.startCamera();
        await flushPromises();

        expect(wrapper.vm.cameraStarting).toBe(false);
        expect(wrapper.vm.cameraStream).not.toBeNull();
        wrapper.unmount();
    });
});

describe('ImportExternalExamModal — pedal USB (Fase 5 / 7b)', () => {
    it('tecla de captura (default Enter) tira foto quando não está gravando', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.fileItems).toHaveLength(1);
        expect(wrapper.vm.fileItems[0].isVideo).toBe(false);
        wrapper.unmount();
    });

    it('tecla de captura encerra e salva a gravação (toggle) quando já está gravando', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();
        wrapper.vm.startRecording();
        expect(wrapper.vm.isRecording).toBe(true);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.isRecording).toBe(false);
        expect(wrapper.vm.fileItems).toHaveLength(1);
        expect(wrapper.vm.fileItems[0].isVideo).toBe(true);
        wrapper.unmount();
    });

    it('tecla de avançar (default Espaço) cicla a lateralidade OD → OE → AO → OD', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await flushPromises();
        expect(wrapper.vm.form.laterality).toBeNull();

        window.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        expect(wrapper.vm.form.laterality).toBe(1);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        expect(wrapper.vm.form.laterality).toBe(2);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        expect(wrapper.vm.form.laterality).toBeNull();
        wrapper.unmount();
    });

    it('[GAP] tecla do pedal é ignorada com foco num campo de texto (ex.: busca de paciente)', async () => {
        const wrapper = mountModal({ open: false, patient: null });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await flushPromises();

        // OffcanvasPanel usa <Teleport> — o conteúdo não aparece dentro da
        // árvore do wrapper (confirmado: wrapper.find() acha nada), mas está
        // de verdade em document.body (mountModal usa attachTo: document.body).
        const input = document.body.querySelector('input[type="text"]');
        expect(input).not.toBeNull();
        input.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.form.laterality).toBeNull();
        wrapper.unmount();
    });

    it('[GAP] tecla Enter/Espaço com foco num <button> (ex.: "Ativar câmera") nunca é interceptada — deixa o click nativo do botão acontecer (achado via revisão Codex)', async () => {
        const wrapper = mountModal({ open: false, patient: null });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await flushPromises();

        const activateBtn = Array.from(document.body.querySelectorAll('button')).find((b) =>
            b.textContent.includes('Ativar câmera'),
        );
        expect(activateBtn).toBeTruthy();
        activateBtn.focus();

        // event.defaultPrevented é a causa raiz de verdade: sem a correção,
        // onPedalKeydown() chamava preventDefault() aqui, cancelando o click
        // NATIVO que o navegador dispara pra um <button> focado em Enter/
        // Espaço (ativação por teclado, acessibilidade) — "Ativar câmera"
        // ficava morto pro teclado, e capturePhoto() rodava no lugar sem
        // nenhum efeito visível (sem vídeo ainda) — achado via revisão Codex.
        const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        activateBtn.dispatchEvent(event);
        await flushPromises();

        expect(event.defaultPrevented).toBe(false);
        expect(wrapper.vm.fileItems).toHaveLength(0); // capturePhoto() nunca rodou no lugar
        wrapper.unmount();
    });

    it('nunca dispara fora da aba câmera (captureMode=upload)', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        expect(wrapper.vm.captureMode).toBe('upload');

        window.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.form.laterality).toBeNull();
        wrapper.unmount();
    });

    it('listener do pedal desliga quando o modal fecha (funcionava antes, para de disparar depois)', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await flushPromises();

        window.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        expect(wrapper.vm.form.laterality).toBe(1); // prova que o listener estava ativo

        await wrapper.setProps({ open: false });
        await flushPromises();

        window.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.form.laterality).toBe(1); // não avançou pra 2: listener removido
        wrapper.unmount();
    });

    it('trocar a tecla de captura via select persiste as 2 teclas JUNTAS (array_merge raso no backend)', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        wrapper.vm.setPedalKey('capture', 'PageUp');

        expect(pedalPrefs.saved).toHaveLength(1);
        expect(pedalPrefs.saved[0]).toEqual([
            'eye_images_pedal_shortcuts',
            { capture_key: 'PageUp', advance_key: ' ' },
        ]);
        wrapper.unmount();
    });

    it('as opções de cada select nunca incluem a tecla já escolhida na outra', async () => {
        pedalPrefs.values['eye_images_pedal_shortcuts'] = { capture_key: 'PageUp', advance_key: 'PageDown' };
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(wrapper.vm.pedalCaptureKey).toBe('PageUp');
        expect(wrapper.vm.pedalAdvanceKey).toBe('PageDown');
        expect(wrapper.vm.pedalCaptureOptions).not.toContain('PageDown');
        expect(wrapper.vm.pedalAdvanceOptions).not.toContain('PageUp');
        wrapper.unmount();
    });

    it('[GAP] capture_key salvo como null (desligado de propósito) nunca cai no default — Enter não dispara mais (achado via revisão Codex)', async () => {
        pedalPrefs.values['eye_images_pedal_shortcuts'] = { capture_key: null, advance_key: 'PageDown' };
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        expect(wrapper.vm.pedalCaptureKey).toBeNull();
        expect(wrapper.vm.pedalAdvanceKey).toBe('PageDown'); // só a desligada muda, a outra continua normal

        // Sem a correção, ?? caía no default 'Enter' mesmo com capture_key
        // salvo como null — a tecla continuava funcionando apesar do
        // usuário ter desligado explicitamente (achado via revisão Codex).
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.fileItems).toHaveLength(0);
        wrapper.unmount();
    });
});

describe('ImportExternalExamModal — olho por arquivo (Fase 5 / revisão Codex)', () => {
    it('[GAP] pedal avançando de olho ENTRE capturas marca cada arquivo com o olho de quando foi capturado', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.form.laterality = 1; // OD
        wrapper.vm.capturePhoto();
        await flushPromises();

        wrapper.vm.advanceLaterality(); // OD -> OE (mesma ação do pedal)
        wrapper.vm.capturePhoto();
        await flushPromises();

        expect(wrapper.vm.fileItems).toHaveLength(2);
        expect(wrapper.vm.fileItems[0].laterality).toBe(1);
        expect(wrapper.vm.fileItems[1].laterality).toBe(2);
        expect(wrapper.vm.form.files_laterality).toEqual([1, 2]);
        wrapper.unmount();
    });

    it('[GAP] upload manual trocando o rádio entre arquivos também preserva o olho por arquivo', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        wrapper.vm.form.laterality = 1;
        wrapper.vm.onFilesChange({
            target: { files: [new File(['a'], 'a.jpg', { type: 'image/jpeg' })], value: '' },
        });

        wrapper.vm.form.laterality = 2;
        wrapper.vm.onFilesChange({
            target: { files: [new File(['b'], 'b.jpg', { type: 'image/jpeg' })], value: '' },
        });

        expect(wrapper.vm.fileItems[0].laterality).toBe(1);
        expect(wrapper.vm.fileItems[1].laterality).toBe(2);
        expect(wrapper.vm.form.files_laterality).toEqual([1, 2]);
        wrapper.unmount();
    });

    it('[GAP] cycleItemLaterality() corrige o olho de UM item já na lista (badge clicável) sem afetar os outros nem reagir a troca futura do rádio — achado na 10ª rodada de revisão adversarial Codex', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        // Rádio em AO por engano no momento de adicionar a.jpg; corrigido
        // pra OE antes de adicionar b.jpg (lote misto de propósito).
        wrapper.vm.form.laterality = null;
        wrapper.vm.onFilesChange({
            target: { files: [new File(['a'], 'a.jpg', { type: 'image/jpeg' })], value: '' },
        });
        wrapper.vm.form.laterality = 2;
        wrapper.vm.onFilesChange({
            target: { files: [new File(['b'], 'b.jpg', { type: 'image/jpeg' })], value: '' },
        });

        // Sem controle de correção por item, só removendo e readicionando o
        // arquivo (achado do Codex) — corrige SÓ a.jpg (AO -> OD, 1º passo
        // do ciclo), nunca reaplica em massa (b.jpg precisa continuar OE).
        wrapper.vm.cycleItemLaterality(wrapper.vm.fileItems[0]);

        expect(wrapper.vm.fileItems[0].laterality).toBe(1);
        expect(wrapper.vm.fileItems[1].laterality).toBe(2); // b.jpg intocado
        expect(wrapper.vm.form.files_laterality).toEqual([1, 2]);
        wrapper.unmount();
    });

    it('remover um arquivo do meio reindexeia files_laterality junto com form.files', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        wrapper.vm.form.laterality = 1;
        wrapper.vm.onFilesChange({ target: { files: [new File(['a'], 'a.jpg', { type: 'image/jpeg' })], value: '' } });
        wrapper.vm.form.laterality = 2;
        wrapper.vm.onFilesChange({ target: { files: [new File(['b'], 'b.jpg', { type: 'image/jpeg' })], value: '' } });
        wrapper.vm.form.laterality = null;
        wrapper.vm.onFilesChange({ target: { files: [new File(['c'], 'c.jpg', { type: 'image/jpeg' })], value: '' } });

        wrapper.vm.removeFile(wrapper.vm.fileItems[0].id); // remove o item OD (a.jpg)

        expect(wrapper.vm.fileItems.map((i) => i.laterality)).toEqual([2, null]);
        expect(wrapper.vm.form.files_laterality).toEqual([2, null]);
        wrapper.unmount();
    });

    it('[GAP] trocar o olho ENQUANTO a foto ainda está sendo codificada (toBlob assíncrono) não troca o olho da captura', async () => {
        // Sobrescreve o mock padrão do beforeEach (que resolve na hora) só
        // neste teste, pra controlar manualmente quando o toBlob() "termina"
        // de codificar o JPEG — mesma técnica do GAP de startCamera()/
        // getUserMedia() pendente, aplicada ao callback assíncrono do canvas.
        let pendingBlobCallback = null;
        HTMLCanvasElement.prototype.toBlob = vi.fn((cb) => {
            pendingBlobCallback = cb;
        });

        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.form.laterality = 1; // OD no momento em que o obturador é "apertado"
        wrapper.vm.capturePhoto();
        expect(pendingBlobCallback).not.toBeNull(); // toBlob() ainda "codificando", callback não disparou

        wrapper.vm.form.laterality = 2; // pedal avança o olho ANTES do toBlob() resolver

        pendingBlobCallback(new Blob(['fake'], { type: 'image/jpeg' })); // toBlob() finalmente resolve
        await flushPromises();

        expect(wrapper.vm.fileItems).toHaveLength(1);
        expect(wrapper.vm.fileItems[0].laterality).toBe(1); // olho de QUANDO a foto foi tirada, não o atual (2)
        expect(wrapper.vm.form.files_laterality).toEqual([1]);
        wrapper.unmount();
    });

    it('[GAP] fechar o painel e reabrir pra OUTRO paciente ENQUANTO a foto ainda codifica não contamina o lote do paciente novo', async () => {
        let pendingBlobCallback = null;
        HTMLCanvasElement.prototype.toBlob = vi.fn((cb) => {
            pendingBlobCallback = cb;
        });

        const wrapper = mountModal({
            open: false,
            patient: { id: 'pat-1', code: '123', person: { full_name: 'Paciente A' } },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.capturePhoto(); // foto do paciente A, ainda "codificando"
        expect(pendingBlobCallback).not.toBeNull();

        // Painel fecha (médico termina/cancela com o paciente A) e reabre pra
        // um paciente B diferente — mesmo componente, sessão de import nova.
        await wrapper.setProps({ open: false });
        await flushPromises();
        await wrapper.setProps({
            open: true,
            patient: { id: 'pat-2', code: '456', person: { full_name: 'Paciente B' } },
        });
        await flushPromises();
        expect(wrapper.vm.fileItems).toHaveLength(0); // sessão nova começa limpa

        // SÓ ENTÃO o toBlob() do paciente A finalmente resolve.
        pendingBlobCallback(new Blob(['fake'], { type: 'image/jpeg' }));
        await flushPromises();

        // A foto do paciente A não pode aparecer no lote do paciente B.
        expect(wrapper.vm.fileItems).toHaveLength(0);
        expect(wrapper.vm.form.files).toHaveLength(0);
        wrapper.unmount();
    });

    it('[GAP] trocar de paciente na BUSCA INTERNA (painel continua aberto) ENQUANTO a foto ainda codifica não contamina o lote do paciente novo — achado na 6ª rodada de revisão adversarial Codex', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true })) };
        let pendingBlobCallback = null;
        HTMLCanvasElement.prototype.toBlob = vi.fn((cb) => {
            pendingBlobCallback = cb;
        });

        const wrapper = mountModal({ open: false, patient: null });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.selectPatient({ id: 'pat-A', full_name: 'Paciente A' });
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.capturePhoto(); // foto do paciente A, ainda "codificando"
        expect(pendingBlobCallback).not.toBeNull();

        // Painel NUNCA fecha — o médico troca de paciente na busca interna
        // (mesmo componente, mesma instância, sem reset() de abertura).
        await wrapper.vm.selectPatient({ id: 'pat-B', full_name: 'Paciente B' });
        await flushPromises();
        expect(wrapper.vm.fileItems).toHaveLength(0); // sessão de captura invalidada na troca

        // SÓ ENTÃO o toBlob() do paciente A finalmente resolve.
        pendingBlobCallback(new Blob(['fake'], { type: 'image/jpeg' }));
        await flushPromises();

        // A foto do paciente A não pode aparecer no lote do paciente B.
        expect(wrapper.vm.fileItems).toHaveLength(0);
        expect(wrapper.vm.form.files).toHaveLength(0);
        expect(wrapper.vm.form.patient_id).toBe('pat-B');
        delete window.Swal;
        wrapper.unmount();
    });

    it('[GAP] trocar de paciente SEM nada pendente nunca pergunta (sem falso positivo) — achado na 6ª rodada de revisão adversarial Codex', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true })) };
        const wrapper = mountModal({ open: false, patient: null });
        await wrapper.setProps({ open: true });
        await flushPromises();

        wrapper.vm.selectPatient({ id: 'pat-A', full_name: 'Paciente A' });
        await flushPromises();
        wrapper.vm.selectPatient({ id: 'pat-B', full_name: 'Paciente B' }); // nada em fileItems ainda
        await flushPromises();

        expect(window.Swal.fire).not.toHaveBeenCalled();
        expect(wrapper.vm.form.patient_id).toBe('pat-B');
        delete window.Swal;
        wrapper.unmount();
    });

    it('[GAP] cancelar a troca de paciente com arquivo pendente mantém o paciente e o lote atuais', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: false })) };
        const wrapper = mountModal({ open: false, patient: null });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.selectPatient({ id: 'pat-A', full_name: 'Paciente A' });
        await flushPromises();
        wrapper.vm.onFilesChange({ target: { files: [new File(['x'], 'a.jpg', { type: 'image/jpeg' })], value: '' } });
        await flushPromises();
        expect(wrapper.vm.fileItems).toHaveLength(1);

        await wrapper.vm.selectPatient({ id: 'pat-B', full_name: 'Paciente B' });
        await flushPromises();

        expect(wrapper.vm.form.patient_id).toBe('pat-A'); // nunca trocou — usuário cancelou
        expect(wrapper.vm.fileItems).toHaveLength(1); // lote de A preservado
        delete window.Swal;
        wrapper.unmount();
    });

    it('[GAP] limpar o paciente escolhido (botão "x") com arquivo pendente também pergunta e invalida a sessão de captura', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: true })) };
        const wrapper = mountModal({ open: false, patient: null });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.selectPatient({ id: 'pat-A', full_name: 'Paciente A' });
        await flushPromises();
        wrapper.vm.onFilesChange({ target: { files: [new File(['x'], 'a.jpg', { type: 'image/jpeg' })], value: '' } });
        await flushPromises();
        expect(wrapper.vm.fileItems).toHaveLength(1);

        await wrapper.vm.clearPickedPatient();
        await flushPromises();

        expect(window.Swal.fire).toHaveBeenCalled();
        expect(wrapper.vm.form.patient_id).toBe('');
        expect(wrapper.vm.fileItems).toHaveLength(0); // lote de A descartado, não herdado por quem for escolhido depois
        delete window.Swal;
        wrapper.unmount();
    });

    it('[GAP] trocar o olho ENQUANTO está gravando não troca o olho do vídeo salvo ao parar', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        wrapper.vm.captureMode = 'camera';
        await wrapper.vm.startCamera();
        await flushPromises();

        wrapper.vm.form.laterality = 1; // OD no momento em que a gravação COMEÇA
        wrapper.vm.startRecording();
        expect(wrapper.vm.isRecording).toBe(true);

        wrapper.vm.form.laterality = 2; // pedal avança o olho ENQUANTO ainda está gravando

        wrapper.vm.stopRecording(true);
        await flushPromises();

        expect(wrapper.vm.isRecording).toBe(false);
        expect(wrapper.vm.fileItems).toHaveLength(1);
        expect(wrapper.vm.fileItems[0].isVideo).toBe(true);
        expect(wrapper.vm.fileItems[0].laterality).toBe(1); // olho de QUANDO a gravação começou, não o atual (2)
        expect(wrapper.vm.form.files_laterality).toEqual([1]);
        wrapper.unmount();
    });

    it('[GAP] clicar no rádio OD/OE (não só setar form.laterality programaticamente) grava NÚMERO, nunca string — advanceLaterality()/fileLateralityLabel() comparam estrito (achado via revisão Codex)', async () => {
        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        // OffcanvasPanel usa <Teleport> — o rádio não aparece na árvore do
        // wrapper (wrapper.find() acharia nada), mas está de verdade em
        // document.body (mountModal usa attachTo: document.body). value="1"
        // (atributo HTML estático) grava a STRING '1' via v-model, nunca o
        // NÚMERO 1 — só :value="1" (binding dinâmico, igual o rádio AO já
        // usava) preserva o tipo.
        const odRadio = document.body.querySelector('#import-laterality-od');
        expect(odRadio).not.toBeNull();
        odRadio.checked = true;
        odRadio.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.form.laterality).toBe(1); // número, nunca a string '1'
        expect(wrapper.vm.fileLateralityLabel(wrapper.vm.form.laterality)).toBe('OD');

        // advanceLaterality() (pedal) precisa achar o 1 numérico no ciclo —
        // com form.laterality='1' (string), indexOf('1') não achava nada e
        // sempre voltava pro índice 0 (OD) em vez de avançar pra OE.
        wrapper.vm.advanceLaterality();
        expect(wrapper.vm.form.laterality).toBe(2);

        const oeRadio = document.body.querySelector('#import-laterality-oe');
        oeRadio.checked = true;
        oeRadio.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();

        expect(wrapper.vm.form.laterality).toBe(2);
        expect(wrapper.vm.fileLateralityLabel(wrapper.vm.form.laterality)).toBe('OE');

        wrapper.vm.advanceLaterality(); // OE -> AO, nunca de volta pra OD
        expect(wrapper.vm.form.laterality).toBeNull();
        wrapper.unmount();
    });
});

describe('ImportExternalExamModal — data do grupo travado usa locale (EE-14)', () => {
    it('banner do grupo pré-definido mostra a data via useLocaleFormat, não pt-BR fixo', async () => {
        // O reset de campos (inclusive presetLocked) roda no watch(open) —
        // precisa da transição false->true, não só montar já aberto (mesmo
        // ponto de atenção de BulkCancelModal/CancelModal/etc. nesta base).
        const wrapper = mountModal({
            open: false,
            presetGroup: { examTypeId: 'type-1', examTypeName: 'Retinografia', examPerformedAt: '2026-09-26' },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        // Locale da sessão de teste cai no padrão pt_BR (mock global de
        // usePage() sem a prop `locale`, ver tests/JavaScript/setup.js) —
        // comportamento en é coberto em tests/JavaScript/composables/
        // useLocaleFormatEnglish.test.js (composable usado por este banner).
        expect(document.body.textContent).toContain('26/09/2026');
        wrapper.unmount();
    });
});
