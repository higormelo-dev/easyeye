/**
 * EasyEye — Animações do site institucional.
 *
 * Estratégia:
 *   • GSAP + ScrollTrigger para timelines complexas e animações por scroll
 *   • Acessibilidade: respeita `prefers-reduced-motion` do SO/navegador
 *   • SPA-safe: retorna função de cleanup; chamar no onUnmounted da Home.vue
 *   • Idempotente: re-execução não duplica animações
 *
 * Hierarquia de efeitos (do mais imediato ao último):
 *   1.  Hero entrance      — stagger imediato do título → subtítulo → CTAs → trust
 *   2.  Hero visual        — parallax sutil do mockup + cards flutuantes
 *   3.  Hero blobs         — movimento decorativo contínuo (ambiente)
 *   4.  Metrics counter    — incrementa números quando entra na viewport
 *   5.  Features stagger   — cards sobem em sequência
 *   6.  How steps          — passos aparecem um a um
 *   7.  Compliance badges  — pop sequencial
 *   8.  Testimonials       — cards fade-up
 *   9.  Pricing cards      — scale-in com destaque no featured
 *   10. FAQ items          — fade-up suave
 *   11. Contact cards      — fade-up
 */

import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

const REVEAL_OPTS = {
    y: 32,
    opacity: 0,
    duration: 0.8,
    ease: 'power2.out',
};

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
    // ── 0. Acessibilidade ────────────────────────────────────────────────────
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion) {
        // Garante que elementos não fiquem invisíveis se algum CSS usar opacity:0 como estado inicial
        gsap.set('.hero-title, .hero-sub, .hero-ctas, .hero-trust, .feature-card, .testimonial-card, .pricing-card, .faq-item, .contact-card', {
            clearProps: 'all',
        });
        return () => {};
    }

    // Garante limpeza de execuções anteriores (HMR/navegação Inertia)
    ScrollTrigger.getAll().forEach(st => st.kill());
    gsap.killTweensOf('*');

    const context = gsap.context(() => {

        // ── 1. Hero entrance ─────────────────────────────────────────────────
        const heroTl = gsap.timeline({ defaults: { ease: 'power3.out' } });
        heroTl
            .from('.hero-title', { y: 40, opacity: 0, duration: 1.0 })
            .from('.hero-sub', { y: 24, opacity: 0, duration: 0.8 }, '-=0.5')
            .from('.hero-ctas > *', { y: 16, opacity: 0, duration: 0.6, stagger: 0.1 }, '-=0.4')
            .from('.hero-trust', { y: 16, opacity: 0, duration: 0.6 }, '-=0.3');

        // ── 2. Hero visual: mockup + cards flutuantes (entrada + parallax) ──
        gsap.from('.hero-mockup', {
            scale: 0.94,
            opacity: 0,
            duration: 1.2,
            ease: 'back.out(1.4)',
            delay: 0.4,
        });

        // Float cards: entrada
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

        // ── 3. Hero blobs: ambiente decorativo ───────────────────────────────
        gsap.to('.hero-blob-1', {
            x: 40, y: -30, scale: 1.1, duration: 12, ease: 'sine.inOut', repeat: -1, yoyo: true,
        });
        gsap.to('.hero-blob-2', {
            x: -30, y: 40, scale: 0.95, duration: 14, ease: 'sine.inOut', repeat: -1, yoyo: true,
        });

        // ── 4. Metrics counter ───────────────────────────────────────────────
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

        // ── 5. Features stagger ──────────────────────────────────────────────
        gsap.from('.feature-card', {
            ...REVEAL_OPTS,
            stagger: 0.1,
            scrollTrigger: { trigger: '#funcionalidades', start: 'top 80%', once: true },
        });

        // ── 6. How steps ─────────────────────────────────────────────────────
        gsap.from('.how-step-num, .how-step-content', {
            x: -24, opacity: 0, duration: 0.7, stagger: 0.08, ease: 'power2.out',
            scrollTrigger: { trigger: '#como-funciona', start: 'top 75%', once: true },
        });
        gsap.from('.how-visual', {
            x: 30, opacity: 0, duration: 0.9, ease: 'power2.out',
            scrollTrigger: { trigger: '#como-funciona', start: 'top 75%', once: true },
        });

        // ── 7. Compliance badges ─────────────────────────────────────────────
        gsap.from('.compliance-badges > *', {
            scale: 0.85, opacity: 0, duration: 0.5, stagger: 0.08, ease: 'back.out(1.6)',
            scrollTrigger: { trigger: '.compliance', start: 'top 80%', once: true },
        });

        // ── 8. Testimonials ──────────────────────────────────────────────────
        gsap.from('.testimonial-card', {
            ...REVEAL_OPTS,
            stagger: 0.12,
            scrollTrigger: { trigger: '#depoimentos', start: 'top 80%', once: true },
        });

        // ── 9. Pricing cards ─────────────────────────────────────────────────
        gsap.from('.pricing-card', {
            y: 40, scale: 0.96, opacity: 0, duration: 0.7, stagger: 0.1, ease: 'power2.out',
            scrollTrigger: { trigger: '#precos', start: 'top 80%', once: true },
        });

        // Destaque sutil do plano featured após a entrada
        const featured = document.querySelector('.pricing-card.featured');
        if (featured) {
            gsap.to(featured, {
                y: -8,
                duration: 1.4,
                ease: 'sine.inOut',
                repeat: -1,
                yoyo: true,
                delay: 1.2,
            });
        }

        // ── 10. FAQ items ────────────────────────────────────────────────────
        gsap.from('.faq-item', {
            ...REVEAL_OPTS,
            y: 20,
            duration: 0.6,
            stagger: 0.06,
            scrollTrigger: { trigger: '#faq', start: 'top 85%', once: true },
        });

        // ── 11. Contact cards ────────────────────────────────────────────────
        gsap.from('.contact-card', {
            ...REVEAL_OPTS,
            stagger: 0.12,
            scrollTrigger: { trigger: '#contato', start: 'top 80%', once: true },
        });

    });

    // ── Cleanup function ─────────────────────────────────────────────────────
    return () => {
        context.revert(); // mata tweens, restaura props originais, libera GC
        ScrollTrigger.getAll().forEach(st => st.kill());
    };
}
