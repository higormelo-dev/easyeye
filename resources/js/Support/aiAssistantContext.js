import { reactive } from 'vue';

/**
 * Estado singleton (módulo ES = uma instância só na app) que páginas podem
 * popular para oferecer contexto opcional ao Assistente Virtual flutuante
 * (AiFloatingAssistant.vue, montado uma vez em AppLayout.vue).
 *
 * "Opcional" é a palavra-chave do pedido de produto: o widget NUNCA manda
 * patient_id/medical_record_id para o backend sem o médico apertar o toggle
 * "Usar contexto desta tela" — este objeto só disponibiliza a opção; ativar
 * é sempre decisão explícita do usuário na UI do widget (ver AiFloatingAssistant).
 *
 * Uso numa página/form:
 *   import { setAiContext, clearAiContext } from '@/Support/aiAssistantContext';
 *   onMounted(() => setAiContext({ patient_id, medical_record_id, label: 'Prontuário de João S.' }));
 *   onBeforeUnmount(clearAiContext);
 */
export const aiAssistantContext = reactive({
    patient_id: null,
    medical_record_id: null,
    exam_ids: [],
    label: '',
});

export function setAiContext({ patient_id = null, medical_record_id = null, exam_ids = [], label = '' } = {}) {
    aiAssistantContext.patient_id = patient_id;
    aiAssistantContext.medical_record_id = medical_record_id;
    aiAssistantContext.exam_ids = exam_ids;
    aiAssistantContext.label = label;
}

export function clearAiContext() {
    aiAssistantContext.patient_id = null;
    aiAssistantContext.medical_record_id = null;
    aiAssistantContext.exam_ids = [];
    aiAssistantContext.label = '';
}
