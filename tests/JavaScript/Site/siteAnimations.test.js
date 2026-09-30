import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { initSiteAnimations } from '@/site-animations';

let cleanup;
let foreignTrigger;
let hidden;
let reduced;
let desktop;
let intersections;

function mediaQuery(media, matches) {
    const listeners = new Set();
    return {
        media,
        matches,
        listeners,
        addEventListener(_, listener) { listeners.add(listener); },
        removeEventListener(_, listener) { listeners.delete(listener); },
        addListener(listener) { listeners.add(listener); },
        removeListener(listener) { listeners.delete(listener); },
        change(value) {
            this.matches = value;
            listeners.forEach(listener => listener({ matches: value, media }));
        },
    };
}

const title = () => document.querySelector('.hero-title');
const metric = () => document.querySelector('.metric-value');
const corners = () => [...document.querySelectorAll('.hero-calibration-corner')];
const heroAnimation = () => gsap.getTweensOf(corners()[0])[0]?.parent;
const heroObserver = () => intersections.find(observer => observer.target?.matches('.hero-instrument'));
const counterAnimation = () => ScrollTrigger.getAll().find(trigger => trigger.trigger === metric())?.animation;

function start(locale = 'pt-BR', inView = true) {
    cleanup = initSiteAnimations(locale);
    heroObserver()?.report(inView);
    // O teste controla o progresso pela API pública, sem depender do relógio.
    gsap.ticker.sleep();
}

function setHidden(value) {
    hidden = value;
    document.dispatchEvent(new Event('visibilitychange'));
}

beforeEach(() => {
    hidden = false;
    reduced = mediaQuery('(prefers-reduced-motion: reduce)', false);
    desktop = mediaQuery('(min-width: 641px)', true);
    intersections = [];
    vi.stubGlobal('IntersectionObserver', class {
        constructor(callback) {
            this.callback = callback;
            this.disconnected = false;
            intersections.push(this);
        }

        observe(target) { this.target = target; }
        disconnect() { this.disconnected = true; }
        report(isIntersecting, intersectionRatio = isIntersecting ? 1 : 0) {
            if (!this.disconnected) this.callback([{ target: this.target, isIntersecting, intersectionRatio }]);
        }
    });
    vi.spyOn(document, 'hidden', 'get').mockImplementation(() => hidden);
    vi.spyOn(window, 'matchMedia').mockImplementation(query => query.includes('reduced-motion') ? reduced : desktop);
    document.body.innerHTML = `
        <section class="hero">
            <h1 class="hero-title">Prontuário oftalmológico</h1>
            <p class="hero-sub">Sua clínica em um lugar.</p>
            <div class="hero-ctas"><a href="/register">Começar</a></div>
            <p class="hero-trust">Confiança</p>
            <div class="hero-instrument">
                <figure class="hero-mockup"><img class="hero-shot" src="/hero.webp" alt="Prontuário"></figure>
                ${['tl', 'tr', 'br', 'bl'].map(corner => `<svg class="hero-calibration-corner" data-corner="${corner}" aria-hidden="true"><path d="M0 12V0H12" /></svg>`).join('')}
            </div>
            <div class="hero-float-card card-top">Exames</div>
            <div class="hero-float-card card-bottom">Histórico</div>
        </section>
        <span class="metric-value" data-amount="99.9" data-decimals="1" data-prefix="+ " data-suffix="%">+ 99,9%</span>
        <div id="other-surface">Outro componente</div>`;
    // Happy DOM não calcula geometria. A métrica fica abaixo da dobra até o
    // teste iniciar sua animação, como ocorre antes de rolar a landing.
    vi.spyOn(metric(), 'getBoundingClientRect').mockReturnValue({
        top: 1200, bottom: 1232, left: 0, right: 120, width: 120, height: 32,
    });
});

afterEach(() => {
    cleanup?.();
    cleanup = undefined;
    foreignTrigger?.kill();
    foreignTrigger = undefined;
    gsap.ticker.sleep();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('site animations — ciclo de vida', () => {
    it('cleanup restaura conteúdo e preserva triggers de outros componentes', () => {
        foreignTrigger = ScrollTrigger.create({ id: 'other-component', trigger: '#other-surface', start: 'top top' });
        start();
        counterAnimation().progress(0.4);
        expect(metric().textContent).not.toBe('+ 99,9%');

        cleanup();
        expect(metric().textContent).toBe('+ 99,9%');
        expect(title().style.transform).toBe('');
        expect(title().style.opacity).toBe('');
        expect(corners().every(corner => !corner.style.transform && !corner.style.opacity)).toBe(true);
        expect(heroObserver().disconnected).toBe(true);
        expect(ScrollTrigger.getById('other-component')).toBe(foreignTrigger);
        expect(counterAnimation()).toBeUndefined();
    });

    it('reduzir movimento em execução reverte estilos e números sem repetir a entrada depois', () => {
        start();
        heroAnimation().progress(0.3);
        counterAnimation().progress(0.4);
        reduced.change(true);

        expect(corners().every(corner => !corner.style.transform && !corner.style.opacity)).toBe(true);
        expect(heroObserver().disconnected).toBe(true);
        expect(metric().textContent).toBe('+ 99,9%');
        expect(counterAnimation()).toBeUndefined();

        reduced.change(false);
        expect(heroAnimation()).toBeUndefined();
        expect(counterAnimation()).toBeUndefined();
        expect(metric().textContent).toBe('+ 99,9%');
    });

    it('aba oculta pausa execuções e só retoma enquanto o movimento continua permitido', () => {
        start();
        const entrance = heroAnimation();
        const counter = counterAnimation();
        entrance.progress(0.2);
        counter.play().progress(0.2);
        const beforeHidden = metric().textContent;

        setHidden(true);
        expect(entrance.paused()).toBe(true);
        expect(counter.paused()).toBe(true);
        counter.progress(0.5);
        expect(metric().textContent).toBe(beforeHidden);

        setHidden(false);
        expect(entrance.paused()).toBe(false);
        expect(counter.paused()).toBe(false);

        setHidden(true);
        reduced.change(true);
        setHidden(false);
        expect(heroAnimation()).toBeUndefined();
        expect(counterAnimation()).toBeUndefined();
        expect(metric().textContent).toBe('+ 99,9%');
    });

    it('preferência inicial reduzida mantém conteúdo estático e cleanup remove os listeners', () => {
        reduced.matches = true;
        start();
        expect(title().getAttribute('style')).toBeNull();
        expect(metric().textContent).toBe('+ 99,9%');
        expect(counterAnimation()).toBeUndefined();
        expect(heroObserver()).toBeUndefined();

        reduced.change(false);
        expect(heroAnimation()).toBeUndefined();
        expect(heroObserver()).toBeUndefined();

        cleanup();
        expect(reduced.listeners.size).toBe(0);
        reduced.change(false);
        setHidden(false);
        expect(heroAnimation()).toBeUndefined();
        expect(counterAnimation()).toBeUndefined();
    });

    it('uma montagem em aba oculta só executa a entrada quando a aba fica visível', () => {
        hidden = true;
        start();
        expect(heroAnimation()).toBeUndefined();
        expect(corners().every(corner => !corner.getAttribute('style'))).toBe(true);
        expect(metric().textContent).toBe('+ 99,9%');

        setHidden(false);
        expect(heroAnimation().paused()).toBe(false);
        expect(counterAnimation().paused()).toBe(true);
    });

    it('retomar a preferência permite apenas contadores ainda não apresentados', () => {
        start();
        heroAnimation().progress(0.3);
        reduced.change(true);
        reduced.change(false);

        expect(heroAnimation()).toBeUndefined();
        expect(metric().textContent).toBe('+ 99,9%');
        counterAnimation().progress(0.5);
        expect(metric().textContent).not.toBe('+ 99,9%');
    });

    it('uma falha de inicialização devolve conteúdo e remove os listeners locais', () => {
        expect(() => start('invalid_locale???')).toThrow(RangeError);
        expect(title().style.opacity).toBe('');
        expect(title().style.transform).toBe('');
        expect(metric().textContent).toBe('+ 99,9%');
        expect(reduced.listeners.size).toBe(0);
        expect(heroAnimation()).toBeUndefined();
        expect(counterAnimation()).toBeUndefined();
    });

    it('anima apenas os cantos e libera seus estilos ao concluir sem mover o conteúdo', () => {
        start();
        const stable = [...document.querySelectorAll('.hero-title, .hero-sub, .hero-trust, .hero-mockup, .hero-shot, .hero-ctas, .hero-ctas a, .hero-float-card')];
        expect(stable.every(element => gsap.getTweensOf(element).length === 0 && !element.getAttribute('style'))).toBe(true);
        expect(heroAnimation().duration()).toBeCloseTo(0.8);
        expect(corners().every(corner => Number(corner.style.opacity || 1) >= 0.4)).toBe(true);
        heroAnimation().progress(0.1);
        expect(corners().every(corner => Number(corner.style.opacity) >= 0.4)).toBe(true);
        heroAnimation().progress(1);
        expect(corners().every(corner => !corner.style.transform && !corner.style.opacity)).toBe(true);
        expect(stable.every(element => !element.getAttribute('style'))).toBe(true);
        expect(heroObserver().disconnected).toBe(true);
        heroObserver().report(false);
        heroObserver().report(true);
        setHidden(true);
        setHidden(false);
        expect(heroAnimation()).toBeUndefined();
    });

    it('mobile usa gesto mais curto e resize não reinicia a entrada', () => {
        desktop.matches = false;
        start();
        expect(heroAnimation().duration()).toBeCloseTo(0.55);
        heroAnimation().progress(1);
        desktop.change(true);
        window.dispatchEvent(new Event('resize'));
        expect(heroAnimation()).toBeUndefined();
    });

    it('espera a moldura entrar na tela e pausa fora dela sem reiniciar o gesto', () => {
        start('pt-BR', false);
        expect(heroAnimation()).toBeUndefined();
        expect(corners().every(corner => !corner.getAttribute('style'))).toBe(true);

        heroObserver().report(true, 0.2);
        expect(heroAnimation()).toBeUndefined();
        heroObserver().report(true, 0.35);
        const entrance = heroAnimation();
        entrance.progress(0.3);
        heroObserver().report(true, 0.1);
        expect(entrance.paused()).toBe(false);
        heroObserver().report(false);
        expect(entrance.paused()).toBe(true);
        setHidden(true);
        setHidden(false);
        expect(entrance.paused()).toBe(true);
        expect(entrance.progress()).toBeCloseTo(0.3);

        heroObserver().report(true);
        expect(heroAnimation()).toBe(entrance);
        expect(entrance.paused()).toBe(false);
        expect(entrance.progress()).toBeCloseTo(0.3);
    });

    it.each(['instrument', 'image', 'corner', 'observer'])('mantém o hero estático sem %s e ainda permite contadores', missing => {
        if (missing === 'instrument') document.querySelector('.hero-instrument').remove();
        if (missing === 'image') document.querySelector('.hero-shot').removeAttribute('src');
        if (missing === 'corner') corners()[0].remove();
        if (missing === 'observer') vi.stubGlobal('IntersectionObserver', undefined);
        start();
        expect(heroObserver()).toBeUndefined();
        expect(corners().every(corner => !corner.getAttribute('style'))).toBe(true);
        expect(counterAnimation()).toBeDefined();
    });

    it.each(['pt-BR', 'en'])('contador mantém prefixo, sufixo e precisão em %s e restaura texto final', locale => {
        start(locale);
        const counter = counterAnimation();
        counter.progress(0.5);
        expect(metric().textContent).toMatch(locale === 'pt-BR' ? /^\+ \d+,\d%$/ : /^\+ \d+\.\d%$/);
        counter.progress(1);
        expect(metric().textContent).toBe('+ 99,9%');
    });
});
