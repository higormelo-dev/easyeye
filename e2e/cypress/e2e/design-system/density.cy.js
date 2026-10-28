// Densidade dos controles na tela renderizada (design-system/MASTER.md §1 e §6; plano de adoção, D4).
//
// Para cada página já migrada para uma região `data-ee-density`, em 1440 px (mouse: 40 px) e em 375 px
// (toque por largura: 44 px): todo controle visível mede a altura da região (±1 px), `textarea` ≥ 96 px, um
// menu do ActionDropdown aberto tem itens ≥ a altura da região, cada modal/offcanvas aberto é medido (aba por
// aba) e a página não tem rolagem lateral. A lista de páginas cresce a cada lote do plano de adoção.
//
// Só lê: abre modais, menus e drawers mas nunca salva, confirma, sobe arquivo nem busca cotação (Frankfurter). Roda
// contra o banco de dev seedado (e2e/README.md); a precificação de IA tem `throttle:manager-read` (60/min): uma
// visita por rodada. O editor de convênios da importação só aparece com um import que tem nomes sem de-para; o
// spec não cria nenhum: responde a página e o relatório desse import com dados SINTÉTICOS (cy.intercept) e
// mede o componente real. Nada vai ao servidor (nenhum PUT/POST do editor é disparado).

/* global require */

const {
    assertDensity,
    assertDropdownDensity,
    assertNoHorizontalScroll,
    expectNoDensityIssues,
    measuredSummary,
    resetDensityIssues,
} = require('../../support/density');

const VIEWPORTS = [
    { name: '1440 px (mouse)', width: 1440, height: 900, control: 40 },
    { name: '375 px (toque)', width: 375, height: 812, control: 44 },
];

const PAGE_CONTENT = '.page-wrapper .content';

// A página montou de verdade (sem página de erro do sistema) e tem região de densidade.
function expectRegion(selector) {
    cy.get('body').then(($body) => {
        const $err = $body.find('.ee-error');
        if ($err.length) {
            throw new Error(`Página de erro do sistema: ${$err.find('.ee-error__status').text().trim()}`);
        }
    });
    cy.get(selector, { timeout: 15000 }).should('exist');
}

// Abre o 1º menu de ações de uma linha da tabela, mede e fecha.
function measureRowActionMenu(vp, label) {
    cy.get('tbody button[aria-haspopup="menu"]').first().scrollIntoView().click();
    assertDropdownDensity({ control: vp.control, label: `${label} / menu de ações` });
    cy.get('body').type('{esc}');
    cy.get('ul.dropdown-menu.show').should('not.exist');
}

// Mede o diálogo aberto em cada aba (ou uma vez, se não tem abas) e fecha pelo botão de fechar.
function measureDialog(vp, label, { allowEmpty = false } = {}) {
    // O invólucro cobre a tela; em largura curta o diálogo vira folha na base e o centro do invólucro cai no fundo.
    cy.get('[role="dialog"] .ee-modal__dialog').should('be.visible');
    // O diálogo é teleportado para o <body>: sem a região própria ele não herda a densidade da página.
    cy.get('[role="dialog"]').should('have.attr', 'data-ee-density');
    cy.get('[role="dialog"]').then(($dialog) => {
        const tabs = $dialog.find('.ee-modal__tabs .nav-link').length;

        if (!tabs) {
            assertDensity('[role="dialog"]', { control: vp.control, label, allowEmpty });
            return;
        }
        for (let index = 0; index < tabs; index += 1) {
            cy.get('[role="dialog"] .ee-modal__tabs .nav-link').eq(index).click();
            cy.get('[role="dialog"] .ee-modal__tabs .nav-link')
                .eq(index)
                .invoke('text')
                .then((tab) =>
                    assertDensity('[role="dialog"]', { control: vp.control, label: `${label} / aba ${tab.trim()}` }),
                );
        }
    });
    assertNoHorizontalScroll(`${label} (aberto)`);
    cy.get('[role="dialog"] .btn-close').click();
    cy.get('[role="dialog"]').should('not.exist');
}

// Abre o drawer de detalhe da 1ª linha (botão "Visualizar"), espera o carregamento e mede.
function measureDetailDrawer(vp, label) {
    cy.get('tbody [title="Visualizar"]').first().scrollIntoView().click();
    cy.get('[role="dialog"] .ee-modal__dialog').should('be.visible');
    cy.get('[role="dialog"] .spinner-border').should('not.exist');
    // Drawer de leitura pode não ter controle algum além do fechar: a região no diálogo é o que se prova.
    measureDialog(vp, label, { allowEmpty: true });
}

// ── Editor de convênios da importação de pacientes (dados sintéticos) ─────────────────────────────────────
const FAKE_IMPORT_ID = '00000000-0000-4000-8000-0000000000e2';
const FAKE_COVENANTS_URL = `/panel/patients/import/${FAKE_IMPORT_ID}/covenants`;

function fakeCovenantRow(name, key, rows, failed) {
    return {
        source_name: name,
        source_name_normalized: key,
        status: 'unmatched',
        covenant: null,
        plan: null,
        mapping: null,
        retryable: true,
        rows,
        failed_rows: failed,
    };
}

const FAKE_REPORT = {
    import: {
        id: FAKE_IMPORT_ID,
        original_name: 'densidade-sintetico.csv',
        status: 'done',
        is_reprocess: false,
        reprocess_of_id: null,
        files_expire_at: null,
        files_expired: false,
        report_available: true,
    },
    permissions: { can_create_covenant: true, can_reprocess: true },
    totals: { file_rows: 20, failed_rows: 17, failed_rows_exact: true, unmatched_names: 2, mapped_names: 0 },
    names_capped: false,
    items: [
        fakeCovenantRow('CONVENIO SINTETICO UM', 'convenio sinteticoum', 12, 12),
        fakeCovenantRow('CONVENIO SINTETICO DOIS', 'conveniosinteticodois', 5, 5),
        {
            ...fakeCovenantRow('CONVENIO ENCONTRADO', 'convenioencontrado', 3, 0),
            status: 'found',
            retryable: false,
            covenant: { id: FAKE_IMPORT_ID, name: 'Convênio encontrado', active: true, label: 'Convênio encontrado' },
        },
    ],
};

function fakeHistoryRow() {
    return {
        id: FAKE_IMPORT_ID,
        original_name: 'densidade-sintetico.csv',
        status: 'done',
        status_label: 'Concluído',
        status_color: 'success',
        is_done: true,
        is_pending: false,
        created_at: '26/10/2026 10:00',
        confirmed_at: '26/10/2026 10:01',
        total_rows: 20,
        processed_rows: 20,
        imported_rows: 3,
        skipped_rows: 0,
        error_rows: 17,
        warning_rows: 0,
        progress: 100,
        abort_reason: null,
        user_name: 'Sintético',
        preview: null,
        has_errors_file: false,
        reprocess_of_id: null,
        is_reprocess: false,
        has_covenant_errors: true,
        files_expire_at: null,
        files_expired: false,
        channel: `imports.patients.${FAKE_IMPORT_ID}`,
        urls: {
            confirm: `/panel/patients/import/${FAKE_IMPORT_ID}/confirm`,
            cancel: `/panel/patients/import/${FAKE_IMPORT_ID}/cancel`,
            errors: null,
            covenants: FAKE_COVENANTS_URL,
            covenants_reprocess: `${FAKE_COVENANTS_URL}/reprocess`,
        },
    };
}

// A página de importação chega por navegação Inertia (JSON): acrescenta o import sintético ao histórico e
// responde o relatório dele. Só GET; o editor nunca grava neste spec.
function stubSyntheticCovenantImport() {
    cy.intercept('GET', '**/panel/patients/import', (req) => {
        if (!req.headers['x-inertia']) return;
        req.continue((res) => {
            const page = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
            page.props.imports = [fakeHistoryRow(), ...(page.props.imports ?? [])];
            res.send(page);
        });
    });
    cy.intercept('GET', `**${FAKE_COVENANTS_URL}`, { body: { data: FAKE_REPORT } });
}

// Evidência da rodada (controles medidos por escopo): cypress/results/ não vai ao git.
after(() => {
    cy.writeFile('cypress/results/density-report.json', measuredSummary());
});

VIEWPORTS.forEach((vp) => {
    describe(`Densidade ${vp.name} — controle de ${vp.control}px`, () => {
        beforeEach(() => {
            resetDensityIssues();
            cy.viewport(vp.width, vp.height);
        });

        describe('Médicos (clinic.admin)', () => {
            beforeEach(() => {
                cy.loginAs('clinic.admin');
            });

            it('/panel/doctors: lista, menu de ações e modal de cadastro', () => {
                cy.visit('/panel/doctors');
                expectRegion('.page-doctors[data-ee-density]');
                cy.get('tbody tr').should('have.length.greaterThan', 0);

                assertDensity(PAGE_CONTENT, { control: vp.control, label: 'Médicos / lista' });
                assertNoHorizontalScroll('Médicos / lista');
                measureRowActionMenu(vp, 'Médicos');

                cy.contains('button', 'Novo médico').click();
                measureDialog(vp, 'Médicos / cadastro');
                expectNoDensityIssues();
            });

            it('/panel/doctors: drawer de detalhe', () => {
                cy.visit('/panel/doctors');
                expectRegion('.page-doctors[data-ee-density]');
                cy.get('tbody tr').should('have.length.greaterThan', 0);

                measureDetailDrawer(vp, 'Médicos / detalhe');
                expectNoDensityIssues();
            });

            it('/panel/doctors/import: página de importação (sem enviar arquivo)', () => {
                cy.visit('/panel/doctors/import');
                expectRegion('.page-wrapper .content [data-ee-density]');

                assertDensity(PAGE_CONTENT, { control: vp.control, label: 'Médicos / importação' });
                assertNoHorizontalScroll('Médicos / importação');
                expectNoDensityIssues();
            });

            it('/panel/doctors/{doctor}/work-schedule: escala do 1º médico da lista', () => {
                // O id vem da lista (link da ação "Horários de atendimento"), nunca fixo.
                cy.visit('/panel/doctors');
                expectRegion('.page-doctors[data-ee-density]');
                cy.get('tbody a[href$="/work-schedule"]').first().scrollIntoView().click();
                cy.location('pathname').should('match', /\/panel\/doctors\/[^/]+\/work-schedule$/);
                expectRegion('.page-doctor-work-schedule[data-ee-density]');

                assertDensity(PAGE_CONTENT, { control: vp.control, label: 'Médicos / escala' });
                assertNoHorizontalScroll('Médicos / escala');
                expectNoDensityIssues();
            });
        });

        describe('Pacientes (clinic.admin)', () => {
            beforeEach(() => {
                cy.loginAs('clinic.admin');
            });

            it('/panel/patients: lista, menu de ações e modal de cadastro', () => {
                cy.visit('/panel/patients');
                expectRegion('.page-patients[data-ee-density]');
                cy.get('tbody tr').should('have.length.greaterThan', 0);

                assertDensity(PAGE_CONTENT, { control: vp.control, label: 'Pacientes / lista' });
                assertNoHorizontalScroll('Pacientes / lista');
                measureRowActionMenu(vp, 'Pacientes');

                cy.contains('button', 'Novo paciente').click();
                measureDialog(vp, 'Pacientes / cadastro');
                expectNoDensityIssues();
            });

            it('/panel/patients: drawer de detalhe', () => {
                cy.visit('/panel/patients');
                expectRegion('.page-patients[data-ee-density]');
                cy.get('tbody tr').should('have.length.greaterThan', 0);

                measureDetailDrawer(vp, 'Pacientes / detalhe');
                expectNoDensityIssues();
            });

            it('/panel/patients: modal de mesclar (só abre, não mescla)', () => {
                cy.intercept('GET', '**/merge-candidates').as('candidates');
                cy.visit('/panel/patients');
                expectRegion('.page-patients[data-ee-density]');
                cy.get('tbody tr').should('have.length.greaterThan', 0);

                cy.get('tbody button[aria-haspopup="menu"]').first().scrollIntoView().click();
                cy.get('[data-testid="patient-merge-action"]').first().click();
                cy.wait('@candidates');
                cy.get('[role="dialog"] .spinner-border').should('not.exist');
                measureDialog(vp, 'Pacientes / mesclar');
                expectNoDensityIssues();
            });

            it('/panel/patients/import: página de importação (sem enviar arquivo)', () => {
                cy.visit('/panel/patients/import');
                expectRegion('.page-wrapper .content [data-ee-density]');

                assertDensity(PAGE_CONTENT, { control: vp.control, label: 'Pacientes / importação' });
                assertNoHorizontalScroll('Pacientes / importação');
                expectNoDensityIssues();
            });

            it('/panel/patients/import: editor de convênios e as confirmações (dados sintéticos)', () => {
                stubSyntheticCovenantImport();
                cy.visit('/panel/patients');
                expectRegion('.page-patients[data-ee-density]');
                cy.get('[data-testid="patients-header-actions"] a[href$="/panel/patients/import"]').click();
                cy.location('pathname').should('eq', '/panel/patients/import');

                cy.get('[data-test="resolve-covenants"]').first().click();
                cy.get('[data-test="covenant-resolution"] section.covenant-mapping[data-ee-density]').should(
                    'be.visible',
                );
                assertDensity('[data-test="covenant-resolution"]', {
                    control: vp.control,
                    label: 'Importação / editor de convênios',
                });
                assertNoHorizontalScroll('Importação / editor de convênios');

                // "Todos como particular" e "Salvar e reprocessar" só abrem a confirmação; nenhuma é confirmada.
                cy.get('[data-test="mapping-save"]').parent().find('button').first().click();
                measureDialog(vp, 'Importação / confirmar particular');
                cy.get('[data-test="mapping-reprocess"]').click();
                measureDialog(vp, 'Importação / confirmar reprocessar');
                expectNoDensityIssues();
            });
        });

        describe('Precificação de IA (saas.admin)', () => {
            beforeEach(() => {
                cy.loginAs('saas.admin');
            });

            it('/panel/manager/ai-pricing: três abas e os dois modais de confirmação', () => {
                // Uma visita por rodada (throttle:manager-read 60/min); tudo o mais é troca de aba.
                cy.visit('/panel/manager/ai-pricing');
                expectRegion('.ai-pricing[data-ee-density]');

                ['rate', 'billing', 'internal'].forEach((tab) => {
                    cy.get(`button[data-tab="${tab}"]`).click();
                    cy.get(`[data-test="tab-panel-${tab}"]`).should('be.visible');
                    assertDensity('.ai-pricing', { control: vp.control, label: `Precificação / aba ${tab}` });
                    assertNoHorizontalScroll(`Precificação / aba ${tab}`);
                });

                // "Revisar" só abre a confirmação (nada é salvo sem o motivo e o botão de salvar).
                cy.get('button[data-tab="billing"]').click();
                cy.get('[data-test="settings-review"]').filter(':visible').click();
                measureDialog(vp, 'Precificação / confirmar ajustes');

                cy.get('button[data-tab="rate"]').click();
                cy.get('[data-test="rate-review"]').click();
                measureDialog(vp, 'Precificação / confirmar câmbio');
                expectNoDensityIssues();
            });
        });
    });
});
