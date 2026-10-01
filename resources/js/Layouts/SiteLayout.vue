<template>
    <!-- site-shell: escopo dos ajustes de contraste do site institucional (o login usa o mesmo site.scss). -->
    <div class="site-shell">
        <!-- Primeiro item do Tab: pula a navegação e vai direto ao conteúdo. -->
        <a href="#conteudo" class="skip-link">{{ t.nav.skip }}</a>

        <!-- ═══════════════════ NAVBAR ═══════════════════ -->
        <nav id="navbar" ref="navbar" :class="{ scrolled: isScrolled || mobileOpen }">
            <div class="container">
                <div class="nav-inner">
                    <!-- Sem v-motion: o plugin nunca foi registrado no site.js (a diretiva
                         não resolvia no navegador, só gerava aviso); hover dos botões vem do CSS. -->
                    <a :href="routes.siteHome" class="nav-logo" :aria-label="appName">
                        <span class="nav-logo-imgs">
                            <img :src="logoSvg" :alt="appName" class="logo-v-dark" />
                            <img :src="logoWhiteSvg" alt="" class="logo-v-white" aria-hidden="true" />
                        </span>
                    </a>

                    <ul class="nav-links">
                        <li v-for="link in primaryLinks" :key="link.anchor">
                            <a
                                :href="routes.siteHome + link.anchor"
                                :aria-current="currentSection === link.anchor ? 'location' : null"
                                >{{ link.label }}</a
                            >
                        </li>
                    </ul>

                    <div class="nav-right">
                        <!-- Seletor de idioma -->
                        <div class="lang-switcher" ref="langSwitcher">
                            <button
                                ref="langToggle"
                                class="lang-btn"
                                @click="langOpen = !langOpen"
                                :aria-expanded="langOpen"
                                :title="t.nav.language"
                                :aria-label="t.nav.language"
                            >
                                <span>{{ currentLocaleData?.flag ?? '🌐' }}</span>
                            </button>
                            <Transition name="fade">
                                <div v-if="langOpen" class="lang-dropdown">
                                    <a
                                        v-for="locale in locales"
                                        :key="locale.code"
                                        :href="locale.url"
                                        :class="['lang-item', { active: locale.active }]"
                                    >
                                        <span>{{ locale.flag }}</span>
                                        {{ locale.native }}
                                        <i v-if="locale.active" class="ti ti-check check" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </Transition>
                        </div>

                        <a :href="routes.go" class="btn btn-outline btn-sm">
                            <i class="ti ti-login" aria-hidden="true"></i> {{ t.nav.login }}
                        </a>
                        <a :href="registrationHref" class="btn btn-primary btn-sm">
                            {{ registrationLabel }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>

                    <button
                        ref="mobileToggle"
                        type="button"
                        class="nav-mobile-btn"
                        @click="mobileOpen = !mobileOpen"
                        :aria-expanded="mobileOpen"
                        :aria-label="t.nav.menu"
                        aria-controls="site-mobile-menu"
                    >
                        <i class="ti" :class="mobileOpen ? 'ti-x' : 'ti-menu-2'" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <div id="site-mobile-menu" class="nav-mobile-menu" :class="{ open: mobileOpen }">
                <ul>
                    <li v-for="link in primaryLinks" :key="link.anchor">
                        <a
                            :href="routes.siteHome + link.anchor"
                            :aria-current="currentSection === link.anchor ? 'location' : null"
                            @click="mobileOpen = false"
                            >{{ link.label }}</a
                        >
                    </li>
                </ul>

                <div class="mobile-lang">
                    <a
                        v-for="locale in locales"
                        :key="locale.code"
                        :href="locale.url"
                        :class="{ active: locale.active }"
                    >
                        {{ locale.flag }} {{ locale.native }}
                    </a>
                </div>

                <div class="mobile-ctas">
                    <a :href="routes.go" class="btn btn-outline" style="justify-content: center">
                        <i class="ti ti-login" aria-hidden="true"></i> {{ t.nav.login }}
                    </a>
                    <a
                        :href="registrationHref"
                        class="btn btn-primary"
                        style="justify-content: center"
                        @click="mobileOpen = false"
                    >
                        {{ registrationLabel }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </nav>
        <!-- ═══════════════════ END NAVBAR ═══════════════════ -->

        <main id="conteudo" ref="content" tabindex="-1">
            <slot />
        </main>

        <!-- ═══════════════════ FOOTER ═══════════════════ -->
        <footer>
            <div class="container">
                <div class="footer-inner" :class="{ 'footer-inner--compact': !hasCompanyPages }">
                    <div class="footer-brand">
                        <a :href="routes.siteHome" class="nav-logo">
                            <img :src="logoSmallSvg" :alt="appName" />
                        </a>
                        <p>{{ t.footer.tagline }}</p>
                        <div class="footer-social">
                            <a
                                href="https://instagram.com/easyeye"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="Instagram"
                                ><i class="ti ti-brand-instagram" aria-hidden="true"></i
                            ></a>
                            <a
                                href="https://linkedin.com/company/easyeye"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="LinkedIn"
                                ><i class="ti ti-brand-linkedin" aria-hidden="true"></i
                            ></a>
                            <a
                                href="https://youtube.com/@easyeye"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="YouTube"
                                ><i class="ti ti-brand-youtube" aria-hidden="true"></i
                            ></a>
                            <a
                                href="https://wa.me/5561984676485"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="WhatsApp"
                                ><i class="ti ti-brand-whatsapp" aria-hidden="true"></i
                            ></a>
                        </div>
                    </div>

                    <div class="footer-col">
                        <h2>{{ t.footer.product }}</h2>
                        <ul>
                            <li>
                                <a :href="routes.siteHome + '#funcionalidades'">{{ t.nav.features }}</a>
                            </li>
                            <li>
                                <a :href="routes.siteHome + '#demonstracao'">{{ t.nav.demo }}</a>
                            </li>
                            <li>
                                <a :href="routes.siteHome + '#precos'">{{ t.nav.pricing }}</a>
                            </li>
                            <li>
                                <a :href="routes.siteHome + '#como-funciona'">{{ t.nav.how }}</a>
                            </li>
                            <li v-if="t.nav.testimonials">
                                <a :href="routes.siteHome + '#depoimentos'">{{ t.nav.testimonials }}</a>
                            </li>
                            <li>
                                <a :href="routes.siteHome + '#faq'">{{ t.nav.faq }}</a>
                            </li>
                        </ul>
                    </div>

                    <!-- Páginas institucionais só aparecem quando existem (routes.* = null
                         enquanto a rota não existe — ver App\Support\Site\SiteLinks). -->
                    <div class="footer-col">
                        <h2>{{ t.footer.system }}</h2>
                        <ul>
                            <li>
                                <a :href="routes.go">{{ t.footer.login }}</a>
                            </li>
                            <li v-if="!registrationClosed">
                                <a :href="routes.register">{{ t.footer.register }}</a>
                            </li>
                            <li>
                                <a :href="routes.siteHome + '#contato'">{{ t.footer.contact }}</a>
                            </li>
                            <li v-if="routes.help">
                                <a :href="routes.help">{{ t.footer.help }}</a>
                            </li>
                            <li v-if="routes.status">
                                <a :href="routes.status">{{ t.footer.status }}</a>
                            </li>
                            <li v-if="routes.apiDocs">
                                <a :href="routes.apiDocs">{{ t.footer.api }}</a>
                            </li>
                        </ul>
                    </div>

                    <div v-if="hasCompanyPages" class="footer-col">
                        <h2>{{ t.footer.company }}</h2>
                        <ul>
                            <li v-if="routes.about">
                                <a :href="routes.about">{{ t.footer.about }}</a>
                            </li>
                            <li v-if="routes.blog">
                                <a :href="routes.blog">{{ t.footer.blog }}</a>
                            </li>
                            <li v-if="routes.partners">
                                <a :href="routes.partners">{{ t.footer.partners }}</a>
                            </li>
                            <li v-if="routes.careers">
                                <a :href="routes.careers">{{ t.footer.careers }}</a>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="footer-bottom">
                    <p v-html="(t.footer.copyright ?? '').replace(':year', currentYear).replace(':name', appName)"></p>
                    <div v-if="routes.privacy || routes.terms || routes.lgpd" class="footer-legal">
                        <a v-if="routes.privacy" :href="routes.privacy">{{ t.footer.privacy }}</a>
                        <a v-if="routes.terms" :href="routes.terms">{{ t.footer.terms }}</a>
                        <a v-if="routes.lgpd" :href="routes.lgpd">{{ t.footer.lgpd }}</a>
                    </div>
                </div>
            </div>
        </footer>
        <!-- ═══════════════════ END FOOTER ═══════════════════ -->
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import logoSvg from '@img/system/logo.svg';
import logoWhiteSvg from '@img/system/logo-white.svg';
import logoSmallSvg from '@img/system/logo-small.svg';

const props = defineProps({
    t: { type: Object, required: true },
    routes: { type: Object, required: true },
    appName: { type: String, default: 'EasyEye' },
    hasHero: { type: Boolean, default: true },
});

const page = usePage();
const locales = computed(() => page.props.locales ?? []);
const currentLocaleData = computed(() => locales.value.find((l) => l.active));
const primaryLinks = computed(() => [
    { anchor: '#funcionalidades', label: props.t.nav.features },
    { anchor: '#demonstracao', label: props.t.nav.demo },
    { anchor: '#precos', label: props.t.nav.pricing },
    { anchor: '#contato', label: props.t.nav.contact },
]);
const registrationClosed = computed(() => typeof page.props.trialDays === 'number' && page.props.trialDays <= 0);
const registrationHref = computed(() =>
    registrationClosed.value ? props.routes.siteHome + '#contato' : props.routes.register,
);
const registrationLabel = computed(() =>
    registrationClosed.value
        ? props.t.nav.contact
        : Number(page.props.trialDays) > 0
          ? props.t.nav.get_started
          : props.t.nav.create_account,
);
const hasCompanyPages = computed(() => ['about', 'blog', 'partners', 'careers'].some((key) => props.routes[key]));

const isScrolled = ref(false);
const currentSection = ref('');
const navbar = ref(null);
const content = ref(null);
const langOpen = ref(false);
const mobileOpen = ref(false);
const mobileToggle = ref(null);
const langToggle = ref(null);
const langSwitcher = ref(null);
const currentYear = new Date().getFullYear();
let desktopQuery;
let sectionFrame = null;

// A seção precisa cobrir a linha de leitura abaixo do cabeçalho. Nos trechos
// sem destino primário (hero, FAQ, depoimentos...), nenhum link fica marcado.
function updateCurrentSection() {
    sectionFrame = null;
    currentSection.value = '';
    if (!props.hasHero || !navbar.value || !content.value) return;

    const readingLine = navbar.value.getBoundingClientRect().bottom + 16;
    if (readingLine >= window.innerHeight) return;

    const current = primaryLinks.value.find(({ anchor }) => {
        const section = content.value.querySelector(anchor);
        if (!section) return false;
        const { top, bottom } = section.getBoundingClientRect();
        return top <= readingLine && bottom > readingLine;
    });
    currentSection.value = current?.anchor ?? '';
}

function scheduleCurrentSection() {
    if (sectionFrame === null) sectionFrame = window.requestAnimationFrame(updateCurrentSection);
}

function onScroll() {
    isScrolled.value = props.hasHero ? window.scrollY > 20 : true;
    scheduleCurrentSection();
}

function onClickOutside(e) {
    if (langSwitcher.value && !langSwitcher.value.contains(e.target)) {
        langOpen.value = false;
    }
}

function onKeydown(e) {
    if (e.key !== 'Escape') return;
    if (langOpen.value) {
        langOpen.value = false;
        langToggle.value?.focus();
    } else if (mobileOpen.value) {
        mobileOpen.value = false;
        mobileToggle.value?.focus();
    }
}

function onDesktopChange(e) {
    if (e.matches) mobileOpen.value = false;
}

// Com o menu móvel aberto a página de trás não rola junto (o menu tem rolagem própria).
watch(mobileOpen, (open) => {
    document.documentElement.classList.toggle('site-menu-open', open);
});

onMounted(() => {
    onScroll();
    desktopQuery = window.matchMedia('(min-width: 1201px)');
    desktopQuery.addEventListener('change', onDesktopChange);
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', scheduleCurrentSection);
    document.addEventListener('click', onClickOutside);
    document.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
    window.removeEventListener('resize', scheduleCurrentSection);
    if (sectionFrame !== null) window.cancelAnimationFrame(sectionFrame);
    document.removeEventListener('click', onClickOutside);
    document.removeEventListener('keydown', onKeydown);
    desktopQuery?.removeEventListener('change', onDesktopChange);
    document.documentElement.classList.remove('site-menu-open');
});
</script>
