// Cobrança da clínica: Minha assinatura, checkout dentro do sistema, régua de
// cobrança (acesso limitado / bloqueado), créditos de IA, manager e cadastro.
//
// Dados: clínicas de CENÁRIO próprias criadas por e2e/scripts/seed-billing.php
// ("CY-BILL EM DIA", "ATRASO", "LIMITADO", "BLOQUEADO", "RECORRENCIA",
// "TRIAL FIM", "TRIAL VENCIDO" — ver o cabeçalho do seed e o e2e/README.md) —
// isoladas da CLÍNICA TESTE INTEGRADOR, que continua liberada para os outros
// specs. Removidas no fim por clean-billing.php.
//
// Gateway: NUNCA é chamado. As assinaturas de cenário não têm recorrência no
// gateway e o que emitiria cobrança (Pix/boleto, contratar, comprar créditos,
// "Enviar cobrança à clínica", cadastro) é respondido por cy.intercept nas
// rotas JSON do próprio sistema, com o mesmo contrato do backend
// (cypress/support/billing.js). O que não chama o gateway (resumo, opções,
// link do cartão no Asaas, descartar pedido, prévia/troca de plano no manager,
// "Marcar como visto") vai ao backend de verdade.

const {
    PIX_CODE,
    BOLETO_LINE,
    inMinutes,
    pixInstructions,
    boletoInstructions,
    stubGatewayCalls,
    stubClipboard,
    seedBilling,
    cleanBilling,
} = require('../../support/billing');

let S = null; // saída do seed (ids, token da TV)

describe('Cobrança da clínica — checkout, régua e manager', () => {
    before(() => {
        seedBilling().then((out) => {
            S = out;
        });
    });

    after(() => {
        cleanBilling();
    });

    // ── Minha assinatura: quem acessa ───────────────────────────────────────
    describe('Minha assinatura — acesso por perfil (clínica em dia)', () => {
        it('admin vê plano, situação, fatura em aberto e o histórico', () => {
            cy.loginAs('billing.ok.admin');
            cy.visit('/panel/dashboard');
            cy.expectPanelPage();
            // Em dia: sem aviso de cobrança no topo.
            cy.get('[data-test="subscription-banner"]').should('not.exist');

            // Item "Minha assinatura" no menu (só para quem paga a assinatura).
            cy.get('#sidebar-menu a[href$="/panel/my-subscription"]').should('exist');

            cy.visit('/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            cy.get('[data-test="my-subscription-plan"]').should('contain.text', 'Pro');
            cy.get('[data-test="my-subscription-amount"]').should('contain.text', '899,90');
            cy.get('[data-test="my-subscription-card"]').should('contain.text', 'Ativo');
            cy.get('[data-test="open-invoice"]').should('have.length', 1).and('contain.text', '899,90');
            cy.get('[data-test="open-invoice-pay"]').should('be.visible');
            // Pedido de créditos de IA à parte (não é dívida da assinatura).
            cy.get('[data-test="open-ai-packs"] [data-test="open-ai-pack"]').should('have.length', 1);
            cy.get('[data-test="my-subscription-history"]').should('contain.text', 'Paga');
            cy.get('[data-test="my-subscription-change"]').should('be.visible');
            // Plano e ciclo atuais: "Contratar" desabilitado (já é o plano vigente).
            cy.get('[data-test="my-subscription-contract"]').should('be.disabled');
        });

        it('financeiro também acessa (é contato de cobrança)', () => {
            cy.loginAs('billing.ok.financial');
            cy.visit('/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            cy.get('[data-test="my-subscription-plan"]').should('contain.text', 'Pro');
            cy.get('[data-test="open-invoice-pay"]').should('be.visible');
        });

        it('secretária não acessa Minha assinatura', () => {
            cy.loginAs('billing.ok.secretary');
            cy.visit('/panel/dashboard');
            cy.expectPanelPage();
            cy.get('#sidebar-menu a[href$="/panel/my-subscription"]').should('not.exist');
            cy.expectForbidden('/panel/my-subscription');
            cy.request({
                url: '/panel/my-subscription/summary',
                headers: { Accept: 'application/json' },
                failOnStatusCode: false,
            })
                .its('status')
                .should('eq', 403);
        });

        it('médico não acessa Minha assinatura', () => {
            cy.loginAs('billing.ok.doctor');
            cy.visit('/panel/dashboard');
            cy.expectPanelPage();
            cy.get('#sidebar-menu a[href$="/panel/my-subscription"]').should('not.exist');
            cy.expectForbidden('/panel/my-subscription');
        });
    });

    // ── Pagar a fatura em aberto ────────────────────────────────────────────
    describe('Pagar fatura dentro do sistema (Pix, boleto, cartão)', () => {
        beforeEach(() => {
            cy.loginAs('billing.ok.admin');
            stubGatewayCalls();
        });

        it('Pix: QR Code, copia e cola com "Copiar" e validade', () => {
            cy.visit('/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            stubClipboard();
            cy.get('[data-test="open-invoice-pay"]').click();

            cy.get('[role="dialog"] [data-test="checkout-flow"]')
                .should('be.visible')
                .within(() => {
                    cy.get('[data-test="checkout-amount"]').should('contain.text', '899,90');
                    cy.get('[data-test="checkout-methods"] button[data-method]').should('have.length', 3);
                    // Cartão no Asaas = checkout hospedado do gateway.
                    cy.get('button[data-method="credit_card"] [data-test="method-hosted"]').should('be.visible');
                    cy.get('button[data-method="pix"]').click();
                });
            cy.wait('@instructions').its('request.query.method').should('eq', 'pix');

            cy.get('[data-test="checkout-pix"]').should('be.visible');
            // QR gerado no navegador a partir do copia e cola (data: URL).
            cy.get('[data-test="pix-qr"]')
                .should('be.visible')
                .and('have.attr', 'src')
                .and('match', /^data:image\/png;base64,/);
            cy.get('[data-test="checkout-pix"] textarea, [data-test="checkout-pix"] input').should(
                'have.value',
                PIX_CODE,
            );
            cy.get('[data-test="pix-expires"]').should('be.visible');
            cy.get('[data-test="checkout-pix"] [data-test="copy-button"]').click();
            cy.get('@clipboard').should('have.been.calledWith', PIX_CODE);
            cy.get('[data-test="checkout-pix"]').should('contain.text', 'Copiado');
            // Aguardando a confirmação, com "Já paguei — atualizar".
            cy.get('[data-test="checkout-waiting"]').should('be.visible');
            cy.get('[data-test="checkout-check"]').should('be.visible');
            cy.get('@gatewayPost.all').should('have.length', 0);
        });

        it('Boleto: linha digitável, PDF e vencimento; voltar às formas de pagamento', () => {
            cy.visit('/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            stubClipboard();
            cy.get('[data-test="open-invoice-pay"]').click();
            cy.get('[role="dialog"] button[data-method="boleto"]').click();
            cy.wait('@instructions').its('request.query.method').should('eq', 'boleto');

            cy.get('[data-test="checkout-boleto"]').should('be.visible');
            cy.get('[data-test="checkout-boleto"] textarea, [data-test="checkout-boleto"] input').should(
                'have.value',
                BOLETO_LINE,
            );
            cy.get('[data-test="boleto-pdf"]')
                .should('have.attr', 'href', 'https://sandbox.asaas.com/b/pdf/cy-billing')
                .and('have.attr', 'target', '_blank');
            cy.get('[data-test="boleto-due"]').should('be.visible');
            cy.get('[data-test="checkout-boleto"] [data-test="copy-button"]').click();
            cy.get('@clipboard').should('have.been.calledWith', BOLETO_LINE);

            cy.get('[data-test="checkout-back"]').click();
            cy.get('[data-test="checkout-methods"]').should('be.visible');
            cy.get('@gatewayPost.all').should('have.length', 0);
        });

        it('Cartão no Asaas: avisa e abre o checkout hospedado (backend real, sem chamar o gateway)', () => {
            cy.visit('/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            const checkoutUrl = 'https://sandbox.asaas.com/checkoutSession/show?id=chk_cy';
            cy.intercept(
                {
                    method: 'GET',
                    pathname: `/panel/my-subscription/invoices/${S.ok.open_invoice}/instructions`,
                    query: { method: 'credit_card' },
                },
                { data: { issue_required: true } },
            ).as('cardInstructions');
            cy.intercept('POST', `/panel/my-subscription/invoices/${S.ok.open_invoice}/charge`, (req) => {
                expect(req.body).to.deep.include({ method: 'credit_card' });
                req.reply({
                    data: {
                        mode: 'hosted',
                        method: 'credit_card',
                        recurrent: true,
                        checkout_url: checkoutUrl,
                        expires_at: inMinutes(30),
                        invoice: {
                            id: S.ok.open_invoice,
                            status: 'pending',
                            can_pay: true,
                            amount: 899.9,
                            currency: 'BRL',
                        },
                    },
                });
            }).as('hostedCharge');
            cy.intercept('GET', /^https:\/\/sandbox\.asaas\.com\/checkoutSession\/show\?id=chk_cy$/, (req) => {
                req.redirect(`${Cypress.config('baseUrl')}/panel/my-subscription`);
            }).as('asaasHostedRedirect');
            cy.get('[data-test="open-invoice-pay"]').click();
            cy.get('button[data-method="credit_card"] [data-test="method-hosted"]').should('be.visible');
            cy.get('[role="dialog"] button[data-method="credit_card"]').click();
            cy.wait('@cardInstructions');
            cy.wait('@hostedCharge');
            cy.get('[data-test="checkout-hosted"]').should('be.visible');
            cy.get('[data-test="checkout-hosted-go"]').should('be.visible').click();
            cy.wait('@asaasHostedRedirect');
            cy.location('pathname').should('eq', '/panel/my-subscription');
        });

        it('link de entrada ?invoice= abre o pagamento da fatura direto', () => {
            cy.visit(`/panel/my-subscription?invoice=${S.ok.open_invoice}`);
            // O modal do pagamento abre sozinho (cobre o menu): confere só que o painel montou.
            cy.get('#sidebar-menu', { timeout: 15000 }).should('exist');
            cy.get('[role="dialog"] [data-test="checkout-flow"]').should('be.visible');
            cy.get('[role="dialog"]').should('contain.text', S.ok.reference);
        });
    });

    // ── Trocar de plano em Minha assinatura ─────────────────────────────────
    describe('Trocar de plano (clínica em dia)', () => {
        beforeEach(() => {
            cy.loginAs('billing.ok.admin');
            stubGatewayCalls();
        });

        it('upgrade: mostra a diferença proporcional e paga dentro do sistema', () => {
            cy.intercept('GET', '/panel/my-subscription/options*').as('options');
            cy.intercept('POST', '/panel/my-subscription/contract', (req) => {
                expect(req.body.plan_id).to.eq(S.plans.premium);
                expect(req.body.method).to.eq('pix');
                req.reply(pixInstructions({ id: 'upgrade-stub', reference: 'CY-UPGRADE' }));
            }).as('contract');

            cy.visit('/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            cy.get(`input[name="my-sub-plan"][value="${S.plans.premium}"]`).check({ force: true });
            cy.get('[data-test="my-subscription-contract"]').should('not.be.disabled').click();
            cy.wait('@options').its('response.statusCode').should('eq', 200);

            cy.get('[data-test="plan-change-summary"]')
                .should('have.attr', 'data-type', 'upgrade')
                .and('contain.text', 'R$')
                .and('contain.text', '150,00');
            cy.get('[role="dialog"] [data-test="checkout-amount"]').should('contain.text', '150,00');
            cy.get('[role="dialog"] button[data-method="pix"]').click();
            cy.wait('@contract');
            cy.get('[data-test="checkout-pix"]').should('be.visible');
        });

        it('downgrade: agenda para o fim do período pago (nada é cobrado agora)', () => {
            cy.intercept('POST', '/panel/my-subscription/contract', (req) => {
                expect(req.body.plan_id).to.eq(S.plans.basico);
                req.reply({ data: { change: { type: 'scheduled', effective_at: inMinutes(5 * 24 * 60) } } });
            }).as('contract');

            cy.visit('/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            cy.get(`input[name="my-sub-plan"][value="${S.plans.basico}"]`).check({ force: true });
            cy.get('[data-test="my-subscription-contract"]').click();
            cy.get('[data-test="plan-change-summary"]').should('have.attr', 'data-type', 'scheduled');
            // Sem formas de pagamento: só confirmar.
            cy.get('[role="dialog"] [data-test="checkout-methods"]').should('not.exist');
            cy.get('[data-test="plan-change-confirm"]').click();
            cy.wait('@contract');
            cy.get('[data-test="plan-change-scheduled"]').should('contain.text', 'agendad');
        });
    });

    // ── Teste grátis ────────────────────────────────────────────────────────
    describe('Teste grátis — fim e contratação', () => {
        it('trial acabando: aviso com "Contratar agora" que leva a Minha assinatura', () => {
            cy.loginAs('billing.trial_ending.admin');
            cy.visit('/panel/dashboard');
            cy.expectPanelPage();
            cy.get('[data-test="subscription-banner"]').should('have.attr', 'data-kind', 'trial_ending');
            cy.get('[data-test="subscription-banner-checkout"]')
                .should('have.attr', 'href')
                .and('include', '/panel/my-subscription');
            cy.get('[data-test="subscription-banner-checkout"]').click();
            cy.location('pathname').should('eq', '/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            cy.get('[data-test="my-subscription-contract"]').should('not.be.disabled');
        });

        it('IA no teste grátis (sem franquia): médico vê o paywall e a orientação de pedir ao administrador', () => {
            cy.loginAs('billing.trial_ending.doctor');
            cy.visit('/panel/ai/usage');
            cy.expectPanelPage();
            cy.get('[data-test="ai-paywall"]').should('be.visible');
            cy.get('[data-test="ai-paywall-ask-admin"]').should('be.visible');
            cy.get('[data-test="ai-paywall-buy"]').should('not.exist');
            // Médico não compra créditos.
            cy.get('[data-test="ai-credit-pack-checkout"]').should('not.exist');
        });

        it('IA no teste grátis: admin vê o paywall com "Comprar créditos"', () => {
            cy.loginAs('billing.trial_ending.admin');
            cy.visit('/panel/ai/usage');
            cy.expectPanelPage();
            cy.get('[data-test="ai-paywall"]').should('be.visible');
            cy.get('[data-test="ai-paywall-buy"]').should('be.visible');
            cy.get('[data-test="ai-credit-pack-checkout"]').should('be.visible');
        });

        it('trial vencido: bloqueia na hora e "Contratar" abre a contratação já pagando', () => {
            cy.loginAs('billing.trial_over.admin');
            stubGatewayCalls();
            cy.intercept('POST', '/panel/my-subscription/contract', (req) => {
                expect(req.body.method).to.eq('boleto');
                req.reply(boletoInstructions({ id: 'trial-stub', reference: 'CY-TRIAL' }));
            }).as('contract');

            cy.visit('/panel/dashboard');
            cy.location('pathname').should('eq', '/subscription/expired');
            cy.get('[data-test="expired-heading"]').should('contain.text', 'teste');
            cy.get('[data-test="expired-contract"]').should('have.length.at.least', 1);
            cy.get('[data-test="expired-contract"]').first().click();
            cy.location('pathname').should('eq', '/panel/my-subscription');
            cy.get('[role="dialog"] [data-test="checkout-flow"]').should('be.visible');
            cy.get('[role="dialog"] button[data-method="boleto"]').click();
            cy.wait('@contract');
            cy.get('[data-test="checkout-boleto"]').should('be.visible');
        });
    });

    // ── Créditos de IA ──────────────────────────────────────────────────────
    describe('Tela de IA — comprar créditos e pedido pendente', () => {
        beforeEach(() => {
            cy.loginAs('billing.ok.admin');
            stubGatewayCalls();
        });

        it('comprar pacote abre o checkout no próprio sistema', () => {
            cy.intercept('POST', '/panel/my-subscription/ai-credits', (req) => {
                expect(req.body.package_code).to.be.a('string');
                expect(req.body.method).to.eq('pix');
                req.reply(pixInstructions({ id: 'ai-pack-stub', reference: 'IA-STUB' }));
            }).as('aiPackOrder');

            cy.visit('/panel/ai/usage');
            cy.expectPanelPage();
            cy.get('[data-test="ai-credit-pack-checkout"]').should('be.visible');
            cy.get('[data-test="ai-pack-blocked"]').should('not.exist');
            cy.get('[data-test="ai-pack"]').should('have.length.at.least', 1);
            cy.get('[data-test="ai-pack-buy"]').first().click();

            cy.get('[role="dialog"] [data-test="checkout-flow"]')
                .should('be.visible')
                .within(() => {
                    cy.get('[data-test="checkout-amount"]').should('be.visible');
                    cy.get('button[data-method="pix"]').click();
                });
            cy.wait('@aiPackOrder');
            cy.get('[data-test="checkout-pix"]').should('be.visible');
            cy.get('[data-test="pix-qr"]').should('be.visible');
        });

        it('pedido pendente: "Continuar pagamento" abre o checkout da fatura do pedido', () => {
            cy.visit('/panel/ai/usage');
            cy.expectPanelPage();
            cy.get('[data-test="ai-pack-pending"]').should('be.visible');
            cy.get('[data-test="ai-pack-pending-order"]').should('have.length', 1);
            cy.get('[data-test="ai-pack-pending-continue"]').click();
            cy.get('[role="dialog"] button[data-method="pix"]').click();
            cy.wait('@instructions').then(({ request }) => {
                expect(request.url).to.include(S.ok.ai_pack);
                expect(request.query.method).to.eq('pix');
            });
            cy.get('[data-test="checkout-pix"]').should('be.visible');
        });

        it('pedido pendente: "Descartar" pede confirmação e remove o pedido (backend real)', () => {
            cy.intercept('DELETE', `/panel/my-subscription/ai-credits/${S.ok.ai_pack}`).as('discard');
            cy.visit('/panel/ai/usage');
            cy.expectPanelPage();
            cy.get('[data-test="ai-pack-pending-discard"]').click();
            cy.get('[data-test="ai-pack-pending-confirm"]').should('be.visible');
            // "Manter" desiste sem apagar nada.
            cy.get('[data-test="ai-pack-pending-keep"]').click();
            cy.get('[data-test="ai-pack-pending-confirm"]').should('not.exist');

            cy.get('[data-test="ai-pack-pending-discard"]').click();
            cy.get('[data-test="ai-pack-pending-discard-yes"]').click();
            cy.wait('@discard').its('response.statusCode').should('eq', 200);
            cy.get('[data-test="ai-pack-discarded"]').should('be.visible');
            cy.get('[data-test="ai-pack-pending"]').should('not.exist');

            // Minha assinatura também não mostra mais o pedido.
            cy.visit('/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            cy.get('[data-test="open-ai-packs"]').should('not.exist');
        });
    });

    // ── Régua: aviso de atraso (D+1) ────────────────────────────────────────
    describe('Pagamento em atraso (1 dia) — aviso, acesso total', () => {
        it('admin: aviso no topo com "Pagar agora"; IA e financeiro continuam', () => {
            cy.loginAs('billing.overdue.admin');
            cy.visit('/panel/dashboard');
            cy.expectPanelPage();
            cy.get('[data-test="subscription-banner"]')
                .should('have.attr', 'data-kind', 'overdue')
                .and('contain.text', 'Pagamento em atraso');
            // Aviso de pagamento não pode ser fechado.
            cy.get('[data-test="subscription-banner-dismiss"]').should('not.exist');
            cy.get('[data-test="subscription-banner-checkout"]')
                .should('have.attr', 'href')
                .and('include', `/panel/my-subscription?invoice=${S.overdue.open_invoice}`);

            cy.visit('/panel/ai/usage');
            cy.expectPanelPage();
            cy.location('pathname').should('eq', '/panel/ai/usage');
            cy.visit('/panel/financial/cash-flow');
            cy.expectPanelPage();
            cy.location('pathname').should('eq', '/panel/financial/cash-flow');

            // "Pagar agora" do aviso abre o pagamento da fatura vencida.
            cy.visit('/panel/dashboard');
            cy.get('[data-test="subscription-banner-checkout"]').click();
            cy.location('pathname').should('eq', '/panel/my-subscription');
            cy.get('[role="dialog"] [data-test="checkout-flow"]').should('be.visible');
        });
    });

    // ── Régua: acesso limitado (D+3) ────────────────────────────────────────
    describe('Acesso limitado (atraso de 4 dias)', () => {
        it('admin: aviso no topo, IA e financeiro bloqueados; agenda e pacientes seguem', () => {
            cy.loginAs('billing.limited.admin');
            cy.visit('/panel/dashboard');
            cy.expectPanelPage();
            cy.get('[data-test="subscription-banner"]')
                .should('have.attr', 'data-kind', 'limited')
                .and('contain.text', 'Acesso limitado');
            cy.get('[data-test="subscription-banner-checkout"]')
                .should('have.attr', 'href')
                .and('include', `/panel/my-subscription?invoice=${S.limited.open_invoice}`);

            // IA → tela que explica o acesso limitado.
            cy.visit('/panel/ai/usage');
            cy.location('pathname').should('eq', '/subscription/expired');
            cy.get('[data-test="expired-limited"]').should('be.visible');
            cy.get('[data-test="expired-pay-checkout"]').should('be.visible');

            // Financeiro → bloqueado.
            cy.visit('/panel/financial/cash-flow');
            cy.location('pathname').should('eq', '/subscription/expired');

            // Agenda e pacientes continuam.
            cy.visit('/panel/schedules');
            cy.expectPanelPage();
            cy.location('pathname').should('eq', '/panel/schedules');
            cy.visit('/panel/patients');
            cy.expectPanelPage();
            cy.location('pathname').should('eq', '/panel/patients');

            // Minha assinatura segue aberta para pagar.
            cy.visit('/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            cy.get('[data-test="open-invoice"]').should('have.length', 1);
        });

        it('médico: aviso sem valor/link (procurar o administrador) e IA bloqueada', () => {
            cy.loginAs('billing.limited.doctor');
            cy.visit('/panel/dashboard');
            cy.expectPanelPage();
            cy.get('[data-test="subscription-banner"]').should('have.attr', 'data-kind', 'limited');
            cy.get('[data-test="subscription-banner-ask-admin"]').should('be.visible');
            cy.get('[data-test="subscription-banner-checkout"]').should('not.exist');
            cy.get('[data-test="subscription-banner-pay"]').should('not.exist');

            cy.visit('/panel/ai/usage');
            cy.location('pathname').should('eq', '/subscription/expired');
            cy.get('[data-test="expired-limited"]').should('be.visible');
            cy.get('[data-test="expired-ask-admin"]').should('be.visible');
            cy.get('[data-test="expired-pay-checkout"]').should('not.exist');

            cy.visit('/panel/schedules');
            cy.expectPanelPage();
            cy.location('pathname').should('eq', '/panel/schedules');
        });
    });

    describe('Acesso limitado — secretária', () => {
        it('aviso sem valor/link; agenda e pacientes seguem; sem "Minha assinatura"', () => {
            cy.loginAs('billing.limited.secretary');
            cy.visit('/panel/dashboard');
            cy.expectPanelPage();
            cy.get('[data-test="subscription-banner"]').should('have.attr', 'data-kind', 'limited');
            cy.get('[data-test="subscription-banner-ask-admin"]').should('be.visible');
            cy.get('[data-test="subscription-banner-checkout"]').should('not.exist');
            cy.get('#sidebar-menu a[href$="/panel/my-subscription"]').should('not.exist');
            cy.visit('/panel/schedules');
            cy.expectPanelPage();
            cy.location('pathname').should('eq', '/panel/schedules');
            cy.visit('/panel/patients');
            cy.expectPanelPage();
            cy.location('pathname').should('eq', '/panel/patients');
        });
    });

    describe('Acesso limitado — financeiro', () => {
        it('módulo financeiro bloqueado, mas Minha assinatura segue aberta para pagar', () => {
            cy.loginAs('billing.limited.financial');
            stubGatewayCalls();
            cy.visit('/panel/dashboard');
            cy.expectPanelPage();
            // Financeiro é contato de cobrança: vê o "Pagar agora".
            cy.get('[data-test="subscription-banner"]').should('have.attr', 'data-kind', 'limited');
            cy.get('[data-test="subscription-banner-checkout"]').should('be.visible');

            cy.visit('/panel/financial/bi');
            cy.location('pathname').should('eq', '/subscription/expired');
            cy.get('[data-test="expired-limited"]').should('be.visible');
            cy.get('[data-test="expired-pay-checkout"]').click();
            cy.location('pathname').should('eq', '/panel/my-subscription');
            cy.get('[role="dialog"] button[data-method="pix"]').click();
            cy.wait('@instructions');
            cy.get('[data-test="checkout-pix"]').should('be.visible');
        });
    });

    // ── Régua: bloqueio total (D+7) ─────────────────────────────────────────
    describe('Bloqueio total (atraso de 8 dias)', () => {
        it('admin: painel leva a /subscription/expired e "Pagar agora" abre o checkout no sistema', () => {
            cy.loginAs('billing.blocked.admin');
            stubGatewayCalls();
            cy.visit('/panel/dashboard');
            cy.location('pathname').should('eq', '/subscription/expired');
            cy.get('[data-test="expired-heading"]').should('be.visible');
            cy.get('[data-test="expired-message"]').should('contain.text', 'CY-BILL BLOQUEADO');
            cy.get('[data-test="expired-payment"]').should('be.visible');

            cy.visit('/panel/schedules');
            cy.location('pathname').should('eq', '/subscription/expired');

            cy.get('[data-test="expired-pay-checkout"]')
                .should('have.attr', 'href')
                .and('include', `/panel/my-subscription?invoice=${S.blocked.open_invoice}`);
            cy.get('[data-test="expired-pay-checkout"]').click();
            cy.location('pathname').should('eq', '/panel/my-subscription');
            cy.get('[role="dialog"] [data-test="checkout-flow"]').should('be.visible');
            cy.get('[role="dialog"] button[data-method="pix"]').click();
            cy.wait('@instructions');
            cy.get('[data-test="checkout-pix"]').should('be.visible');
        });

        it('secretária: tela de bloqueio orienta a procurar o administrador (sem pagar)', () => {
            cy.loginAs('billing.blocked.secretary');
            cy.visit('/panel/dashboard');
            cy.location('pathname').should('eq', '/subscription/expired');
            cy.get('[data-test="expired-ask-admin"]').should('be.visible');
            cy.get('[data-test="expired-pay-checkout"]').should('not.exist');
            cy.get('[data-test="expired-payment"]').should('not.exist');
            cy.request({ url: '/panel/my-subscription', failOnStatusCode: false }).its('status').should('eq', 403);
        });

        it('TV de chamada mostra "Serviço indisponível"', () => {
            cy.visit(`/call-panel/${S.blocked.call_token}`);
            cy.get('[data-test="call-panel-unavailable"]', { timeout: 15000 })
                .should('be.visible')
                .and('contain.text', 'Serviço indisponível');
            cy.request(`/call-panel/${S.blocked.call_token}/feed`).then(({ body }) => {
                expect(body.unavailable).to.eq(true);
                expect(body.data).to.have.length(0);
            });
        });

        it('portal do paciente: só consulta, com aviso neutro (sem falar de cobrança)', () => {
            cy.loginAs('billing.patient');
            cy.visit('/meus-documentos');
            cy.get('[data-test="portal-read-only"]').should('be.visible').and('contain.text', 'Somente consulta');
            cy.get('[data-test="portal-read-only"]')
                .invoke('text')
                .then((text) => {
                    expect(text.toLowerCase()).not.to.match(/pagamento|assinatura|cobran|d[ií]vida|atraso|fatura/);
                });
            cy.contains('a', 'CY-BILL BLOQUEADO').click();
            cy.location('pathname').should('include', `/meus-documentos/clinicas/${S.blocked.patient}`);
            cy.get('[data-test="portal-read-only"]')
                .should('be.visible')
                .and('contain.text', 'documentos continuam disponíveis');
            // Baixar meus dados (LGPD) continua disponível.
            cy.contains('a', 'Baixar meus dados').should('be.visible');
        });
    });

    // ── Manager ─────────────────────────────────────────────────────────────
    describe('Manager — Assinaturas', () => {
        beforeEach(() => {
            cy.loginAs('saas.admin');
        });

        it('badge "Recorrência desativada" e o aviso no detalhe', () => {
            cy.visit('/panel/manager/subscriptions?search=CY-BILL');
            cy.expectPanelPage();
            cy.contains('tr', 'CY-BILL RECORRENCIA').within(() => {
                cy.get('[data-test="sub-recurrence-alert"]')
                    .should('be.visible')
                    .and('contain.text', 'Recorrência desativada');
                cy.get('button[title="Gerenciar"], [title="Gerenciar"]').first().click();
            });
            cy.get('[data-test="sdd-recurrence-alert"]').should('be.visible');
            // "Marcar como visto" (backend real): o alerta sai do detalhe.
            cy.intercept(
                'POST',
                `/panel/manager/subscriptions/${S.recurrence.subscription}/recurrence-alert/acknowledge`,
            ).as('ack');
            cy.get('[data-test="sdd-recurrence-ack"]').click();
            cy.wait('@ack').its('response.statusCode').should('eq', 200);
            cy.get('[data-test="sdd-recurrence-alert"]').should('not.exist');
            // As outras clínicas de cenário não têm o alerta.
            cy.contains('tr', 'CY-BILL EM DIA').find('[data-test="sub-recurrence-alert"]').should('not.exist');
        });

        it('"Enviar cobrança à clínica" na fatura em aberto (envio simulado)', () => {
            cy.intercept(
                'POST',
                `/panel/manager/subscriptions/${S.ok.subscription}/invoices/${S.ok.open_invoice}/send-charge`,
                {
                    statusCode: 200,
                    body: {
                        message: 'Cobrança enviada a 2 contatos por e-mail.',
                        data: { recipients: 2, whatsapp: 0, channels: ['mail'], invoice_id: S.ok.open_invoice },
                    },
                },
            ).as('sendCharge');

            cy.visit('/panel/manager/subscriptions?search=CY-BILL');
            cy.expectPanelPage();
            cy.contains('tr', 'CY-BILL EM DIA').find('[title="Gerenciar"]').first().click();
            cy.get('[data-test="sdd-open-invoice"]').should('be.visible').and('contain.text', '899,90');
            cy.get('[data-test="sdd-send-charge"]').click();
            cy.wait('@sendCharge');
            cy.get('[data-test="sdd-charge-sent"]').should('contain.text', 'Cobrança enviada');
        });

        it('Nova assinatura em clínica com plano pago: prévia da troca de plano (upgrade)', () => {
            cy.intercept('GET', '/panel/manager/subscriptions/change-preview*').as('preview');
            cy.visit(`/panel/manager/subscriptions?new=1&new_plan=${S.plans.premium}&new_entity=${S.ok.entity}`);
            cy.get('#sidebar-menu', { timeout: 15000 }).should('exist');
            cy.get('[role="dialog"]').should('be.visible');
            cy.get('[role="dialog"] label[data-mode="gateway"]').click();
            cy.wait('@preview').its('response.statusCode').should('eq', 200);
            cy.get('[data-test="plan-change-preview"]').should('be.visible').and('have.attr', 'data-type', 'upgrade');
            // Upgrade: "Enviar a cobrança à clínica agora" já vem marcado.
            cy.get('[data-test="plan-change-notify-input"]').should('be.checked');
        });

        it('confirmar o upgrade (sem enviar à clínica) gera a fatura da diferença em Minha assinatura', () => {
            cy.intercept('GET', '/panel/manager/subscriptions/change-preview*').as('preview');
            cy.intercept('POST', '/panel/manager/subscriptions').as('store');
            // Nada de e-mail/WhatsApp real: o envio à clínica fica desmarcado (e barrado).
            cy.intercept('POST', '/panel/manager/subscriptions/*/invoices/*/send-charge', {
                statusCode: 500,
                body: {},
            }).as('sendCharge');
            cy.visit(`/panel/manager/subscriptions?new=1&new_plan=${S.plans.premium}&new_entity=${S.ok.entity}`);
            cy.get('#sidebar-menu', { timeout: 15000 }).should('exist');
            cy.get('[role="dialog"] label[data-mode="gateway"]').click();
            cy.wait('@preview');
            cy.get('[data-test="plan-change-preview"]').should('have.attr', 'data-type', 'upgrade');
            cy.get('[data-test="plan-change-notify-input"]').uncheck();
            cy.contains('[role="dialog"] button', 'Gerar fatura do upgrade').should('not.be.disabled').click();
            cy.wait('@store').its('response.statusCode').should('be.within', 200, 201);
            cy.get('@sendCharge.all').should('have.length', 0);

            // A clínica vê a fatura do upgrade para pagar dentro do sistema.
            cy.loginAs('billing.ok.admin');
            cy.visit('/panel/my-subscription');
            cy.expectPanelPage('Minha assinatura');
            cy.contains('[data-test="open-invoice"]', 'Upgrade para Premium').should('be.visible');
            // A assinatura atual continua no Pro até o pagamento.
            cy.get('[data-test="my-subscription-plan"]').should('contain.text', 'Pro');
        });
    });

    // ── Cadastro no site: contratar já pagando ──────────────────────────────
    describe('Cadastro — "Contratar agora"', () => {
        const SIGNUP_INVOICES = {
            pix: '7d6bc3d2-338e-4d3b-8cf7-5810d643fa61',
            boleto: 'df686adb-7d8b-4926-8466-9eb8b90ef7e8',
            card: '36cc9b15-c582-4244-96cc-6191e1e6acf2',
            cardFailed: '13512c10-dd62-4162-990a-40d080e29aee',
        };
        const RETURN_PLAN_ID = 'f3a1c962-08b6-45f7-a4d7-4b3aaf67a70e';
        const signupMethods = [
            { method: 'pix', label: 'Pix', mode: 'transparent' },
            { method: 'boleto', label: 'Boleto', mode: 'transparent' },
            { method: 'credit_card', label: 'Cartão de crédito', mode: 'hosted' },
        ];

        function stubSignupStart(method, reply, onRegistration = null) {
            let registration = null;
            cy.intercept('POST', '/register', (req) => {
                expect(req.body.start_mode).to.eq('checkout');
                expect(req.body.billing_cycle).to.be.a('string');
                expect(req.body.terms_accepted).to.eq(true);
                expect(req.body.plan_id).to.match(/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i);
                registration = { planId: req.body.plan_id, cycle: req.body.billing_cycle };
                onRegistration?.(registration);
                req.reply({ redirect: '/panel/dashboard', checkout: { contract: true, options: true, summary: true } });
            }).as('register');
            cy.intercept('GET', '/signup-checkout/options*', (req) => {
                expect(registration, 'cadastro antes das opções').to.not.be.null;
                expect(req.query.plan_id).to.eq(registration.planId);
                expect(req.query.billing_cycle).to.eq(registration.cycle);
                req.reply({
                    data: {
                        plan: { id: registration.planId, name: 'Pro' },
                        cycle: registration.cycle,
                        amount: 899.9,
                        gateway: 'asaas',
                        methods: signupMethods,
                        mode: 'hosted',
                        card: null,
                        realtime: null,
                    },
                });
            }).as('signupOptions');
            cy.intercept('POST', '/signup-checkout/contract', (req) => {
                expect(registration, 'cadastro antes do contrato').to.not.be.null;
                expect(req.body.method).to.eq(method);
                expect(req.body.plan_id).to.eq(registration.planId);
                expect(req.body.billing_cycle).to.eq(registration.cycle);
                req.reply(typeof reply === 'function' ? reply(registration) : reply);
            }).as('signupContract');
        }

        function createSignup(label) {
            const email = `cy-signup-${label}-${Date.now()}@cy-billing.test`;

            cy.visit('/register');
            cy.get('[data-testid="register-name"]').type('Cypress Cadastro');
            cy.get('[data-testid="register-email"]').type(email).blur();
            cy.get('[data-testid="register-password"]').type('Cadastro@2026!', { log: false });
            cy.get('[data-testid="register-password-confirmation"]').type('Cadastro@2026!', { log: false });
            cy.get('[data-testid="register-next"]').click();
            cy.get('[data-testid="register-plan"]', { timeout: 15000 }).should('have.length.at.least', 1);
            cy.get('.reg-card .reg-fade-enter-active', { timeout: 15000 }).should('not.exist');
            cy.get('[data-testid="register-plan"]')
                .first()
                .scrollIntoView({ offset: { top: -100, left: 0 } });
            cy.get('[data-testid="register-plan"]').first().should('be.visible');
            cy.get('[data-testid="register-start-checkout"]', { timeout: 15000 }).scrollIntoView({
                offset: { top: -100, left: 0 },
            });
            cy.get('[data-testid="register-start-checkout"]', { timeout: 15000 })
                .should('be.visible')
                .click()
                .should('have.attr', 'aria-checked', 'true');
            cy.get('[data-testid="register-plan"]')
                .first()
                .scrollIntoView({ offset: { top: -100, left: 0 } });
            cy.get('[data-testid="register-plan"]').first().should('be.visible').click();
            cy.get('[data-testid="register-company-name"]').scrollIntoView({ offset: { top: -100, left: 0 } });
            cy.get('[data-testid="register-company-name"]').should('be.visible').type(`CY-BILL CADASTRO ${label}`);
            cy.get('[data-testid="register-company-phone"]').scrollIntoView({ offset: { top: -100, left: 0 } });
            cy.get('[data-testid="register-company-phone"]').should('be.visible').type('11987654321');
            cy.get('[data-testid="terms-accept-checkbox"]').scrollIntoView({ offset: { top: -100, left: 0 } });
            cy.get('[data-testid="terms-accept-checkbox"]').should('be.visible').check();
            cy.get('[data-testid="register-submit"]').scrollIntoView({ offset: { top: -100, left: 0 } });
            cy.get('[data-testid="register-submit"]').should('be.visible').click();
            cy.wait('@register');
            cy.wait('@signupOptions');
            cy.get('[data-test="signup-checkout"]').should('be.visible');
        }

        // O POST /register é interceptado, portanto não há sessão/entidade para
        // pedir a rota real. Reaproveita o HTML Inertia de /register com a page
        // prop de retorno, mantendo o bundle Vue real e todos os intercepts.
        function stubSignupReturn(query, props) {
            return cy.request('/register').then(({ body }) => {
                const pageScript = body.match(/<script[^>]*data-page="app"[^>]*>(.*?)<\/script>/s);
                expect(pageScript, 'script data-page Inertia de /register').to.not.be.null;
                const page = JSON.parse(pageScript[1]);
                page.component = 'Auth/SignupCheckoutReturn';
                page.url = `/signup-checkout/return?${query}`;
                page.props = { ...page.props, ...props };
                const pageJson = JSON.stringify(page).replaceAll('</', '<\\/');
                const html = body.replace(
                    /(<script[^>]*data-page="app"[^>]*>).*?(<\/script>)/s,
                    (_match, opening, closing) => `${opening}${pageJson}${closing}`,
                );

                cy.intercept('GET', '/signup-checkout/return*', {
                    statusCode: 200,
                    headers: { 'content-type': 'text/html; charset=UTF-8' },
                    body: html,
                }).as('signupReturn');
            });
        }

        it('escolher contratar agora mostra o checkout (cadastro e pagamento simulados)', () => {
            stubSignupStart('pix', pixInstructions({ id: SIGNUP_INVOICES.pix, reference: 'CY-SIGNUP' }));
            createSignup('smoke');
            cy.get('[data-test="signup-checkout"] button[data-method]').should('have.length', 3);
            cy.get('[data-test="signup-pay-later"]').should('be.visible');
            cy.get('[data-test="signup-checkout"] button[data-method="pix"]').click();
            cy.wait('@signupContract');
            cy.get('[data-test="signup-checkout"] [data-test="checkout-pix"]').should('be.visible');
        });

        it('Pix: QR, copia e cola, validade e ativação simulada liberam o próximo passo', () => {
            stubSignupStart('pix', pixInstructions({ id: SIGNUP_INVOICES.pix, reference: 'CY-SIGNUP-PIX' }));
            cy.intercept('GET', '/signup-checkout/summary', {
                data: { invoices: [{ id: SIGNUP_INVOICES.pix, status: 'paid' }] },
            }).as('signupSummary');
            createSignup('pix');
            stubClipboard();

            cy.get('[data-test="signup-checkout"] button[data-method="pix"]').click();
            cy.wait('@signupContract');
            cy.get('[data-test="pix-qr"]')
                .should('have.attr', 'src')
                .and('match', /^data:image\/png;base64,/);
            cy.get('[data-test="checkout-pix"] textarea, [data-test="checkout-pix"] input').should(
                'have.value',
                PIX_CODE,
            );
            cy.get('[data-test="pix-expires"]').should('be.visible');
            cy.get('[data-test="checkout-pix"] [data-test="copy-button"]').click();
            cy.get('@clipboard').should('have.been.calledWith', PIX_CODE);
            cy.get('[data-test="checkout-check"]').click();
            cy.wait('@signupSummary');
            cy.get('[data-test="checkout-paid"]').should('be.visible');
            cy.get('[data-test="signup-enter-panel"]').should('be.visible');
        });

        it('Boleto: linha, PDF, vencimento e ativação simulada liberam o próximo passo', () => {
            stubSignupStart(
                'boleto',
                boletoInstructions({ id: SIGNUP_INVOICES.boleto, reference: 'CY-SIGNUP-BOLETO' }),
            );
            cy.intercept('GET', '/signup-checkout/summary', {
                data: { invoices: [{ id: SIGNUP_INVOICES.boleto, status: 'paid' }] },
            }).as('signupSummary');
            createSignup('boleto');
            stubClipboard();

            cy.get('[data-test="signup-checkout"] button[data-method="boleto"]').click();
            cy.wait('@signupContract');
            cy.get('[data-test="checkout-boleto"] textarea, [data-test="checkout-boleto"] input').should(
                'have.value',
                BOLETO_LINE,
            );
            cy.get('[data-test="boleto-pdf"]')
                .should('have.attr', 'href')
                .and('match', /^https:/);
            cy.get('[data-test="boleto-due"]').should('be.visible');
            cy.get('[data-test="checkout-boleto"] [data-test="copy-button"]').click();
            cy.get('@clipboard').should('have.been.calledWith', BOLETO_LINE);
            cy.get('[data-test="checkout-check"]').click();
            cy.wait('@signupSummary');
            cy.get('[data-test="checkout-paid"]').should('be.visible');
            cy.get('[data-test="signup-enter-panel"]').should('be.visible');
        });

        it('Cartão hospedado volta aguardando e confirma quando a ativação simulada chega', () => {
            const invoice = { id: SIGNUP_INVOICES.card, status: 'pending', can_pay: true, awaiting_confirmation: true };
            let selectedPlanId = null;
            stubSignupStart(
                'credit_card',
                {
                    data: {
                        invoice,
                        mode: 'hosted',
                        checkout_url: 'https://checkout.example.test/hosted/cy-signup-card',
                    },
                },
                ({ planId }) => (selectedPlanId = planId),
            );
            createSignup('card');
            cy.get('[data-test="signup-checkout"] button[data-method="credit_card"]').click();
            cy.wait('@signupContract');
            cy.get('[data-test="checkout-hosted"]').should('be.visible');
            cy.get('[data-test="checkout-hosted-go"]').should('be.visible').and('contain.text', 'pagamento seguro');

            let summaryReads = 0;
            cy.intercept('GET', '/signup-checkout/summary', (req) => {
                summaryReads += 1;
                req.reply({
                    data: {
                        invoices: [{ ...invoice, status: summaryReads > 1 ? 'paid' : 'pending' }],
                        subscription: {
                            plan: { id: selectedPlanId },
                            cycle: 'monthly',
                            status: summaryReads > 1 ? 'active' : 'past_due',
                        },
                        realtime: null,
                    },
                });
            }).as('signupSummary');
            stubSignupReturn(`checkout_return=success&checkout_invoice=${invoice.id}`, {
                status: 'success',
                invoice,
                subscription_active: false,
                next_step: 'verify_email',
                next_url: '/verify-email',
                summary_url: '/signup-checkout/summary',
            });
            cy.intercept('GET', 'https://checkout.example.test/hosted/cy-signup-card', (req) => {
                req.redirect(
                    `${Cypress.config('baseUrl')}/signup-checkout/return?checkout_return=success&checkout_invoice=${invoice.id}`,
                );
            }).as('hostedRedirect');
            cy.get('[data-test="checkout-hosted-go"]').click();
            cy.wait('@hostedRedirect');
            cy.wait('@signupReturn');
            cy.get('[data-testid="checkout-return-status"]').should('contain.text', 'Aguardando');
            cy.get('[data-testid="checkout-return-status"]').should('not.contain.text', 'Pagamento confirmado');
            cy.get('[data-testid="checkout-return-status"]').should('be.visible');
            cy.get('.signup-return-refresh').click();
            cy.wait('@signupSummary');
            cy.get('[data-testid="checkout-return-status"]').should('contain.text', 'Pagamento confirmado');
            cy.get('[data-testid="checkout-return-next"]').should('contain.text', 'Verificar e-mail');
        });

        it('Cartão recusado retorna failed/can_pay e permite uma nova tentativa', () => {
            const failedInvoice = {
                id: SIGNUP_INVOICES.cardFailed,
                status: 'failed',
                can_pay: true,
                awaiting_confirmation: false,
            };
            cy.intercept('GET', '/signup-checkout/summary', {
                data: {
                    invoices: [failedInvoice],
                    subscription: { plan: { id: RETURN_PLAN_ID }, cycle: 'monthly', status: 'past_due' },
                    realtime: null,
                },
            }).as('signupSummary');
            cy.intercept('GET', '/signup-checkout/options*', (req) => {
                expect(req.query.plan_id).to.eq(RETURN_PLAN_ID);
                expect(req.query.billing_cycle).to.eq('monthly');
                req.reply({
                    data: {
                        plan: { id: RETURN_PLAN_ID, name: 'Pro' },
                        cycle: 'monthly',
                        amount: 899.9,
                        gateway: 'asaas',
                        methods: signupMethods,
                        realtime: null,
                    },
                });
            }).as('signupOptions');
            cy.intercept('POST', '/signup-checkout/contract', (req) => {
                expect(req.body.method).to.eq('credit_card');
                expect(req.body.plan_id).to.eq(RETURN_PLAN_ID);
                expect(req.body.billing_cycle).to.eq('monthly');
                req.reply({
                    data: {
                        invoice: failedInvoice,
                        mode: 'hosted',
                        checkout_url: 'https://checkout.example.test/hosted/retry',
                    },
                });
            }).as('signupRetry');
            stubSignupReturn(`checkout_return=cancel&checkout_invoice=${failedInvoice.id}`, {
                status: 'cancel',
                invoice: failedInvoice,
                subscription_active: false,
                next_step: 'verify_email',
                next_url: '/verify-email',
                summary_url: '/signup-checkout/summary',
            });
            cy.visit(`/signup-checkout/return?checkout_return=cancel&checkout_invoice=${failedInvoice.id}`);
            cy.wait('@signupReturn');
            cy.get('[data-testid="checkout-return-status"]').should('contain.text', 'não foi concluído');
            cy.wait('@signupOptions');
            cy.get('[data-test="signup-checkout"] button[data-method="credit_card"]').click();
            cy.wait('@signupRetry');
            cy.get('[data-test="checkout-hosted-go"]').should('be.visible').and('contain.text', 'pagamento seguro');
        });
    });
});
