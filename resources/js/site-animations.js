/**
 * EasyEye — calibração breve da moldura clínica e contadores finitos.
 * O conteúdo permanece visível se o JavaScript ou um trigger falhar.
 * Cada montagem cuida apenas das próprias animações e devolve seu cleanup.
 */
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

export function initSiteAnimations(locale = 'pt-BR') {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const desktop = window.matchMedia('(min-width: 641px)');
    const originalMetrics = new Map();
    const startedMetrics = new Set();
    const animations = new Set();
    const pausedForVisibility = new Set();
    let context = null;
    let disposed = false;
    let heroObserver = null;
    let heroEntrance = null;
    let heroInView = false;
    let startVisibleHero = null;
    // A página já apresentada sem movimento não ganha uma entrada tardia.
    let heroPresented = reducedMotion.matches;

    document.querySelectorAll('.metric-value').forEach(el => originalMetrics.set(el, el.textContent));

    function restoreMetrics() {
        originalMetrics.forEach((text, el) => { el.textContent = text; });
    }

    function pauseWhenHidden(animation) {
        if (document.hidden && !animation.paused() && animation.totalProgress() < 1) {
            animation.pause();
            pausedForVisibility.add(animation);
        }
    }

    function track(animation) {
        animations.add(animation);
        pauseWhenHidden(animation);
        return animation;
    }

    function stopAnimations() {
        heroObserver?.disconnect();
        heroObserver = null;
        startVisibleHero = null;
        // Context.revert restaura estilos e mata somente os triggers deste contexto.
        context?.revert();
        context = null;
        heroEntrance = null;
        heroInView = false;
        animations.clear();
        pausedForVisibility.clear();
        restoreMetrics();
    }

    function syncHeroPlayback() {
        if (!heroEntrance || heroEntrance.totalProgress() >= 1) return;
        if (document.hidden || !heroInView) heroEntrance.pause();
        else heroEntrance.resume();
    }

    function observeHero() {
        if (heroPresented || typeof window.IntersectionObserver !== 'function') return;
        const instrument = document.querySelector('.hero-instrument');
        if (!instrument?.querySelector('.hero-shot')?.getAttribute('src')) return;
        const directions = { tl: [-1, -1], tr: [1, -1], br: [1, 1], bl: [-1, 1] };
        const corners = Object.keys(directions).map(corner => instrument.querySelector(`.hero-calibration-corner[data-corner="${corner}"]`));
        if (corners.some(corner => !corner)) return;
        let visibleRatio = 0;

        function revealWhenVisible() {
            if (disposed || reducedMotion.matches || !context) return;
            if (!heroPresented && heroInView && visibleRatio >= 0.35 && !document.hidden) {
                heroPresented = true;
                context.add(() => {
                    const distance = desktop.matches ? 8 : 3;
                    heroEntrance = gsap.timeline({
                        paused: true,
                        defaults: { ease: 'expo.out', duration: desktop.matches ? 0.8 : 0.55, clearProps: 'transform,opacity' },
                        onComplete() {
                            heroObserver?.disconnect();
                            heroObserver = null;
                            startVisibleHero = null;
                        },
                    });
                    corners.forEach(corner => {
                        const [x, y] = directions[corner.dataset.corner];
                        heroEntrance.fromTo(corner, { x: x * distance, y: y * distance, opacity: 0.4 }, { x: 0, y: 0, opacity: 1 }, 0);
                    });
                });
            }
            syncHeroPlayback();
        }

        heroObserver = new IntersectionObserver(([entry]) => {
            heroInView = entry.isIntersecting;
            visibleRatio = entry.intersectionRatio;
            revealWhenVisible();
        }, { threshold: [0, 0.35] });
        heroObserver.observe(instrument);
        // Documento oculto na primeira interseção: guarda o início para a volta.
        startVisibleHero = revealWhenVisible;
    }

    function startAnimations() {
        if (disposed || reducedMotion.matches || context) return;
        context = gsap.context(() => {});

        try {
            context.add(() => {
                originalMetrics.forEach((original, el) => {
                    if (startedMetrics.has(el)) return;
                    const target = Number(el.dataset.amount);
                    const decimals = Number(el.dataset.decimals ?? 0);
                    if (!Number.isFinite(target) || target <= 0 || !Number.isInteger(decimals) || decimals < 0 || decimals > 20) return;

                    const formatter = new Intl.NumberFormat(locale.replace('_', '-'), {
                        minimumFractionDigits: decimals,
                        maximumFractionDigits: decimals,
                    });
                    const count = { value: 0 };
                    track(gsap.to(count, {
                        value: target,
                        duration: 0.7,
                        ease: 'power2.out',
                        snap: { value: 10 ** -decimals },
                        onStart() {
                            startedMetrics.add(el);
                            pauseWhenHidden(this);
                        },
                        onUpdate() {
                            if (disposed || reducedMotion.matches || document.hidden) return;
                            el.textContent = `${el.dataset.prefix ?? ''}${formatter.format(count.value)}${el.dataset.suffix ?? ''}`;
                        },
                        onComplete() { el.textContent = original; },
                        scrollTrigger: { trigger: el, start: 'top 88%', once: true },
                    }));
                });
            });
            observeHero();
        } catch (error) {
            cleanup();
            throw error;
        }
    }

    function onMotionPreferenceChange() {
        if (reducedMotion.matches) {
            heroPresented = true;
            stopAnimations();
        } else {
            // Contadores ainda não vistos podem animar; nada já iniciado se repete.
            startAnimations();
        }
    }

    function onVisibilityChange() {
        startVisibleHero?.();
        if (document.hidden) {
            animations.forEach(pauseWhenHidden);
        } else if (!reducedMotion.matches) {
            pausedForVisibility.forEach(animation => animation.resume());
            pausedForVisibility.clear();
        }
    }

    function cleanup() {
        if (disposed) return;
        disposed = true;
        reducedMotion.removeEventListener('change', onMotionPreferenceChange);
        document.removeEventListener('visibilitychange', onVisibilityChange);
        stopAnimations();
    }

    reducedMotion.addEventListener('change', onMotionPreferenceChange);
    document.addEventListener('visibilitychange', onVisibilityChange);
    startAnimations();
    return cleanup;
}
