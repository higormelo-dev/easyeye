<template>
    <Head>
        <!-- O nome do app entra pelo callback de título do site.js ("EasyEye — …"). -->
        <title>{{ title }}</title>
        <meta name="description" :content="description" />
        <link v-if="canonicalUrl" rel="canonical" :href="canonicalUrl" />
        <!-- A Inter vem no <head> do app.blade.php; ícones: Tabler, via site.js. -->
    </Head>

    <!-- O <main> vem do SiteLayout (alvo do "Pular para o conteúdo"). -->
    <SiteLayout :t="t" :routes="routes" :app-name="appName" :has-hero="false">
        <section class="legal" aria-labelledby="legal-title">
            <div class="container">
                <div class="legal-inner">
                    <h1 id="legal-title" class="section-title">{{ title }}</h1>

                    <template v-if="document">
                        <p class="legal-meta" data-test="legal-version">{{ versionText }}</p>
                        <!-- Idioma: o texto oficial é o em português. Em outro idioma aparece a
                             tradução de cortesia (com link para o original) ou o original, avisado. -->
                        <p
                            v-if="document.isTranslation"
                            class="legal-language"
                            role="note"
                            data-test="legal-translation"
                        >
                            <i class="ti ti-language" aria-hidden="true"></i>
                            <span>
                                {{ t.legal.translation_notice }}
                                <a :href="document.originalUrl">{{ t.legal.read_original }}</a>
                            </span>
                        </p>
                        <p
                            v-else-if="document.isOriginal"
                            class="legal-language"
                            role="note"
                            data-test="legal-original"
                        >
                            <i class="ti ti-language" aria-hidden="true"></i>
                            <span>
                                {{ document.hasTranslation ? t.legal.original_notice : t.legal.original_only }}
                                <a v-if="document.hasTranslation" :href="document.translationUrl">{{
                                    t.legal.read_translation
                                }}</a>
                            </span>
                        </p>
                        <nav v-if="headings.length" class="legal-index" aria-labelledby="legal-index-title">
                            <h2 id="legal-index-title">{{ t.legal.contents }}</h2>
                            <ol :lang="document.contentLang">
                                <li v-for="heading in headings" :key="heading.id">
                                    <a :href="'#' + heading.id">{{ heading.text }}</a>
                                </li>
                            </ol>
                        </nav>
                        <article
                            class="legal-body"
                            data-test="legal-body"
                            aria-labelledby="legal-title"
                            :lang="document.contentLang"
                        >
                            <template v-for="block in blocks" :key="block.id">
                                <h2 v-if="block.type === 'heading'" :id="block.id" tabindex="-1">{{ block.text }}</h2>
                                <ul v-else-if="block.type === 'list'">
                                    <li v-for="(item, index) in block.items" :key="index">{{ item }}</li>
                                </ul>
                                <p v-else>{{ block.text }}</p>
                            </template>
                        </article>
                    </template>

                    <div v-else class="legal-body" role="status" data-test="legal-unavailable">
                        <h2 class="legal-unavailable-title">{{ t.legal.unavailable_title }}</h2>
                        <p>
                            {{ t.legal.unavailable_text }}
                            <a :href="'mailto:' + contact.support">{{ contact.support }}</a
                            >.
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
 * parágrafos separados por linha em branco, títulos numerados e listas com
 * hífen. Todo conteúdo é interpolado como texto, nunca como HTML.
 */
const props = defineProps({
    kind: { type: String, required: true }, // 'privacy' | 'terms'
    // { version, effectiveFrom, content, contentLang, isTranslation, isOriginal,
    //   hasTranslation, originalUrl, translationUrl } | null
    document: { type: Object, default: null },
    t: { type: Object, required: true },
    routes: { type: Object, required: true },
    contact: { type: Object, default: () => ({ sales: '', support: '' }) },
    appName: { type: String, default: 'EasyEye' },
    canonicalUrl: { type: String, default: '' },
});

const { tx } = useTrans(() => props.t.legal ?? {});

const title = computed(() => tx(`${props.kind}_title`));
const description = computed(() => tx(`${props.kind}_description`));
const versionText = computed(() =>
    tx('version', {
        version: props.document?.version ?? '',
        date: props.document?.effectiveFrom ?? '',
    }),
);
const blocks = computed(() =>
    (props.document?.content ?? '')
        .replace(/\r\n?/g, '\n')
        .split(/\n\s*\n/)
        .map((paragraph) => paragraph.trim())
        .filter(Boolean)
        .map((text, index) => {
            const id = `legal-section-${index}`;
            if (/^\d+\.\s+[^\n]+$/.test(text)) return { id, type: 'heading', text };

            const lines = text.split('\n');
            if (lines.every((line) => /^-\s+/.test(line))) {
                return { id, type: 'list', items: lines.map((line) => line.replace(/^-\s+/, '')) };
            }

            return { id, type: 'paragraph', text };
        }),
);
const headings = computed(() => blocks.value.filter((block) => block.type === 'heading'));
</script>
