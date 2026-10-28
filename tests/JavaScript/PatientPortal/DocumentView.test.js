import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import DocumentView from '@/Pages/PatientPortal/DocumentView.vue';

/**
 * Portal do Paciente — visualização de documento compartilhado. Cobre só a
 * escolha de <iframe>/<img>/<video>/fallback por tipo (achado via revisão
 * Codex: isImage vinha true pra TODO exame, inclusive vídeo capturado pela
 * câmera — renderizava <img> quebrada em vez do <video> nativo).
 */
const baseProps = {
    type: 'exame',
    typeLabel: 'Exame',
    title: 'Retinografia',
    showUrl: 'https://s3.example/show',
    downloadUrl: 'https://s3.example/download',
};

function mountPage(propsOverride = {}) {
    return mount(DocumentView, {
        global: {
            mocks: { route: globalThis.route },
            stubs: { Head: true, Link: true, PatientPortalLayout: { template: '<div><slot /></div>' } },
        },
        props: { ...baseProps, ...propsOverride },
    });
}

describe('PatientPortal/DocumentView — tipo de preview por exame', () => {
    it('[GAP] exame de vídeo (isVideo) renderiza <video controls>, nunca <img>', () => {
        const wrapper = mountPage({ isImage: false, isVideo: true });

        const video = wrapper.find('video');
        expect(video.exists()).toBe(true);
        expect(video.attributes('src')).toBe('https://s3.example/show');
        expect(video.attributes('controls')).toBeDefined();
        expect(wrapper.find('img').exists()).toBe(false);
    });

    it('exame de foto (isImage) continua renderizando <img>, nunca <video>', () => {
        const wrapper = mountPage({ isImage: true, isVideo: false });

        expect(wrapper.find('img').exists()).toBe(true);
        expect(wrapper.find('video').exists()).toBe(false);
    });

    it('nem imagem nem vídeo nem PDF: mostra o aviso de pré-visualização indisponível com link de download', () => {
        const wrapper = mountPage({ isImage: false, isVideo: false, isPdf: false });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('video').exists()).toBe(false);
        expect(wrapper.text()).toContain('Pré-visualização indisponível');
    });
});
