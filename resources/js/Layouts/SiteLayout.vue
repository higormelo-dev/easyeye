<template>
    <div>
        <!-- ═══════════════════ NAVBAR ═══════════════════ -->
        <nav id="navbar" :class="{ scrolled: isScrolled }">
            <div class="container">
                <div class="nav-inner">
                    <a :href="routes.siteHome" class="nav-logo" :aria-label="appName">
                        <span class="nav-logo-imgs">
                            <img :src="logoSvg" :alt="appName" class="logo-v-dark">
                            <img :src="logoWhiteSvg" alt="" class="logo-v-white" aria-hidden="true">
                        </span>
                    </a>

                    <ul class="nav-links">
                        <li><a :href="routes.siteHome + '#funcionalidades'">{{ t.nav.features }}</a></li>
                        <li><a :href="routes.siteHome + '#como-funciona'">{{ t.nav.how }}</a></li>
                        <li><a :href="routes.siteHome + '#precos'">{{ t.nav.pricing }}</a></li>
                        <li><a :href="routes.siteHome + '#depoimentos'">{{ t.nav.testimonials }}</a></li>
                        <li><a :href="routes.siteHome + '#faq'">{{ t.nav.faq }}</a></li>
                        <li><a :href="routes.siteHome + '#contato'">{{ t.nav.contact }}</a></li>
                    </ul>

                    <div class="nav-right">
                        <!-- Seletor de idioma -->
                        <div class="lang-switcher" ref="langSwitcher">
                            <button
                                class="lang-btn"
                                @click="langOpen = !langOpen"
                                :aria-expanded="langOpen"
                                :title="t.nav.language"
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
                                        <i v-if="locale.active" class="bi bi-check2 check"></i>
                                    </a>
                                </div>
                            </Transition>
                        </div>

                        <a :href="routes.go" class="btn btn-outline" style="padding:10px 20px;font-size:14px;">
                            <i class="bi bi-box-arrow-in-right"></i> {{ t.nav.login }}
                        </a>
                        <a :href="routes.register" class="btn btn-primary" style="padding:10px 20px;font-size:14px;">
                            {{ t.nav.get_started }} <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>

                    <button class="nav-mobile-btn" @click="mobileOpen = !mobileOpen" :aria-expanded="mobileOpen">
                        <i class="bi" :class="mobileOpen ? 'bi-x-lg' : 'bi-list'"></i>
                    </button>
                </div>
            </div>

            <div class="nav-mobile-menu" :class="{ open: mobileOpen }">
                <ul>
                    <li><a :href="routes.siteHome + '#funcionalidades'" @click="mobileOpen = false">{{ t.nav.features }}</a></li>
                    <li><a :href="routes.siteHome + '#como-funciona'"   @click="mobileOpen = false">{{ t.nav.how }}</a></li>
                    <li><a :href="routes.siteHome + '#precos'"          @click="mobileOpen = false">{{ t.nav.pricing }}</a></li>
                    <li><a :href="routes.siteHome + '#depoimentos'"     @click="mobileOpen = false">{{ t.nav.testimonials }}</a></li>
                    <li><a :href="routes.siteHome + '#faq'"             @click="mobileOpen = false">{{ t.nav.faq }}</a></li>
                    <li><a :href="routes.siteHome + '#contato'"         @click="mobileOpen = false">{{ t.nav.contact }}</a></li>
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
                    <a :href="routes.go" class="btn btn-outline" style="justify-content:center;">
                        <i class="bi bi-box-arrow-in-right"></i> {{ t.nav.login }}
                    </a>
                    <a :href="routes.register" class="btn btn-primary" style="justify-content:center;">
                        {{ t.nav.get_started }} <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
        </nav>
        <!-- ═══════════════════ END NAVBAR ═══════════════════ -->

        <slot />

        <!-- ═══════════════════ FOOTER ═══════════════════ -->
        <footer>
            <div class="container">
                <div class="footer-inner">
                    <div class="footer-brand">
                        <a :href="routes.siteHome" class="nav-logo">
                            <img :src="logoSmallSvg" :alt="appName">
                        </a>
                        <p>{{ t.footer.tagline }}</p>
                        <div class="footer-social">
                            <a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
                            <a href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
                            <a href="#" aria-label="YouTube"><i class="bi bi-youtube"></i></a>
                            <a href="#" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a>
                        </div>
                    </div>

                    <div class="footer-col">
                        <h5>{{ t.footer.product }}</h5>
                        <ul>
                            <li><a :href="routes.siteHome + '#funcionalidades'">{{ t.nav.features }}</a></li>
                            <li><a :href="routes.siteHome + '#precos'">{{ t.nav.pricing }}</a></li>
                            <li><a :href="routes.siteHome + '#como-funciona'">{{ t.nav.how }}</a></li>
                            <li><a :href="routes.siteHome + '#depoimentos'">{{ t.nav.testimonials }}</a></li>
                            <li><a :href="routes.siteHome + '#faq'">{{ t.nav.faq }}</a></li>
                        </ul>
                    </div>

                    <div class="footer-col">
                        <h5>{{ t.footer.system }}</h5>
                        <ul>
                            <li><a :href="routes.go">{{ t.footer.login }}</a></li>
                            <li><a :href="routes.register">{{ t.footer.register }}</a></li>
                            <li><a href="#">{{ t.footer.help }}</a></li>
                            <li><a href="#">{{ t.footer.status }}</a></li>
                            <li><a href="#">{{ t.footer.api }}</a></li>
                        </ul>
                    </div>

                    <div class="footer-col">
                        <h5>{{ t.footer.company }}</h5>
                        <ul>
                            <li><a href="#">{{ t.footer.about }}</a></li>
                            <li><a href="#">{{ t.footer.blog }}</a></li>
                            <li><a href="#">{{ t.footer.partners }}</a></li>
                            <li><a :href="routes.siteHome + '#contato'">{{ t.footer.contact }}</a></li>
                            <li><a href="#">{{ t.footer.careers }}</a></li>
                        </ul>
                    </div>
                </div>

                <div class="footer-bottom">
                    <p v-html="(t.footer.copyright ?? '').replace(':year', currentYear).replace(':name', appName)"></p>
                    <div class="footer-legal">
                        <a href="#">{{ t.footer.privacy }}</a>
                        <a href="#">{{ t.footer.terms }}</a>
                        <a href="#">{{ t.footer.lgpd }}</a>
                    </div>
                </div>
            </div>
        </footer>
        <!-- ═══════════════════ END FOOTER ═══════════════════ -->
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
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
const currentLocaleData = computed(() => locales.value.find(l => l.active));

const isScrolled = ref(false);
const langOpen = ref(false);
const mobileOpen = ref(false);
const langSwitcher = ref(null);
const currentYear = new Date().getFullYear();


function onScroll() {
    isScrolled.value = props.hasHero ? window.scrollY > 20 : true;
}

function onClickOutside(e) {
    if (langSwitcher.value && !langSwitcher.value.contains(e.target)) {
        langOpen.value = false;
    }
}

onMounted(() => {
    if (!props.hasHero) isScrolled.value = true;
    window.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('click', onClickOutside);
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
    document.removeEventListener('click', onClickOutside);
});
</script>
