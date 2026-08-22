import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

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

    const form = useForm({
        // Clínico
        covenant_id: '',
        card_number: '',
        skin_id:     '',
        iris_id:     '',
        active:      true,
        // Pessoal
        name:              '',
        nickname:          '',
        national_registry: '',
        birth_date:        '',
        gender:            '',
        marital_status:    '',
        email:             '',
        mother_name:       '',
        father_name:       '',
        // Documento
        state_registry:         '',
        state_registry_agency:  '',
        state_registry_initial: '',
        state_registry_date:    '',
        // Contato
        telephone: '',
        cellphone: '',
        whatsapp:  false,
        // Endereço
        zipcode:    '',
        address:    '',
        number:     '',
        complement: '',
        district:   '',
        city:       '',
        state:      '',
        country:    'Brasil',
    });

    function resetForm() {
        // defaults() pode ter sido movido por loadEditData — restaura o
        // template vazio antes do reset pra não "resetar" pro último paciente.
        form.defaults({
            covenant_id: '', card_number: '', skin_id: '', iris_id: '', active: true,
            name: '', nickname: '', national_registry: '', birth_date: '', gender: '',
            marital_status: '', email: '', mother_name: '', father_name: '',
            state_registry: '', state_registry_agency: '', state_registry_initial: '', state_registry_date: '',
            telephone: '', cellphone: '', whatsapp: false,
            zipcode: '', address: '', number: '', complement: '', district: '', city: '', state: '', country: 'Brasil',
        });
        form.reset();
        form.clearErrors();
    }

    async function loadEditData(id) {
        loading.value = true;
        try {
            const res  = await fetch(route('panel.patients.editData', id));
            const json = await res.json();
            const d    = json.data;
            Object.keys(form.data()).forEach((key) => {
                if (key in d) form[key] = d[key] ?? form[key];
            });
            // Baseline = dado carregado: isDirty passa a refletir só edição
            // REAL do usuário (decide se o save do agendamento faz PUT).
            form.defaults(form.data());
        } finally {
            loading.value = false;
        }
    }

    // Laravel manda {field: [msg, ...]} — os `form.errors.xxx` dos templates
    // esperam string única (formato que o Inertia entrega num post/put padrão).
    function flattenErrors(errors) {
        return Object.fromEntries(
            Object.entries(errors).map(([key, val]) => [key, Array.isArray(val) ? val[0] : val]),
        );
    }

    /**
     * Salva via fetch+JSON (sem redirect Inertia — fluxos embutidos).
     * Retorna { ok, patient } ou { ok: false } com form.errors preenchido.
     */
    async function savePatient(patientId = null) {
        form.processing = true;
        form.clearErrors();

        const url    = patientId ? route('panel.patients.update', patientId) : route('panel.patients.store');
        const method = patientId ? 'PUT' : 'POST';

        try {
            const res = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify(form.data()),
            });
            const json = await res.json();

            if (res.ok) {
                return {
                    ok: true,
                    patient: {
                        id:        json.data?.id ?? patientId,
                        code:      json.data?.attributes?.code ?? null,
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
            const d   = await res.json();
            if (!d.erro) {
                form.address  = d.logradouro ?? form.address;
                form.district = d.bairro     ?? form.district;
                form.city     = d.localidade ?? form.city;
                form.state    = d.uf         ?? form.state;
            }
        } catch { /**/ }
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
        personal: Object.keys(form.errors).some(k =>
            ['name', 'nickname', 'national_registry', 'birth_date', 'gender', 'marital_status', 'email', 'mother_name', 'father_name'].includes(k),
        ),
        clinical: Object.keys(form.errors).some(k =>
            ['covenant_id', 'card_number', 'skin_id', 'iris_id'].includes(k),
        ),
        contact: Object.keys(form.errors).some(k =>
            ['telephone', 'cellphone', 'whatsapp'].includes(k),
        ),
        address: Object.keys(form.errors).some(k =>
            ['zipcode', 'address', 'number', 'complement', 'district', 'city', 'state', 'country'].includes(k),
        ),
    }));

    // Abas com obrigatório vazio (destaque azul) — espelha PatientRequest::rules().
    // `=== ''` e não falsy: gênero 0 (Masculino) é válido.
    const isBlank = (v) => v === '' || v === null || v === undefined;

    const tabIncomplete = computed(() => ({
        personal: ['name', 'birth_date', 'gender', 'marital_status', 'national_registry', 'email']
            .some(k => isBlank(form[k])),
        clinical: isBlank(form.covenant_id),
        contact: isBlank(form.cellphone),
        address: false,
    }));

    return {
        form,
        loading,
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
