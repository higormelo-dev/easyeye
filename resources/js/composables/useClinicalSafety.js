import { reactive, ref, unref } from 'vue';
import { requestJson, firstError } from '@/Support/clinicalSafety.js';
import { latestRequest } from '@/Support/latestRequest.js';

/**
 * Situação de segurança clínica de UM paciente (Fase 2 / A1-A2): alergias,
 * "nega alergias", medicações em uso e alertas de risco ocular.
 *
 * Toda ação grava na hora (PatientClinicalSafetyController) e a resposta traz
 * a situação inteira — a tela troca o bloco e o alerta de uma vez. O servidor
 * decide quem pode o quê (recepção só registra "informado"; o médico com
 * cadastro ativo confirma/descarta) e devolve `can_review`.
 *
 * Devolve um objeto REATIVO (refs desembrulhadas): `store.summary`,
 * `store.busy`… — pode ser passado como prop aos blocos de edição.
 */
export function useClinicalSafety(patientId, initial = null) {
    const summary = ref(initial);
    const loading = ref(false);
    const busy = ref(false);
    const error = ref('');

    const url = (id, name, extra = null) => route(name, extra ? [id, extra] : id);

    // Paciente trocado com resposta pendente (o mesmo bloco serve ao A e
    // depois ao B — cadastro na Agenda/Pacientes): a resposta do A não entra
    // no B. Leitura: só a última vale; ação: só se o paciente ainda é o mesmo.
    const loads = latestRequest();
    const stillSamePatient = (id) => String(unref(patientId) ?? '') === String(id ?? '');

    async function load() {
        const id = unref(patientId);
        const req = loads.begin();
        loading.value = true;
        error.value = '';
        try {
            const res = await requestJson(url(id, 'panel.patients.clinical-safety.show'), { signal: req.signal });
            if (!req.current() || !stillSamePatient(id)) return false;
            if (res.ok) summary.value = res.data.data;
            else error.value = firstError(res.data, 'load_error');
            return res.ok;
        } catch {
            if (req.current()) error.value = 'load_error';
            return false;
        } finally {
            if (req.current()) loading.value = false;
        }
    }

    async function run(name, extra, method, body) {
        const id = unref(patientId);
        busy.value = true;
        error.value = '';
        try {
            const res = await requestJson(url(id, name, extra), { method, body });
            if (!stillSamePatient(id)) return { ok: res.ok, stale: true };
            if (res.ok) {
                summary.value = { ...(summary.value ?? {}), ...res.data.data };
                return { ok: true };
            }
            error.value = firstError(res.data, 'save_error');
            return { ok: false, errors: res.data?.errors ?? {}, message: error.value };
        } catch {
            error.value = 'save_error';
            return { ok: false, errors: {}, message: error.value };
        } finally {
            busy.value = false;
        }
    }

    return reactive({
        summary,
        loading,
        busy,
        error,
        load,
        // Trocou/fechou o paciente: resposta pendente não vale mais.
        cancel: () => {
            loads.cancel();
            loading.value = false;
        },
        addAllergy: (data) => run('panel.patients.allergies.store', null, 'POST', data),
        updateAllergy: (id, data) => run('panel.patients.allergies.update', id, 'PUT', data),
        confirmAllergy: (id) => run('panel.patients.allergies.confirm', id, 'POST', {}),
        discardAllergy: (id, reason = '') => run('panel.patients.allergies.discard', id, 'POST', { reason }),
        setDenied: (denied) => run('panel.patients.allergy-status.update', null, 'PUT', { denied }),
        addMedication: (data) => run('panel.patients.medications.store', null, 'POST', data),
        updateMedication: (id, data) => run('panel.patients.medications.update', id, 'PUT', data),
        confirmMedication: (id) => run('panel.patients.medications.confirm', id, 'POST', {}),
        setMedicationStatus: (id, status) => run('panel.patients.medications.status', id, 'POST', { status }),
    });
}
