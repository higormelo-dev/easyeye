<template>
    <Head>
        <title>{{ t.meta.title }}</title>
        <meta name="description" :content="t.meta.description">

        <!-- Canonical -->
        <link rel="canonical" :href="seo.canonicalUrl">

        <!-- hreflang (multi-idioma) -->
        <link
            v-for="alt in seo.alternateLocales"
            :key="alt.code"
            rel="alternate"
            :hreflang="alt.default ? 'x-default' : alt.code"
            :href="alt.url"
        >
        <link
            v-for="alt in seo.alternateLocales"
            :key="'h-' + alt.code"
            rel="alternate"
            :hreflang="alt.code"
            :href="alt.url"
        >

        <!-- Open Graph -->
        <meta property="og:title" :content="t.meta.og_title">
        <meta property="og:description" :content="t.meta.og_description">
        <meta property="og:type" content="website">
        <meta property="og:url" :content="seo.canonicalUrl">
        <meta property="og:image" :content="seo.ogImage">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:locale" :content="seo.currentLocale">
        <meta property="og:site_name" :content="appName">

        <!-- Twitter Cards -->
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" :content="t.meta.og_title">
        <meta name="twitter:description" :content="t.meta.og_description">
        <meta name="twitter:image" :content="seo.ogImage">

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
        <link v-if="heroSrc" rel="preload" as="image" type="image/webp" :href="heroSrc">
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
                            {{ t.hero.title }}<br>
                            <em>{{ t.hero.title_em }}</em>
                        </h1>
                        <p class="hero-sub">{{ t.hero.subtitle }}</p>
                        <div class="hero-ctas">
                            <a :href="routes.register" class="btn btn-primary btn-lg">
                                {{ t.hero.cta_primary }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
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
                        <div class="hero-trust">
                            <div class="hero-trust-avatars" aria-hidden="true">
                                <span v-for="initials in t.hero.trust_initials" :key="initials">{{ initials }}</span>
                            </div>
                            <span v-html="t.hero.trust?.replace(':count', '500')"></span>
                        </div>
                    </div>

                    <!-- Painel inicial real do sistema (dados fictícios); os cartões flutuantes
                         dizem o que o produto faz além do painel. -->
                    <div v-if="heroSrc" class="hero-visual">
                        <div class="hero-float-card card-top">
                            <div class="icon icon-mint"><i class="ti ti-photo" aria-hidden="true"></i></div>
                            <div>
                                <div class="hero-float-lbl">{{ t.hero.card_top_lbl }}</div>
                                <div class="hero-float-val">{{ t.hero.card_top_val }}</div>
                            </div>
                        </div>

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
                                width="1400"
                                height="735"
                                fetchpriority="high"
                                decoding="async"
                            >
                        </figure>

                        <div class="hero-float-card card-bottom">
                            <div class="icon icon-orange"><i class="ti ti-shield-check" aria-hidden="true"></i></div>
                            <div>
                                <div class="hero-float-lbl">{{ t.hero.card_bot_lbl }}</div>
                                <div class="hero-float-val">{{ t.hero.card_bot_val }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ METRICS ═══════════════════ -->
        <div class="metrics">
            <div class="container">
                <div class="metrics-grid">
                    <div v-for="metric in t.metrics" :key="metric.value" class="metric-item">
                        <div class="metric-value" :data-amount="metric.amount" :data-decimals="metric.decimals"
                             :data-prefix="metric.prefix" :data-suffix="metric.suffix">{{ metric.value }}</div>
                        <div class="metric-label">{{ metric.label }}</div>
                    </div>
                </div>
                <p v-if="t.metrics_context" class="metrics-context">{{ t.metrics_context }}</p>
            </div>
        </div>

        <!-- ═══════════════════ PROBLEMAS ═══════════════════ -->
        <section class="problems" id="problemas">
            <div class="container">
                <div class="problems-header text-center">
                    <span class="section-label">{{ t.problems.label }}</span>
                    <h2 class="section-title">{{ t.problems.title }}</h2>
                    <p class="section-sub">{{ t.problems.subtitle }}</p>
                </div>
                <div class="problems-grid">
                    <div v-for="item in t.problems.items" :key="item.title" class="problem-card">
                        <div class="problem-icon"><i :class="'ti ' + item.icon" aria-hidden="true"></i></div>
                        <h3>{{ item.title }}</h3>
                        <p>{{ item.text }}</p>
                    </div>
                </div>
                <div class="problems-bridge">
                    <i class="ti ti-circle-arrow-down" aria-hidden="true"></i>
                    <span>{{ t.problems.bridge }}</span>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ DEMONSTRAÇÃO (logo após as dores: prova antes da lista) ═══════════════════ -->
        <section
            class="demo"
            id="demonstracao"
            @mouseenter="demoHovered = true"
            @mouseleave="demoHovered = false"
            @focusin="demoFocused = true"
            @focusout="onDemoFocusOut"
        >
            <div class="container">
                <div class="demo-header text-center">
                    <span class="section-label">{{ t.demo.label }}</span>
                    <h2 class="section-title">{{ t.demo.title }}</h2>
                    <p class="section-sub">{{ t.demo.subtitle }}</p>
                </div>

                <!-- A aba ativa mostra quanto falta para o próximo print (is-rotating); a
                     troca acontece no fim dessa animação, então pausar a animação (mouse,
                     foco, seção fora da tela) pausa a troca junto. -->
                <div
                    :class="['demo-tabs', { 'is-rotating': demoAuto, 'is-paused': demoPaused }]"
                    role="tablist"
                    :aria-label="t.demo.title"
                    @keydown="onDemoTabKeydown"
                >
                    <button
                        v-for="(tab, i) in demoTabs"
                        :id="`demo-tab-${tab.key}`"
                        :key="tab.key"
                        :ref="(el) => { demoTabEls[i] = el; }"
                        type="button"
                        role="tab"
                        :aria-selected="activeDemoTab === i ? 'true' : 'false'"
                        :aria-controls="`demo-panel-${tab.key}`"
                        :tabindex="activeDemoTab === i ? 0 : -1"
                        :class="['demo-tab', { active: activeDemoTab === i }]"
                        @click="setDemoTab(i)"
                        @animationend="onDemoTimerEnd"
                    >
                        <i :class="'ti ' + tab.icon" aria-hidden="true"></i> {{ tab.label }}
                    </button>
                </div>

                <!-- Painéis empilhados na mesma célula: a seção fica com a altura do maior
                     print e a troca de aba não empurra o conteúdo de baixo. O inativo fica
                     com visibility: hidden (fora do Tab e do leitor de tela). -->
                <div class="demo-panel-wrap">
                    <div
                        v-for="(tab, i) in demoTabs"
                        :id="`demo-panel-${tab.key}`"
                        :key="tab.key"
                        :class="['demo-panel', { 'is-active': activeDemoTab === i }]"
                        role="tabpanel"
                        :aria-labelledby="`demo-tab-${tab.key}`"
                        tabindex="0"
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
                            >
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
                    <span class="section-label">{{ t.audiences.label }}</span>
                    <h2 class="section-title">{{ t.audiences.title }}</h2>
                    <p class="section-sub">{{ t.audiences.subtitle }}</p>
                </div>
                <div class="audiences-grid">
                    <article v-for="group in t.audiences.groups" :key="group.key" class="audience-card" :data-test="`audience-${group.key}`">
                        <div class="audience-icon"><i :class="'ti ' + group.icon" aria-hidden="true"></i></div>
                        <h3>{{ group.title }}</h3>
                        <p class="audience-for">{{ group.audience }}</p>
                        <ul class="audience-list">
                            <template v-for="item in group.items" :key="item.text">
                                <li v-if="isOffered(item.feature)">
                                    <i class="ti ti-check" aria-hidden="true"></i>
                                    <span>
                                        {{ item.text }}
                                        <span v-if="availability(item.feature)" class="audience-plan" data-test="audience-plan">{{ availability(item.feature) }}</span>
                                    </span>
                                </li>
                            </template>
                        </ul>
                        <div v-if="group.flow?.length" :class="['audience-flow', `is-${tissFlow}`]" data-test="tiss-flow">
                            <p class="audience-flow-label">{{ group.flow_label }}</p>
                            <ol class="audience-flow-steps">
                                <li v-for="(step, stepIndex) in group.flow" :key="step" :style="{ '--step': stepIndex }"><span>{{ step }}</span></li>
                            </ol>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ COMO FUNCIONA ═══════════════════ -->
        <section class="how" id="como-funciona">
            <div class="container">
                <div :class="['how-inner', { 'how-inner--solo': !howImageExists }]">
                    <div>
                        <span class="section-label">{{ t.how.label }}</span>
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
                        >
                    </figure>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ DIFERENCIAIS + CONFORMIDADE NA PRÁTICA ═══════════════════ -->
        <section class="differentiators" id="diferenciais">
            <div class="container">
                <div class="diff-header text-center">
                    <span class="section-label">{{ t.differentiators.label }}</span>
                    <h2 class="section-title">{{ t.differentiators.title }}</h2>
                    <p class="section-sub">{{ t.differentiators.subtitle }}</p>
                </div>

                <div class="diff-grid">
                    <div v-for="item in t.differentiators.items" :key="item.title" class="diff-card">
                        <div class="diff-icon"><i :class="'ti ' + item.icon" aria-hidden="true"></i></div>
                        <div>
                            <h3>{{ item.title }}</h3>
                            <p>{{ item.text }}</p>
                        </div>
                    </div>
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
        <section class="testimonials" id="depoimentos">
            <div class="container">
                <div class="testimonials-header text-center">
                    <span class="section-label">{{ t.testimonials.label }}</span>
                    <h2 class="section-title">{{ t.testimonials.title }}</h2>
                    <p v-if="t.testimonials.context" class="section-sub testimonials-context">{{ t.testimonials.context }}</p>
                </div>
                <div class="testimonials-grid">
                    <div v-for="testimonial in t.testimonials.items" :key="testimonial.name" class="testimonial-card">
                        <div class="testimonial-stars" role="img" :aria-label="t.testimonials.rating?.replace(':stars', testimonial.stars)">
                            <svg
                                v-for="s in 5"
                                :key="s"
                                :class="['testimonial-star', { 'is-empty': s > testimonial.stars }]"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                                focusable="false"
                            ><path d="M12 2.5l2.94 5.96 6.58.96-4.76 4.64 1.12 6.55L12 17.52l-5.88 3.09 1.12-6.55L2.48 9.42l6.58-.96z" /></svg>
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

        <!-- ═══════════════════ PLANOS ═══════════════════ -->
        <section class="pricing" id="precos">
            <div class="container">
                <div class="pricing-header text-center">
                    <span class="section-label">{{ t.pricing.label }}</span>
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
                    <a :href="'mailto:' + contact.sales" class="btn btn-primary">
                        <i class="ti ti-message-dots" aria-hidden="true"></i> {{ t.pricing.contact_cta }}
                    </a>
                </div>

                <template v-else>
                    <p class="pricing-included">
                        <i class="ti ti-circle-check" aria-hidden="true"></i>
                        <span><strong>{{ t.pricing.included_all_label }}:</strong> {{ t.pricing.included_all }}</span>
                    </p>

                    <div class="pricing-grid">
                        <article
                            v-for="(plan, index) in plans"
                            :key="plan.id"
                            :class="['pricing-card', { featured: plan.is_featured }]"
                        >
                            <div v-if="plan.is_featured" class="pricing-badge">{{ t.pricing.featured_badge }}</div>
                            <h3 class="pricing-name">{{ plan.name }}</h3>
                            <p v-if="plan.description" class="pricing-desc">{{ plan.description }}</p>

                            <div class="pricing-price">
                                <span v-if="plan.is_free" class="price-value price-value--request">{{ t.pricing.on_request }}</span>
                                <template v-else>
                                    <span class="price-currency">R$</span>
                                    <span class="price-value">{{ formatPrice(plan.price) }}</span>
                                    <span class="price-period">{{ plan.price_period_label }}</span>
                                </template>
                            </div>

                            <!-- Planos acima do primeiro listam só o que acrescentam (antes: 13 linhas
                                 por card, 7 delas "não incluído" no Básico). -->
                            <p v-if="planListings[index].inheritsFrom && planListings[index].rows.length" class="pricing-inherits" data-test="pricing-inherits">
                                {{ t.pricing.everything_in?.replace(':plan', planListings[index].inheritsFrom) }}
                            </p>
                            <ul v-if="planListings[index].rows.length" class="pricing-features">
                                <li v-for="feature in planListings[index].rows" :key="feature.id">
                                    <i class="ti ti-circle-check" aria-hidden="true"></i>
                                    {{ feature.display_label }}
                                </li>
                            </ul>

                            <!-- Optotipos ainda não existe: "Em breve", só aqui (sem bloco próprio no meio da página). -->
                            <div v-if="plan.slug === 'premium' && t.pricing.upcoming?.length" class="pricing-upcoming" data-test="pricing-upcoming">
                                <div class="pricing-upcoming-label">{{ t.pricing.upcoming_label }}</div>
                                <div v-for="item in t.pricing.upcoming" :key="item.title" class="pricing-upcoming-item">
                                    <i :class="'ti ' + item.icon" aria-hidden="true"></i>
                                    <span>{{ item.title }}</span>
                                    <span class="pricing-upcoming-badge">{{ item.badge }}</span>
                                </div>
                            </div>

                            <!-- Prazo acima do botão: assim os botões ficam na mesma linha em todos os cards. -->
                            <div class="pricing-cta">
                                <p v-if="plan.trial_days" class="pricing-trial">
                                    <i class="ti ti-gift" aria-hidden="true"></i>
                                    {{ t.pricing.trial_text?.replace(':days', plan.trial_days) }}
                                </p>
                                <a v-if="plan.is_free"
                                   :href="'mailto:' + contact.sales"
                                   :class="['btn', plan.is_featured ? 'btn-outline-white' : 'btn-outline']">
                                    {{ t.pricing.contact_cta }}
                                </a>
                                <a v-else-if="plan.is_featured" :href="routes.register" class="btn btn-featured">
                                    {{ t.pricing.get_started }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                                </a>
                                <a v-else :href="routes.register" class="btn btn-outline">
                                    {{ t.pricing.get_started }}
                                </a>
                            </div>
                        </article>
                    </div>

                    <div class="pricing-credit-note">
                        <i class="ti ti-info-circle" aria-hidden="true"></i>
                        <p v-html="t.pricing_credit_note_html"></p>
                    </div>
                </template>
            </div>
        </section>

        <!-- ═══════════════════ FAQ ═══════════════════ -->
        <section class="faq" id="faq">
            <div class="container">
                <div class="faq-header text-center">
                    <span class="section-label">{{ t.faq.label }}</span>
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
                <div class="contact-header">
                    <span class="section-label">{{ t.contact.label }}</span>
                    <h2 class="contact-headline">
                        {{ t.contact.headline_pre }} <em>EasyEye</em>?<br>
                        {{ t.contact.headline_post }}
                    </h2>
                    <p class="contact-subline">{{ t.contact.subtitle }}</p>
                </div>

                <div class="contact-main">
                    <ContactForm :t="t.contact.form" :action="routes.contactStore" />

                    <div class="contact-aside">
                        <div class="contact-aside-item">
                            <div class="contact-aside-icon icon-teal">
                                <i class="ti ti-brand-whatsapp" aria-hidden="true"></i>
                            </div>
                            <div>
                                <h3>{{ t.contact.sales.title }}</h3>
                                <p>{{ t.contact.sales.desc }}</p>
                                <a href="https://wa.me/5561984676485" target="_blank" rel="noopener noreferrer">
                                    <i class="ti ti-brand-whatsapp" aria-hidden="true"></i> {{ t.contact.sales.channel }}
                                </a>
                            </div>
                        </div>

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
                                <h3>{{ t.contact.trial.title }}</h3>
                                <p>{{ minTrial ? t.contact.trial.desc?.replace(':days', minTrial) : t.contact.trial.desc_no_trial }}</p>
                                <a :href="routes.register">
                                    {{ t.contact.trial.cta }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>

                        <div class="contact-aside-quote">
                            <p>"{{ t.contact.aside.quote_text }}"</p>
                            <span>{{ t.contact.aside.quote_author }}</span>
                        </div>
                    </div>
                </div>

                <div class="contact-trust">
                    <div class="contact-trust-item">
                        <i class="ti ti-lock" aria-hidden="true"></i> <span>{{ t.contact.trust_ssl }}</span>
                    </div>
                    <div class="contact-trust-item">
                        <i class="ti ti-shield-check" aria-hidden="true"></i> <span>{{ t.contact.trust_lgpd }}</span>
                    </div>
                    <div class="contact-trust-item">
                        <i class="ti ti-rosette-discount-check" aria-hidden="true"></i> <span>{{ t.contact.trust_cfm }}</span>
                    </div>
                    <div class="contact-trust-item">
                        <i class="ti ti-star" aria-hidden="true"></i> <span>{{ t.contact.trust_nps }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ CTA FINAL ═══════════════════ -->
        <section class="cta-final">
            <div class="container">
                <h2>{{ t.cta.title }}</h2>
                <p class="cta-final-sub">{{ minTrial ? t.cta.subtitle_trial?.replace(':days', minTrial) : t.cta.subtitle }}</p>
                <div class="cta-final-btns">
                    <a :href="routes.register" class="btn btn-primary btn-lg">
                        {{ t.cta.primary }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                    </a>
                    <a :href="'mailto:' + contact.sales" class="btn btn-outline-white btn-lg">
                        <i class="ti ti-message-dots" aria-hidden="true"></i> {{ t.cta.secondary }}
                    </a>
                </div>
                <p class="cta-note">{{ t.cta.note }}</p>
            </div>
        </section>

        <!-- Celular: "Começar grátis" ao alcance do polegar depois do hero (antes ~15.700px sem
             nenhum botão de cadastro). Some no contato, no CTA final e no rodapé, que já têm os seus. -->
        <div class="mobile-cta" :class="{ 'is-visible': showMobileCta }" :aria-hidden="showMobileCta ? 'false' : 'true'" data-test="mobile-cta">
            <a :href="routes.register" class="btn btn-primary" :tabindex="showMobileCta ? 0 : -1">
                {{ t.hero.cta_primary }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

    </SiteLayout>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import ContactForm from '@/Components/Site/ContactForm.vue';

const props = defineProps({
    t: { type: Object, required: true },
    plans: { type: Array, default: () => [] },
    routes: { type: Object, required: true },
    // E-mails oficiais (config mail.contact_address / mail.support_address).
    contact: { type: Object, default: () => ({ sales: '', support: '' }) },
    appName: { type: String, default: 'EasyEye' },
    // false quando não existe; filemtime (int) quando existe — usado como ?v= cache-buster
    heroImage: { type: [Boolean, Number], default: false },
    howImageExists: { type: [Boolean, Number], default: false },
    demoImages: { type: Object, default: () => ({}) },
    seo: { type: Object, default: () => ({}) },
});

// ─── ASSET HELPER ───
function asset(path) {
    return '/' + path;
}

// Painel inicial do sistema (dados fictícios), em WebP; sem o arquivo, hero só com texto.
const heroSrc = computed(() => (props.heroImage ? `${asset('site/images/hero-dashboard.webp')}?v=${props.heroImage}` : null));

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
// Troca automática: a aba ativa anima o tempo restante (CSS) e, no fim dessa
// animação, passa para a próxima. Pausa com o mouse ou o foco na seção e fora
// da tela: o painel não muda embaixo de quem está lendo ou navegando pelo
// teclado. Escolher uma aba desliga a troca automática.
const demoAuto = ref(false);
const demoHovered = ref(false);
const demoFocused = ref(false);
const demoInView = ref(false);
const demoPaused = computed(() => demoHovered.value || demoFocused.value || !demoInView.value);
let demoObserver = null;
function setDemoTab(i) {
    activeDemoTab.value = i;
    demoAuto.value = false;
}
function onDemoTimerEnd(event) {
    if (!demoAuto.value || (event.animationName && event.animationName !== 'demo-timer')) return;
    activeDemoTab.value = (activeDemoTab.value + 1) % demoTabs.value.length;
}
function watchDemoVisibility() {
    const demo = document.querySelector('#demonstracao');
    if (!demo || !('IntersectionObserver' in window)) {
        demoInView.value = true;
        return;
    }
    demoObserver = new IntersectionObserver(([entry]) => { demoInView.value = entry.isIntersecting; }, { threshold: 0.25 });
    demoObserver.observe(demo);
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

function onDemoFocusOut(event) {
    demoFocused.value = event.currentTarget.contains(event.relatedTarget);
}

// ─── FUNCIONALIDADES POR PÚBLICO ───
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
    return props.plans.filter((plan) => (plan.features ?? []).some((f) => f.key === featureKey && f.enabled && !f.is_none));
}
function isOffered(featureKey) {
    return !featureKey || !props.plans.length || plansWith(featureKey).length > 0;
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
// Teste grátis vem do banco; sem nenhum plano com teste, a página não promete prazo.
const minTrial = computed(() => {
    const withTrial = props.plans.filter((p) => p.trial_days);
    return withTrial.length ? Math.min(...withTrial.map((p) => p.trial_days)) : null;
});

// O primeiro plano lista o que inclui; os seguintes, só o que acrescentam
// (recurso novo ou limite diferente do anterior) — desde que tenham tudo o que
// o anterior tem, senão "Tudo do X, mais:" seria falso e o card lista tudo.
// Ausências ("não incluído", "sem créditos") não viram linha.
const includedFeatures = (plan) => (plan?.features ?? []).filter((f) => f.enabled && !f.is_none);
const planListings = computed(() => props.plans.map((plan, index) => {
    const current = includedFeatures(plan);
    const previous = index > 0 ? includedFeatures(props.plans[index - 1]) : [];
    const currentKeys = new Set(current.map((f) => f.key));

    if (!previous.length || !previous.every((f) => currentKeys.has(f.key))) {
        return { inheritsFrom: null, rows: current };
    }

    const previousLabels = new Map(previous.map((f) => [f.key, f.display_label]));
    return {
        inheritsFrom: props.plans[index - 1].name,
        rows: current.filter((f) => previousLabels.get(f.key) !== f.display_label),
    };
}));

// Separadores no idioma do visitante (antes sempre pt-BR, também no site em inglês).
function formatPrice(price) {
    return Number(price).toLocaleString(props.seo?.currentLocale || 'pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ─── CTA FIXO NO CELULAR ───
const showMobileCta = ref(false);
let ctaObserver = null;

function watchMobileCta() {
    if (!('IntersectionObserver' in window)) return;

    // Visível quando o hero já saiu da tela e nem o contato, o CTA final ou o rodapé estão nela.
    const blockers = new Set();
    const targets = ['.hero', '#contato', '.cta-final', 'footer'].map((selector) => document.querySelector(selector)).filter(Boolean);
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
    flowObserver = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) return;
        tissFlow.value = 'played';
        flowObserver.disconnect();
    }, { threshold: 0.6 });
    flowObserver.observe(flow);
}

// ─── ANIMAÇÕES (GSAP) ───
// Import DINÂMICO e client-only: gsap/ScrollTrigger nunca entram no bundle
// SSR nem rodam em Node. E por regra (pós-incidente "site em branco"),
// animação nunca é condição de visibilidade — se este import falhar, a
// página fica 100% visível mesmo assim; só perde o floreio do hero.
let cleanupAnimations = null;
onMounted(async () => {
    // Troca automática é movimento: quem pede menos movimento no sistema não recebe.
    const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    demoAuto.value = !reduceMotion && demoTabs.value.length > 1;

    watchMobileCta();
    watchDemoVisibility();
    playTissFlowOnView(reduceMotion);

    try {
        const { initSiteAnimations } = await import('@/site-animations');
        cleanupAnimations = initSiteAnimations(props.seo.currentLocale ?? 'pt-BR');
    } catch (e) {
        console.error('Site animations failed to load (page stays fully visible):', e);
    }
});
onUnmounted(() => {
    cleanupAnimations?.();
    ctaObserver?.disconnect();
    demoObserver?.disconnect();
    flowObserver?.disconnect();
});
</script>
