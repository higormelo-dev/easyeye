// Fase 6 — Retornos, protocolos de acompanhamento, condições crônicas e orientações pós-procedimento.
// Perfis: clinic.secretary (opera a lista), clinic.doctor (decide: ativa protocolo, registra condição),
// clinic.admin (configura convites e cadastra modelos de orientação).
//
// Preparo (idempotente e auto-limpante, mesma convenção dos outros specs):
//   php artisan tinker --execute="require 'e2e/scripts/seed-recall.php';"
// Depois:
//   php artisan tinker --execute="require 'e2e/scripts/clean-recall.php';"
//
// O seed liga a feature has_patient_recall no plano da clínica, libera o protocolo de glaucoma (validado e liberado
// pelos serviços do produto), cria o paciente CY-RCL PACIENTE (WhatsApp + autorização de lembretes) com um retorno
// em atraso e liga o canal de convite (driver mock: nada sai de verdade). NÃO adota o protocolo: o médico ativa na tela.

const PATIENT = 'CY-RCL PACIENTE';

function openPatientDrawer(name) {
    cy.visit('/panel/patients');
    cy.expectPanelPage();
    cy.get('input[type="search"], input[placeholder*="Buscar"]').first().clear().type(name);
    cy.contains('tr', name, { timeout: 15000 }).find('[title="Visualizar"]').first().click({ force: true });
}

describe('Fase 6 — secretária: lista de Retornos', () => {
    beforeEach(() => {
        cy.loginAs('clinic.secretary');
    });

    it('o menu traz Retornos e a lista mostra o paciente em atraso sem expor diagnóstico', () => {
        cy.visit('/panel/dashboard');
        cy.expectPanelPage();
        cy.get('#sidebar-menu a[href*="/panel/recall"]').should('exist');

        cy.visit('/panel/recall');
        cy.get('[data-testid="recall-page"]').should('be.visible');
        cy.contains('h4, h1, h2', 'Retornos').should('be.visible');
        cy.contains('[data-testid="recall-row"]', PATIENT).should('be.visible');
        // Quem não é médico vê "o que falta", nunca a condição.
        cy.get('[data-testid="recall-page"]').should('not.contain.text', 'Glaucoma');
    });

    it('registra um contato e o contador da linha sobe', () => {
        cy.visit('/panel/recall');
        cy.contains('[data-testid="recall-row"]', PATIENT).find('[data-testid="recall-contact"]').click();
        cy.get('#recall-outcome').select('Não atendeu');
        cy.get('#recall-note').type('Tentei às 10h');
        cy.get('[data-testid="recall-dialog-submit"]').click();

        cy.contains('Contato registrado.').should('be.visible');
        cy.contains('[data-testid="recall-row"]', PATIENT).should('contain.text', '1 contato');
    });

    it('o convite por WhatsApp mostra a prévia neutra e entra na fila', () => {
        cy.visit('/panel/recall');
        cy.contains('[data-testid="recall-row"]', PATIENT).find('[data-testid="recall-invite"]').click();

        cy.get('[data-testid="invite-dialog"]').should('be.visible');
        cy.get('[data-testid="invite-blockers"]').should('not.exist');
        cy.get('[data-testid="invite-message"]')
            .should('be.visible')
            .invoke('text')
            .then((text) => {
                // Mensagem neutra (ADR 0002): só primeiro nome e clínica — nunca condição, exame ou motivo.
                expect(text).to.not.match(/glaucoma|campimetria|oct|dmri|catarata/i);
            });

        cy.get('[data-testid="recall-dialog-submit"]').click();
        cy.contains('Convite enviado para a fila do WhatsApp.').should('be.visible');
    });

    it('Configurar retornos abre em leitura: só o médico ativa protocolo', () => {
        cy.visit('/panel/recall/settings');
        cy.get('[data-testid="recall-settings"]').should('be.visible');
        cy.get('[data-testid="doctor-only"]').should('be.visible');
        cy.get('[data-testid="submit-protocol"]').should('not.exist');
        cy.get('[data-testid="open-deactivate"]').should('not.exist');
    });

    it('a secretária não vê Configurações nem as orientações pós-procedimento', () => {
        cy.visit('/panel/dashboard');
        cy.expectPanelPage();
        cy.get('#sidebar-menu a[href*="/panel/setting"]').should('not.exist');
        cy.request({ url: '/panel/setting/care-guidance', failOnStatusCode: false, followRedirect: false })
            .its('status')
            .should('be.oneOf', [302, 401, 403]);
    });
});

describe('Fase 6 — médica: protocolo e condição crônica', () => {
    beforeEach(() => {
        cy.loginAs('clinic.doctor');
    });

    it('ativa o protocolo de glaucoma lendo a regra e confirmando a responsabilidade', () => {
        cy.visit('/panel/recall/settings');
        cy.get('[data-testid="protocol-glaucoma"]').should('be.visible');
        cy.get('[data-testid="protocol-glaucoma"] [data-testid="protocol-status"]').should('contain.text', 'Disponível');
        // A evidência da validação (revisor externo, CRM/RQE conferidos) fica à vista antes de ativar.
        cy.get('[data-testid="protocol-glaucoma"] [data-testid="protocol-evidence"]').should('be.visible');

        cy.get('[data-testid="protocol-glaucoma"] [data-testid="toggle-rule"]').click();
        cy.get('[data-testid="protocol-glaucoma"] [data-testid="submit-protocol"]').should('be.disabled');
        cy.get('[data-testid="protocol-glaucoma"] [data-testid="acknowledge"]').check();
        cy.get('[data-testid="protocol-glaucoma"] [data-testid="submit-protocol"]').should('be.enabled').click();

        cy.contains('Protocolo ativado').should('be.visible');
        cy.get('[data-testid="protocol-glaucoma"] [data-testid="protocol-status"]').should('contain.text', 'Ativo');
    });

    it('registra a condição na ficha do paciente e depois dá alta com motivo', () => {
        openPatientDrawer(PATIENT);
        cy.get('[data-testid="patient-conditions"]').should('be.visible');
        cy.get('[data-testid="pc-add"]').click();
        cy.get('[data-testid="pc-add-form"]').should('be.visible');
        cy.get('#pc-protocol').select('Glaucoma');
        cy.get('#pc-eye').select(1);
        cy.get('[data-testid="pc-add-submit"]').click();
        cy.get('[data-testid="pc-message"]').should('contain.text', 'Condição registrada.');
        cy.get('[data-testid="pc-condition-glaucoma"]').should('be.visible');

        cy.get('[data-testid="pc-discharge"]').first().click();
        // Alta exige motivo.
        cy.get('[data-testid="pc-discharge-submit"]').click();
        cy.get('[data-testid="pc-error"], [data-testid="pc-discharge-form"] [role="alert"]').should('exist');
        cy.get('[data-testid="pc-discharge-form"] textarea').type('Paciente estável, alta do acompanhamento');
        cy.get('[data-testid="pc-discharge-submit"]').click();
        cy.get('[data-testid="pc-message"]').should('contain.text', 'Alta registrada.');
    });

    it('desativa o protocolo exigindo motivo', () => {
        cy.visit('/panel/recall/settings');
        cy.get('[data-testid="protocol-glaucoma"] [data-testid="open-deactivate"]').click();
        cy.get('[data-testid="deactivate-submit"]').click();
        cy.get('[data-testid="deactivate-submit"]').should('exist'); // sem motivo, o diálogo não fecha
        cy.get('textarea:visible').last().type('Clínica vai revisar o intervalo antes de usar');
        cy.get('[data-testid="deactivate-submit"]').click();
        cy.contains('Protocolo desativado.').should('be.visible');
    });
});

describe('Fase 6 — admin: convites e modelos de orientação', () => {
    beforeEach(() => {
        cy.loginAs('clinic.admin');
    });

    it('Configurar retornos traz o bloco de convites e o admin não ativa protocolo', () => {
        cy.visit('/panel/recall/settings');
        cy.get('[data-testid="recall-settings"]').should('be.visible');
        cy.get('[data-testid="invites-card"]').should('be.visible');
        cy.get('[data-testid="doctor-order-switch"]').should('exist');
        cy.get('[data-testid="submit-protocol"]').should('not.exist');
    });

    it('cadastra, edita e exclui um modelo de orientação pós-procedimento', () => {
        cy.visit('/panel/setting/care-guidance');
        cy.expectPanelPage();
        cy.get('[data-testid="guidance-new"]').click();
        cy.get('[data-testid="guidance-title"]').type('CY-RCL Pós-op de catarata');
        cy.get('[data-testid="guidance-location"]').type('Sala de curativos, 2º andar');
        cy.get('[data-testid="guidance-body"]').type('Não coçar o olho. Usar o protetor ao dormir.');
        cy.get('[data-testid="guidance-add-drop"]').click();
        cy.get('[data-testid="guidance-drops"] input[type="text"]').first().type('Tobradex');
        cy.get('[data-testid="guidance-save"]').click();

        cy.contains('Modelo salvo.').should('be.visible');

        // Criado o modelo, o diálogo continua aberto para anexar PDFs; salvar de novo (edição) fecha.
        cy.get('[data-testid="guidance-body"]').type(' Evitar esforço por 7 dias.');
        cy.get('[data-testid="guidance-save"]').click();
        cy.get('[data-testid="guidance-editor"]').should('not.exist');
        cy.contains('article', 'CY-RCL Pós-op de catarata').should('be.visible');

        cy.contains('article', 'CY-RCL Pós-op de catarata').find('[data-testid="guidance-delete"]').click();
        cy.get('[data-testid="guidance-delete-confirm"]').click();
        cy.contains('Modelo excluído.').should('be.visible');
        cy.contains('article', 'CY-RCL Pós-op de catarata').should('not.exist');
    });

    it('o cadastro do paciente só habilita a autorização de lembretes com o WhatsApp marcado', () => {
        cy.visit('/panel/patients');
        cy.expectPanelPage();
        cy.contains('button, a', 'Novo paciente').first().click();
        cy.get('#chkWhatsapp').should('exist').uncheck({ force: true });
        cy.get('#chkCareReminders').should('be.disabled');
        cy.get('#chkWhatsapp').check({ force: true });
        cy.get('#chkCareReminders').should('be.enabled');
    });
});
