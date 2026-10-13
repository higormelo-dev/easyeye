<script setup>
/**
 * ImportExternalExamModal — "Importar exame externo" (Gerenciador de Imagens).
 *
 * Upload manual de N arquivos de exame para um paciente, sem passar pelo
 * integrador/equipamento — ver ExternalExamImportController::store() /
 * ImportExternalExamRequest / ExternalExamImportService (cria um PatientExam
 * por arquivo, agrupados por import_batch_id).
 *
 * Diagnóstico é opcional e embutido direto no form via Cid10Picker (mesmo
 * componente/endereços de DiagnosisManagerModal.vue), sem abrir outro modal
 * aninhado.
 *
 * Props:
 *   open       – controla visibilidade
 *   patient    – paciente em foco na tela (id, code, full_name/person.full_name),
 *                quando já selecionado na página. Se null, mostra campo de
 *                busca (mesmo endpoint usado em ScheduleFormModal.vue —
 *                panel.patients.search).
 *   doctors    – [{id, name}] — já carregado por EyeImagesController::index()
 *   examTypes  – [{id, name}] — idem (props.exam_types da página)
 *   equipments – [{id, name}] — idem (props.equipments da página)
 *   urls       – { import_store, diagnosis_search, diagnosis_store }
 *   presetGroup – { examTypeId, examTypeName, examPerformedAt, equipmentId,
 *                equipmentName } opcional. "Adicionar imagem a exame
 *                existente" (botão Upload de um grupo já exibido no
 *                Gerenciador de Imagens) reaproveita este MESMO modal em vez
 *                de duplicar upload/diagnóstico/picker de arquivos — só
 *                pré-preenche e trava tipo/data/equipamento pra a imagem
 *                nova cair automaticamente no mesmo grupo visual (chave de
 *                agrupamento em Index.vue: data|equipamento|tipo). O médico
 *                pode destravar ("Usar outro grupo") se o palpite errar.
 */
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import Cid10Picker from '@/Components/Panel/Cid10Picker.vue';
import { useUserPreferences } from '@/composables/useUserPreferences.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

const props = defineProps({
    open: { type: Boolean, required: true },
    patient: { type: Object, default: null },
    doctors: { type: Array, default: () => [] },
    examTypes: { type: Array, default: () => [] },
    equipments: { type: Array, default: () => [] },
    urls: { type: Object, default: () => ({}) },
    presetGroup: { type: Object, default: null },
    t: { type: Object, default: () => ({}) },
    // Fase 5 / 6a — auto-stop da gravação por vídeo (config server-side
    // eye_image_capture.capture.max_video_seconds); client-side é só UX,
    // o teto que de fato importa é max_video_kb validado no backend.
    maxVideoSeconds: { type: Number, default: 20 },
    // Fase 5 / 7a/7b — allowlist + default das teclas do pedal USB.
    pedalShortcutsConfig: {
        type: Object,
        default: () => ({
            allowed_keys: ['Enter', ' ', 'PageDown', 'PageUp'],
            default_capture_key: 'Enter',
            default_advance_key: ' ',
        }),
    },
});

function tt(key, fallback = '') {
    return props.t?.[key] ?? fallback;
}

function fillText(text, replacements = {}) {
    return Object.entries(replacements).reduce((acc, [key, value]) => acc.replaceAll(`:${key}`, value ?? ''), text);
}

const presetLocked = ref(false);

const emit = defineEmits(['close', 'imported']);

function todayIso() {
    return new Date().toISOString().substring(0, 10);
}

function patientLabel(p) {
    return p?.person?.full_name ?? p?.full_name ?? '—';
}

const form = useForm({
    patient_id: '',
    exam_id: '',
    exam_performed_at: todayIso(),
    doctor_id: '',
    entity_integrator_equipment_id: '',
    external_origin: '',
    laterality: null,
    diagnoses: [],
    files: [],
    // Fase 5 / 7b — snapshot do olho NO MOMENTO de cada arquivo/captura
    // (índice-alinhado com `files`, via syncFormFiles()). Sem isto, o pedal
    // trocando de olho ENTRE capturas da mesma sessão aberta do modal
    // rotulava toda a leva com o último olho escolhido, não o de cada
    // captura (achado via revisão Codex) — ver ExternalExamImportService::import().
    files_laterality: [],
    // Origem de CADA arquivo ('external_import' = upload manual, 'browser_capture'
    // = câmera), índice-alinhada com `files` (syncFormFiles()). Lote misto
    // (upload + captura) leva uma origem por arquivo: um campo único do lote
    // marcava o scan externo como captura da clínica — produção PAGÁVEL no
    // repasse médico — só porque uma foto da câmera entrou junto (achado via
    // revisão adversarial Codex).
    files_source: [],
});

// ── Paciente: pré-selecionado (props.patient) ou busca ──────────────────────
const hasFixedPatient = computed(() => !!props.patient?.id);

const patientQuery = ref('');
const patientResults = ref([]);
const patientSearching = ref(false);
const pickedPatient = ref(null);
let patientDebounce = null;

function onPatientInput() {
    clearTimeout(patientDebounce);
    pickedPatient.value = null;
    form.patient_id = '';
    const q = patientQuery.value.trim();
    if (q.length < 2) {
        patientResults.value = [];
        return;
    }
    patientDebounce = setTimeout(searchPatients, 350);
}

async function searchPatients() {
    patientSearching.value = true;
    try {
        const { data } = await window.axios.get(route('panel.patients.search'), {
            params: { q: patientQuery.value.trim() },
        });
        patientResults.value = Array.isArray(data) ? data : [];
    } catch {
        patientResults.value = [];
    } finally {
        patientSearching.value = false;
    }
}

// Trocar o paciente escolhido AQUI DENTRO (busca interna, painel continua
// aberto) nunca tocava fileItems/captureSessionId — diferente de abrir o
// painel pra outro paciente (watch(props.open) abaixo já chama
// clearFileItems()). Uma captura ainda codificando (canvas.toBlob()/
// recorder.onstop, ambos assíncronos) do paciente ANTERIOR entrava no lote
// do paciente NOVO em silêncio, e o submit() gravava a imagem errada no
// prontuário de outra pessoa (achado na 6ª rodada de revisão adversarial
// Codex). clearFileItems() SEMPRE roda (mesmo sem nada visível em
// fileItems — invalida qualquer captura ainda em voo); o diálogo só
// pergunta quando há algo que o usuário de fato perderia.
function hasPendingCaptureWork() {
    return fileItems.value.length > 0 || finalizingCapture.value;
}

async function confirmPatientChangeDiscardsWork() {
    const message = tt(
        'import_patient_change_discards_files',
        'Trocar de paciente agora descarta os arquivos e capturas já adicionados a este lote. Continuar?',
    );
    if (window.Swal) {
        const result = await window.Swal.fire({
            icon: 'warning',
            title: message,
            showCancelButton: true,
            confirmButtonText: tt('import_patient_change_confirm', 'Trocar de paciente'),
            cancelButtonText: tt('cancel', 'Cancelar'),
        });
        return result.isConfirmed;
    }
    return window.confirm(message);
}

async function selectPatient(p) {
    if (pickedPatient.value?.id === p.id) return; // já é este — nada a trocar/perder
    if (hasPendingCaptureWork() && !(await confirmPatientChangeDiscardsWork())) return;
    clearFileItems();
    pickedPatient.value = p;
    form.patient_id = p.id;
    patientQuery.value = p.full_name;
    patientResults.value = [];
}

async function clearPickedPatient() {
    if (hasPendingCaptureWork() && !(await confirmPatientChangeDiscardsWork())) return;
    clearFileItems();
    pickedPatient.value = null;
    form.patient_id = '';
    patientQuery.value = '';
    patientResults.value = [];
}

// ── Origem: equipamento cadastrado vs texto livre (UX only — ambos os campos
// são nullable no backend, não há regra de "um dos dois é obrigatório") ─────
const originMode = ref('equipment'); // 'equipment' | 'manual'

watch(originMode, (mode) => {
    if (mode === 'equipment') form.external_origin = '';
    else form.entity_integrator_equipment_id = '';
});

// ── Diagnóstico (Cid10Picker embutido — mesmo padrão de DiagnosisManagerModal) ─
const creatingCustomDiagnosis = ref(false);
const createDiagnosisError = ref('');

async function onCreateDiagnosis(term) {
    if (!props.urls?.diagnosis_store) return;
    creatingCustomDiagnosis.value = true;
    createDiagnosisError.value = '';
    try {
        const { data } = await window.axios.post(props.urls.diagnosis_store, { name: term });
        const created = data?.data;
        if (created) {
            form.diagnoses = [
                ...form.diagnoses,
                {
                    code: created.code ?? null,
                    custom_diagnosis_id: created.custom_diagnosis_id,
                    description: created.description,
                    is_primary: form.diagnoses.length === 0,
                },
            ];
        }
    } catch (error) {
        createDiagnosisError.value = error?.response?.data?.message ?? 'Não foi possível cadastrar o diagnóstico.';
    } finally {
        creatingCustomDiagnosis.value = false;
    }
}

// ── Arquivos (multi-file básico: input[multiple] + preview + remover) ──────
const fileItems = ref([]); // [{ id, file, name, size, isImage, isVideo, laterality, previewUrl }]

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

// Fase 5 / 7b: rótulo do olho GRAVADO em cada item (mesma convenção OD/OE/AO
// de EyeImages/Index.vue — ver eye_images.eye_od/eye_oe/eye_ao).
function fileLateralityLabel(laterality) {
    if (laterality === 1) return tt('eye_od', 'OD');
    if (laterality === 2) return tt('eye_oe', 'OE');
    return tt('eye_ao', 'AO');
}

function onFilesChange(e) {
    const picked = Array.from(e.target.files ?? []);
    e.target.value = ''; // permite reselecionar o mesmo arquivo depois de remover

    for (const file of picked) {
        fileItems.value.push({
            id: `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
            file,
            name: file.name,
            size: file.size,
            isImage: /^image\//.test(file.type),
            isVideo: /^video\//.test(file.type),
            // Olho NO MOMENTO deste arquivo entrar na lista — trocar o rádio
            // depois de adicionar este item não deve rotular retroativamente
            // (mesmo princípio da captura pela câmera, ver addCapturedFile()).
            laterality: form.laterality,
            previewUrl: /^image\//.test(file.type) ? URL.createObjectURL(file) : null,
            origin: 'upload',
        });
    }
    syncFormFiles();
}

function removeFile(id) {
    const idx = fileItems.value.findIndex((i) => i.id === id);
    if (idx === -1) return;
    if (fileItems.value[idx].previewUrl) URL.revokeObjectURL(fileItems.value[idx].previewUrl);
    fileItems.value.splice(idx, 1);
    syncFormFiles();
}

function syncFormFiles() {
    form.files = fileItems.value.map((i) => i.file);
    form.files_laterality = fileItems.value.map((i) => i.laterality ?? null);
    form.files_source = fileItems.value.map((i) => (i.origin === 'capture' ? 'browser_capture' : 'external_import'));
}

function clearFileItems() {
    captureSessionId += 1;
    fileItems.value.forEach((i) => {
        if (i.previewUrl) URL.revokeObjectURL(i.previewUrl);
    });
    fileItems.value = [];
}

// Compartilhado por onFilesChange() (upload) e capturePhoto()/stopRecording()
// (câmera) — mesma lista fileItems, mesmo form.files, zero mudança de endpoint.
//
// `laterality` é SEMPRE recebido como parâmetro explícito, nunca lido de
// form.laterality aqui dentro: capturePhoto()/startRecording() disparam uma
// operação ASSÍNCRONA (canvas.toBlob()/MediaRecorder) antes de chamar esta
// função, e o pedal "avançar" pode trocar form.laterality ENTRE o disparo e
// o callback. Ler form.laterality só no momento em que addCapturedFile() é
// chamada (ou seja, dentro do callback) rotularia a captura com o olho NOVO,
// não o que estava selecionado quando o obturador/gravação foi acionado
// (achado via revisão Codex). Cada chamador deve capturar form.laterality
// num const local no momento em que a ação É INICIADA.
//
// `sessionId` é a MESMA ideia aplicada à sessão de import inteira: se o
// painel fechar e reabrir pra OUTRO paciente enquanto o toBlob()/onstop
// ainda não disparou, captureSessionId já mudou (clearFileItems() do reset)
// — descarta em silêncio em vez de contaminar o lote do paciente novo com
// o arquivo do paciente anterior (achado via revisão Codex).
function addCapturedFile(file, laterality, sessionId) {
    if (sessionId !== captureSessionId) return;
    fileItems.value.push({
        id: `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
        file,
        name: file.name,
        size: file.size,
        isImage: /^image\//.test(file.type),
        isVideo: /^video\//.test(file.type),
        laterality,
        previewUrl: /^image\//.test(file.type) ? URL.createObjectURL(file) : null,
        origin: 'capture',
    });
    syncFormFiles();
}

// ── Captura pela câmera do navegador (Fase 5 / 6a) ──────────────────────────
// Segmented control "Enviar arquivo" | "Capturar da câmera". getUserMedia
// exige HTTPS (ou localhost) e permissão explícita do usuário por origem —
// falha de permissão/câmera ausente precisa aparecer pro usuário, nunca só
// no console (achado no plano original — risco documentado).
const captureMode = ref('upload'); // 'upload' | 'camera'
const videoDevices = ref([]); // [{deviceId, label}]
const selectedDeviceId = ref('');
const cameraStream = ref(null);
const cameraError = ref('');
const cameraStarting = ref(false);
const videoEl = ref(null);
// srcObject = stream (startCamera()) não significa frame decodificado —
// videoWidth/videoHeight continuam 0 até o navegador decodificar o 1º
// frame (evento loadeddata). cameraStarting já vira false antes disso
// (startCamera() só espera o getUserMedia(), não o vídeo de fato
// carregar), deixando "Foto" clicável numa janela em que drawImage()
// lança InvalidStateError (dimensão 0) ou produz um blob inútil sem
// nenhum aviso — achado via revisão Codex.
const videoFrameReady = ref(false);
const canvasEl = ref(null);
const isRecording = ref(false);
// Contador, não booleano: cobre foto E vídeo, inclusive o caso raro de duas
// capturas ainda finalizando ao mesmo tempo (ex.: tira foto e já começa/para
// uma gravação antes do toBlob() da foto terminar). canvas.toBlob()
// (capturePhoto()) e MediaRecorder.onstop (stopRecording(true)) são AMBOS
// assíncronos — nenhum dos dois tem uma flag própria tipo isRecording que
// fique true durante essa janela. "Importar" clicado nela mandava o lote
// SEM a captura ainda em voo, e um import bem-sucedido limpa a lista e
// invalida a sessão de captura, perdendo a foto/vídeo em silêncio (achado
// via revisão Codex — 1ª rodada só cobriu vídeo/onstop, a foto/toBlob()
// tem exatamente a mesma janela).
const pendingCaptureFinalizations = ref(0);
const finalizingCapture = computed(() => pendingCaptureFinalizations.value > 0);
const recordingSeconds = ref(0);
// Incrementado a cada clearFileItems() (reabrir o painel pra outro
// paciente, ou depois de um submit bem-sucedido) — invalida qualquer
// captura pendente (canvas.toBlob()/recorder.onstop, ambos assíncronos).
// Sem isto, fechar o painel e reabrir pra OUTRO paciente enquanto uma foto
// ainda estava sendo codificada fazia addCapturedFile() empurrar a foto do
// paciente ANTERIOR pro lote do paciente NOVO (achado via revisão Codex).
let captureSessionId = 0;

let mediaRecorder = null;
let recordingTimer = null;
let recordingAutoStop = null;

function cameraErrorMessage(error) {
    if (error?.name === 'NotAllowedError' || error?.name === 'PermissionDeniedError') {
        return tt(
            'capture_permission_denied',
            'Permissão da câmera negada. Libere o acesso nas configurações do navegador e tente de novo.',
        );
    }
    if (error?.name === 'NotFoundError' || error?.name === 'DevicesNotFoundError') {
        return tt('capture_not_found', 'Nenhuma câmera encontrada neste dispositivo.');
    }
    return tt('capture_generic_error', 'Não foi possível acessar a câmera.');
}

async function refreshDeviceList() {
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        videoDevices.value = devices.filter((d) => d.kind === 'videoinput');
    } catch {
        videoDevices.value = [];
    }
}

// Incrementado a cada stopCamera() — invalida um startCamera() pendente
// (getUserMedia() demora até o usuário decidir no diálogo nativo de
// permissão do navegador, que pode levar segundos/nunca responder). Sem
// isto, fechar o painel ou trocar pra aba de upload NO MEIO dessa espera
// não impedia o stream de ser atribuído quando a permissão enfim chegasse
// — câmera ficava acesa atrás do painel já fechado (achado via revisão Codex).
let cameraRequestId = 0;

async function startCamera() {
    cameraError.value = '';
    videoFrameReady.value = false;

    if (!navigator.mediaDevices?.getUserMedia) {
        cameraError.value = tt(
            'capture_not_supported',
            'Captura pela câmera requer conexão segura (HTTPS) e não é suportada neste navegador.',
        );
        return;
    }

    const requestId = ++cameraRequestId;
    cameraStarting.value = true;
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: selectedDeviceId.value ? { deviceId: { exact: selectedDeviceId.value } } : true,
            audio: false,
        });

        if (requestId !== cameraRequestId) {
            // stopCamera() já rodou enquanto esperava o navegador — descarta
            // sem nunca tocar em cameraStream/videoEl (já não existem mais
            // neste contexto, ou pertencem a uma sessão de câmera nova).
            stream.getTracks().forEach((track) => track.stop());
            return;
        }

        cameraStream.value = stream;
        await nextTick();
        if (videoEl.value) videoEl.value.srcObject = stream;
        await refreshDeviceList();
        if (!selectedDeviceId.value) {
            selectedDeviceId.value = stream.getVideoTracks()[0]?.getSettings?.().deviceId ?? '';
        }
    } catch (error) {
        if (requestId === cameraRequestId) cameraError.value = cameraErrorMessage(error);
    } finally {
        if (requestId === cameraRequestId) cameraStarting.value = false;
    }
}

function stopCamera() {
    cameraRequestId += 1;
    if (isRecording.value) stopRecording(false);
    cameraStream.value?.getTracks().forEach((track) => track.stop());
    cameraStream.value = null;
    if (videoEl.value) videoEl.value.srcObject = null;
    videoFrameReady.value = false;
    // Invalidar uma requisição de startCamera() pendente (cameraRequestId++
    // acima) sempre significa "não estamos mais numa tentativa de iniciar",
    // mesmo que o próprio getUserMedia() ainda não tenha resolvido/rejeitado.
    // O guard `if (requestId === cameraRequestId)` no finally de startCamera()
    // vai pular o reset dele quando essa resposta tardia chegar — sem esta
    // linha, nada mais reseta cameraStarting e o botão "Ativar câmera" (que
    // usa :disabled="cameraStarting") fica desabilitado pra sempre depois de
    // um cancelamento nesta janela (achado via revisão Codex).
    cameraStarting.value = false;
}

async function switchDevice() {
    stopCamera();
    await startCamera();
}

function capturePhoto() {
    // form.post() manda uma FOTOGRAFIA do form no momento da chamada — uma
    // captura feita DEPOIS disso nunca entra na requisição já em voo, e o
    // onSuccess (clearFileItems()) perdia ela em silêncio quando a resposta
    // chegasse (achado via revisão Codex).
    if (!videoEl.value || !canvasEl.value || !videoFrameReady.value || isRecording.value || form.processing) return;

    const video = videoEl.value;
    const canvas = canvasEl.value;
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    // Snapshot do olho E da sessão de import NO MOMENTO do disparo
    // (obturador), ANTES do toBlob() assíncrono — ver addCapturedFile().
    const laterality = form.laterality;
    const sessionId = captureSessionId;

    // toBlob() é assíncrono (achado via revisão Codex) — entre o obturador
    // e o blob pronto, "Importar" não via nada pendente. Decrementa sempre,
    // mesmo com blob nulo (falha de encode): nada fica pendente pra sempre.
    pendingCaptureFinalizations.value += 1;
    canvas.toBlob(
        (blob) => {
            pendingCaptureFinalizations.value -= 1;
            if (!blob) return;
            addCapturedFile(
                new File([blob], `captura-${Date.now()}.jpg`, { type: 'image/jpeg' }),
                laterality,
                sessionId,
            );
            if (window.showSuccessToast) window.showSuccessToast(tt('capture_photo_taken', 'Foto capturada.'));
        },
        'image/jpeg',
        0.92,
    );
}

// webm com VP9 primeiro (menor arquivo); mp4 só existe em navegadores que
// não suportam webm (Safari) — ver Riscos do plano (codec varia por navegador).
const RECORDER_MIME_CANDIDATES = ['video/webm;codecs=vp9', 'video/webm', 'video/mp4'];

function pickRecorderMimeType() {
    if (!window.MediaRecorder?.isTypeSupported) return '';
    return RECORDER_MIME_CANDIDATES.find((type) => window.MediaRecorder.isTypeSupported(type)) ?? '';
}

function startRecording() {
    // Mesmo motivo do guard em capturePhoto(): nunca iniciar uma captura
    // nova enquanto o lote atual já foi mandado pro servidor.
    if (!cameraStream.value || isRecording.value || !window.MediaRecorder || form.processing) return;

    const mimeType = pickRecorderMimeType();
    // Local a ESTA gravação, nunca module-level: cada MediaRecorder tem seu
    // próprio buffer, fechado só pelos SEUS callbacks. Antes, um `let`
    // compartilhado era resetado no início de toda nova gravação — se o
    // dataavailable FINAL de uma gravação descartada (stopRecording(false),
    // onstop já anulado, mas dataavailable não) chegasse DEPOIS de outra
    // gravação já ter começado, os bytes da gravação velha entravam no
    // buffer da gravação nova (vídeo corrompido, ou pior, pedaço de um
    // paciente anterior colado no vídeo do paciente atual) — achado via
    // revisão Codex.
    const chunks = [];

    // Snapshot do olho E da sessão de import NO MOMENTO em que a gravação
    // COMEÇA, não quando termina — uma gravação dura até maxVideoSeconds
    // (padrão 20s), janela bem maior que a de uma foto pro pedal avançar o
    // olho (ou o painel fechar/reabrir pra outro paciente) ANTES do onstop
    // disparar (mesma causa raiz de capturePhoto(), ver addCapturedFile()).
    const laterality = form.laterality;
    const sessionId = captureSessionId;

    let recorder;
    try {
        recorder = mimeType
            ? new MediaRecorder(cameraStream.value, { mimeType })
            : new MediaRecorder(cameraStream.value);
    } catch {
        cameraError.value = tt('capture_generic_error', 'Não foi possível acessar a câmera.');
        return;
    }

    // onstop fecha sobre `recorder` (local, fixo) — nunca a variável
    // mutável `mediaRecorder` do módulo, que stopRecording() já zera antes
    // do evento assíncrono de stop disparar.
    recorder.ondataavailable = (e) => {
        if (e.data.size > 0) chunks.push(e.data);
    };
    recorder.onstop = () => {
        const type = recorder.mimeType || mimeType || 'video/webm';
        const extension = type.includes('mp4') ? 'mp4' : 'webm';
        if (chunks.length) {
            addCapturedFile(new File(chunks, `captura-${Date.now()}.${extension}`, { type }), laterality, sessionId);
            if (window.showSuccessToast) window.showSuccessToast(tt('capture_video_recorded', 'Vídeo capturado.'));
        }
        pendingCaptureFinalizations.value -= 1;

        // MediaRecorder pode parar por conta própria (câmera desconectada,
        // permissão revogada, track termina) sem nunca passar por
        // stopRecording() — que é quem normalmente zera isRecording e os
        // timers na hora do clique em "Parar". Se mediaRecorder ainda
        // aponta pra ESTE recorder, foi exatamente isso: mais ninguém vai
        // limpar, faz aqui (achado via revisão Codex).
        if (mediaRecorder === recorder) {
            clearInterval(recordingTimer);
            clearTimeout(recordingAutoStop);
            recordingTimer = null;
            recordingAutoStop = null;
            isRecording.value = false;
            mediaRecorder = null;
        }
    };

    // start() pode lançar mesmo com o construtor tendo funcionado — ex.: a
    // track do stream termina (câmera cai) no intervalo entre `new
    // MediaRecorder()` e start(). Chamado ANTES de qualquer estado nosso
    // mudar (contador, mediaRecorder, isRecording): se falhar, não há nada
    // pra desfazer, só sair — sem isto, o contador já tinha sido somado e
    // nunca seria decrementado, já que onstop nunca dispara pra um recorder
    // que nunca chegou a gravar (achado via revisão Codex).
    try {
        recorder.start();
    } catch {
        cameraError.value = tt('capture_generic_error', 'Não foi possível acessar a câmera.');
        return;
    }

    // Somado aqui (não em stopRecording()) porque onstop SEMPRE dispara
    // exatamente uma vez por gravação iniciada — pelo clique em "Parar" ou
    // autonomamente — então 1 incremento aqui pra 1 decremento lá em cima
    // fecha a conta sem precisar saber quem disparou o stop. Antes, o
    // incremento só existia dentro de stopRecording(true): um stop
    // autônomo decrementava um incremento que nunca tinha acontecido,
    // deixando o contador negativo — e podia até cancelar uma FOTO ainda
    // pendente de outra captura (achado via revisão Codex).
    pendingCaptureFinalizations.value += 1;

    mediaRecorder = recorder;
    isRecording.value = true;
    recordingSeconds.value = 0;
    recordingTimer = setInterval(() => {
        recordingSeconds.value += 1;
    }, 1000);
    recordingAutoStop = setTimeout(() => stopRecording(true), (props.maxVideoSeconds || 20) * 1000);
}

// save=false descarta sem persistir (ex.: fechar o modal/trocar de câmera
// no meio de uma gravação) — remove o onstop antes de parar, então o File
// nunca chega a ser criado.
function stopRecording(save = true) {
    clearInterval(recordingTimer);
    clearTimeout(recordingAutoStop);
    recordingTimer = null;
    recordingAutoStop = null;
    isRecording.value = false;

    const recorder = mediaRecorder;
    mediaRecorder = null;
    if (recorder && recorder.state !== 'inactive') {
        // save=false descarta: onstop nunca roda (anulado abaixo), então o
        // incremento de startRecording() precisa ser desfeito aqui mesmo —
        // mais ninguém vai decrementar. save=true deixa o onstop fazer o
        // decremento dele, depois que o vídeo entrar no lote — é exatamente
        // essa janela entre "parou" e "o vídeo entrou no lote" que
        // finalizingCapture cobre.
        if (!save) {
            recorder.onstop = null;
            pendingCaptureFinalizations.value -= 1;
        }
        recorder.stop();
    }
}

watch(captureMode, (mode) => {
    if (mode !== 'camera') stopCamera();
});

onBeforeUnmount(stopCamera);

// ── Pedal USB (Fase 5 / 7a/7b) ───────────────────────────────────────────
// O pedal "age como teclado": dispara keydown com uma das teclas da
// allowlist (config/eye_image_capture.php, nunca Escape/setas). Tecla
// "capturar" tira foto (ou, se já está gravando, encerra e salva — um
// botão só cobre os dois casos mais comuns). Tecla "avançar" cicla a
// lateralidade OD→OE→AO pra próxima captura (a MESMA tecla também passa
// pra próxima imagem no visualizador — EyeImages/Index.vue — já que os
// dois momentos nunca ficam abertos ao mesmo tempo).
const { getPreference: getPedalPreference, savePreference: savePedalPreference } = useUserPreferences();
const { date } = useLocaleFormat();

const pedalAllowedKeys = computed(
    () => props.pedalShortcutsConfig?.allowed_keys ?? ['Enter', ' ', 'PageDown', 'PageUp'],
);
// Preferência AUSENTE (nunca configurada) cai no default; preferência
// PRESENTE mas null (usuário desligou esta tecla de propósito — PATCH
// /panel/preferences aceita null aqui, ver PreferencesTest.php) fica null
// mesmo — nenhum KeyboardEvent.key é null de verdade, então a comparação
// no handler nunca casa (desliga o atalho sem precisar de valor sentinela).
// `?? default` sozinho tratava as duas situações como a MESMA coisa —
// "desligado de propósito" sempre recaía no Enter/Espaço padrão (achado
// via revisão Codex).
function resolvedPedalKey(savedKey, defaultFromConfig, hardDefault) {
    const saved = getPedalPreference('eye_images_pedal_shortcuts', {});

    return savedKey in saved ? saved[savedKey] : (defaultFromConfig ?? hardDefault);
}

const pedalCaptureKey = computed(() =>
    resolvedPedalKey('capture_key', props.pedalShortcutsConfig?.default_capture_key, 'Enter'),
);
const pedalAdvanceKey = computed(() =>
    resolvedPedalKey('advance_key', props.pedalShortcutsConfig?.default_advance_key, ' '),
);

// Cada <select> nunca oferece o valor que o OUTRO já usa — impossível
// configurar as duas teclas iguais pela UI (o backend também rejeita,
// Rule::in + "devem ser diferentes", pra cobrir uma request feita à mão).
const pedalCaptureOptions = computed(() => pedalAllowedKeys.value.filter((k) => k !== pedalAdvanceKey.value));
const pedalAdvanceOptions = computed(() => pedalAllowedKeys.value.filter((k) => k !== pedalCaptureKey.value));

const PEDAL_KEY_LABEL_KEYS = {
    Enter: 'pedal_key_enter',
    ' ': 'pedal_key_space',
    PageDown: 'pedal_key_pagedown',
    PageUp: 'pedal_key_pageup',
};
function pedalKeyLabel(key) {
    return tt(PEDAL_KEY_LABEL_KEYS[key] ?? '', key);
}

// As 2 teclas sempre são salvas JUNTAS (array_merge de UserPreference é
// raso): mandar só a que mudou apagaria a outra no próximo load.
function setPedalKey(which, value) {
    savePedalPreference('eye_images_pedal_shortcuts', {
        capture_key: which === 'capture' ? value : pedalCaptureKey.value,
        advance_key: which === 'advance' ? value : pedalAdvanceKey.value,
    });
}

const LATERALITY_CYCLE = [1, 2, null]; // OD → OE → AO → OD...
function advanceLaterality() {
    const idx = LATERALITY_CYCLE.indexOf(form.laterality);
    form.laterality = LATERALITY_CYCLE[(idx + 1) % LATERALITY_CYCLE.length];
}

// Corrige o olho de UM item já na lista (upload ou captura), sem tocar nos
// outros — nunca reaplica o rádio atual em massa: isso quebraria o lote
// misto (ex. OD por vários arquivos, troca pra OE só pros próximos), que é
// exatamente o que o snapshot em onFilesChange()/addCapturedFile() protege
// (achado na 10ª rodada de revisão adversarial Codex: sem controle nenhum
// pra corrigir, só removendo e readicionando o arquivo).
function cycleItemLaterality(item) {
    const idx = LATERALITY_CYCLE.indexOf(item.laterality);
    item.laterality = LATERALITY_CYCLE[(idx + 1) % LATERALITY_CYCLE.length];
    syncFormFiles();
}

function isTypingTarget(el) {
    const tag = el?.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || !!el?.isContentEditable;
}

// Enter/Espaço com foco num <button> (ou link) disparam o click NATIVO do
// navegador (ativação por teclado, acessibilidade) — isso vale pra ESSAS
// DUAS teclas especificamente, nunca depende de qual tecla o pedal está
// configurado. Interceptar com preventDefault() cancelava esse click
// nativo, deixando o botão focado "morto" pro teclado — e o pedal,
// hardcoded pra capturar foto, rodava a ação ERRADA no lugar (ex.: tirava
// foto em vez de ligar a câmera, ou em vez de começar a gravar) — achado
// via revisão Codex.
function isNativeActivationTarget(el, key) {
    return (key === 'Enter' || key === ' ') && (el?.tagName === 'BUTTON' || el?.tagName === 'A');
}

function onPedalKeydown(event) {
    if (
        event.isComposing ||
        isTypingTarget(event.target) ||
        isNativeActivationTarget(event.target, event.key) ||
        captureMode.value !== 'camera'
    )
        return;

    if (event.key === pedalCaptureKey.value) {
        event.preventDefault();
        if (isRecording.value) stopRecording(true);
        else capturePhoto();
    } else if (event.key === pedalAdvanceKey.value) {
        event.preventDefault();
        advanceLaterality();
    }
}

onBeforeUnmount(() => window.removeEventListener('keydown', onPedalKeydown));

// ── Validação client-side básica (a de verdade é sempre a do backend) ───────
const clientError = ref('');

function validateClientSide() {
    if (!form.patient_id) return 'Selecione o paciente.';
    if (!form.exam_id) return 'Selecione o tipo de exame.';
    // Gravação em andamento: "pare" é a instrução certa (ação do usuário).
    if (isRecording.value) return tt('capture_recording_in_progress', 'Pare a gravação antes de importar.');
    // Foto (canvas.toBlob()) ou vídeo (onstop) ainda terminando de entrar em
    // fileItems/form.files — nada pra "parar" aqui, só esperar a captura
    // finalizar (normalmente alguns milissegundos). Importar nessa janela
    // mandava o lote SEM essa captura, e o sucesso fechando o painel
    // descartava/perdia ela em silêncio (achado via revisão Codex — a 1ª
    // rodada só cobriu o onstop do vídeo; o toBlob() da foto tem a mesma janela).
    if (finalizingCapture.value) {
        return tt('capture_finalizing_in_progress', 'Aguarde a captura terminar antes de importar.');
    }
    if (fileItems.value.length === 0) return 'Selecione ao menos um arquivo.';
    return '';
}

// Erros que não têm campo próprio no template (ex.: "diagnoses", "diagnoses.0").
const knownFields = [
    'patient_id',
    'exam_id',
    'exam_performed_at',
    'doctor_id',
    'entity_integrator_equipment_id',
    'external_origin',
    'laterality',
];
const genericErrors = computed(() =>
    Object.entries(form.errors ?? {})
        .filter(([key]) => !knownFields.includes(key) && key !== 'files' && !key.startsWith('files.'))
        .map(([, msg]) => msg),
);

function submit() {
    clientError.value = '';
    const err = validateClientSide();
    if (err) {
        clientError.value = err;
        return;
    }

    // A origem de cada arquivo já está em form.files_source (syncFormFiles(),
    // derivada de fileItems — nunca de um flag do lote): mistura upload +
    // captura no mesmo lote é permitida e cada arquivo leva a PRÓPRIA origem.
    form.post(props.urls.import_store, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            clearFileItems();
            form.reset();
            emit('imported');
        },
        onError: () => {
            clientError.value = 'Verifique os campos destacados abaixo.';
        },
    });
}

function close() {
    emit('close');
}

// ── Reset ao abrir (mesmo padrão de DiagnosisManagerModal.vue) ─────────────
watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            // Câmera nunca fica acesa com o painel fechado — OffcanvasPanel
            // desmonta o conteúdo do slot via v-if interno, mas isso NÃO
            // chama track.stop() por conta própria (removeu o <video> do DOM,
            // não "desliga a luz" da câmera).
            stopCamera();
            window.removeEventListener('keydown', onPedalKeydown);
            return;
        }

        window.addEventListener('keydown', onPedalKeydown);
        form.clearErrors();
        clientError.value = '';
        createDiagnosisError.value = '';
        clearFileItems();
        originMode.value = props.equipments?.length ? 'equipment' : 'manual';

        captureMode.value = 'upload';
        cameraError.value = '';
        selectedDeviceId.value = '';
        videoDevices.value = [];

        form.patient_id = props.patient?.id ?? '';
        form.doctor_id = '';
        form.external_origin = '';
        form.laterality = null;
        form.diagnoses = [];
        form.files = [];
        form.files_laterality = [];
        form.files_source = [];

        if (props.presetGroup) {
            form.exam_id = props.presetGroup.examTypeId ?? '';
            form.exam_performed_at = props.presetGroup.examPerformedAt ?? todayIso();
            form.entity_integrator_equipment_id = props.presetGroup.equipmentId ?? '';
            originMode.value = props.presetGroup.equipmentId ? 'equipment' : 'manual';
            presetLocked.value = true;
        } else {
            form.exam_id = '';
            form.exam_performed_at = todayIso();
            form.entity_integrator_equipment_id = '';
            presetLocked.value = false;
        }

        pickedPatient.value = null;
        patientQuery.value = '';
        patientResults.value = [];
    },
);
</script>

<template>
    <OffcanvasPanel :open="open" :width="640" @close="close">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-upload me-2 text-primary"></i>
                {{ presetGroup ? 'Adicionar imagem ao exame' : 'Importar exame externo' }}
            </h5>
            <p class="text-muted mt-1 mb-0" style="font-size: 0.8rem">
                {{
                    presetGroup
                        ? 'A imagem nova entra no mesmo grupo já exibido — tipo, data e equipamento vêm preenchidos.'
                        : 'Upload manual de exame realizado fora do integrador (ex.: laudo trazido pelo paciente).'
                }}
            </p>
        </template>

        <div v-if="clientError" class="alert alert-warning py-2 px-3 small mb-3">{{ clientError }}</div>
        <div v-if="genericErrors.length" class="alert alert-danger py-2 px-3 small mb-3">
            <div v-for="(msg, i) in genericErrors" :key="i">{{ msg }}</div>
        </div>

        <!-- Paciente -->
        <div class="mb-3">
            <label class="form-label fw-semibold">Paciente <span class="text-danger">*</span></label>

            <div v-if="hasFixedPatient" class="form-control bg-body-secondary d-flex align-items-center gap-2">
                <i class="fa fa-user text-muted"></i>
                <span>{{ patientLabel(patient) }}</span>
                <span v-if="patient.code" class="text-muted">({{ patient.code }})</span>
            </div>

            <div v-else class="position-relative">
                <div v-if="pickedPatient" class="form-control d-flex align-items-center justify-content-between">
                    <span>
                        <i class="fa fa-user text-muted me-2"></i>{{ patientLabel(pickedPatient) }}
                        <span v-if="pickedPatient.code" class="text-muted">({{ pickedPatient.code }})</span>
                    </span>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0" @click="clearPickedPatient">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <template v-else>
                    <input
                        v-model="patientQuery"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': form.errors.patient_id }"
                        placeholder="Buscar paciente por nome, código ou telefone..."
                        autocomplete="off"
                        @input="onPatientInput"
                    />
                    <span v-if="patientSearching" class="position-absolute top-50 end-0 translate-middle-y me-3">
                        <span class="spinner-border spinner-border-sm text-secondary"></span>
                    </span>
                    <ul
                        v-if="patientResults.length > 0"
                        class="list-group position-absolute w-100 shadow-sm"
                        style="z-index: 1060; max-height: 220px; overflow-y: auto"
                    >
                        <li
                            v-for="p in patientResults"
                            :key="p.id"
                            class="list-group-item list-group-item-action py-2 px-3"
                            style="cursor: pointer"
                            @mousedown.prevent="selectPatient(p)"
                        >
                            <div class="fw-semibold small">{{ p.full_name }}</div>
                            <div class="text-muted" style="font-size: 0.75rem">
                                {{ p.cellphone || p.telephone || '—' }} &bull; {{ p.code }}
                            </div>
                        </li>
                    </ul>
                </template>
            </div>
            <div v-if="form.errors.patient_id" class="text-danger small mt-1">{{ form.errors.patient_id }}</div>
        </div>

        <div
            v-if="presetGroup && presetLocked"
            class="alert alert-info d-flex align-items-center justify-content-between py-2 px-3 mb-3"
        >
            <div style="font-size: 0.82rem">
                <i class="ti ti-folder-check me-1"></i>
                <strong>{{ presetGroup.examTypeName || 'Exame' }}</strong>
                — {{ date(form.exam_performed_at) }}
                <span v-if="presetGroup.equipmentName"> · {{ presetGroup.equipmentName }}</span>
            </div>
            <button type="button" class="btn btn-sm btn-link p-0" @click="presetLocked = false">
                Usar outro grupo
            </button>
        </div>

        <div v-if="!(presetGroup && presetLocked)" class="row g-3 mb-3">
            <div class="col-12 col-sm-6">
                <label class="form-label fw-semibold">Tipo de exame <span class="text-danger">*</span></label>
                <select v-model="form.exam_id" class="form-select" :class="{ 'is-invalid': form.errors.exam_id }">
                    <option value="">Selecione...</option>
                    <option v-for="t in examTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <div v-if="form.errors.exam_id" class="invalid-feedback d-block">{{ form.errors.exam_id }}</div>
            </div>

            <div class="col-12 col-sm-6">
                <label class="form-label fw-semibold">Data do exame <span class="text-danger">*</span></label>
                <input
                    v-model="form.exam_performed_at"
                    type="date"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.exam_performed_at }"
                    :max="todayIso()"
                />
                <div v-if="form.errors.exam_performed_at" class="invalid-feedback d-block">
                    {{ form.errors.exam_performed_at }}
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold d-block">Olho</label>
            <!-- :value (binding dinâmico), nunca value="1" (atributo HTML
                 estático, sempre string) — form.laterality precisa ficar
                 NÚMERO/null igual LATERALITY_CYCLE/fileLateralityLabel()
                 abaixo (comparação estrita). value="1" fazia form.laterality
                 virar a STRING '1': advanceLaterality() (pedal) não achava
                 '1' no array numérico e sempre voltava pra OD em vez de
                 avançar pra AO, e fileLateralityLabel() rotulava qualquer
                 captura feita com OD/OE selecionado no rádio como AO
                 (achado via revisão Codex). -->
            <div class="btn-group btn-group-sm" role="group" :class="{ 'is-invalid': form.errors.laterality }">
                <input id="import-laterality-od" v-model="form.laterality" type="radio" class="btn-check" :value="1" />
                <label class="btn btn-outline-primary" for="import-laterality-od">OD</label>

                <input id="import-laterality-oe" v-model="form.laterality" type="radio" class="btn-check" :value="2" />
                <label class="btn btn-outline-danger" for="import-laterality-oe">OE</label>

                <input
                    id="import-laterality-ao"
                    v-model="form.laterality"
                    type="radio"
                    class="btn-check"
                    :value="null"
                />
                <label class="btn btn-outline-secondary" for="import-laterality-ao">AO</label>
            </div>
            <div v-if="form.errors.laterality" class="invalid-feedback d-block">{{ form.errors.laterality }}</div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Médico</label>
            <select v-model="form.doctor_id" class="form-select" :class="{ 'is-invalid': form.errors.doctor_id }">
                <option value="">Não informado</option>
                <option v-for="d in doctors" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
            <div v-if="form.errors.doctor_id" class="invalid-feedback d-block">{{ form.errors.doctor_id }}</div>
        </div>

        <!-- Origem -->
        <div v-if="!(presetGroup && presetLocked)" class="mb-3">
            <label class="form-label fw-semibold d-block">Origem do exame</label>
            <div class="btn-group btn-group-sm mb-2" role="group">
                <input
                    id="import-origin-equipment"
                    v-model="originMode"
                    type="radio"
                    class="btn-check"
                    value="equipment"
                    :disabled="!equipments.length"
                />
                <label class="btn btn-outline-secondary" for="import-origin-equipment">Equipamento cadastrado</label>

                <input id="import-origin-manual" v-model="originMode" type="radio" class="btn-check" value="manual" />
                <label class="btn btn-outline-secondary" for="import-origin-manual">Descrever origem</label>
            </div>

            <select
                v-if="originMode === 'equipment'"
                v-model="form.entity_integrator_equipment_id"
                class="form-select"
                :class="{ 'is-invalid': form.errors.entity_integrator_equipment_id }"
            >
                <option value="">Não informado</option>
                <option v-for="eq in equipments" :key="eq.id" :value="eq.id">{{ eq.name }}</option>
            </select>
            <input
                v-else
                v-model="form.external_origin"
                type="text"
                class="form-control"
                :class="{ 'is-invalid': form.errors.external_origin }"
                placeholder="Ex.: Clínica X, exame trazido pelo paciente..."
                maxlength="255"
            />

            <div v-if="form.errors.entity_integrator_equipment_id" class="invalid-feedback d-block">
                {{ form.errors.entity_integrator_equipment_id }}
            </div>
            <div v-if="form.errors.external_origin" class="invalid-feedback d-block">
                {{ form.errors.external_origin }}
            </div>
        </div>

        <!-- Diagnóstico (opcional) -->
        <div class="mb-3">
            <div v-if="createDiagnosisError" class="alert alert-warning py-2 px-3 small mb-2">
                {{ createDiagnosisError }}
            </div>
            <Cid10Picker
                v-model="form.diagnoses"
                :search-url="urls.diagnosis_search || ''"
                :most-used-url="urls.diagnosis_search || ''"
                allow-custom-entry
                primary-toggle
                :creating="creatingCustomDiagnosis"
                :max-items="20"
                label="Diagnóstico (opcional)"
                placeholder="Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…"
                @create="onCreateDiagnosis"
            />
        </div>

        <!-- Arquivos -->
        <div class="mb-2">
            <label class="form-label fw-semibold">Arquivos <span class="text-danger">*</span></label>

            <!-- Fase 5 / 6a — segmented control: enviar arquivo (default, comportamento
                 preservado) ou capturar foto/vídeo pela câmera do navegador. -->
            <div class="btn-group btn-group-sm mb-2" role="group">
                <input id="import-capture-upload" v-model="captureMode" type="radio" class="btn-check" value="upload" />
                <label class="btn btn-outline-secondary" for="import-capture-upload">
                    <i class="ti ti-upload me-1"></i>{{ tt('capture_tab_upload', 'Enviar arquivo') }}
                </label>

                <input id="import-capture-camera" v-model="captureMode" type="radio" class="btn-check" value="camera" />
                <label class="btn btn-outline-secondary" for="import-capture-camera">
                    <i class="ti ti-camera me-1"></i>{{ tt('capture_tab_camera', 'Capturar da câmera') }}
                </label>
            </div>

            <template v-if="captureMode === 'upload'">
                <input
                    type="file"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.files }"
                    multiple
                    accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                    @change="onFilesChange"
                />
                <div class="form-text">Até 10 arquivos, JPG/PNG/PDF, até 10 MB cada.</div>
            </template>

            <!-- Câmera (Fase 5 / 6a) -->
            <div v-else>
                <!-- Pedal USB (Fase 5 / 7b) — opcional; cada select nunca
                     oferece a tecla que a outra já usa. -->
                <div class="d-flex gap-3 align-items-end flex-wrap mb-2">
                    <div>
                        <label class="form-label mb-1" style="font-size: 0.72rem">
                            {{ tt('pedal_capture_key_label', 'Pedal — capturar') }}
                        </label>
                        <select
                            class="form-select form-select-sm"
                            style="width: 140px"
                            :value="pedalCaptureKey"
                            @change="setPedalKey('capture', $event.target.value)"
                        >
                            <option v-for="k in pedalCaptureOptions" :key="k" :value="k">{{ pedalKeyLabel(k) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label mb-1" style="font-size: 0.72rem">
                            {{ tt('pedal_advance_key_label', 'Pedal — avançar') }}
                        </label>
                        <select
                            class="form-select form-select-sm"
                            style="width: 140px"
                            :value="pedalAdvanceKey"
                            @change="setPedalKey('advance', $event.target.value)"
                        >
                            <option v-for="k in pedalAdvanceOptions" :key="k" :value="k">{{ pedalKeyLabel(k) }}</option>
                        </select>
                    </div>
                    <div class="text-muted" style="font-size: 0.7rem; max-width: 220px">
                        {{
                            tt(
                                'pedal_shortcuts_help',
                                'Se você usa um pedal USB (ele funciona como um teclado), escolha aqui quais teclas ele envia.',
                            )
                        }}
                    </div>
                </div>

                <div v-if="cameraError" class="alert alert-warning py-2 px-3 small">{{ cameraError }}</div>

                <div v-if="!cameraStream" class="text-center py-4 border rounded bg-body-secondary">
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="cameraStarting"
                        @click="startCamera"
                    >
                        <span v-if="cameraStarting" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-camera me-1"></i>{{ tt('capture_start_button', 'Ativar câmera') }}
                    </button>
                </div>

                <template v-else>
                    <select
                        v-if="videoDevices.length > 1"
                        v-model="selectedDeviceId"
                        class="form-select form-select-sm mb-2"
                        @change="switchDevice"
                    >
                        <option v-for="d in videoDevices" :key="d.deviceId" :value="d.deviceId">
                            {{ d.label || tt('capture_device_label', 'Câmera') }}
                        </option>
                    </select>

                    <div class="position-relative bg-dark rounded overflow-hidden mb-2">
                        <video
                            ref="videoEl"
                            autoplay
                            muted
                            playsinline
                            class="w-100 d-block"
                            style="max-height: 320px; object-fit: contain"
                            @loadeddata="videoFrameReady = true"
                        ></video>
                        <span v-if="isRecording" class="badge bg-danger position-absolute top-0 start-0 m-2">
                            <i class="ti ti-circle-filled me-1"></i>
                            {{
                                fillText(tt('capture_recording_label', 'Gravando… :seconds s'), {
                                    seconds: recordingSeconds,
                                })
                            }}
                        </span>
                    </div>
                    <canvas ref="canvasEl" class="d-none"></canvas>

                    <div class="d-flex gap-2">
                        <button
                            type="button"
                            class="btn btn-outline-primary btn-sm"
                            :disabled="!videoFrameReady || isRecording || form.processing"
                            @click="capturePhoto"
                        >
                            <i class="ti ti-camera me-1"></i>{{ tt('capture_photo_button', 'Foto') }}
                        </button>
                        <button
                            v-if="!isRecording"
                            type="button"
                            class="btn btn-outline-danger btn-sm"
                            :disabled="form.processing"
                            @click="startRecording"
                        >
                            <i class="ti ti-video me-1"></i>{{ tt('capture_record_button', 'Gravar vídeo') }}
                        </button>
                        <button v-else type="button" class="btn btn-danger btn-sm" @click="stopRecording(true)">
                            <i class="ti ti-player-stop me-1"></i>{{ tt('capture_stop_button', 'Parar gravação') }}
                        </button>
                    </div>
                </template>
            </div>

            <div v-if="form.errors.files" class="invalid-feedback d-block">{{ form.errors.files }}</div>

            <!-- Lista combinada (upload + capturas) — mesmo form.files pros dois. -->
            <ul v-if="fileItems.length" class="list-group mt-2">
                <li
                    v-for="(item, idx) in fileItems"
                    :key="item.id"
                    class="list-group-item d-flex align-items-center gap-2 py-2"
                >
                    <img
                        v-if="item.previewUrl"
                        :src="item.previewUrl"
                        alt=""
                        style="width: 36px; height: 36px; object-fit: cover; border-radius: 4px; flex-shrink: 0"
                    />
                    <i
                        v-else-if="item.isVideo"
                        class="ti ti-video text-primary"
                        style="font-size: 1.4rem; width: 36px; text-align: center; flex-shrink: 0"
                    ></i>
                    <i
                        v-else
                        class="fa fa-file-pdf text-danger"
                        style="font-size: 1.4rem; width: 36px; text-align: center; flex-shrink: 0"
                    ></i>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="small fw-semibold text-truncate">
                            {{ item.name }}
                            <!-- Olho gravado por item (Fase 5 / 7b) — confirma visualmente que o
                                 pedal "avançar" entre capturas não rotulou tudo igual. Clicável
                                 (Fase 5 / 7b, 10ª rodada de revisão adversarial Codex): corrige SÓ
                                 este item sem precisar remover e readicionar o arquivo. -->
                            <button
                                type="button"
                                class="badge bg-secondary-subtle text-secondary-emphasis ms-1 border-0"
                                :title="tt('capture_item_laterality_fix', 'Clique para corrigir o olho deste arquivo')"
                                @click="cycleItemLaterality(item)"
                            >
                                {{ fileLateralityLabel(item.laterality) }}
                            </button>
                        </div>
                        <div class="text-muted" style="font-size: 0.72rem">{{ formatBytes(item.size) }}</div>
                        <div v-if="form.errors[`files.${idx}`]" class="text-danger small">
                            {{ form.errors[`files.${idx}`] }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="btn btn-sm btn-link text-danger flex-shrink-0"
                        @click="removeFile(item.id)"
                    >
                        <i class="fa fa-trash"></i>
                    </button>
                </li>
            </ul>
        </div>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary btn-sm" @click="close">Cancelar</button>
            <button type="button" class="btn btn-primary btn-sm" :disabled="form.processing" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                Importar
            </button>
        </template>
    </OffcanvasPanel>
</template>
