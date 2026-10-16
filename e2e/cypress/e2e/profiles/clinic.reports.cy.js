// Fase 5 — laudos de exame: fluxo de rascunho, devolução, impressão e ditado.
//
// Contrato EXATO do seed e2e/scripts/seed-eye-images.php (idempotente; o clean correspondente remove o cenário):
// - clínica ativa e com o recurso de laudos/ditado: CLÍNICA TESTE INTEGRADOR;
// - médica ativa, vinculada à clínica e apta a emitir: Dra. Ana, dra.ana@clinicateste.com;
// - secretária ativa, vinculada à clínica e apta a preparar laudos: Secretária CY Reports,
//   secretaria@clinicateste.com;
// - paciente ativo da mesma clínica: CY-REPORTS PACIENTE;
// - os dois exames são ATIVOS, têm arquivo/imagem acessível, um OD e um OE, e TODOS os seus timestamps
//   `created_at` e `exam_performed_at` são EXATAMENTE 2026-10-01 10:00:00 no fuso America/Sao_Paulo
//   (2026-10-01 13:00:00 UTC); não use a data/hora atual nem horário próximo da meia-noite;
// - um rascunho de laudo com conteúdo não vazio, os dois exames, criado pela secretária e endereçado à Dra. Ana,
//   inicialmente no status submitted, também com `created_at` exatamente no instante acima;
// - configuração de ditado disponível para a clínica (provedor aprovado/habilitado para transcrição e teto positivo),
//   para que a prop dictation.available seja true. O áudio do teste é interceptado e nunca sai do navegador.
//
// Preparo (não roda dentro deste spec):
//   no dev atual rode o seed com E2E_EXAM_DISK=s3 php artisan tinker --execute="require 'e2e/scripts/seed-eye-images.php';"
// Limpeza:
//   php artisan tinker --execute="require 'e2e/scripts/clean-eye-images.php';"

const PATIENT = 'CY-REPORTS PACIENTE';
const RETURN_REASON = 'Revise a lateralidade do OE antes de emitir.';
const DICTATED_TEXT = 'Texto de ditado CY Reports.';
const FIXTURE_DATE = '2026-10-01';

function eyeImagesUrl() {
    return `/panel/eye-images?period=custom&date_from=${FIXTURE_DATE}&date_to=${FIXTURE_DATE}`;
}

function openDrafts() {
    cy.visit(eyeImagesUrl());
    cy.expectPanelPage();
    cy.get('[data-testid="drafts-button"]').should('be.visible').click();
}

function openDraftForPatient() {
    cy.contains('[data-testid="draft-row"]', PATIENT, { timeout: 15000 })
        .should('be.visible')
        .find('[data-testid="draft-open-button"]')
        .click();
    cy.get('[data-testid="eye-image-report-modal"]', { timeout: 15000 }).should('be.visible');
}

function expectLoadedPrintSheets(imagesPerSheet) {
    cy.get('.ei-sheet')
        .should('have.length', imagesPerSheet.length)
        .each(($sheet, index) => {
            cy.wrap($sheet)
                .find('.ei-cell img')
                .should(($images) => {
                    expect($images, `imagens na folha ${index + 1}`).to.have.length(imagesPerSheet[index]);
                    [...$images].forEach((image) => {
                        expect(image.complete, 'imagem do exame carregou').to.equal(true);
                        expect(image.naturalWidth, 'largura natural da imagem do exame').to.be.greaterThan(0);
                    });
                });
        });

    cy.get('.ei-sheet .ei-cell img').should(($images) => {
        expect($images, 'duas imagens dos exames').to.have.length(2);
        const sources = [...$images].map((image) => image.currentSrc || image.src);

        expect(new Set(sources), 'os dois exames impressos são distintos').to.have.size(2);
    });
}

function installDictationBrowserStubs(win) {
    class FakeMediaRecorder {
        constructor(stream) {
            this.stream = stream;
            this.state = 'inactive';
        }

        start() {
            this.state = 'recording';
        }

        stop() {
            this.state = 'inactive';
            this.ondataavailable?.({ data: new win.Blob(['cy-audio'], { type: 'audio/webm' }) });
            this.onstop?.();
        }
    }

    class FakeAudioContext {
        decodeAudioData() {
            return Promise.resolve({ duration: 0.01 });
        }

        close() {}
    }

    class FakeOfflineAudioContext {
        constructor() {
            this.destination = {};
        }

        createBufferSource() {
            return { connect() {}, start() {} };
        }

        startRendering() {
            return Promise.resolve({ getChannelData: () => new Float32Array(16000) });
        }
    }

    Object.defineProperty(win.navigator, 'mediaDevices', {
        configurable: true,
        value: { getUserMedia: () => Promise.resolve({ getTracks: () => [{ stop() {} }] }) },
    });
    win.MediaRecorder = FakeMediaRecorder;
    win.AudioContext = FakeAudioContext;
    win.OfflineAudioContext = FakeOfflineAudioContext;
}

describe('Fase 5 — médica: devolver rascunho para ajuste', () => {
    it('revisa o rascunho submetido e o devolve com motivo', () => {
        cy.loginAs('clinic.doctor');
        openDrafts();
        openDraftForPatient();

        cy.get('[data-testid="draft-return-button"]').should('be.visible').click();
        cy.get('.swal2-textarea').type(RETURN_REASON);
        cy.get('.swal2-confirm').contains('Devolver').click();
        cy.get('[data-testid="eye-image-report-modal"]').should('not.exist');
    });
});

describe('Fase 5 — secretária: corrigir e reenviar rascunho', () => {
    beforeEach(() => {
        cy.loginAs('clinic.secretary');
    });

    it('vê o selo de devolvidos, filtra o status, lê o motivo e reenvia ao médico', () => {
        openDrafts();
        cy.get('[data-testid="drafts-returned-badge"]').should('contain.text', '1');
        cy.get('[data-testid="drafts-status-filter"]').select('returned');
        cy.contains('[data-testid="draft-row"]', PATIENT).should('contain.text', RETURN_REASON);

        openDraftForPatient();
        cy.get('[data-testid="draft-return-banner"]').should('contain.text', RETURN_REASON);
        cy.get('[data-testid="draft-return-button"]').should('not.exist');
        cy.get('[data-testid="draft-submit-button"]').should('contain.text', 'Reenviar para o médico').click();
        cy.get('[data-testid="eye-image-report-modal"]', { timeout: 15000 }).should('not.exist');
    });

    it('imprime o paciente em folhas de duas imagens', () => {
        cy.visit(eyeImagesUrl());
        cy.expectPanelPage();
        cy.contains('[data-testid="eye-images-patient"]', PATIENT, { timeout: 15000 }).click();
        cy.get('[data-testid="print-open-button"]').should('be.visible').click();
        cy.window().then((win) => cy.stub(win, 'print').as('print'));
        cy.get('[data-testid="print-per-sheet"]').should('be.visible').contains('button', '1').click();
        cy.get('[data-testid="print-per-sheet"] button').contains('1').should('have.attr', 'aria-pressed', 'true');
        expectLoadedPrintSheets([1, 1]);
        cy.get('[data-testid="print-run-button"]').click();
        cy.get('@print').should('have.been.calledOnce');

        cy.get('[data-testid="print-per-sheet"]').contains('button', '2').click();
        cy.get('[data-testid="print-per-sheet"] button').contains('2').should('have.attr', 'aria-pressed', 'true');
        expectLoadedPrintSheets([2]);
        cy.get('[data-testid="print-run-button"]').click();
        cy.get('@print').should('have.been.calledTwice');
    });
});

describe('Fase 5 — secretária: ditado no rascunho', () => {
    it('mostra o aviso de privacidade e insere a transcrição do servidor no editor', () => {
        cy.loginAs('clinic.secretary');
        cy.intercept('POST', '**/panel/eye-images/reports/dictate', (req) => {
            expect(req.headers['content-type']).to.include('multipart/form-data');
            expect(req.body).to.include('name="audio"');
            expect(req.body).to.include('filename="ditado.wav"');
            expect(req.body).to.include('RIFF');
            expect(req.body).to.include('WAVE');
            req.reply({ statusCode: 200, body: { text: DICTATED_TEXT } });
        }).as('dictate');
        cy.visit(eyeImagesUrl(), { onBeforeLoad: installDictationBrowserStubs });
        cy.expectPanelPage();
        cy.get('[data-testid="drafts-button"]').should('be.visible').click();
        openDraftForPatient();

        cy.get('[data-testid="dictation-notice"]').should('be.visible').and('contain.text', 'não é guardado');
        cy.get('[data-testid="report-editor"] iframe')
            .its('0.contentDocument.body')
            .should('have.attr', 'contenteditable', 'true')
            .then(cy.wrap)
            .click();
        cy.get('[data-testid="dictation-button"]').should('have.attr', 'aria-pressed', 'false').click();
        cy.get('[data-testid="dictation-button"]').should('have.attr', 'aria-pressed', 'true').click();
        cy.wait('@dictate');
        cy.get('[data-testid="report-editor"] iframe')
            .its('0.contentDocument.body')
            .should('not.be.empty')
            .then(cy.wrap)
            .should('contain.text', DICTATED_TEXT);
    });
});
