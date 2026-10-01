<template>
    <Head>
        <title>{{ t.meta.title }}</title>
        <meta name="description" :content="t.meta.description" />

        <!-- Canonical -->
        <link rel="canonical" :href="seo.canonicalUrl" />

        <!-- hreflang (multi-idioma) -->
        <link
            v-for="alt in seo.alternateLocales"
            :key="alt.code"
            rel="alternate"
            :hreflang="alt.default ? 'x-default' : alt.code"
            :href="alt.url"
        />
        <link
            v-for="alt in seo.alternateLocales"
            :key="'h-' + alt.code"
            rel="alternate"
            :hreflang="alt.code"
            :href="alt.url"
        />

        <!-- Open Graph -->
        <meta property="og:title" :content="t.meta.og_title" />
        <meta property="og:description" :content="t.meta.og_description" />
        <meta property="og:type" content="website" />
        <meta property="og:url" :content="seo.canonicalUrl" />
        <meta property="og:image" :content="seo.ogImage" />
        <meta property="og:image:width" content="1200" />
        <meta property="og:image:height" content="630" />
        <meta property="og:locale" :content="seo.currentLocale" />
        <meta property="og:site_name" :content="appName" />

        <!-- Twitter Cards -->
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" :content="t.meta.og_title" />
        <meta name="twitter:description" :content="t.meta.og_description" />
        <meta name="twitter:image" :content="seo.ogImage" />

        <!-- JSON-LD Structured Data -->
        <component
            v-for="(schema, i) in seo.jsonLd"
            :key="i"
            :is="'script'"
            type="application/ld+json"
            v-text="JSON.stringify(schema)"
        />

        <!-- Imagem do hero (LCP). A Inter vem no <head> do app.blade.php (HTML inicial);
             ícones: Tabler, via site.js. -->
        <link v-if="heroSrc" rel="preload" as="image" type="image/webp" :href="heroSrc" />
    </Head>

    <SiteLayout :t="t" :routes="routes" :app-name="appName" :has-hero="true">
        <!-- ═══════════════════ HERO ═══════════════════ -->
        <section class="hero">
            <div class="hero-blob hero-blob-1" aria-hidden="true"></div>
            <div class="hero-blob hero-blob-2" aria-hidden="true"></div>
            <div class="container">
                <div :class="['hero-inner', { 'hero-inner--solo': !heroSrc }]">
                    <div class="hero-text">
                        <div class="badge-pill">
                            <i class="ti ti-sparkles" aria-hidden="true"></i>
                            {{ t.hero.badge }}
                        </div>
                        <h1 class="hero-title">
                            {{ t.hero.title }}<br />
                            <em>{{ t.hero.title_em }}</em>
                        </h1>
                        <p class="hero-sub">{{ t.hero.subtitle }}</p>
                        <div class="hero-ctas">
                            <a :href="signupHref" class="btn btn-primary btn-lg">
                                {{ signupLabel }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                            </a>
                            <a href="#demonstracao" class="btn btn-outline-white btn-lg">
                                <i class="ti ti-eye" aria-hidden="true"></i> {{ t.hero.cta_secondary }}
                            </a>
                        </div>
                        <!-- Prazo e "sem cartão" junto do botão: antes só apareciam no fim da página. -->
                        <p v-if="minTrial" class="hero-cta-note" data-test="hero-cta-note">
                            <i class="ti ti-circle-check" aria-hidden="true"></i>
                            {{ t.hero.cta_note?.replace(':days', minTrial) }}
                        </p>
                        <div v-if="t.hero.trust" class="hero-trust">
                            <div v-if="t.hero.trust_initials?.length" class="hero-trust-avatars" aria-hidden="true">
                                <span v-for="initials in t.hero.trust_initials" :key="initials">{{ initials }}</span>
                            </div>
                            <span v-html="t.hero.trust"></span>
                        </div>
                    </div>

                    <!-- Recorte real do prontuário, sem dados de pacientes. -->
                    <div v-if="heroSrc" class="hero-visual">
                        <div class="hero-instrument">
                            <figure class="hero-mockup">
                                <div class="mockup-bar" aria-hidden="true">
                                    <span class="mockup-dot mockup-dot-r"></span>
                                    <span class="mockup-dot mockup-dot-y"></span>
                                    <span class="mockup-dot mockup-dot-g"></span>
                                    <div class="mockup-url"></div>
                                </div>
                                <img
                                    :src="heroSrc"
                                    :alt="t.hero.visual_alt"
                                    class="hero-shot"
                                    :width="heroImageWidth"
                                    :height="heroImageHeight"
                                    fetchpriority="high"
                                    decoding="async"
                                />
                            </figure>
                            <!-- Marcas decorativas: a captura permanece plana e visível. -->
                            <div class="hero-calibration" aria-hidden="true">
                                <svg
                                    v-for="(rotation, corner) in { tl: 0, tr: 90, br: 180, bl: 270 }"
                                    :key="corner"
                                    class="hero-calibration-corner"
                                    :data-corner="corner"
                                    viewBox="0 0 36 36"
                                    width="36"
                                    height="36"
                                    focusable="false"
                                >
                                    <path d="M1 35V27A26 26 0 0 1 27 1H35" :transform="`rotate(${rotation} 18 18)`" />
                                </svg>
                            </div>
                        </div>

                        <div class="hero-visual-notes">
                            <div class="hero-float-card card-top">
                                <div class="icon icon-mint"><i class="ti ti-eye" aria-hidden="true"></i></div>
                                <div>
                                    <div class="hero-float-lbl">{{ t.hero.card_top_lbl }}</div>
                                    <div class="hero-float-val">{{ t.hero.card_top_val }}</div>
                                </div>
                            </div>
                            <div class="hero-float-card card-bottom">
                                <div class="icon icon-orange">
                                    <i class="ti ti-report-medical" aria-hidden="true"></i>
                                </div>
                                <div>
                                    <div class="hero-float-lbl">{{ t.hero.card_bot_lbl }}</div>
                                    <div class="hero-float-val">{{ t.hero.card_bot_val }}</div>
                                </div>
                            </div>
                        </div>
                        <a :href="heroSrc" class="demo-enlarge hero-enlarge" target="_blank" rel="noopener">
                            <i class="ti ti-zoom-in" aria-hidden="true"></i> {{ t.demo.enlarge }}
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ METRICS ═══════════════════ -->
        <div v-if="t.metrics?.length" class="metrics">
            <div class="container">
                <div class="metrics-grid">
                    <div v-for="metric in t.metrics" :key="metric.value" class="metric-item">
                        <div
                            class="metric-value"
                            :data-amount="metric.amount"
                            :data-decimals="metric.decimals"
                            :data-prefix="metric.prefix"
                            :data-suffix="metric.suffix"
                        >
                            {{ metric.value }}
                        </div>
                        <div class="metric-label">{{ metric.label }}</div>
                    </div>
                </div>
                <details v-if="t.metrics_context" id="dados-indicadores" class="metrics-context">
                    <summary>{{ t.metrics_context_label }}</summary>
                    <p>{{ t.metrics_context }}</p>
                </details>
            </div>
        </div>

        <!-- ═══════════════════ PROBLEMAS ═══════════════════ -->
        <section class="problems" id="problemas">
            <div class="container">
                <div class="problems-header text-center">
                    <h2 class="section-title">{{ t.problems.title }}</h2>
                    <p class="section-sub">{{ t.problems.subtitle }}</p>
                </div>
                <div class="problems-grid">
                    <details v-for="item in t.problems.items" :key="item.title" class="problem-card">
                        <summary>
                            <span class="problem-icon"><i :class="'ti ' + item.icon" aria-hidden="true"></i></span>
                            <h3>{{ item.title }}</h3>
                            <i class="ti ti-chevron-down disclosure-chevron" aria-hidden="true"></i>
                        </summary>
                        <p>{{ item.text }}</p>
                    </details>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ DEMONSTRAÇÃO (logo após as dores: prova antes da lista) ═══════════════════ -->
        <section class="demo" id="demonstracao">
            <div class="container">
                <div class="demo-header text-center">
                    <h2 class="section-title">{{ t.demo.title }}</h2>
                    <p class="section-sub">{{ t.demo.subtitle }}</p>
                </div>

                <!-- O visitante escolhe a captura e pode examiná-la no próprio ritmo. -->
                <div class="demo-tabs" role="tablist" :aria-label="t.demo.title" @keydown="onDemoTabKeydown">
                    <button
                        v-for="(tab, i) in demoTabs"
                        :id="`demo-tab-${tab.key}`"
                        :key="tab.key"
                        :ref="
                            (el) => {
                                demoTabEls[i] = el;
                            }
                        "
                        type="button"
                        role="tab"
                        :aria-selected="activeDemoTab === i ? 'true' : 'false'"
                        :aria-controls="`demo-panel-${tab.key}`"
                        :tabindex="activeDemoTab === i ? 0 : -1"
                        :class="['demo-tab', { active: activeDemoTab === i }]"
                        @click="setDemoTab(i)"
                    >
                        <i :class="'ti ' + tab.icon" aria-hidden="true"></i> {{ tab.label }}
                    </button>
                </div>

                <!-- Painéis empilhados na mesma célula: a seção fica com a altura do maior
                     print e a troca de aba não empurra o conteúdo de baixo. O inativo
                     fica inert para sair do foco e do leitor de tela já durante a transição. -->
                <div class="demo-panel-wrap">
                    <div
                        v-for="(tab, i) in demoTabs"
                        :id="`demo-panel-${tab.key}`"
                        :key="tab.key"
                        :class="['demo-panel', { 'is-active': activeDemoTab === i }]"
                        role="tabpanel"
                        :aria-labelledby="`demo-tab-${tab.key}`"
                        :tabindex="activeDemoTab === i ? 0 : -1"
                        :inert="activeDemoTab !== i"
                    >
                        <figure class="demo-mockup">
                            <div class="demo-mockup-bar" aria-hidden="true">
                                <span class="mockup-dot mockup-dot-r"></span>
                                <span class="mockup-dot mockup-dot-y"></span>
                                <span class="mockup-dot mockup-dot-g"></span>
                                <div class="demo-mockup-url"></div>
                            </div>
                            <img
                                :src="demoSrc(tab.key)"
                                :alt="tab.caption"
                                :width="DEMO_SIZES[tab.key]?.[0]"
                                :height="DEMO_SIZES[tab.key]?.[1]"
                                loading="lazy"
                                decoding="async"
                            />
                        </figure>
                        <p class="demo-caption">
                            {{ tab.caption }}
                            <span v-if="tab.fictitious" class="demo-fictitious">{{ t.demo.fictitious }}</span>
                        </p>
                        <!-- No celular o print fica pequeno: abre a imagem inteira para ampliar. -->
                        <a :href="demoSrc(tab.key)" class="demo-enlarge" target="_blank" rel="noopener">
                            <i class="ti ti-zoom-in" aria-hidden="true"></i> {{ t.demo.enlarge }}
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ FUNCIONALIDADES POR PÚBLICO ═══════════════════ -->
        <!-- Antes: Benefícios (8) + Funcionalidades (6) repetindo as mesmas capacidades.
             Agora 3 blocos, um por público; "Disponível no …" vem dos planos do banco. -->
        <section class="audiences" id="funcionalidades">
            <div class="container">
                <div class="audiences-header text-center">
                    <h2 class="section-title">{{ t.audiences.title }}</h2>
                    <p class="section-sub">{{ t.audiences.subtitle }}</p>
                </div>
                <div class="audiences-grid">
                    <article
                        v-for="group in t.audiences.groups"
                        :key="group.key"
                        class="audience-card"
                        :data-test="`audience-${group.key}`"
                    >
                        <div class="audience-icon"><i :class="'ti ' + group.icon" aria-hidden="true"></i></div>
                        <h3>{{ group.title }}</h3>
                        <p class="audience-for">{{ group.audience }}</p>
                        <ul class="audience-list">
                            <template v-for="item in offeredItems(group).slice(0, 2)" :key="item.text">
                                <li v-if="isOffered(item.feature)">
                                    <i class="ti ti-check" aria-hidden="true"></i>
                                    <span>
                                        {{ item.text }}
                                        <span
                                            v-if="availability(item.feature)"
                                            class="audience-plan"
                                            data-test="audience-plan"
                                            >{{ availability(item.feature) }}</span
                                        >
                                    </span>
                                </li>
                            </template>
                        </ul>
                        <details v-if="offeredItems(group).length > 2" class="audience-details">
                            <summary>
                                {{ t.audiences.more?.replace(':audience', group.title.toLocaleLowerCase()) }}
                            </summary>
                            <ul class="audience-list">
                                <li v-for="item in offeredItems(group).slice(2)" :key="item.text">
                                    <i class="ti ti-check" aria-hidden="true"></i>
                                    <span>
                                        {{ item.text }}
                                        <span
                                            v-if="availability(item.feature)"
                                            class="audience-plan"
                                            data-test="audience-plan"
                                            >{{ availability(item.feature) }}</span
                                        >
                                    </span>
                                </li>
                            </ul>
                        </details>
                    </article>
                </div>
                <aside
                    v-for="group in audienceFlows"
                    :key="group.key"
                    :class="['audience-flow', `is-${tissFlow}`]"
                    :aria-labelledby="`audience-flow-${group.key}`"
                    data-test="tiss-flow"
                >
                    <h3 :id="`audience-flow-${group.key}`" class="audience-flow-label">{{ group.flow_label }}</h3>
                    <ol class="audience-flow-steps">
                        <li v-for="(step, stepIndex) in group.flow" :key="step" :style="{ '--step': stepIndex }">
                            <span>{{ step }}</span>
                        </li>
                    </ol>
                </aside>
            </div>
        </section>

        <!-- ═══════════════════ PLANOS ═══════════════════ -->
        <section class="pricing" id="precos">
            <div class="container">
                <div class="pricing-header text-center">
                    <h2 class="section-title">{{ t.pricing.title }}</h2>
                    <p class="section-sub">
                        {{ t.pricing.subtitle }}
                        <template v-if="plans.length && minTrial">
                            {{ t.pricing.trial_suffix?.replace(':days', minTrial) }}
                        </template>
                    </p>
                </div>

                <div v-if="!plans.length" class="pricing-empty">
                    <i class="ti ti-package" aria-hidden="true"></i>
                    <p class="pricing-empty-title">{{ t.pricing.empty_title }}</p>
                    <p class="pricing-empty-text">{{ t.pricing.empty_subtitle }}</p>
                    <a :href="salesHref" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                        <i class="ti ti-message-dots" aria-hidden="true"></i> {{ t.pricing.contact_cta }}
                    </a>
                </div>

                <template v-else>
                    <p class="pricing-included">
                        <i class="ti ti-circle-check" aria-hidden="true"></i>
                        <span
                            ><strong>{{ t.pricing.included_all_label }}:</strong> {{ t.pricing.included_all }}</span
                        >
                    </p>

                    <PricingPlans
                        :plans="plans"
                        :t="t.pricing"
                        :trial-days="trialDays"
                        :register-url="routes.register"
                        :sales-href="salesHref"
                        :locale="seo.currentLocale || 'pt-BR'"
                    />

                    <aside
                        id="creditos-ia"
                        class="pricing-credit-note"
                        aria-labelledby="pricing-credit-title"
                        tabindex="-1"
                    >
                        <div class="pricing-credit-heading">
                            <h3 id="pricing-credit-title">
                                <i class="ti ti-info-circle" aria-hidden="true"></i>
                                {{ t.pricing_credit_note.title }}
                            </h3>
                            <p>{{ t.pricing_credit_note.intro }}</p>
                        </div>
                        <dl class="pricing-credit-rules">
                            <div v-for="rule in ['actions', 'chat', 'usage', 'renewal']" :key="rule">
                                <dt>{{ t.pricing_credit_note[`${rule}_title`] }}</dt>
                                <dd>{{ t.pricing_credit_note[`${rule}_body`] }}</dd>
                            </div>
                        </dl>
                        <div class="pricing-credit-terms">
                            <p>{{ t.pricing_credit_note.topup }}</p>
                            <p>{{ t.pricing_credit_note.trial_note }}</p>
                            <p>{{ t.pricing_credit_note.medical_note }}</p>
                        </div>
                    </aside>
                </template>
            </div>
        </section>

        <!-- ═══════════════════ COMO FUNCIONA ═══════════════════ -->
        <section class="how" id="como-funciona">
            <div class="container">
                <div :class="['how-inner', { 'how-inner--solo': !howImageExists }]">
                    <div>
                        <h2 class="section-title">{{ t.how.title }}</h2>
                        <p class="section-sub how-sub">{{ t.how.subtitle }}</p>

                        <!-- Passos estáticos: antes giravam sozinhos e pareciam clicáveis sem fazer nada. -->
                        <ol class="how-steps">
                            <li v-for="(step, i) in t.how.steps" :key="i" class="how-step">
                                <span class="how-step-num" aria-hidden="true">{{ i + 1 }}</span>
                                <div class="how-step-content">
                                    <h3>{{ step.title }}</h3>
                                    <p>{{ step.text }}</p>
                                </div>
                            </li>
                        </ol>
                    </div>

                    <figure v-if="howImageExists" class="how-visual">
                        <img
                            :src="asset('site/images/how-it-works.webp') + '?v=' + howImageExists"
                            :alt="t.how.screenshot_alt"
                            width="1002"
                            height="444"
                            loading="lazy"
                            decoding="async"
                        />
                    </figure>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ DIFERENCIAIS + CONFORMIDADE NA PRÁTICA ═══════════════════ -->
        <section class="differentiators" id="diferenciais">
            <div class="container">
                <div class="diff-header text-center">
                    <h2 class="section-title">{{ t.differentiators.title }}</h2>
                    <p class="section-sub">{{ t.differentiators.subtitle }}</p>
                </div>

                <div class="diff-grid">
                    <details v-for="item in t.differentiators.items" :key="item.title" class="diff-card">
                        <summary>
                            <span class="diff-icon"><i :class="'ti ' + item.icon" aria-hidden="true"></i></span>
                            <h3>{{ item.title }}</h3>
                            <i class="ti ti-chevron-down disclosure-chevron" aria-hidden="true"></i>
                        </summary>
                        <p>{{ item.text }}</p>
                    </details>
                </div>

                <div class="diff-proof">
                    <h3 class="diff-proof-title">{{ t.differentiators.proof_title }}</h3>
                    <ul class="diff-proof-list">
                        <li v-for="proof in t.differentiators.proof" :key="proof.label" class="comp-badge">
                            <i :class="'ti ' + proof.icon" aria-hidden="true"></i> {{ proof.label }}
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ DEPOIMENTOS ═══════════════════ -->
        <section v-if="t.testimonials?.items?.length" class="testimonials" id="depoimentos">
            <div class="container">
                <div class="testimonials-header text-center">
                    <h2 class="section-title">{{ t.testimonials.title }}</h2>
                    <p v-if="t.testimonials.context" class="section-sub testimonials-context">
                        {{ t.testimonials.context }}
                    </p>
                </div>
                <div class="testimonials-grid">
                    <div v-for="testimonial in t.testimonials.items" :key="testimonial.name" class="testimonial-card">
                        <div
                            class="testimonial-stars"
                            role="img"
                            :aria-label="t.testimonials.rating?.replace(':stars', testimonial.stars)"
                        >
                            <svg
                                v-for="s in 5"
                                :key="s"
                                :class="['testimonial-star', { 'is-empty': s > testimonial.stars }]"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <path
                                    d="M12 2.5l2.94 5.96 6.58.96-4.76 4.64 1.12 6.55L12 17.52l-5.88 3.09 1.12-6.55L2.48 9.42l6.58-.96z"
                                />
                            </svg>
                        </div>
                        <p class="testimonial-text">{{ testimonial.text }}</p>
                        <div class="testimonial-author">
                            <div class="testimonial-avatar" aria-hidden="true">{{ testimonial.initials }}</div>
                            <div>
                                <div class="testimonial-name">{{ testimonial.name }}</div>
                                <div class="testimonial-role">{{ testimonial.role }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ FAQ ═══════════════════ -->
        <section class="faq" id="faq">
            <div class="container">
                <div class="faq-header text-center">
                    <h2 class="section-title">{{ t.faq.title }}</h2>
                </div>
                <div class="faq-list">
                    <div v-for="(faq, i) in t.faq.items" :key="i" class="faq-item">
                        <h3 class="faq-heading">
                            <button
                                :id="`faq-q-${i}`"
                                type="button"
                                class="faq-question"
                                :aria-expanded="faqOpen === i ? 'true' : 'false'"
                                :aria-controls="`faq-a-${i}`"
                                @click="toggleFaq(i)"
                            >
                                <span>{{ faq.q }}</span>
                                <!-- O + gira para × ao abrir (CSS pelo aria-expanded). -->
                                <i class="ti ti-plus" aria-hidden="true"></i>
                            </button>
                        </h3>
                        <!-- Abre com altura suave (grid 0fr → 1fr); fechada, visibility: hidden
                             tira a resposta do Tab e do leitor de tela. -->
                        <div
                            :id="`faq-a-${i}`"
                            :class="['faq-answer', { 'is-open': faqOpen === i }]"
                            role="region"
                            :aria-labelledby="`faq-q-${i}`"
                            data-test="faq-answer"
                        >
                            <div class="faq-answer-clip">
                                <div class="faq-answer-inner">{{ faq.a }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ CONTATO ═══════════════════ -->
        <section class="contact" id="contato">
            <div class="container">
                <div class="contact-main">
                    <div class="contact-header">
                        <h2 class="section-title">{{ t.contact.title }}</h2>
                        <p class="contact-subline">{{ t.contact.subtitle }}</p>
                    </div>

                    <div class="contact-aside-item contact-sales">
                        <div class="contact-aside-icon icon-teal">
                            <i class="ti ti-brand-whatsapp" aria-hidden="true"></i>
                        </div>
                        <div>
                            <h3>{{ t.contact.sales.title }}</h3>
                            <p>{{ t.contact.sales.desc }}</p>
                            <a :href="salesHref" target="_blank" rel="noopener noreferrer">
                                <i class="ti ti-brand-whatsapp" aria-hidden="true"></i> {{ t.contact.sales.cta }}
                            </a>
                            <p class="contact-hours">{{ t.contact.sales.hours }} · {{ t.contact.sales.channel }}</p>
                        </div>
                    </div>

                    <ContactForm :t="t.contact.form" :action="routes.contactStore" />

                    <div class="contact-aside">
                        <div class="contact-aside-item">
                            <div class="contact-aside-icon icon-blue">
                                <i class="ti ti-headset" aria-hidden="true"></i>
                            </div>
                            <div>
                                <h3>{{ t.contact.support.title }}</h3>
                                <p>{{ t.contact.support.desc }}</p>
                                <a :href="'mailto:' + contact.support">
                                    <i class="ti ti-mail" aria-hidden="true"></i> {{ contact.support }}
                                </a>
                            </div>
                        </div>

                        <div class="contact-aside-item">
                            <div class="contact-aside-icon icon-solid">
                                <i class="ti ti-rocket" aria-hidden="true"></i>
                            </div>
                            <div>
                                <h3>{{ minTrial ? t.contact.trial.title : t.contact.trial.title_no_trial }}</h3>
                                <p>
                                    {{
                                        minTrial
                                            ? t.contact.trial.desc?.replace(':days', minTrial)
                                            : t.contact.trial.desc_no_trial
                                    }}
                                </p>
                                <a :href="signupHref">
                                    {{ signupLabel }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>

                        <div
                            v-if="t.contact.aside?.quote_text && t.contact.aside?.quote_author"
                            class="contact-aside-quote"
                        >
                            <p>"{{ t.contact.aside.quote_text }}"</p>
                            <span>{{ t.contact.aside.quote_author }}</span>
                        </div>
                    </div>
                </div>

                <!-- Espaço entre ícone e texto: o Vue apaga espaço com quebra de linha
                     entre tags, então o Prettier não reformata este bloco. -->
                <!-- prettier-ignore -->
                <div class="contact-trust" :class="{ 'contact-trust--compact': !t.contact.trust_nps }">
                    <div class="contact-trust-item">
                        <i class="ti ti-lock" aria-hidden="true"></i> <span>{{ t.contact.trust_ssl }}</span>
                    </div>
                    <div class="contact-trust-item">
                        <i class="ti ti-shield-check" aria-hidden="true"></i> <span>{{ t.contact.trust_lgpd }}</span>
                    </div>
                    <div class="contact-trust-item">
                        <i class="ti ti-rosette-discount-check" aria-hidden="true"></i> <span>{{ t.contact.trust_cfm }}</span>
                    </div>
                    <div v-if="t.contact.trust_nps" class="contact-trust-item">
                        <i class="ti ti-star" aria-hidden="true"></i> <span>{{ t.contact.trust_nps }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ CTA FINAL ═══════════════════ -->
        <section class="cta-final">
            <div class="container">
                <h2>{{ t.cta.title }}</h2>
                <p class="cta-final-sub">
                    {{ minTrial ? t.cta.subtitle_trial?.replace(':days', minTrial) : t.cta.subtitle }}
                </p>
                <div class="cta-final-btns">
                    <a :href="signupHref" class="btn btn-primary btn-lg">
                        {{ minTrial ? t.cta.primary : t.pricing.contact_cta }}
                        <i class="ti ti-arrow-right" aria-hidden="true"></i>
                    </a>
                    <a
                        v-if="minTrial"
                        :href="salesHref"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="btn btn-outline-white btn-lg"
                    >
                        <i class="ti ti-message-dots" aria-hidden="true"></i> {{ t.cta.secondary }}
                    </a>
                </div>
                <p class="cta-note">{{ t.cta.note }}</p>
            </div>
        </section>

        <!-- O CTA fixo recolhe quando outra ação de cadastro ou contato está visível. -->
        <div
            class="mobile-cta"
            :class="{ 'is-visible': showMobileCta }"
            :aria-hidden="showMobileCta ? 'false' : 'true'"
            data-test="mobile-cta"
        >
            <a :href="signupHref" class="btn btn-primary" :tabindex="showMobileCta ? 0 : -1">
                {{ signupLabel }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </SiteLayout>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import ContactForm from '@/Components/Site/ContactForm.vue';
import PricingPlans from '@/Components/Site/PricingPlans.vue';

const props = defineProps({
    t: { type: Object, required: true },
    plans: { type: Array, default: () => [] },
    trialDays: { type: Number, default: 0 },
    routes: { type: Object, required: true },
    // E-mails oficiais (config mail.contact_address / mail.support_address).
    contact: { type: Object, default: () => ({ sales: '', support: '' }) },
    appName: { type: String, default: 'EasyEye' },
    // false quando não existe; filemtime (int) quando existe — usado como ?v= cache-buster
    heroImage: { type: [Boolean, Number], default: false },
    heroImageUrl: { type: String, default: '/site/images/hero-prontuario.webp' },
    heroImageWidth: { type: Number, default: 1061 },
    heroImageHeight: { type: Number, default: 857 },
    howImageExists: { type: [Boolean, Number], default: false },
    demoImages: { type: Object, default: () => ({}) },
    seo: { type: Object, default: () => ({}) },
});

// ─── ASSET HELPER ───
function asset(path) {
    return '/' + path;
}

// Recorte clínico real; sem o arquivo, o hero apresenta apenas o texto.
const heroSrc = computed(() => (props.heroImage ? `${props.heroImageUrl}?v=${props.heroImage}` : null));
const salesHref = 'https://wa.me/5561984676485';

// ─── DEMONSTRAÇÃO VISUAL ───
// Recortes em WebP sem dados de teste; aba sem imagem não aparece.
const DEMO_SIZES = {
    prontuario: [1600, 731],
    imagens: [1600, 894],
    agenda: [1600, 936],
    laudos: [1600, 950],
};
const demoTabs = computed(() => (props.t?.demo?.tabs ?? []).filter((tab) => props.demoImages?.[tab.key]));
function demoSrc(key) {
    return `${asset(`site/images/demo-${key}.webp`)}?v=${props.demoImages?.[key]}`;
}

const activeDemoTab = ref(0);
const demoTabEls = ref([]);
function setDemoTab(i) {
    activeDemoTab.value = i;
}

// Padrão de abas do WAI-ARIA: setas trocam de aba (com foco), Home/End vão às pontas.
const DEMO_TAB_KEYS = { ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last' };
function onDemoTabKeydown(event) {
    const count = demoTabs.value.length;
    const move = DEMO_TAB_KEYS[event.key];
    if (!count || move === undefined) return;

    event.preventDefault();
    const next = move === 'first' ? 0 : move === 'last' ? count - 1 : (activeDemoTab.value + move + count) % count;
    setDemoTab(next);
    nextTick(() => demoTabEls.value[next]?.focus());
}

// ─── FUNCIONALIDADES POR PÚBLICO ───
const audienceFlows = computed(() => (props.t?.audiences?.groups ?? []).filter((group) => group.flow?.length));

// Planos que incluem cada funcionalidade (booleanas); "Disponível no …" só
// quando nem todos incluem. Sem planos cadastrados, nada é afirmado.
const listFormat = computed(() => {
    try {
        return new Intl.ListFormat(props.seo?.currentLocale || 'pt-BR', { style: 'long', type: 'conjunction' });
    } catch {
        return null;
    }
});
function plansWith(featureKey) {
    return props.plans.filter((plan) =>
        (plan.features ?? []).some((f) => f.key === featureKey && f.enabled && !f.is_none),
    );
}
function isOffered(featureKey) {
    return !featureKey || !props.plans.length || plansWith(featureKey).length > 0;
}
function offeredItems(group) {
    return group.items.filter((item) => isOffered(item.feature));
}
function availability(featureKey) {
    if (!featureKey || !props.plans.length) return '';
    const names = plansWith(featureKey).map((plan) => plan.name);
    if (!names.length || names.length === props.plans.length) return '';

    const list = listFormat.value ? listFormat.value.format(names) : names.join(', ');
    return (props.t?.audiences?.available_in ?? '').replace(':plans', list);
}

// ─── FAQ ───
const faqOpen = ref(null);
function toggleFaq(i) {
    faqOpen.value = faqOpen.value === i ? null : i;
}

// ─── PLANOS ───
// A mesma configuração efetiva usada pelo cadastro e início da assinatura.
const minTrial = computed(() => (props.plans.length && props.trialDays > 0 ? props.trialDays : null));
const signupHref = computed(() => (minTrial.value ? props.routes.register : salesHref));
const signupLabel = computed(() => (minTrial.value ? props.t.hero.cta_primary : props.t.pricing.contact_cta));

// ─── CTA FIXO NO CELULAR ───
const showMobileCta = ref(false);
let ctaObserver = null;

function watchMobileCta() {
    if (!('IntersectionObserver' in window)) return;

    // Evita duas ações equivalentes ao mesmo tempo, inclusive nos cartões dos planos.
    const blockers = new Set();
    const targets = document.querySelectorAll(
        '.hero, #contato, .cta-final, footer, .pricing-cta a, .pricing-integrator-cta',
    );
    ctaObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => (entry.isIntersecting ? blockers.add(entry.target) : blockers.delete(entry.target)));
        showMobileCta.value = blockers.size === 0;
    });
    targets.forEach((target) => ctaObserver.observe(target));
}

// ─── FLUXO TISS (momento principal do movimento) ───
// Sem JavaScript ou com "reduzir movimento", as etapas já aparecem concluídas
// ('static'). Com movimento: ficam "a fazer" (tracejadas, sempre legíveis) e,
// ao entrarem na tela, se completam uma a uma, uma única vez.
const tissFlow = ref('static');
let flowObserver = null;
function playTissFlowOnView(reduceMotion) {
    const flow = document.querySelector('.audience-flow');
    if (!flow || reduceMotion || !('IntersectionObserver' in window)) return;

    tissFlow.value = 'armed';
    flowObserver = new IntersectionObserver(
        (entries) => {
            if (!entries.some((entry) => entry.isIntersecting)) return;
            tissFlow.value = 'played';
            flowObserver.disconnect();
        },
        { threshold: 0.6 },
    );
    flowObserver.observe(flow);
}

// ─── ANIMAÇÕES (GSAP) ───
// Import DINÂMICO e client-only: gsap/ScrollTrigger nunca entram no bundle
// SSR nem rodam em Node. E por regra (pós-incidente "site em branco"),
// animação nunca é condição de visibilidade — se este import falhar, a
// página fica 100% visível mesmo assim; só perde o floreio do hero.
let cleanupAnimations = null;
let motionQuery = null;
let animationDisposed = false;
function onMotionPreference(event) {
    if (!event.matches) return;
    flowObserver?.disconnect();
    tissFlow.value = 'static';
}
onMounted(async () => {
    // O fluxo TISS respeita a preferência por menos movimento desde a montagem.
    motionQuery = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    const reduceMotion = motionQuery?.matches ?? false;
    motionQuery?.addEventListener('change', onMotionPreference);
    watchMobileCta();
    playTissFlowOnView(reduceMotion);

    try {
        const { initSiteAnimations } = await import('@/site-animations');
        if (animationDisposed) return;
        cleanupAnimations = initSiteAnimations(props.seo.currentLocale ?? 'pt-BR');
    } catch (e) {
        console.error('Site animations failed to load (page stays fully visible):', e);
    }
});
onUnmounted(() => {
    animationDisposed = true;
    cleanupAnimations?.();
    motionQuery?.removeEventListener('change', onMotionPreference);
    ctaObserver?.disconnect();
    flowObserver?.disconnect();
});
</script>
