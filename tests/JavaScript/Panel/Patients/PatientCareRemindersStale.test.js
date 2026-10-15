import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { effectScope, nextTick } from 'vue';

/**
 * Autorização de lembretes de retorno (LGPD): é um ATO da pessoa, não um campo do formulário. O valor carregado
 * voltava em TODO salvamento, então, se o paciente respondesse SAIR com a tela de edição aberta, salvar um campo sem
 * relação "marcava" de novo a caixa que a tela ainda mostrava e restaurava o consentimento retirado. Agora só vai ao
 * servidor o que a pessoa MUDOU (igual ao carregado = nulo, o servidor não mexe).
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();

    return { ...actual, usePage: () => ({ props: { t_ui: { patient_form: {} } } }) };
});

const { router } = await import('@inertiajs/vue3');
const { usePatientForm } = await import('@/Support/usePatientForm.js');

const json = (status, body) => Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });
const sent = (call) => JSON.parse(call[1].body);
/** O que cada salvamento mandou como autorização, na ordem. */
const consents = () => globalThis.fetch.mock.calls.map((call) => sent(call).care_reminders_consent);

let scope;

/** Abre o cadastro de um paciente cuja autorização carregada é `loaded` (o editData do servidor). */
async function openEdit(loaded) {
    globalThis.route = vi.fn((name, id) => `/_routes/${name}/${id ?? ''}`);
    globalThis.fetch = vi.fn((url) => {
        if (String(url).includes('editData')) {
            return json(200, { data: { name: 'MARIA', care_reminders_consent: loaded, whatsapp: true } });
        }

        return json(200, { data: { id: 'p-1' } });
    });

    scope = effectScope();
    const api = scope.run(() => usePatientForm({}));
    await api.loadEditData('p-1');
    globalThis.fetch.mockClear();

    return api;
}

beforeEach(() => {
    vi.clearAllMocks();
});

afterEach(() => {
    scope?.stop();
    scope = null;
});

describe('autorização de lembretes — só vai o que a pessoa mudou', () => {
    it('salvar outro campo com a caixa como carregada (marcada) NÃO reenvia a autorização', async () => {
        const { form, savePatient } = await openEdit(true);

        form.occupation = 'Aposentada';
        await savePatient('p-1');

        expect(sent(globalThis.fetch.mock.calls[0]).care_reminders_consent).toBeNull();
        // O valor na tela continua o carregado: só o que VAI é omitido.
        expect(form.care_reminders_consent).toBe(true);
    });

    it('idem desmarcada: não revoga uma autorização que outra pessoa registrou depois que a tela abriu', async () => {
        const { form, savePatient } = await openEdit(false);

        form.occupation = 'Aposentada';
        await savePatient('p-1');

        expect(sent(globalThis.fetch.mock.calls[0]).care_reminders_consent).toBeNull();
    });

    it('desmarcar (revogar) e marcar (autorizar de novo, depois de carregar desmarcada) são enviados', async () => {
        const marked = await openEdit(true);
        marked.form.care_reminders_consent = false;
        await marked.savePatient('p-1');

        expect(sent(globalThis.fetch.mock.calls[0]).care_reminders_consent).toBe(false);

        scope.stop();
        const unmarked = await openEdit(false);
        unmarked.form.care_reminders_consent = true;
        await unmarked.savePatient('p-1');

        expect(sent(globalThis.fetch.mock.calls[0]).care_reminders_consent).toBe(true);
    });

    it('desmarcar e marcar de volta fica igual ao carregado: não é um ato, não envia', async () => {
        const { form, savePatient } = await openEdit(true);

        form.care_reminders_consent = false;
        form.care_reminders_consent = true;
        await savePatient('p-1');

        expect(sent(globalThis.fetch.mock.calls[0]).care_reminders_consent).toBeNull();
    });

    it('paciente novo: caixa intocada não envia nada; marcada envia true', async () => {
        globalThis.route = vi.fn((name) => `/_routes/${name}`);
        globalThis.fetch = vi.fn(() => json(200, { data: { id: 'p-9' } }));
        scope = effectScope();
        const { form, savePatient } = scope.run(() => usePatientForm({}));

        await savePatient(null);
        expect(sent(globalThis.fetch.mock.calls[0]).care_reminders_consent).toBeNull();

        form.care_reminders_consent = true;
        await savePatient(null);
        expect(sent(globalThis.fetch.mock.calls[1]).care_reminders_consent).toBe(true);
    });

    it('o salvamento pelo Inertia (Pacientes > Editar) aplica a mesma regra', async () => {
        const put = vi.spyOn(router, 'put').mockImplementation(() => {});
        const { form } = await openEdit(true);

        form.occupation = 'Aposentada';
        form.put('/pacientes/p-1');

        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][1].care_reminders_consent).toBeNull();
        expect(put.mock.calls[0][1].occupation).toBe('Aposentada');

        form.care_reminders_consent = false;
        form.put('/pacientes/p-1');

        expect(put.mock.calls[1][1].care_reminders_consent).toBe(false);
    });
});

/**
 * O agendamento salva o paciente ANTES do horário e, se o horário for recusado, a tela continua aberta (o formulário do
 * paciente também): a base "o que o servidor tem" precisa avançar a cada salvamento que deu certo. Antes ela ficava no
 * valor carregado, então marcar (gravou true), desmarcar e tentar de novo mandava nulo e a revogação sumia.
 */
describe('autorização de lembretes — a base avança depois de cada salvamento que deu certo', () => {
    it('marcar → salvar → desmarcar → salvar de novo: manda true e depois false (e não nulo)', async () => {
        const { form, savePatient } = await openEdit(false);

        form.care_reminders_consent = true;
        await savePatient('p-1');

        // O horário foi recusado e a pessoa volta atrás na mesma tela.
        form.care_reminders_consent = false;
        await savePatient('p-1');

        expect(consents()).toEqual([true, false]);
    });

    it('depois de gravar, repetir o salvamento sem mexer na caixa não reenvia a autorização', async () => {
        const { form, savePatient } = await openEdit(false);

        form.care_reminders_consent = true;
        await savePatient('p-1');
        await savePatient('p-1');
        await savePatient('p-1');

        expect(consents()).toEqual([true, null, null]);
    });

    it('revogar → salvar → marcar de novo → salvar: manda false e depois true', async () => {
        const { form, savePatient } = await openEdit(true);

        form.care_reminders_consent = false;
        await savePatient('p-1');

        form.care_reminders_consent = true;
        await savePatient('p-1');

        expect(consents()).toEqual([false, true]);
    });

    it('paciente NOVO criado no agendamento: o 2º salvamento já é do cadastro existente e a revogação vai', async () => {
        globalThis.route = vi.fn((name, id) => `/_routes/${name}/${id ?? ''}`);
        globalThis.fetch = vi.fn(() => json(200, { data: { id: 'p-9' } }));
        scope = effectScope();
        const { form, savePatient } = scope.run(() => usePatientForm({}));

        form.care_reminders_consent = true;
        const first = await savePatient(null);

        form.care_reminders_consent = false;
        await savePatient(first.patient.id);

        expect(consents()).toEqual([true, false]);
        expect(globalThis.fetch.mock.calls[1][0]).toContain('update');
    });

    it('salvamento RECUSADO (422) não avança a base: a autorização marcada continua sendo enviada', async () => {
        const { form, savePatient } = await openEdit(false);

        globalThis.fetch.mockImplementation(() => json(422, { errors: { cellphone: ['Informe o celular.'] } }));

        form.care_reminders_consent = true;
        const refused = await savePatient('p-1');

        expect(refused.ok).toBe(false);

        globalThis.fetch.mockImplementation(() => json(200, { data: { id: 'p-1' } }));
        const retried = await savePatient('p-1');

        expect(retried.ok).toBe(true);
        expect(consents()).toEqual([true, true]);
    });

    it('a base é o que FOI enviado, não o que a caixa mostra quando a resposta volta', async () => {
        const { form, savePatient } = await openEdit(false);

        let release;
        globalThis.fetch.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    release = () =>
                        resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: { id: 'p-1' } }) });
                }),
        );

        form.care_reminders_consent = true;
        const pending = savePatient('p-1');

        // Com o POST no ar, a pessoa desmarca de novo: isso ainda NÃO está no servidor.
        form.care_reminders_consent = false;
        release();
        await pending;

        globalThis.fetch.mockImplementation(() => json(200, { data: { id: 'p-1' } }));
        await savePatient('p-1');

        // O servidor tem true (o que foi enviado); a tela mostra false, que é uma mudança e tem de ir.
        expect(consents()).toEqual([true, false]);
    });

    it('reabrir o cadastro recomeça da base carregada, não da do salvamento anterior', async () => {
        const { form, savePatient, resetForm, loadEditData } = await openEdit(false);

        form.care_reminders_consent = true;
        await savePatient('p-1');

        // Outra pessoa/SAIR revogou enquanto isso; o painel reabre e carrega o estado real do servidor.
        resetForm();
        globalThis.fetch.mockImplementation((url) =>
            String(url).includes('editData')
                ? json(200, { data: { name: 'MARIA', care_reminders_consent: false, whatsapp: true } })
                : json(200, { data: { id: 'p-1' } }),
        );
        await loadEditData('p-1');
        globalThis.fetch.mockClear();

        form.occupation = 'Aposentada';
        await savePatient('p-1');

        expect(consents()).toEqual([null]);
    });

    // O agendamento só salva o paciente quando `isDirty` (o que mudou desde que abriu). Depois de um salvamento que deu
    // certo, o "como está salvo" é o que foi enviado — voltar a caixa ao valor com que o cadastro abriu é MUDANÇA.
    it('depois de salvar, desmarcar a caixa (que abriu desmarcada) conta como mudança: sem isso o agendamento pularia a revogação', async () => {
        const { form, savePatient } = await openEdit(false);

        form.care_reminders_consent = true;
        await nextTick();
        expect(form.isDirty).toBe(true);

        await savePatient('p-1');
        await nextTick();

        // Nada mudou desde o envio: o retry do horário não precisa gravar o paciente de novo.
        expect(form.isDirty).toBe(false);

        form.care_reminders_consent = false;
        await nextTick();

        expect(form.isDirty).toBe(true);
    });

    it('o que a pessoa digitou enquanto o salvamento estava no ar continua pendente (não vira "já salvo")', async () => {
        const { form, savePatient } = await openEdit(false);

        let release;
        globalThis.fetch.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    release = () =>
                        resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: { id: 'p-1' } }) });
                }),
        );

        form.occupation = 'Aposentada';
        const pending = savePatient('p-1');

        form.occupation = 'Professora';
        release();
        await pending;
        await nextTick();

        expect(form.isDirty).toBe(true);
    });

    it('salvamento recusado (422) deixa tudo pendente: a base do formulário não muda', async () => {
        const { form, savePatient } = await openEdit(false);

        globalThis.fetch.mockImplementation(() => json(422, { errors: { cellphone: ['Informe o celular.'] } }));

        form.occupation = 'Aposentada';
        await savePatient('p-1');
        await nextTick();

        expect(form.isDirty).toBe(true);
    });
});
