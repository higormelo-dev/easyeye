import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ProviderLgpdSection from '@/Pages/Panel/Manager/AiProviders/ProviderLgpdSection.vue';
import RolesCard from '@/Pages/Panel/Manager/AiProviders/RolesCard.vue';
import ProviderTable from '@/Pages/Panel/Manager/AiProviders/ProviderTable.vue';
import ProviderSetupHelp from '@/Pages/Panel/Manager/AiProviders/ProviderSetupHelp.vue';
import { lgpdBadge } from '@/Pages/Panel/Manager/AiProviders/providerStatus.js';
import { blockedLgpd, lgpd, mockFetch, ready } from './aiProvidersFixtures';

/**
 * LGPD dos provedores de IA no painel: selo, bloqueio nos papéis, registro do
 * mecanismo de transferência internacional (art. 33) e ajuda de configuração.
 */
const t = {
    lgpd_badge_blocked: 'Bloqueado p/ pacientes',
    lgpd_badge_pending: 'Registro pendente',
    lgpd_badge_recorded: 'Mecanismo registrado',
    lgpd_badge_needs: 'Exige registro',
    lgpd_badge_br: 'No Brasil',
    lgpd_badge_eu: 'UE (adequação)',
    blocked_reason_gemini_terms: 'Os termos da Gemini API proíbem o uso em prática clínica.',
    blocked_note: 'Segue disponível onde não há dado de paciente.',
    location_us: 'Estados Unidos',
    location_eu: 'União Europeia',
    transfer_contract: 'Exige mecanismo contratual',
    transfer_adequacy: 'Adequação (UE)',
    transfer_not_needed: 'Não precisa de registro.',
    patients_allowed: 'Permitido',
    patients_blocked: 'Bloqueado',
    mechanism_standard_clauses: 'Cláusulas-padrão ANPD',
    mechanism_specific_clauses: 'Cláusulas específicas',
    mechanism_corporate_rules: 'Normas corporativas globais',
    transfer_register: 'Registrar mecanismo',
    transfer_replace: 'Substituir registro',
    transfer_remove: 'Remover registro',
    transfer_remove_confirm: 'Remover o registro de :provider?',
    transfer_registered_by: 'Registrado por :name em :date',
    lgpd_checked_at: 'Conferido em :date.',
    problem_blocked: ':provider: bloqueado (LGPD).',
    problem_transfer: ':provider: registre o mecanismo.',
    option_blocked: 'bloqueado — LGPD',
    setup_required_url: 'Defina também o endereço.',
    setup_azure_note: 'Crie o deployment Standard regional em swedencentral.',
};

const mechanisms = ['standard_clauses', 'specific_clauses', 'corporate_rules'];

beforeEach(() => {
    window.showSuccessToast = vi.fn();
    window.showErrorToast = vi.fn();
});

afterEach(() => vi.useRealTimers());

describe('selo LGPD', () => {
    it('a situação mais importante primeiro: bloqueado, pendente, registrado, exige, Brasil, UE', () => {
        const badge = (over) => lgpdBadge({ lgpd: lgpd(over) }, t);

        expect(lgpdBadge({ lgpd: blockedLgpd('gemini_terms') }, t)).toMatchObject({
            code: 'blocked',
            hint: t.blocked_reason_gemini_terms,
        });
        expect(badge({ pending: true, in_role: true }).code).toBe('pending');
        expect(badge({ record: { mechanism: 'standard_clauses' } })).toMatchObject({
            code: 'recorded',
            hint: 'Cláusulas-padrão ANPD',
        });
        expect(badge({}).code).toBe('needs');
        expect(badge({ needs_record: false, transfer: 'none', location: 'br' }).label).toBe('No Brasil');
        expect(badge({ needs_record: false, transfer: 'adequacy', location: 'eu' }).label).toBe('UE (adequação)');
        expect(lgpdBadge({}, t)).toBeNull();
    });

    it('tabela mostra o selo de cada provedor', () => {
        const wrapper = mount(ProviderTable, {
            props: {
                providers: [
                    ready({ code: 'gemini', label: 'Google (Gemini)', lgpd: blockedLgpd('gemini_terms') }),
                    ready({ code: 'openai', label: 'OpenAI', lgpd: lgpd({ pending: true, in_role: true }) }),
                ],
                t,
            },
        });

        expect(wrapper.find('[data-provider-row="gemini"] [data-lgpd]').attributes('data-lgpd')).toBe('blocked');
        expect(wrapper.find('[data-provider-row="openai"] [data-lgpd]').text()).toBe('Registro pendente');
    });
});

describe('RolesCard — LGPD', () => {
    const modes = [{ value: 'economy', label: 'Economia', needs: 1 }];
    const providers = [
        ready({
            code: 'mistral',
            label: 'Mistral AI',
            model: 'mistral-large',
            lgpd: lgpd({ needs_record: false, transfer: 'adequacy' }),
        }),
        ready({ code: 'openai', label: 'OpenAI', model: 'gpt-4o', lgpd: lgpd() }),
        ready({
            code: 'anthropic',
            label: 'Anthropic',
            model: 'claude',
            lgpd: lgpd({ record: { mechanism: 'standard_clauses' } }),
        }),
        ready({
            code: 'gemini',
            label: 'Google (Gemini)',
            model: 'gemini-3.6-flash',
            lgpd: blockedLgpd('gemini_terms'),
        }),
    ];

    const mountCard = (roles) => mount(RolesCard, { props: { roles, providers, modes, t } });

    it('bloqueado não entra na escolha; salvo num papel aparece desabilitado com o problema', () => {
        const wrapper = mountCard({ primary: 'mistral', reviewer: 'gemini', adjudicator: null });

        const reviewer = wrapper.findAll('[data-role="reviewer"] option');
        const gemini = reviewer.find((o) => o.attributes('value') === 'gemini');
        expect(gemini.attributes('disabled')).toBeDefined();
        expect(gemini.text()).toContain('bloqueado — LGPD');
        expect(wrapper.findAll('[data-role="primary"] option').map((o) => o.attributes('value'))).not.toContain(
            'gemini',
        );
        expect(wrapper.find('[data-role-problems]').text()).toContain('Google (Gemini): bloqueado (LGPD).');
        expect(wrapper.find('[data-roles-save]').attributes('disabled')).toBeDefined();
    });

    it('provedor que PASSA a transferir sem registro trava; registrado ou já salvo segue', async () => {
        const wrapper = mountCard({ primary: 'mistral', reviewer: null, adjudicator: null });

        await wrapper.find('[data-role="reviewer"]').setValue('openai');
        expect(wrapper.find('[data-role-problems]').text()).toContain('OpenAI: registre o mecanismo.');
        expect(wrapper.find('[data-roles-save]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-role="reviewer"]').setValue('anthropic');
        expect(wrapper.find('[data-role-problems]').exists()).toBe(false);
        expect(wrapper.find('[data-roles-save]').attributes('disabled')).toBeUndefined();

        // OpenAI já estava salva num papel: regra "atual com aviso" (o aviso fica na página).
        const saved = mountCard({ primary: 'openai', reviewer: null, adjudicator: null });
        expect(saved.find('[data-role-problems]').exists()).toBe(false);
        expect(saved.find('[data-roles-save]').attributes('disabled')).toBeUndefined();
    });
});

describe('ProviderLgpdSection', () => {
    const mountSection = (over = {}, code = 'openai') =>
        mount(ProviderLgpdSection, {
            props: {
                provider: ready({ code, label: 'OpenAI', lgpd: lgpd(over) }),
                lgpd: { checked_at: '03/10/2026', mechanisms },
                t,
            },
        });

    it('mostra onde processa, a base da transferência, as fontes e a data da conferência', () => {
        const wrapper = mountSection();

        expect(wrapper.find('[data-lgpd-location]').text()).toBe('Estados Unidos');
        expect(wrapper.text()).toContain('Exige mecanismo contratual');
        expect(wrapper.find('[data-lgpd-patients]').text()).toBe('Permitido');
        expect(wrapper.find('a').attributes('rel')).toBe('noopener noreferrer');
        expect(wrapper.text()).toContain('Conferido em 03/10/2026.');
    });

    it('bloqueado: mostra o motivo e não oferece registro', () => {
        const wrapper = mount(ProviderLgpdSection, {
            props: {
                provider: ready({ code: 'gemini', label: 'Gemini', lgpd: blockedLgpd('gemini_terms') }),
                lgpd: { checked_at: null, mechanisms },
                t,
            },
        });

        expect(wrapper.find('[data-lgpd-patients]').text()).toBe('Bloqueado');
        expect(wrapper.text()).toContain('proíbem o uso em prática clínica');
        expect(wrapper.find('[data-lgpd-form]').exists()).toBe(false);
    });

    it('UE (adequação): não precisa de registro', () => {
        const wrapper = mountSection({ location: 'eu', transfer: 'adequacy', needs_record: false, can_record: false });

        expect(wrapper.find('[data-lgpd-form]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Não precisa de registro.');
    });

    it('registra o mecanismo (data máxima = hoje no fuso local) e avisa a página', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 9, 3, 23, 30)); // 23h30 locais: em UTC já seria dia 4
        const fetchMock = mockFetch({ message: 'Mecanismo de transferência registrado.' });
        const wrapper = mountSection();

        expect(wrapper.find('[data-lgpd-signed-at]').attributes('max')).toBe('2026-10-03');
        expect(wrapper.find('[data-lgpd-save]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-lgpd-reference]').setValue('Aditivo CPC-ANPD ao DPA');
        await wrapper.find('[data-lgpd-signed-at]').setValue('2026-09-15');
        await wrapper.find('[data-lgpd-form]').trigger('submit');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/_routes/manager.ai-providers.transfer.update/openai');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(init.body)).toEqual({
            mechanism: 'standard_clauses',
            reference: 'Aditivo CPC-ANPD ao DPA',
            signed_at: '2026-09-15',
        });
        expect(window.showSuccessToast).toHaveBeenCalledWith('Mecanismo de transferência registrado.');
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });

    it('erros de validação do servidor aparecem no campo', async () => {
        mockFetch({ message: 'x', errors: { signed_at: ['A data não pode ser futura.'] } }, false);
        const wrapper = mountSection();

        await wrapper.find('[data-lgpd-reference]').setValue('Aditivo');
        await wrapper.find('[data-lgpd-signed-at]').setValue('2026-09-15');
        await wrapper.find('[data-lgpd-form]').trigger('submit');
        await flushPromises();

        const field = wrapper.find('[data-lgpd-signed-at]');
        expect(field.classes()).toContain('is-invalid');
        expect(field.attributes('aria-invalid')).toBe('true');
        expect(wrapper.text()).toContain('A data não pode ser futura.');
        expect(wrapper.emitted('saved')).toBeUndefined();
    });

    it('registrado: mostra quem registrou; remover só fora de papel e com confirmação', async () => {
        const record = {
            mechanism: 'standard_clauses',
            reference: 'Aditivo CPC-ANPD',
            signed_at: '2026-09-15',
            signed_at_display: '15/09/2026',
            registered_by: 'ADMIN',
            registered_at: '03/10/2026 10:00',
        };

        const inRole = mountSection({ record, in_role: true });
        expect(inRole.find('[data-lgpd-record]').text()).toContain('Registrado por ADMIN em 03/10/2026 10:00');
        expect(inRole.find('[data-lgpd-save]').text()).toContain('Substituir registro');
        expect(inRole.find('[data-lgpd-remove]').exists()).toBe(false);

        const fetchMock = mockFetch({ message: 'Registro removido.' });
        window.confirm = vi.fn(() => false);
        const wrapper = mountSection({ record });

        await wrapper.find('[data-lgpd-remove]').trigger('click');
        expect(window.confirm).toHaveBeenCalledWith('Remover o registro de OpenAI?');
        expect(fetchMock).not.toHaveBeenCalled();

        window.confirm = vi.fn(() => true);
        await wrapper.find('[data-lgpd-remove]').trigger('click');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/_routes/manager.ai-providers.transfer.destroy/openai');
        expect(init.method).toBe('DELETE');
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });
});

describe('ProviderSetupHelp — Azure', () => {
    it('sem endereço padrão: pede a chave, o endereço e a região do deployment', () => {
        const wrapper = mount(ProviderSetupHelp, {
            props: {
                provider: {
                    code: 'azure_openai',
                    label: 'Azure OpenAI (Microsoft)',
                    key_env: 'AZURE_OPENAI_API_KEY',
                    model: 'gpt-4o-mini',
                    model_env: 'AI_AZURE_OPENAI_MODEL',
                    base_url: null,
                    base_url_env: 'AI_AZURE_OPENAI_BASE_URL',
                    keys_url: 'https://ai.azure.com',
                    lgpd: lgpd({ region_env: 'AI_AZURE_OPENAI_DATA_REGION', data_region: 'eu' }),
                },
                t,
            },
        });

        expect(wrapper.find('code').text()).toBe(
            'AZURE_OPENAI_API_KEY=\nAI_AZURE_OPENAI_BASE_URL=\nAI_AZURE_OPENAI_DATA_REGION=eu',
        );
        expect(wrapper.find('[data-setup-note]').text()).toContain('swedencentral');
        expect(wrapper.text()).toContain('Defina também o endereço.');
        expect(wrapper.find('pre').text()).toBe('AI_AZURE_OPENAI_MODEL=gpt-4o-mini');
    });
});
