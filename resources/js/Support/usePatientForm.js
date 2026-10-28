import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { latestRequest, isAbortError } from '@/Support/latestRequest.js';

/**
 * usePatientForm — estado + lógica do cadastro de paciente, compartilhado
 * entre PatientFormModal (Pacientes > Novo/Editar) e ScheduleFormModal
 * (abas Pessoal/Clínico/Contato/Endereço dentro do agendamento).
 *
 * `catalogs` é o objeto de props do componente host (reativo): precisa expor
 * genders, maritalStatuses e statesOfBrazil.
 */
export function usePatientForm(catalogs) {
    const loading = ref(false);
    // Só a última carga vale: abrir o paciente B com a do A pendente (ou
    // limpar o formulário) descarta a resposta do A — senão o cadastro do B
    // abria com os dados do A (e o "Salvar" gravava no B).
    const editLoad = latestRequest();
    // Plano já salvo (nome/registro) — o seletor busca no servidor e precisa
    // do rótulo da opção escolhida antes de qualquer busca.
    const planOption = ref(null);
    // Convênio já salvo (id/nome/ativo): o seletor só lista ativos; o inativo
    // que o paciente já tem aparece marcado em vez de o campo parecer vazio.
    const covenantOption = ref(null);

    const form = useForm({
        // Clínico
        covenant_id: '',
        covenant_plan_id: '',
        card_number: '',
        skin_id: '',
        iris_id: '',
        active: true,
        // Pessoal
        name: '',
        nickname: '',
        national_registry: '',
        birth_date: '',
        gender: '',
        marital_status: '',
        email: '',
        mother_name: '',
        father_name: '',
        occupation: '',
        // Documento
        state_registry: '',
        state_registry_agency: '',
        state_registry_initial: '',
        state_registry_date: '',
        // Contato
        telephone: '',
        cellphone: '',
        whatsapp: false,
        // Autorização de lembretes de retorno por WhatsApp (LGPD): nulo = o formulário não tocou (o servidor não
        // mexe); true/false registra ou revoga. Na edição parte do estado atual do paciente, mas só VAI ao servidor
        // quando a pessoa mudou a caixa (ver `outgoing`).
        care_reminders_consent: null,
        // Endereço
        zipcode: '',
        address: '',
        number: '',
        complement: '',
        district: '',
        city: '',
        state: '',
        country: 'Brasil',
        // "É outra pessoa": só vai true depois que o servidor avisar de homônimo
        // (mesmo nome de outro paciente da clínica) e a recepção confirmar.
        confirm_homonym: false,
        // Paciente NOVO (Fase 2): alergias, "nega alergias" e medicações
        // informadas na aba Clínico vão junto com o cadastro. Na edição a aba
        // grava cada item na hora (o servidor ignora estes campos no PUT).
        allergies: [],
        allergies_denied: false,
        medications: [],
    });

    // A autorização de lembretes como o SERVIDOR a tem: o valor carregado ao abrir o cadastro e, depois de cada
    // salvamento que deu certo, o que esse salvamento gravou. Autorização é um ATO da pessoa, não um campo do
    // formulário: o valor carregado voltava em TODO salvamento, então, se o paciente respondesse SAIR com a tela aberta,
    // salvar outro campo "marcava" de novo a caixa que a tela ainda mostrava (consentimento restaurado em silêncio) — e,
    // ao contrário, desmarcava uma autorização que outra pessoa acabara de registrar. Só vai ao servidor o que a pessoa
    // MUDOU em relação a isso; igual = nulo (o servidor não mexe).
    //
    // O agendamento salva o paciente ANTES do horário e, se o horário for recusado, a tela continua aberta: sem avançar
    // a base depois do salvamento, marcar a caixa (gravou true), desmarcá-la e tentar de novo mandava nulo (a base ainda
    // era "desmarcada"), e a revogação explícita ficava sem efeito. O Inertia (Pacientes) fecha o painel no sucesso, e a
    // reabertura recarrega do servidor.
    const loadedCareReminders = ref(null);

    function outgoing(data) {
        return data.care_reminders_consent === loadedCareReminders.value
            ? { ...data, care_reminders_consent: null }
            : data;
    }

    // Cobre `form.put/post` (Pacientes); `savePatient` (fetch embutido) aplica o mesmo em `form.data()`.
    form.transform(outgoing);

    // Confirmação de homônimo vale para o nome/nascimento confirmados: mudou
    // um dos dois, o servidor reavalia e a recepção confirma de novo.
    watch(
        () => [form.name, form.birth_date],
        () => {
            if (form.confirm_homonym) form.confirm_homonym = false;
        },
    );

    function resetForm() {
        editLoad.cancel();
        loading.value = false;
        loadedCareReminders.value = null;
        // defaults() pode ter sido movido por loadEditData — restaura o
        // template vazio antes do reset pra não "resetar" pro último paciente.
        form.defaults({
            covenant_id: '',
            covenant_plan_id: '',
            card_number: '',
            skin_id: '',
            iris_id: '',
            active: true,
            name: '',
            nickname: '',
            national_registry: '',
            birth_date: '',
            gender: '',
            marital_status: '',
            email: '',
            mother_name: '',
            father_name: '',
            occupation: '',
            state_registry: '',
            state_registry_agency: '',
            state_registry_initial: '',
            state_registry_date: '',
            telephone: '',
            cellphone: '',
            whatsapp: false,
            care_reminders_consent: null,
            zipcode: '',
            address: '',
            number: '',
            complement: '',
            district: '',
            city: '',
            state: '',
            country: 'Brasil',
            confirm_homonym: false,
            allergies: [],
            allergies_denied: false,
            medications: [],
        });
        form.reset();
        form.clearErrors();
        planOption.value = null;
        covenantOption.value = null;
    }

    async function loadEditData(id) {
        const req = editLoad.begin();
        loading.value = true;
        try {
            const res = await fetch(route('panel.patients.editData', id), { signal: req.signal });
            const json = await res.json();
            if (!req.current()) return;
            const d = json.data;
            Object.keys(form.data()).forEach((key) => {
                if (key in d) form[key] = d[key] ?? form[key];
            });
            planOption.value = d.covenant_plan ?? null;
            covenantOption.value = d.covenant_option ?? null;
            loadedCareReminders.value = typeof d.care_reminders_consent === 'boolean' ? d.care_reminders_consent : null;
            // Baseline = dado carregado: isDirty passa a refletir só edição
            // REAL do usuário (decide se o save do agendamento faz PUT).
            form.defaults(form.data());
        } catch (error) {
            if (!isAbortError(error) && req.current()) throw error;
        } finally {
            if (req.current()) loading.value = false;
        }
    }

    // Laravel manda {field: [msg, ...]} — os `form.errors.xxx` dos templates
    // esperam string única (formato que o Inertia entrega num post/put padrão).
    function flattenErrors(errors) {
        return Object.fromEntries(Object.entries(errors).map(([key, val]) => [key, Array.isArray(val) ? val[0] : val]));
    }

    /**
     * Salva via fetch+JSON (sem redirect Inertia — fluxos embutidos).
     * Retorna { ok, patient } ou { ok: false } com form.errors preenchido.
     */
    async function savePatient(patientId = null) {
        form.processing = true;
        form.clearErrors();

        const url = patientId ? route('panel.patients.update', patientId) : route('panel.patients.store');
        const method = patientId ? 'PUT' : 'POST';

        try {
            // O que VAI é um retrato do momento do envio (cópia profunda): a tela pode mudar enquanto a resposta não volta.
            const sent = JSON.parse(JSON.stringify(form.data()));
            const payload = outgoing(sent);
            const res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify(payload),
            });
            const json = await res.json();

            if (res.ok) {
                // O servidor passou a ter o que foi enviado; nulo = não mexeu, a base continua a mesma.
                if (typeof payload.care_reminders_consent === 'boolean') {
                    loadedCareReminders.value = payload.care_reminders_consent;
                }

                // E o que a tela mostra agora é o novo "como está salvo": sem isso, voltar um campo ao valor com que o
                // cadastro abriu (a caixa marcada e desmarcada de novo, depois que o horário foi recusado) parecia "sem
                // mudança" e o agendamento nem tentava salvar o paciente — a revogação ficava só na tela.
                // (`isDirty` se recalcula sozinho: o `processing` que volta a false logo abaixo dispara o observador.)
                form.defaults(sent);

                return {
                    ok: true,
                    patient: {
                        id: json.data?.id ?? patientId,
                        code: json.data?.attributes?.code ?? null,
                        full_name: form.name,
                        cellphone: form.cellphone,
                        telephone: form.telephone,
                    },
                };
            }

            if (res.status === 422 && json.errors) {
                form.setError(flattenErrors(json.errors));
            } else {
                form.setError({ name: json.message ?? 'Erro ao salvar paciente.' });
            }

            return { ok: false };
        } finally {
            form.processing = false;
        }
    }

    async function lookupCep() {
        const cep = form.zipcode.replace(/\D/g, '');
        if (cep.length !== 8) return;
        try {
            const res = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
            const d = await res.json();
            if (!d.erro) {
                form.address = d.logradouro ?? form.address;
                form.district = d.bairro ?? form.district;
                form.city = d.localidade ?? form.city;
                form.state = d.uf ?? form.state;
            }
        } catch {
            /**/
        }
    }

    const genderOptions = computed(() =>
        Object.entries(catalogs.genders ?? {}).map(([v, l]) => ({ value: Number(v), label: l })),
    );
    const maritalOptions = computed(() =>
        Object.entries(catalogs.maritalStatuses ?? {}).map(([v, l]) => ({ value: Number(v), label: l })),
    );
    const stateOptions = computed(() =>
        Object.entries(catalogs.statesOfBrazil ?? {}).map(([v, l]) => ({ value: v, label: l })),
    );

    const tabHasErrors = computed(() => ({
        personal: Object.keys(form.errors).some((k) =>
            [
                'name',
                'confirm_homonym',
                'nickname',
                'national_registry',
                'birth_date',
                'gender',
                'marital_status',
                'email',
                'mother_name',
                'father_name',
                'occupation',
            ].includes(k),
        ),
        clinical: Object.keys(form.errors).some(
            (k) =>
                ['covenant_id', 'covenant_plan_id', 'card_number', 'skin_id', 'iris_id', 'allergies_denied'].includes(
                    k,
                ) ||
                k.startsWith('allergies.') ||
                k.startsWith('medications.'),
        ),
        contact: Object.keys(form.errors).some((k) =>
            ['telephone', 'cellphone', 'whatsapp', 'care_reminders_consent'].includes(k),
        ),
        address: Object.keys(form.errors).some((k) =>
            ['zipcode', 'address', 'number', 'complement', 'district', 'city', 'state', 'country'].includes(k),
        ),
    }));

    // Abas com obrigatório vazio (destaque azul) — espelha PatientRequest::rules().
    // `=== ''` e não falsy: gênero 0 (Masculino) é válido.
    const isBlank = (v) => v === '' || v === null || v === undefined;

    const tabIncomplete = computed(() => ({
        personal: ['name', 'birth_date', 'gender', 'marital_status', 'national_registry', 'email'].some((k) =>
            isBlank(form[k]),
        ),
        clinical: isBlank(form.covenant_id),
        contact: isBlank(form.cellphone),
        address: false,
    }));

    return {
        form,
        loading,
        planOption,
        covenantOption,
        resetForm,
        loadEditData,
        savePatient,
        lookupCep,
        genderOptions,
        maritalOptions,
        stateOptions,
        tabHasErrors,
        tabIncomplete,
    };
}
