import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axios from 'axios';
import AiDigestCard from '@/Pages/Panel/Manager/Finance/AiDigestCard.vue';

vi.mock('axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

/** Análise por IA do painel financeiro: período da tela, análise salva, ponte com o chat. */
const t = {
    title: 'Análise por IA',
    subtitle: '',
    generate: 'Gerar análise',
    regenerate: 'Gerar novamente',
    thinking: 'Analisando',
    error: 'Erro na análise',
    error_timeout: 'Demorou',
    retry: 'Tentar de novo',
    section_winning: 'Ganhando',
    section_losing: 'Perdendo',
    section_opportunities: 'Oportunidades',
    section_actions: 'Ações',
    evidence_label: 'Dado',
    period_chip: 'Período: :from – :to',
    generated_at: 'Gerada em :date',
    stale: 'Análise de :from – :to; tela :cfrom – :cto.',
    stale_action: 'Gerar para o período atual',
    steps: ['Lendo…'],
    elapsed: ':seconds s',
    empty_title: 'O que a análise traz',
    empty_hint: '',
    preview_winning: '',
    preview_losing: '',
    preview_opportunities: '',
    preview_actions: '',
    ask_about: 'Perguntar sobre isto',
    ask_template: 'Sobre ":title" (:evidence)',
    ask_template_no_evidence: 'Sobre ":title"',
    copy: 'Copiar',
    copied: 'Copiado!',
    copy_digest: 'Copiar análise',
};
const period = { preset: '3m', from: '2026-07-03', to: '2026-10-03' };
const urls = { digest: '/d', chat: '/c', show: '/r/__ID__' };
const result = {
    resumo: 'Tudo cresceu.',
    ganhando: [{ titulo: 'MRR subiu', detalhe: 'Mais clínicas', evidencia: 'MRR +12%' }],
    perdendo: [],
    oportunidades: [],
    acoes_sugeridas: [{ titulo: 'Reduzir IA', detalhe: 'Custo alto' }],
};

function mountCard(props = {}) {
    return mount(AiDigestCard, { props: { t, period, urls, initial: null, ...props } });
}

beforeEach(() => {
    vi.clearAllMocks();
    axios.post.mockResolvedValue({ data: { run_id: 'r1' } });
});

describe('AiDigestCard', () => {
    it('vazio mostra o que a análise traz; gerar manda o PERÍODO DA TELA e mostra o resultado', async () => {
        axios.get.mockResolvedValue({
            data: {
                status: 'approved',
                result,
                created_at: '2026-10-03T15:40:00Z',
                period: { from: period.from, to: period.to },
            },
        });
        const wrapper = mountCard();

        expect(wrapper.find('[data-digest-empty]').text()).toContain('O que a análise traz');

        await wrapper.find('[data-digest-empty-generate]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/d', { preset: '3m', from: '2026-07-03', to: '2026-10-03' });
        expect(axios.get).toHaveBeenCalledWith('/r/r1');
        expect(wrapper.find('[data-digest-result]').text()).toContain('Tudo cresceu.');
        expect(wrapper.find('[data-digest-period]').text()).toBe('Período: 03/07/2026 – 03/10/2026');
        expect(wrapper.find('[data-digest-generated-at]').exists()).toBe(true);
        expect(wrapper.find('[data-digest-stale]').exists()).toBe(false);
    });

    it('análise salva aparece sem nova chamada; se for de outro período, avisa e oferece gerar de novo', () => {
        const wrapper = mountCard({
            initial: { run_id: 'x', result, from: '2026-06-01', to: '2026-06-30', created_at: '2026-06-30T10:00:00Z' },
        });

        expect(axios.post).not.toHaveBeenCalled();
        expect(wrapper.find('[data-digest-result]').exists()).toBe(true);
        expect(wrapper.find('[data-digest-stale]').text()).toContain(
            'Análise de 01/06/2026 – 30/06/2026; tela 03/07/2026 – 03/10/2026.',
        );
    });

    it('"Perguntar sobre isto" leva a conclusão (com o dado) para o chat', async () => {
        const wrapper = mountCard({ initial: { run_id: 'x', result, ...period, created_at: null } });

        const asks = wrapper.findAll('[data-digest-ask]');
        await asks[0].trigger('click');
        await asks[1].trigger('click');

        expect(wrapper.emitted('ask')).toEqual([['Sobre "MRR subiu" (MRR +12%)'], ['Sobre "Reduzir IA"']]);
    });

    it('falha mostra o erro com "tentar de novo"; erro HTTP mostra a mensagem do servidor', async () => {
        axios.get.mockResolvedValue({ data: { status: 'failed' } });
        const wrapper = mountCard();

        await wrapper.find('[data-digest-generate]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-digest-error]').text()).toContain('Erro na análise');

        axios.post.mockRejectedValueOnce({ response: { data: { message: 'Muitas tentativas.' } } });
        await wrapper.find('[data-digest-error] button').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-digest-error]').text()).toContain('Muitas tentativas.');
    });

    it('copiar leva a análise em texto', async () => {
        const writeText = vi.fn().mockResolvedValue();
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        const wrapper = mountCard({ initial: { run_id: 'x', result, ...period, created_at: null } });

        await wrapper.find('[data-digest-copy]').trigger('click');

        expect(writeText.mock.calls[0][0]).toBe(
            'Tudo cresceu.\n\nGanhando\n- MRR subiu: Mais clínicas (MRR +12%)\n\nAções\n- Reduzir IA: Custo alto',
        );
    });
});
