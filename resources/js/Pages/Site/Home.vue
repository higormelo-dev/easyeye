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

        <!-- Fonts & Icons -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    </Head>

    <SiteLayout :t="t" :routes="routes" :app-name="appName" :has-hero="true">

        <!-- ═══════════════════ HERO (apresentação do EasyEye) ═══════════════════ -->
        <section class="hero">
            <div class="hero-blob hero-blob-1"></div>
            <div class="hero-blob hero-blob-2"></div>
            <div class="container">
                <div class="hero-inner">
                    <div class="hero-text">
                        <div class="badge-pill">
                            <i class="ti ti-sparkles"></i>
                            {{ t.hero.badge }}
                        </div>
                        <h1 class="hero-title">
                            {{ t.hero.title }}<br>
                            <em>{{ t.hero.title_em }}</em>
                        </h1>
                        <p class="hero-sub">{{ t.hero.subtitle }}</p>
                        <div class="hero-ctas">
                            <a :href="routes.register" class="btn btn-primary btn-lg">
                                {{ t.hero.cta_primary }} <i class="ti ti-arrow-right"></i>
                            </a>
                            <a href="#demonstracao" class="btn btn-outline-white btn-lg">
                                <i class="ti ti-player-play"></i> {{ t.hero.cta_secondary }}
                            </a>
                        </div>
                        <div class="hero-trust">
                            <div class="hero-trust-avatars">
                                <span>JA</span><span>MC</span><span>RS</span><span>PL</span>
                            </div>
                            <span v-html="t.hero.trust?.replace(':count', '500')"></span>
                        </div>
                    </div>

                    <div class="hero-visual">
                        <div class="hero-float-card card-top" style="border-left: 3px solid #06d6a0;">
                            <div class="icon" style="background:rgba(6,214,160,.1)">
                                <i class="ti ti-calendar-check" style="color:#06d6a0"></i>
                            </div>
                            <div>
                                <div style="font-size:11px;color:#64748b;font-weight:500;">{{ t.hero.card_today }}</div>
                                <div>{{ t.hero.card_appointments?.replace(':count', '24') }}</div>
                            </div>
                        </div>

                        <div class="hero-mockup" style="width:100%;">
                            <div class="mockup-bar">
                                <span class="mockup-dot mockup-dot-r"></span>
                                <span class="mockup-dot mockup-dot-y"></span>
                                <span class="mockup-dot mockup-dot-g"></span>
                                <div class="mockup-url"></div>
                            </div>
                            <div class="mockup-body">
                                <div class="mockup-header-row">
                                    <div class="mockup-h-block" style="width:140px;background:rgba(255,255,255,.25);"></div>
                                    <div class="mockup-h-block" style="width:80px;background:rgba(0,180,216,.4);border-radius:6px;padding:6px 0;"></div>
                                </div>
                                <div class="mockup-stats">
                                    <div v-for="w in ['90px','75px','60px','85px']" :key="w" class="mockup-stat">
                                        <div class="mockup-stat-val" :style="'width:' + w"></div>
                                        <div class="mockup-stat-lbl"></div>
                                    </div>
                                </div>
                                <div class="mockup-chart">
                                    <div v-for="h in [40,65,50,80,70,55,90,75,85,60,95,88]" :key="h"
                                         class="mockup-bar-item" :style="'height:' + h + '%'"></div>
                                </div>
                                <div v-for="i in 4" :key="i" class="mockup-table-row">
                                    <div class="mockup-avatar"></div>
                                    <div class="mockup-line" :style="'width:' + (40 + i * 10) + '%'"></div>
                                    <div class="mockup-line-sm"></div>
                                </div>
                            </div>
                        </div>

                        <div class="hero-float-card card-bottom" style="border-left: 3px solid #f97316;">
                            <div class="icon" style="background:rgba(249,115,22,.1)">
                                <i class="ti ti-shield-check" style="color:#f97316"></i>
                            </div>
                            <div>
                                <div style="font-size:11px;color:#64748b;font-weight:500;">{{ t.hero.card_compliance_lbl }}</div>
                                <div>{{ t.hero.card_compliance_val }}</div>
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
                        <div class="metric-value">{{ metric.value }}</div>
                        <div class="metric-label">{{ metric.label }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════ PROBLEMAS QUE RESOLVE ═══════════════════ -->
        <section class="problems" id="problemas">
            <div class="container">
                <div class="problems-header text-center">
                    <span class="section-label">{{ t.problems.label }}</span>
                    <h2 class="section-title">{{ t.problems.title }}</h2>
                    <p class="section-sub text-center">{{ t.problems.subtitle }}</p>
                </div>
                <div class="problems-grid">
                    <div v-for="item in t.problems.items" :key="item.title" class="problem-card">
                        <div class="problem-icon"><i :class="'bi ' + item.icon"></i></div>
                        <h3>{{ item.title }}</h3>
                        <p>{{ item.text }}</p>
                    </div>
                </div>
                <div class="problems-bridge">
                    <i class="ti ti-circle-arrow-down"></i>
                    <span>{{ t.problems.bridge }}</span>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ BENEFÍCIOS ═══════════════════ -->
        <section class="benefits" id="beneficios">
            <div class="container">
                <div class="benefits-header text-center">
                    <span class="section-label">{{ t.benefits.label }}</span>
                    <h2 class="section-title">{{ t.benefits.title }}</h2>
                    <p class="section-sub text-center">{{ t.benefits.subtitle }}</p>
                </div>
                <div class="benefits-grid">
                    <div v-for="item in t.benefits.items" :key="item.title" class="benefit-card">
                        <div :class="['benefit-icon', item.color]">
                            <i :class="'bi ' + item.icon"></i>
                        </div>
                        <h3>{{ item.title }}</h3>
                        <p>{{ item.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ FUNCIONALIDADES ═══════════════════ -->
        <section class="features" id="funcionalidades">
            <div class="container">
                <div class="features-header">
                    <span class="section-label">{{ t.features.label }}</span>
                    <h2 class="section-title">{{ t.features.title }}</h2>
                    <p class="section-sub text-center">{{ t.features.subtitle }}</p>
                </div>
                <div class="features-grid">
                    <div v-for="feature in t.features.items" :key="feature.title" class="feature-card">
                        <div :class="['feature-icon', feature.color]">
                            <i :class="'bi ' + feature.icon"></i>
                        </div>
                        <h3>{{ feature.title }}</h3>
                        <p>{{ feature.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ HOW IT WORKS ═══════════════════ -->
        <section class="how" id="como-funciona">
            <div class="container">
                <div class="how-inner">
                    <div>
                        <span class="section-label">{{ t.how.label }}</span>
                        <h2 class="section-title">{{ t.how.title }}</h2>
                        <p class="section-sub" style="margin-bottom:36px;">{{ t.how.subtitle }}</p>

                        <div class="how-steps">
                            <div
                                v-for="(step, i) in t.how.steps"
                                :key="i"
                                :class="['how-step', { active: howActiveStep === i }]"
                                @click="howActiveStep = i"
                            >
                                <div class="how-step-num">{{ i + 1 }}</div>
                                <div class="how-step-content">
                                    <h4>{{ step.title }}</h4>
                                    <p>{{ step.text }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="how-visual">
                        <img v-if="howImageExists" :src="asset('site/images/how-it-works.png') + '?v=' + howImageExists" :alt="t.how.screenshot_alt">
                        <div v-else class="how-visual-placeholder">
                            <i class="ti ti-device-desktop"></i>
                            <p style="font-size:15px;">{{ t.how.screenshot_placeholder }}</p>
                            <p style="font-size:13px;margin-top:4px;"><code>{{ t.how.screenshot_hint }}</code></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ DEMONSTRAÇÃO VISUAL (tour do produto) ═══════════════════ -->
        <section class="demo" id="demonstracao">
            <div class="container">
                <div class="demo-header text-center">
                    <span class="section-label">{{ t.demo.label }}</span>
                    <h2 class="section-title">{{ t.demo.title }}</h2>
                    <p class="section-sub text-center">{{ t.demo.subtitle }}</p>
                </div>

                <div class="demo-tabs">
                    <button
                        v-for="(tab, i) in t.demo.tabs"
                        :key="tab.key"
                        type="button"
                        :class="['demo-tab', { active: activeDemoTab === i }]"
                        @click="activeDemoTab = i"
                    >
                        <i :class="'bi ' + tab.icon"></i> {{ tab.label }}
                    </button>
                </div>

                <div class="demo-panel-wrap">
                    <template v-for="(tab, i) in t.demo.tabs" :key="tab.key">
                        <div v-show="activeDemoTab === i" class="demo-panel">
                            <div class="demo-mockup">
                                <div class="demo-mockup-bar">
                                    <span class="mockup-dot mockup-dot-r"></span>
                                    <span class="mockup-dot mockup-dot-y"></span>
                                    <span class="mockup-dot mockup-dot-g"></span>
                                    <div class="demo-mockup-url"></div>
                                </div>

                                <img v-if="demoImages?.[tab.key]" :src="asset('site/images/demo-' + tab.key + '.png') + '?v=' + demoImages[tab.key]" :alt="tab.label" style="width:100%;display:block;">

                                <div v-else class="demo-mockup-body">
                                    <!-- Prontuário -->
                                    <template v-if="tab.key === 'prontuario'">
                                        <div class="demo-chips">
                                            <span class="demo-chip active">Anamnese</span>
                                            <span class="demo-chip">Refração</span>
                                            <span class="demo-chip">Biomicroscopia</span>
                                            <span class="demo-chip">Conduta</span>
                                        </div>
                                        <div class="demo-record-grid">
                                            <div>
                                                <div v-for="w in ['85%','65%','92%','48%','70%']" :key="w" class="demo-field-row">
                                                    <div class="demo-field-label"></div>
                                                    <div class="demo-field-line" :style="'width:' + w"></div>
                                                </div>
                                            </div>
                                            <div class="demo-refraction-box">
                                                <div class="demo-refraction-row" style="color:var(--navy);">
                                                    <span></span><span>Esf.</span><span>Cil.</span><span>Eixo</span>
                                                </div>
                                                <div class="demo-refraction-row"><span>OD</span><span class="demo-refraction-cell"></span><span class="demo-refraction-cell"></span><span class="demo-refraction-cell"></span></div>
                                                <div class="demo-refraction-row"><span>OE</span><span class="demo-refraction-cell"></span><span class="demo-refraction-cell"></span><span class="demo-refraction-cell"></span></div>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Agenda -->
                                    <template v-else-if="tab.key === 'agenda'">
                                        <div class="demo-chips">
                                            <span class="demo-chip active"><i class="ti ti-user-circle"></i> Dra. Ana</span>
                                            <span class="demo-chip"><i class="ti ti-user-circle"></i> Dr. Carlos</span>
                                            <span class="demo-chip"><i class="ti ti-user-circle"></i> Dr. Bruno</span>
                                        </div>
                                        <div class="demo-agenda-grid">
                                            <div v-for="d in ['Seg','Ter','Qua','Qui','Sex']" :key="d" class="demo-agenda-col">
                                                <div class="demo-agenda-col-head">{{ d }}</div>
                                                <div
                                                    v-for="slot in agendaSlots(d)"
                                                    :key="slot.top"
                                                    class="demo-agenda-slot"
                                                    :style="`background:${slot.bg};margin-top:${slot.top}px;height:${slot.h}px;`"
                                                ></div>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Gerenciador de Imagens -->
                                    <template v-else-if="tab.key === 'imagens'">
                                        <div class="demo-chips">
                                            <span class="demo-chip active">Todos</span>
                                            <span class="demo-chip">OD</span>
                                            <span class="demo-chip">OE</span>
                                            <span class="demo-chip">Retinografia</span>
                                        </div>
                                        <div class="demo-images-grid">
                                            <div v-for="img in demoImageThumbs" :key="img.tag + img.grad" class="demo-image-thumb" :style="'background:' + img.grad">
                                                <span class="tag">{{ img.tag }}</span>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Laudos & Documentos -->
                                    <template v-else-if="tab.key === 'laudos'">
                                        <div class="demo-docs-list">
                                            <div v-for="doc in demoDocs" :key="doc.title" class="demo-doc-row">
                                                <div class="demo-doc-icon" :style="'background:' + doc.iconBg + ';color:' + doc.iconColor">
                                                    <i :class="'bi ' + doc.icon"></i>
                                                </div>
                                                <div class="demo-doc-lines">
                                                    <div class="demo-doc-line" style="width:60%;"></div>
                                                    <div class="demo-doc-line"></div>
                                                </div>
                                                <span class="demo-doc-status" :style="'background:' + doc.statusBg + ';color:' + doc.statusColor">{{ doc.status }}</span>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <p class="demo-caption">{{ tab.caption }}</p>
                        </div>
                    </template>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ DIFERENCIAIS ═══════════════════ -->
        <section class="differentiators" id="diferenciais">
            <div class="container">
                <div class="diff-header text-center">
                    <span class="section-label">{{ t.differentiators.label }}</span>
                    <h2 class="section-title">{{ t.differentiators.title }}</h2>
                    <p class="section-sub text-center">{{ t.differentiators.subtitle }}</p>
                </div>

                <div class="diff-grid">
                    <div v-for="item in t.differentiators.items" :key="item.title" class="diff-card">
                        <div class="diff-icon"><i :class="'bi ' + item.icon"></i></div>
                        <div>
                            <h3>{{ item.title }}</h3>
                            <p>{{ item.text }}</p>
                        </div>
                    </div>
                </div>

                <!-- Callout: exclusividades do Plano Premium -->
                <div class="pro-callout">
                    <div class="pro-callout-badge">{{ t.differentiators.premium_callout.eyebrow }}</div>
                    <h3>{{ t.differentiators.premium_callout.title }}</h3>
                    <p class="pro-callout-text">{{ t.differentiators.premium_callout.text }}</p>

                    <div class="pro-callout-items">
                        <div v-for="item in t.differentiators.premium_callout.items" :key="item.title" class="pro-callout-item">
                            <div class="pro-callout-item-icon"><i :class="'bi ' + item.icon"></i></div>
                            <div>
                                <div class="pro-callout-item-head">
                                    <h4>{{ item.title }}</h4>
                                    <span class="pro-callout-item-badge">{{ item.badge }}</span>
                                </div>
                                <p>{{ item.text }}</p>
                            </div>
                        </div>
                    </div>

                    <a href="#precos" class="btn btn-featured">
                        {{ t.differentiators.premium_callout.cta }} <i class="ti ti-arrow-right"></i>
                    </a>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ COMPLIANCE ═══════════════════ -->
        <section class="compliance">
            <div class="container">
                <div class="compliance-inner">
                    <div>
                        <span class="section-label">{{ t.compliance.label }}</span>
                        <h2 class="section-title">{{ t.compliance.title }}</h2>
                        <p class="section-sub">{{ t.compliance.subtitle }}</p>
                    </div>
                    <div class="compliance-badges">
                        <div v-for="badge in t.compliance.badges" :key="badge.label" class="comp-badge">
                            <i :class="'bi ' + badge.icon"></i> {{ badge.label }}
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ TESTIMONIALS ═══════════════════ -->
        <section class="testimonials" id="depoimentos">
            <div class="container">
                <div class="testimonials-header">
                    <span class="section-label">{{ t.testimonials.label }}</span>
                    <h2 class="section-title">{{ t.testimonials.title }}</h2>
                </div>
                <div class="testimonials-grid">
                    <div v-for="testimonial in t.testimonials.items" :key="testimonial.name" class="testimonial-card">
                        <div class="testimonial-stars">
                            <i v-for="s in 5" :key="s"
                               :class="'bi ' + (s <= testimonial.stars ? 'bi-star-fill' : 'bi-star')"></i>
                        </div>
                        <p class="testimonial-text">{{ testimonial.text }}</p>
                        <div class="testimonial-author">
                            <div class="testimonial-avatar">{{ testimonial.initials }}</div>
                            <div>
                                <div class="testimonial-name">{{ testimonial.name }}</div>
                                <div class="testimonial-role">{{ testimonial.role }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ PRICING ═══════════════════ -->
        <section class="pricing" id="precos">
            <div class="container">
                <div class="pricing-header">
                    <span class="section-label">{{ t.pricing.label }}</span>
                    <h2 class="section-title">{{ t.pricing.title }}</h2>
                    <p class="section-sub text-center">
                        {{ t.pricing.subtitle }}
                        <template v-if="plans.length && minTrial">
                            {{ t.pricing.trial_suffix?.replace(':days', minTrial) }}
                        </template>
                    </p>
                </div>

                <div v-if="!plans.length" style="text-align:center;padding:60px 20px;">
                    <i class="ti ti-package" style="font-size:56px;color:var(--border);display:block;margin-bottom:16px;"></i>
                    <p style="font-size:18px;font-weight:600;color:var(--navy);margin-bottom:8px;">{{ t.pricing.empty_title }}</p>
                    <p style="color:var(--text-muted);margin-bottom:24px;">{{ t.pricing.empty_subtitle }}</p>
                    <a href="mailto:contato@easyeye.com.br" class="btn btn-primary">
                        <i class="ti ti-message-dots"></i> {{ t.pricing.contact_cta }}
                    </a>
                </div>

                <div v-else class="pricing-grid">
                    <div
                        v-for="plan in plans"
                        :key="plan.id"
                        :class="['pricing-card', { featured: plan.is_featured }]"
                    >
                        <div v-if="plan.is_featured" class="pricing-badge">{{ t.pricing.featured_badge }}</div>
                        <div class="pricing-name">{{ plan.name }}</div>
                        <div v-if="plan.description" class="pricing-desc">{{ plan.description }}</div>

                        <div class="pricing-price">
                            <template v-if="plan.is_free">
                                <span class="price-value" :style="plan.is_featured ? 'font-size:32px;color:#fff' : 'font-size:32px;color:var(--navy)'">
                                    {{ t.pricing.on_request }}
                                </span>
                            </template>
                            <template v-else>
                                <span class="price-currency">R$</span>
                                <span class="price-value">{{ formatPrice(plan.price) }}</span>
                                <span class="price-period">{{ plan.price_period_label }}</span>
                            </template>
                        </div>

                        <div v-if="plan.trial_days" :style="plan.is_featured ? 'font-size:13px;margin-bottom:16px;color:rgba(255,255,255,.65);font-weight:600;' : 'font-size:13px;margin-bottom:16px;color:var(--mint);font-weight:600;'">
                            <i class="ti ti-gift"></i>
                            {{ t.pricing.trial_text?.replace(':days', plan.trial_days) }}
                        </div>

                        <ul v-if="plan.features?.length" class="pricing-features">
                            <li
                                v-for="feature in plan.features"
                                :key="feature.id"
                                :class="{ disabled: !feature.enabled }"
                            >
                                <i :class="'bi ' + (feature.enabled
                                    ? (plan.is_featured ? 'bi-check-circle-fill' : 'bi-check-circle-fill')
                                    : 'bi-x-circle')"
                                   :style="feature.enabled && plan.is_featured ? 'color:var(--mint)' : ''">
                                </i>
                                {{ feature.display_label }}
                            </li>
                        </ul>

                        <!-- Recursos exclusivos do Premium — bloco estático, à parte da lista
                             dinâmica de features acima (não depende de PlanFeature no banco).
                             Optotipos/estoque ainda não existem no produto: badge "Novidade"
                             deixa isso explícito, nunca apresentado como já incluído. -->
                        <div v-if="plan.slug === 'premium'" class="pricing-pro-extra">
                            <div class="pricing-pro-extra-label">{{ t.pricing.premium_exclusive_label }}</div>
                            <div v-for="item in t.differentiators.premium_callout.items" :key="item.title" class="pricing-pro-extra-item">
                                <i :class="'bi ' + item.icon"></i>
                                <span>{{ item.title }}</span>
                                <span class="pricing-pro-extra-badge">{{ t.pricing.premium_exclusive_new }}</span>
                            </div>
                        </div>

                        <a v-if="plan.is_free"
                           href="mailto:contato@easyeye.com.br"
                           :class="['btn', plan.is_featured ? 'btn-outline-white' : 'btn-outline']"
                           style="width:100%;justify-content:center;">
                            {{ t.pricing.contact_cta }}
                        </a>
                        <a v-else-if="plan.is_featured"
                           :href="routes.register"
                           class="btn btn-featured btn-primary"
                           style="width:100%;justify-content:center;">
                            {{ t.pricing.get_started }} <i class="ti ti-arrow-right"></i>
                        </a>
                        <a v-else
                           :href="routes.register"
                           class="btn btn-outline"
                           style="width:100%;justify-content:center;">
                            {{ t.pricing.get_started }}
                        </a>
                    </div>
                </div>

                <div v-if="plans.length" class="pricing-credit-note">
                    <i class="ti ti-info-circle"></i>
                    <p v-html="t.pricing_credit_note_html"></p>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ FAQ ═══════════════════ -->
        <section class="faq" id="faq">
            <div class="container">
                <div class="faq-header">
                    <span class="section-label">{{ t.faq.label }}</span>
                    <h2 class="section-title">{{ t.faq.title }}</h2>
                </div>
                <div class="faq-list">
                    <div v-for="(faq, i) in t.faq.items" :key="i" class="faq-item">
                        <div class="faq-question" @click="toggleFaq(i)">
                            {{ faq.q }}
                            <i :class="'bi ' + (faqOpen === i ? 'bi-dash-lg' : 'bi-plus-lg')"></i>
                        </div>
                        <Transition name="fade">
                            <div v-if="faqOpen === i" class="faq-answer">
                                <div class="faq-answer-inner">{{ faq.a }}</div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ CONTACT ═══════════════════ -->
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

                <div class="contact-cards">
                    <div class="contact-card">
                        <div class="contact-card-icon icon-teal">
                            <i class="ti ti-brand-whatsapp"></i>
                        </div>
                        <h3>{{ t.contact.sales.title }}</h3>
                        <p class="contact-card-desc">{{ t.contact.sales.desc }}</p>
                        <div class="contact-card-meta">
                            <i class="ti ti-clock"></i> {{ t.contact.sales.hours }}
                        </div>
                        <div class="contact-card-meta" style="margin-bottom:20px;">
                            <i class="ti ti-brand-whatsapp"></i> {{ t.contact.sales.channel }}
                        </div>
                        <a href="https://wa.me/5500000000000" target="_blank" rel="noopener noreferrer"
                           class="btn btn-primary" style="width:100%;justify-content:center;">
                            <i class="ti ti-brand-whatsapp"></i> {{ t.contact.sales.cta }}
                        </a>
                    </div>

                    <div class="contact-card">
                        <div class="contact-card-icon icon-blue">
                            <i class="ti ti-headset"></i>
                        </div>
                        <h3>{{ t.contact.support.title }}</h3>
                        <p class="contact-card-desc">{{ t.contact.support.desc }}</p>
                        <div class="contact-card-meta">
                            <i class="ti ti-clock"></i> {{ t.contact.support.hours }}
                        </div>
                        <div class="contact-card-meta" style="margin-bottom:20px;">
                            <i class="ti ti-mail"></i> {{ t.contact.support.channel }}
                        </div>
                        <a :href="'mailto:' + t.contact.support.channel"
                           class="btn btn-outline" style="width:100%;justify-content:center;">
                            <i class="ti ti-mail"></i> {{ t.contact.support.cta }}
                        </a>
                    </div>

                    <div class="contact-card highlight">
                        <div class="contact-card-badge">{{ t.contact.trial.badge }}</div>
                        <div class="contact-card-icon" style="background:rgba(255,255,255,.1);color:var(--teal);">
                            <i class="ti ti-rocket"></i>
                        </div>
                        <h3>{{ t.contact.trial.title }}</h3>
                        <p class="contact-card-desc">{{ t.contact.trial.desc?.replace(':days', trialDays) }}</p>
                        <div class="contact-card-meta" style="margin-bottom:20px;">
                            <i class="ti ti-circle-check"></i> {{ t.contact.trial.note }}
                        </div>
                        <a :href="routes.register"
                           class="btn" style="background:var(--teal);color:#fff;width:100%;justify-content:center;">
                            {{ t.contact.trial.cta }} <i class="ti ti-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <div class="contact-main">
                    <div class="contact-form-box">
                        <div v-if="contactSent" class="cf-success">
                            <div class="cf-success-icon"><i class="ti ti-circle-check"></i></div>
                            <h3>{{ t.contact.form.success_title }}</h3>
                            <p>{{ t.contact.form.success_body }}</p>
                        </div>

                        <div v-else>
                            <h3>{{ t.contact.form.title }}</h3>
                            <p>{{ t.contact.form.subtitle }}</p>

                            <form @submit.prevent="submitContact" novalidate>
                                <div class="cf-row">
                                    <div class="cf-group">
                                        <label for="cf-name">{{ t.contact.form.name }} *</label>
                                        <input id="cf-name" v-model="contactForm.name" type="text" class="cf-control"
                                               :placeholder="t.contact.form.name_ph" required>
                                    </div>
                                    <div class="cf-group">
                                        <label for="cf-email">{{ t.contact.form.email }} *</label>
                                        <input id="cf-email" v-model="contactForm.email" type="email" class="cf-control"
                                               placeholder="seu@email.com.br" required>
                                    </div>
                                </div>

                                <div class="cf-group">
                                    <label for="cf-phone">{{ t.contact.form.phone }} *</label>
                                    <input id="cf-phone" v-model="contactForm.phone" type="tel" class="cf-control"
                                           placeholder="(00) 00000-0000" required>
                                </div>

                                <div class="cf-row">
                                    <div class="cf-group">
                                        <label for="cf-client">{{ t.contact.form.is_client }}</label>
                                        <select id="cf-client" v-model="contactForm.is_client" class="cf-control">
                                            <option value="">{{ t.contact.form.select }}</option>
                                            <option v-for="opt in t.contact.form.is_client_opts" :key="opt" :value="opt">{{ opt }}</option>
                                        </select>
                                    </div>
                                    <div class="cf-group">
                                        <label for="cf-role">{{ t.contact.form.role }}</label>
                                        <select id="cf-role" v-model="contactForm.role" class="cf-control">
                                            <option value="">{{ t.contact.form.select }}</option>
                                            <option v-for="opt in t.contact.form.role_opts" :key="opt" :value="opt">{{ opt }}</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="cf-group">
                                    <label for="cf-segment">{{ t.contact.form.segment }}</label>
                                    <select id="cf-segment" v-model="contactForm.segment" class="cf-control">
                                        <option value="">{{ t.contact.form.select }}</option>
                                        <option v-for="opt in t.contact.form.segment_opts" :key="opt" :value="opt">{{ opt }}</option>
                                    </select>
                                </div>

                                <div class="cf-check">
                                    <input type="checkbox" id="cf-terms" v-model="contactForm.terms" required>
                                    <label for="cf-terms" v-html="t.contact.form.terms"></label>
                                </div>

                                <button type="submit" class="cf-submit" :disabled="!contactForm.terms || contactSending">
                                    <i :class="'bi ' + (contactSending ? 'bi-arrow-repeat cf-spin' : 'bi-send')"></i>
                                    <span>{{ contactSending ? t.contact.form.sending : t.contact.form.submit }}</span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="contact-aside">
                        <div class="contact-aside-item">
                            <div class="contact-aside-icon icon-teal"><i class="ti ti-brand-whatsapp"></i></div>
                            <div>
                                <h4>WhatsApp</h4>
                                <p>
                                    <a href="https://wa.me/5500000000000" target="_blank" rel="noopener noreferrer">(00) 00000-0000</a><br>
                                    {{ t.contact.sales.hours }}
                                </p>
                            </div>
                        </div>
                        <div class="contact-aside-item">
                            <div class="contact-aside-icon icon-blue"><i class="ti ti-mail"></i></div>
                            <div>
                                <h4>E-mail</h4>
                                <p>
                                    <a href="mailto:contato@easyeye.com.br">contato@easyeye.com.br</a><br>
                                    <a href="mailto:suporte@easyeye.com.br">suporte@easyeye.com.br</a>
                                </p>
                            </div>
                        </div>
                        <div class="contact-aside-item">
                            <div class="contact-aside-icon icon-mint"><i class="ti ti-clock"></i></div>
                            <div>
                                <h4>{{ t.contact.aside.hours_title }}</h4>
                                <p>{{ t.contact.aside.hours_body }}</p>
                            </div>
                        </div>
                        <div class="contact-aside-item">
                            <div class="contact-aside-icon icon-purple"><i class="ti ti-message-dots"></i></div>
                            <div>
                                <h4>{{ t.contact.aside.chat_title }}</h4>
                                <p>{{ t.contact.aside.chat_body }}</p>
                            </div>
                        </div>
                        <hr class="contact-aside-divider">
                        <div class="contact-aside-quote">
                            <p>"{{ t.contact.aside.quote_text }}"</p>
                            <span>{{ t.contact.aside.quote_author }}</span>
                        </div>
                    </div>
                </div>

                <div class="contact-trust">
                    <div class="contact-trust-item">
                        <i class="ti ti-lock"></i> <span>{{ t.contact.trust_ssl }}</span>
                    </div>
                    <div class="contact-trust-item">
                        <i class="ti ti-shield-check"></i> <span>{{ t.contact.trust_lgpd }}</span>
                    </div>
                    <div class="contact-trust-item">
                        <i class="ti ti-rosette-discount-check"></i> <span>{{ t.contact.trust_cfm }}</span>
                    </div>
                    <div class="contact-trust-item">
                        <i class="ti ti-star" style="color:#f59e0b;"></i> <span>{{ t.contact.trust_nps }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- ═══════════════════ CTA FINAL ═══════════════════ -->
        <section class="cta-final">
            <div class="container">
                <h2>{{ t.cta.title }}</h2>
                <p>{{ t.cta.subtitle }}</p>
                <div class="cta-final-btns">
                    <a :href="routes.register" class="btn btn-primary btn-lg">
                        {{ t.cta.primary }} <i class="ti ti-arrow-right"></i>
                    </a>
                    <a href="mailto:contato@easyeye.com.br" class="btn btn-outline-white btn-lg">
                        <i class="ti ti-message-dots"></i> {{ t.cta.secondary }}
                    </a>
                </div>
                <p class="cta-note">{{ t.cta.note }}</p>
            </div>
        </section>

    </SiteLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import SiteLayout from '@/Layouts/SiteLayout.vue';
import axios from 'axios';

const props = defineProps({
    t: { type: Object, required: true },
    plans: { type: Array, default: () => [] },
    routes: { type: Object, required: true },
    appName: { type: String, default: 'EasyEye' },
    // false quando não existe; filemtime (int) quando existe — usado como ?v= cache-buster
    howImageExists: { type: [Boolean, Number], default: false },
    demoImages: { type: Object, default: () => ({}) },
    seo: { type: Object, default: () => ({}) },
});

// ─── HOW IT WORKS ───
const howActiveStep = ref(0);

// ─── DEMONSTRAÇÃO VISUAL ───
const activeDemoTab = ref(0);

// Dados fixos do mockup CSS (fallback enquanto não há screenshot real em
// public/site/images/demo-*.png — ver demoImages, montado no controller).
const demoImageThumbs = [
    { tag: 'OD', grad: 'linear-gradient(135deg,#1E5EBF,#00B4D8)' },
    { tag: 'OE', grad: 'linear-gradient(135deg,#6c63ff,#00B4D8)' },
    { tag: 'OD', grad: 'linear-gradient(135deg,#06D6A0,#1E5EBF)' },
    { tag: 'OE', grad: 'linear-gradient(135deg,#f97316,#f59e0b)' },
    { tag: 'OD', grad: 'linear-gradient(135deg,#00B4D8,#6c63ff)' },
    { tag: 'OE', grad: 'linear-gradient(135deg,#1E5EBF,#06D6A0)' },
    { tag: 'OD', grad: 'linear-gradient(135deg,#f59e0b,#ef4444)' },
    { tag: 'OE', grad: 'linear-gradient(135deg,#6c63ff,#1E5EBF)' },
];

const demoDocs = [
    { icon: 'bi-file-earmark-medical', iconBg: 'rgba(30,94,191,.1)', iconColor: '#1E5EBF', status: 'Assinado', statusBg: 'rgba(6,214,160,.12)', statusColor: '#06D6A0' },
    { icon: 'bi-capsule', iconBg: 'rgba(108,99,255,.1)', iconColor: '#6c63ff', status: 'Assinado', statusBg: 'rgba(6,214,160,.12)', statusColor: '#06D6A0' },
    { icon: 'bi-file-earmark-check', iconBg: 'rgba(0,180,216,.1)', iconColor: '#00B4D8', status: 'Rascunho', statusBg: 'rgba(245,158,11,.12)', statusColor: '#f59e0b' },
    { icon: 'bi-file-earmark-arrow-up', iconBg: 'rgba(249,115,22,.1)', iconColor: '#f97316', status: 'Assinado', statusBg: 'rgba(6,214,160,.12)', statusColor: '#06D6A0' },
];

// Blocos de horário fake por dia da semana (mockup da Agenda) — determinístico
// (sem Math.random) para não gerar hidratação divergente entre SSR e client.
const AGENDA_PALETTE = ['#06D6A0', '#1E5EBF', '#00B4D8', '#6c63ff', '#f97316'];
function agendaSlots(day) {
    const seed = day.charCodeAt(0) + day.length;
    const count = 2 + (seed % 3);
    return Array.from({ length: count }, (_, i) => ({
        top: i === 0 ? 4 + ((seed + i) % 20) : 8,
        h: 20 + ((seed * (i + 1)) % 30),
        bg: `rgba(${hexToRgb(AGENDA_PALETTE[(seed + i) % AGENDA_PALETTE.length])},.75)`,
    }));
}
function hexToRgb(hex) {
    const n = parseInt(hex.slice(1), 16);
    return `${(n >> 16) & 255},${(n >> 8) & 255},${n & 255}`;
}

// ─── FAQ ───
const faqOpen = ref(null);
function toggleFaq(i) {
    faqOpen.value = faqOpen.value === i ? null : i;
}

// ─── PRICING ───
const minTrial = computed(() => {
    const withTrial = props.plans.filter(p => p.trial_days);
    return withTrial.length ? Math.min(...withTrial.map(p => p.trial_days)) : null;
});

const trialDays = computed(() => {
    const withTrial = props.plans.filter(p => p.trial_days);
    return withTrial.length ? Math.min(...withTrial.map(p => p.trial_days)) : 14;
});

function formatPrice(price) {
    return Number(price).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ─── CONTACT FORM ───
const contactForm = ref({
    name: '', email: '', phone: '', is_client: '', role: '', segment: '', terms: false,
});
const contactSent = ref(false);
const contactSending = ref(false);

async function submitContact() {
    if (!contactForm.value.terms || contactSending.value) return;
    contactSending.value = true;
    try {
        await axios.post(props.routes.contactStore, contactForm.value);
        contactSent.value = true;
    } catch {
        contactSent.value = true;
    } finally {
        contactSending.value = false;
    }
}

// ─── ASSET HELPER ───
function asset(path) {
    return '/' + path;
}

// ─── ANIMAÇÕES (GSAP) ───
// Import DINÂMICO e client-only: gsap/ScrollTrigger nunca entram no bundle
// SSR nem rodam em Node. E por regra (pós-incidente "site em branco"),
// animação nunca é condição de visibilidade — se este import falhar, a
// página fica 100% visível mesmo assim; só perde o floreio do hero.
let cleanupAnimations = null;
onMounted(async () => {
    try {
        const { initSiteAnimations } = await import('@/site-animations');
        cleanupAnimations = initSiteAnimations();
    } catch (e) {
        console.error('Site animations failed to load (page stays fully visible):', e);
    }
});
onUnmounted(() => {
    cleanupAnimations?.();
});
</script>
