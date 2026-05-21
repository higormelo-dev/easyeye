<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Tela exibida quando a assinatura da empresa expirou. Bloqueia acesso ao
 * painel até que o admin renove. O middleware `check.subscription` redireciona
 * todas as rotas protegidas pra cá enquanto o status estiver fora dos limites.
 */
const props = defineProps({
    entity:           { type: Object, default: null },
    lastSubscription: { type: Object, default: null },
    plans:            { type: Array,  default: () => [] },
    urls:             { type: Object, required: true },
});

function brl(value) {
    return 'R$ ' + Number(value ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
}
</script>

<template>
    <AppLayout title="Assinatura expirada" :breadcrumbs="[]">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-10 col-xl-9">

                    <!-- Hero -->
                    <div class="text-center mb-5">
                        <div class="d-inline-flex align-items-center justify-content-center mb-3 bg-danger-subtle rounded-circle" style="width: 96px; height: 96px;">
                            <i class="ti ti-lock fs-1 text-danger"></i>
                        </div>
                        <h2 class="fw-bold mb-2">Assinatura expirada</h2>
                        <p class="text-muted mb-0">
                            <span v-if="entity">A empresa <strong>{{ entity.name }}</strong> está com o acesso bloqueado.</span>
                            <span v-else>O acesso ao sistema está bloqueado.</span>
                            Renove para continuar usando o EasyEye.
                        </p>
                    </div>

                    <!-- Última assinatura -->
                    <div v-if="lastSubscription" class="alert alert-warning d-flex align-items-start mb-4">
                        <i class="ti ti-info-circle fs-4 me-2 mt-1"></i>
                        <div>
                            <strong>Última assinatura:</strong>
                            {{ lastSubscription.plan_name ?? '—' }}
                            <span v-if="lastSubscription.ends_at"> — encerrada em {{ lastSubscription.ends_at }}</span>
                            <span v-if="lastSubscription.status" class="badge bg-secondary ms-2 fs-11">{{ lastSubscription.status }}</span>
                        </div>
                    </div>

                    <!-- Planos disponíveis -->
                    <h5 class="fw-semibold mb-3">Planos disponíveis</h5>
                    <div v-if="plans.length === 0" class="alert alert-info">
                        Nenhum plano público disponível no momento. Entre em contato com o suporte.
                    </div>
                    <div v-else class="row g-3 mb-4">
                        <div v-for="plan in plans" :key="plan.id" class="col-md-4">
                            <div class="card h-100 shadow-sm">
                                <div class="card-body d-flex flex-column">
                                    <h6 class="fw-bold mb-1">{{ plan.name }}</h6>
                                    <p v-if="plan.description" class="text-muted small mb-3">{{ plan.description }}</p>

                                    <div class="mb-3">
                                        <span class="fs-3 fw-bold text-primary">{{ brl(plan.price) }}</span>
                                        <small class="text-muted">/ {{ plan.billing_cycle === 'monthly' ? 'mês' : 'ano' }}</small>
                                    </div>

                                    <ul class="list-unstyled small mb-3 flex-grow-1">
                                        <li v-for="(value, key) in plan.features_map" :key="key" class="mb-1">
                                            <i class="ti ti-check text-success me-1"></i>
                                            <span class="text-muted">{{ key }}:</span>
                                            <strong>{{ value }}</strong>
                                        </li>
                                    </ul>

                                    <button type="button" class="btn btn-primary btn-sm">
                                        <i class="ti ti-shopping-cart me-1"></i>Contratar este plano
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ações -->
                    <div class="text-center text-muted small">
                        <p class="mb-2">Em caso de dúvidas, entre em contato com o suporte.</p>
                        <Link :href="urls.logout" method="post" as="button" class="btn btn-link btn-sm">
                            <i class="ti ti-logout me-1"></i>Sair
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
