/**
 * EasyEye — Animações do site institucional.
 *
 * REGRA DE OURO (pós-incidente "site em branco"): animação NUNCA é condição
 * de visibilidade. A versão anterior usava gsap.from({opacity: 0}) gated por
 * ScrollTrigger em TODOS os cards da página (benefícios, funcionalidades,
 * planos, contato...) — quando o trigger não disparava no ambiente do
 * usuário, o conteúdo ficava permanentemente invisível (opacity 0). Página
 * inteira "vazia" com só os títulos aparecendo.
 *
 * O que sobrou aqui são só efeitos que TOCAM IMEDIATAMENTE no mount (hero)
 * ou que, em falha, deixam o conteúdo no estado original visível (contador
 * de métricas: se o trigger nunca dispara, o texto estático original
 * permanece — nada some).
 *
 * SPA-safe: retorna função de cleanup; chamar no onUnmounted da Home.vue.
 */

import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

/**
 * Extrai o número alvo de um texto de métrica (ex: "50k+" → 50, "120 + " → 120,
 * "1.250" → 1250) e devolve { target, prefix, suffix } para preservar formatação.
 */
function parseMetric(text) {
    const trimmed = (text ?? '').trim();
    const match = trimmed.match(/^(\D*)([\d.,]+)(.*)$/);
    if (!match) return null;

    const [, prefix, num, suffix] = match;
    const target = parseInt(num.replace(/[.,]/g, ''), 10);
    if (Number.isNaN(target) || target <= 0) return null;

    return { target, prefix, suffix };
}

export function initSiteAnimations() {
    // ── Acessibilidade ───────────────────────────────────────────────────────
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion) {
        return () => {};
    }

    // Garante limpeza de execuções anteriores (HMR/navegação Inertia)
    ScrollTrigger.getAll().forEach(st => st.kill());

    const context = gsap.context(() => {

        // ── Hero entrance (toca IMEDIATAMENTE — sem dependência de scroll) ──
        const heroTl = gsap.timeline({ defaults: { ease: 'power3.out' } });
        heroTl
            .from('.hero-title', { y: 40, opacity: 0, duration: 1.0 })
            .from('.hero-sub', { y: 24, opacity: 0, duration: 0.8 }, '-=0.5')
            .from('.hero-ctas > *', { y: 16, opacity: 0, duration: 0.6, stagger: 0.1 }, '-=0.4')
            .from('.hero-trust', { y: 16, opacity: 0, duration: 0.6 }, '-=0.3');

        // ── Hero visual: mockup + cards flutuantes (entrada imediata) ────────
        gsap.from('.hero-mockup', {
            scale: 0.94,
            opacity: 0,
            duration: 1.2,
            ease: 'back.out(1.4)',
            delay: 0.4,
        });

        gsap.from('.hero-float-card.card-top', {
            x: -30, y: -20, opacity: 0, duration: 0.9, delay: 0.9, ease: 'power2.out',
        });
        gsap.from('.hero-float-card.card-bottom', {
            x: 30, y: 20, opacity: 0, duration: 0.9, delay: 1.1, ease: 'power2.out',
        });

        // Float cards: movimento contínuo (subtle floating)
        gsap.to('.hero-float-card.card-top', {
            y: '+=12', duration: 3.5, ease: 'sine.inOut', repeat: -1, yoyo: true,
        });
        gsap.to('.hero-float-card.card-bottom', {
            y: '-=12', duration: 4, ease: 'sine.inOut', repeat: -1, yoyo: true, delay: 0.5,
        });

        // ── Hero blobs: ambiente decorativo ──────────────────────────────────
        gsap.to('.hero-blob-1', {
            x: 40, y: -30, scale: 1.1, duration: 12, ease: 'sine.inOut', repeat: -1, yoyo: true,
        });
        gsap.to('.hero-blob-2', {
            x: -30, y: 40, scale: 0.95, duration: 14, ease: 'sine.inOut', repeat: -1, yoyo: true,
        });

        // ── Metrics counter (fail-safe: sem trigger, o texto original fica) ──
        document.querySelectorAll('.metric-value').forEach(el => {
            const parsed = parseMetric(el.textContent);
            if (!parsed) return;

            const obj = { val: 0 };
            gsap.to(obj, {
                val: parsed.target,
                duration: 2.0,
                ease: 'power2.out',
                snap: { val: 1 },
                onUpdate: () => {
                    el.textContent = `${parsed.prefix}${Math.round(obj.val).toLocaleString('pt-BR')}${parsed.suffix}`;
                },
                scrollTrigger: { trigger: el, start: 'top 88%', once: true },
            });
        });

        // NOTA: NENHUM reveal de card gated por ScrollTrigger. Todo o conteúdo
        // das seções (benefícios, funcionalidades, planos, contato...) renderiza
        // 100% visível via CSS — sem JS no caminho crítico de visibilidade.
    });

    // ── Cleanup function ─────────────────────────────────────────────────────
    return () => {
        context.revert();
        ScrollTrigger.getAll().forEach(st => st.kill());
    };
}
