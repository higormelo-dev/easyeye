import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SupportButton from '@/Components/Panel/SupportButton.vue';
describe('SupportButton', () => {
    it('preserva URL, semântica acessível e ícone por canal', () => {
        for (const [kind, url, icon] of [
            ['whatsapp', 'https://wa.me/5511', 'ti-brand-whatsapp'],
            ['email', 'mailto:support@example.test', 'ti-mail'],
        ]) {
            const wrapper = mount(SupportButton, { props: { support: { kind, url, label: 'Ajuda' } } });
            expect(wrapper.get('a').attributes()).toMatchObject({
                href: url,
                target: '_blank',
                rel: 'noopener noreferrer',
                'aria-label': 'Ajuda',
                title: 'Ajuda',
            });
            expect(wrapper.get('i').classes()).toContain(icon);
        }
    });
});
