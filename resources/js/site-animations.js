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

export function initSiteAnimations(locale = 'pt-BR') {
    // ── Acessibilidade ───────────────────────────────────────────────────────
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion) {
        return () => {};
    }

    // Garante limpeza de execuções anteriores (HMR/navegação Inertia)
    ScrollTrigger.getAll().forEach(st => st.kill());
    const originalMetrics = new Map();
    // Loops decorativos do hero (manchas e cartões flutuantes): só rodam com o hero na tela.
    const heroLoops = [];

    const context = gsap.context(() => {

        // ── Hero entrance (toca IMEDIATAMENTE — sem dependência de scroll) ──
        // Desaceleração natural (expo), a mesma curva do CSS ($ease-out).
        const heroTl = gsap.timeline({ defaults: { ease: 'expo.out' } });
        heroTl
            .from('.hero-title', { y: 40, opacity: 0, duration: 1.0 })
            .from('.hero-sub', { y: 24, opacity: 0, duration: 0.8 }, '-=0.5')
            // A nota do teste grátis (só existe com plano em teste) entra junto dos botões.
            .from('.hero-ctas > *, .hero-cta-note', { y: 16, opacity: 0, duration: 0.6, stagger: 0.1 }, '-=0.4')
            .from('.hero-trust', { y: 16, opacity: 0, duration: 0.6 }, '-=0.3');

        // ── Hero visual: mockup + cards flutuantes (entrada imediata) ────────
        // Só existe com o print do prontuário publicado (ver heroImage no SiteController).
        if (document.querySelector('.hero-visual')) heroLoops.push(...animateHeroVisual());

        // ── Hero blobs: ambiente decorativo ──────────────────────────────────
        heroLoops.push(
            gsap.to('.hero-blob-1', {
                x: 40, y: -30, scale: 1.1, duration: 12, ease: 'sine.inOut', repeat: -1, yoyo: true,
            }),
            gsap.to('.hero-blob-2', {
                x: -30, y: 40, scale: 0.95, duration: 14, ease: 'sine.inOut', repeat: -1, yoyo: true,
            }),
        );

        // ── Metrics counter (fail-safe: sem trigger, o texto original fica) ──
        document.querySelectorAll('.metric-value').forEach(el => {
            // Numeric data is separate from the translated display string.
            // Parsing "99,9%" as digits previously changed the claim to 999%.
            const target = Number(el.dataset.amount);
            const decimals = Number(el.dataset.decimals ?? 0);
            if (!Number.isFinite(target) || target <= 0 || !Number.isInteger(decimals) || decimals < 0 || decimals > 20) return;
            const formatter = new Intl.NumberFormat(locale.replace('_', '-'), {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            });
            const original = el.textContent;
            originalMetrics.set(el, original);

            const obj = { val: 0 };
            gsap.to(obj, {
                val: target,
                duration: 2.0,
                ease: 'power2.out',
                snap: { val: 10 ** -decimals },
                onUpdate: () => {
                    el.textContent = `${el.dataset.prefix ?? ''}${formatter.format(obj.val)}${el.dataset.suffix ?? ''}`;
                },
                onComplete: () => { el.textContent = original; },
                scrollTrigger: { trigger: el, start: 'top 88%', once: true },
            });
        });

        // NOTA: NENHUM reveal de card gated por ScrollTrigger. A entrada das
        // listas de cartões é CSS nativo ligado à rolagem (_sections.scss,
        // "Entrada das listas de cartões"), que sem suporte deixa tudo visível —
        // sem JS no caminho crítico de visibilidade.
    });

    // Loops decorativos param com o hero fora da tela (voltam ao reaparecer).
    let loopObserver = null;
    const hero = document.querySelector('.hero');
    if (hero && heroLoops.length && 'IntersectionObserver' in window) {
        loopObserver = new IntersectionObserver(([entry]) => {
            heroLoops.forEach((tween) => (entry.isIntersecting ? tween.play() : tween.pause()));
        });
        loopObserver.observe(hero);
    }

    // ── Cleanup function ─────────────────────────────────────────────────────
    return () => {
        loopObserver?.disconnect();
        context.revert();
        originalMetrics.forEach((text, el) => { el.textContent = text; });
        ScrollTrigger.getAll().forEach(st => st.kill());
    };
}

// Print do prontuário e cartões flutuantes: entrada imediata e flutuação leve.
// Chamada dentro do gsap.context acima (o revert do cleanup também desfaz estes).
// Devolve os loops para pausarem com o hero fora da tela.
function animateHeroVisual() {
    // Sem overshoot: o print assenta com desaceleração natural.
    gsap.from('.hero-mockup', {
        scale: 0.96,
        opacity: 0,
        duration: 1.1,
        ease: 'expo.out',
        delay: 0.4,
    });

    gsap.from('.hero-float-card.card-top', {
        x: -30, y: -20, opacity: 0, duration: 0.9, delay: 0.9, ease: 'expo.out',
    });
    gsap.from('.hero-float-card.card-bottom', {
        x: 30, y: 20, opacity: 0, duration: 0.9, delay: 1.1, ease: 'expo.out',
    });

    // Float cards: movimento contínuo (subtle floating)
    return [
        gsap.to('.hero-float-card.card-top', {
            y: '+=12', duration: 3.5, ease: 'sine.inOut', repeat: -1, yoyo: true,
        }),
        gsap.to('.hero-float-card.card-bottom', {
            y: '-=12', duration: 4, ease: 'sine.inOut', repeat: -1, yoyo: true, delay: 0.5,
        }),
    ];
}
