<template>
    <Head>
        <!-- O nome do app entra pelo callback de título do site.js ("EasyEye — …"). -->
        <title>{{ title }}</title>
        <meta name="description" :content="description">
        <link v-if="canonicalUrl" rel="canonical" :href="canonicalUrl">

        <!-- Mesma fonte da Home (só os pesos usados). Ícones: Tabler, via site.js. -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    </Head>

    <!-- O <main> vem do SiteLayout (alvo do "Pular para o conteúdo"). -->
    <SiteLayout :t="t" :routes="routes" :app-name="appName" :has-hero="false">
        <section class="legal" aria-labelledby="legal-title">
            <div class="container">
                <div class="legal-inner">
                    <h1 id="legal-title" class="section-title">{{ title }}</h1>

                    <template v-if="document">
                        <p class="legal-meta" data-test="legal-version">{{ versionText }}</p>
                        <div class="legal-body" data-test="legal-body">
                            <p v-for="(paragraph, index) in paragraphs" :key="index">{{ paragraph }}</p>
                        </div>
                    </template>

                    <div v-else class="legal-body" role="status" data-test="legal-unavailable">
                        <h2 class="legal-unavailable-title">{{ t.legal.unavailable_title }}</h2>
                        <p>
                            {{ t.legal.unavailable_text }}
                            <a :href="'mailto:' + contact.support">{{ contact.support }}</a>.
                        </p>
                    </div>

                    <a :href="routes.siteHome" class="btn btn-outline legal-back">
                        <i class="ti ti-arrow-left" aria-hidden="true"></i> {{ t.legal.back_home }}
                    </a>
                </div>
            </div>
        </section>
    </SiteLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import { useTrans } from '@/composables/useTrans';

/**
 * /privacidade e /termos — versão vigente dos documentos oficiais
 * (term_versions, via SiteLegalController). O conteúdo é texto puro:
 * parágrafos separados por linha em branco, quebras simples preservadas
 * (white-space: pre-line). Nada de v-html.
 */
const props = defineProps({
    kind: { type: String, required: true }, // 'privacy' | 'terms'
    document: { type: Object, default: null }, // { version, effectiveFrom, content } | null
    t: { type: Object, required: true },
    routes: { type: Object, required: true },
    contact: { type: Object, default: () => ({ sales: '', support: '' }) },
    appName: { type: String, default: 'EasyEye' },
    canonicalUrl: { type: String, default: '' },
});

const { tx } = useTrans(() => props.t.legal ?? {});

const title       = computed(() => tx(`${props.kind}_title`));
const description = computed(() => tx(`${props.kind}_description`));
const versionText = computed(() => tx('version', {
    version: props.document?.version ?? '',
    date: props.document?.effectiveFrom ?? '',
}));
const paragraphs = computed(() => (props.document?.content ?? '')
    .split(/\n\s*\n/)
    .map((paragraph) => paragraph.trim())
    .filter(Boolean));
</script>
