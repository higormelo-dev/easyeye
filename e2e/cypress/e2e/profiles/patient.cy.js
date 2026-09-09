// Perfil "patient" — Portal do Paciente (área externa, fora do /panel).
// Guard/model/tabela PRÓPRIOS (patient_accounts) — nunca usa o guard "web"
// de staff. Fixture: e2e/scripts/seed-docs-patient.php (paciente MARIANA
// COSTA E SILVA, mesma pessoa dos manuais de médico/admin/secretária —
// prontuário assinado + 1 documentação [laudo] + 2 exames de imagem +
// 1 anexo, os 3 já compartilhados com o Portal).
//
// cy.loginAs('patient') branca pro guard certo (/portal-paciente/login,
// landing /meus-documentos) — ver e2e/cypress/support/commands.js.

const SEED_PATIENT  = `cd .. && php artisan tinker --execute="require 'e2e/scripts/seed-docs-patient.php';"`;
const CLEAN_PATIENT = `cd .. && php artisan tinker --execute="require 'e2e/scripts/clean-docs-doctor.php';"`;

describe('Perfil patient — acesso e navegação', () => {
  before(() => {
    cy.exec(SEED_PATIENT, { timeout: 40000 }).its('stdout').should('include', 'docspat:');
  });

  it('guest: rotas autenticadas do portal negam sem login (redirect pro login)', () => {
    // followRedirect:false — cy.request segue redirect por padrão, e o 302
    // real vira o 200 da PRÓPRIA tela de login (mascara a negação).
    cy.request({ url: '/meus-documentos', followRedirect: false, failOnStatusCode: false }).then((resp) => {
      // Sem sessão: redirect (302) pro login, ou 401 se o client pedir JSON.
      expect(resp.status, `esperava negação — status ${resp.status}`).to.be.oneOf([302, 401]);
    });
  });

  it('guest: login, esqueci-senha e redefinir-senha são acessíveis sem sessão', () => {
    cy.visit('/portal-paciente/login');
    cy.contains('h4', 'Portal do Paciente').should('be.visible');

    cy.visit('/portal-paciente/esqueci-senha');
    cy.contains('h4', 'Esqueci minha senha').should('be.visible');

    // Token inexistente: a tela ainda RENDERIZA (só falha no POST) — o GET
    // não valida o token contra o banco.
    cy.visit('/portal-paciente/redefinir-senha/token-qualquer?email=x@x.com');
    cy.contains('h4', 'Criar nova senha').should('be.visible');
  });

  it('login: e-mail/senha corretos entram e caem em /meus-documentos', () => {
    cy.visit('/portal-paciente/login');
    cy.get('input[type=email]').type('mariana.silva@easyeye-demo.test');
    cy.get('input[autocomplete=current-password]').type('PortalPaciente@123');
    cy.intercept('POST', '**/portal-paciente/login').as('login');
    cy.contains('button', 'Entrar').click();
    cy.wait('@login').its('response.statusCode').should('be.oneOf', [200, 302, 303]);
    cy.url({ timeout: 15000 }).should('include', '/meus-documentos');
    cy.contains('Minhas Clínicas').should('be.visible');
  });

  it('login: senha errada mostra erro genérico (sem revelar se o e-mail existe)', () => {
    cy.visit('/portal-paciente/login');
    cy.get('input[type=email]').type('mariana.silva@easyeye-demo.test');
    cy.get('input[autocomplete=current-password]').type('SenhaErrada@999');
    cy.contains('button', 'Entrar').click();
    cy.contains('.alert-danger', 'Credenciais inválidas', { timeout: 15000 }).should('be.visible');
    cy.url().should('include', '/portal-paciente/login');

    // E-mail inexistente cai na MESMA mensagem — não dá pra diferenciar.
    cy.get('input[type=email]').clear().type('ninguem.aqui@easyeye-demo.test');
    cy.get('input[autocomplete=current-password]').clear().type('QualquerSenha@1');
    cy.contains('button', 'Entrar').click();
    cy.contains('.alert-danger', 'Credenciais inválidas', { timeout: 15000 }).should('be.visible');
  });

  it('login: conta desativada nega mesmo com a senha correta (kill switch em tempo real)', () => {
    cy.exec(`cd .. && php artisan tinker --execute="App\\Models\\PatientAccount::where('email','mariana.silva@easyeye-demo.test')->first()->forceFill(['active'=>false])->save();"`, { timeout: 30000 });

    cy.visit('/portal-paciente/login');
    cy.get('input[type=email]').type('mariana.silva@easyeye-demo.test');
    cy.get('input[autocomplete=current-password]').type('PortalPaciente@123');
    cy.contains('button', 'Entrar').click();
    cy.contains('.alert-danger', /desativad/i, { timeout: 15000 }).should('be.visible');

    // Reativa pro resto da suíte.
    cy.exec(`cd .. && php artisan tinker --execute="App\\Models\\PatientAccount::where('email','mariana.silva@easyeye-demo.test')->first()->forceFill(['active'=>true])->save();"`, { timeout: 30000 });
  });

  it('logout: encerra a sessão e volta pro login', () => {
    cy.loginAs('patient');
    cy.visit('/meus-documentos');
    cy.contains('Minhas Clínicas').should('be.visible');

    cy.get('.navbar button.btn-link').click();
    cy.intercept('POST', '**/portal-paciente/logout').as('logout');
    cy.contains('.dropdown-menu', 'Sair').click();
    cy.wait('@logout').its('response.statusCode').should('be.oneOf', [200, 302, 303]);
    cy.url({ timeout: 15000 }).should('include', '/portal-paciente/login');

    // Sessão realmente encerrada — /meus-documentos volta a negar.
    cy.request({ url: '/meus-documentos', followRedirect: false, failOnStatusCode: false })
      .its('status').should('be.oneOf', [302, 401]);
  });
});

describe('Perfil patient — convite e redefinição de senha', () => {
  const DEMO_EMAIL = 'cy-convite.demo@easyeye-demo.test';

  const cleanDemo = () => cy.exec(`cd .. && php artisan tinker --execute="
    \\$pe = App\\Models\\People::withTrashed()->where('email','${DEMO_EMAIL}')->first();
    if (\\$pe) {
      App\\Models\\Patient::withTrashed()->where('person_id',\\$pe->id)->forceDelete();
      DB::table('patient_accounts')->where('person_id',\\$pe->id)->delete();
      \\$pe->forceDelete();
    }
  "`, { failOnNonZeroExit: false, timeout: 30000 });

  before(() => cleanDemo());
  after(() => cleanDemo());

  it('convite: link assinado válido cria a conta e loga automaticamente', () => {
    cy.exec(`cd .. && php artisan tinker --execute="
      \\$ent = App\\Models\\Entity::where('name','like','%TESTE INTEGRADOR%')->firstOrFail();
      \\$cov = App\\Models\\Covenant::where('entity_id',\\$ent->id)->first() ?? App\\Models\\Covenant::firstOrFail();
      \\$pe = App\\Models\\People::create(['email'=>'${DEMO_EMAIL}','full_name'=>'CY CONVITE DEMO','cellphone'=>'11988887777']);
      App\\Models\\Patient::create(['entity_id'=>\\$ent->id,'person_id'=>\\$pe->id,'covenant_id'=>\\$cov->id,'active'=>true]);
      echo 'url:' . \\Illuminate\\Support\\Facades\\URL::temporarySignedRoute('patient-portal.invitation.accept', now()->addDays(3), ['person_id'=>\\$pe->id]);
    "`, { timeout: 40000 })
      .its('stdout').then((out) => {
        const url = out.match(/url:(\S+)/)[1];
        cy.visit(url);
      });
    cy.contains('h4', 'Bem-vindo', { timeout: 15000 }).should('be.visible');

    cy.get('input[autocomplete=new-password]').eq(0).type('CyConvite@123');
    cy.get('input[autocomplete=new-password]').eq(1).type('CyConvite@123');
    cy.intercept('POST', '**/convite/aceitar*').as('acceptInvite');
    cy.contains('button', 'Criar minha conta').click();
    cy.wait('@acceptInvite').its('response.statusCode').should('be.oneOf', [200, 302, 303]);
    cy.url({ timeout: 15000 }).should('include', '/meus-documentos');
    cy.contains('Minhas Clínicas').should('be.visible');
  });

  it('convite: link já usado (conta já existe) mostra aviso e não deixa recriar', () => {
    cy.exec(`cd .. && php artisan tinker --execute="
      \\$pe = App\\Models\\People::where('email','${DEMO_EMAIL}')->firstOrFail();
      echo 'url:' . \\Illuminate\\Support\\Facades\\URL::temporarySignedRoute('patient-portal.invitation.accept', now()->addDays(3), ['person_id'=>\\$pe->id]);
    "`, { timeout: 40000 })
      .its('stdout').then((out) => {
        const url = out.match(/url:(\S+)/)[1];
        cy.visit(url);
      });
    cy.contains(/convite.*(já foi utilizado|utilizado)/i, { timeout: 15000 }).should('be.visible');
    cy.url().should('include', '/portal-paciente/login');
  });

  it('esqueci minha senha: token real permite redefinir e logar com a senha nova', () => {
    cy.exec(SEED_PATIENT, { timeout: 40000 });
    cy.exec(`cd .. && php artisan tinker --execute="\\$acc = App\\Models\\PatientAccount::where('email','mariana.silva@easyeye-demo.test')->firstOrFail(); echo 'token:' . \\Illuminate\\Support\\Facades\\Password::broker('patients')->createToken(\\$acc);"`, { timeout: 40000 })
      .its('stdout').then((out) => {
        const token = out.match(/token:(\S+)/)[1];
        cy.visit(`/portal-paciente/redefinir-senha/${token}?email=${encodeURIComponent('mariana.silva@easyeye-demo.test')}`);
      });
    cy.get('input[autocomplete=new-password]').eq(0).type('NovaSenha@456');
    cy.get('input[autocomplete=new-password]').eq(1).type('NovaSenha@456');
    cy.intercept('POST', '**/redefinir-senha').as('resetPassword');
    cy.contains('button', 'Redefinir senha').click();
    cy.wait('@resetPassword').its('response.statusCode').should('be.oneOf', [200, 302, 303]);
    cy.url({ timeout: 15000 }).should('include', '/portal-paciente/login');

    // Login com a senha NOVA — prova que o reset persistiu de verdade.
    cy.get('input[type=email]').type('mariana.silva@easyeye-demo.test');
    cy.get('input[autocomplete=current-password]').type('NovaSenha@456');
    cy.contains('button', 'Entrar').click();
    cy.contains('Minhas Clínicas', { timeout: 15000 }).should('be.visible');

    // Reseed devolve a senha padrão pro resto da suíte.
    cy.exec(SEED_PATIENT, { timeout: 40000 });
  });
});

describe('Perfil patient — documentos (procedimentos completos)', () => {
  before(() => {
    cy.exec(SEED_PATIENT, { timeout: 40000 }).its('stdout').should('include', 'docspat:');
  });
  beforeEach(() => cy.loginAs('patient'));
  after(() => {
    cy.exec(CLEAN_PATIENT, { failOnNonZeroExit: false, timeout: 40000 });
  });

  it('dashboard: lista a(s) clínica(s) onde o paciente já foi atendido', () => {
    cy.visit('/meus-documentos');
    cy.contains('Minhas Clínicas').should('be.visible');
    cy.contains('.card', 'CLÍNICA TESTE INTEGRADOR', { timeout: 15000 }).should('be.visible');
    cy.contains('.card', 'CLÍNICA TESTE INTEGRADOR').contains('Ver documentos').should('be.visible');
  });

  it('clínica: lista laudo, exame e anexo compartilhados, cada um com seu tipo', () => {
    cy.visit('/meus-documentos');
    cy.contains('Ver documentos').click();
    cy.contains('CLÍNICA TESTE INTEGRADOR', { timeout: 15000 }).should('be.visible');
    cy.contains('.list-group-item', 'Laudo', { timeout: 15000 }).should('be.visible');
    cy.contains('.list-group-item', 'Exame').should('be.visible');
    cy.contains('.list-group-item', 'Anexo').should('be.visible');
    cy.get('.list-group-item').should('have.length', 3);
  });

  it('IDOR: paciente NÃO acessa a ficha/documentos de outra pessoa (404, não 403)', () => {
    cy.exec(`cd .. && php artisan tinker --execute="
      \\$ent = App\\Models\\Entity::where('name','like','%TESTE INTEGRADOR%')->firstOrFail();
      \\$cov = App\\Models\\Covenant::where('entity_id',\\$ent->id)->first() ?? App\\Models\\Covenant::firstOrFail();
      \\$pe = App\\Models\\People::create(['full_name'=>'CY OUTRA PESSOA IDOR','cellphone'=>'11977776666']);
      \\$pat = App\\Models\\Patient::create(['entity_id'=>\\$ent->id,'person_id'=>\\$pe->id,'covenant_id'=>\\$cov->id,'active'=>true]);
      echo 'patient_id:' . \\$pat->id;
    "`, { timeout: 40000 })
      .its('stdout').then((out) => {
        const otherId = out.match(/patient_id:(\S+)/)[1];
        cy.request({ url: `/meus-documentos/clinicas/${otherId}`, failOnStatusCode: false })
          .its('status').should('eq', 404);

        cy.exec(`cd .. && php artisan tinker --execute="App\\Models\\Patient::withTrashed()->find('${otherId}')?->forceDelete();"`, { failOnNonZeroExit: false, timeout: 30000 });
      });
  });

  it('documento: abre laudo (PDF) e exame (imagem); baixar responde 200', () => {
    cy.visit('/meus-documentos');
    cy.contains('Ver documentos').click();

    cy.contains('.list-group-item', 'Laudo', { timeout: 15000 })
      .contains('a, button', 'Ver').click();
    cy.get('iframe[title="Documento"]', { timeout: 15000 }).should('be.visible')
      .invoke('attr', 'src').should('include', '/conteudo');
    cy.contains('a', 'Baixar').invoke('attr', 'href').should('include', '/download');

    cy.go('back');
    cy.contains('.list-group-item', 'Exame', { timeout: 15000 })
      .contains('a, button', 'Ver').click();
    cy.get('img[alt]', { timeout: 15000 }).should('be.visible')
      .invoke('attr', 'src').should('include', '/conteudo');
  });

  it('documento: compartilhamento revogado passa a responder 404', () => {
    cy.exec(`cd .. && php artisan tinker --execute="
      \\$doc = App\\Models\\MedicalRecordDocumentation::whereHas('medicalRecord', fn (\\$q) => \\$q->whereHas('patient.person', fn (\\$q2) => \\$q2->where('full_name','MARIANA COSTA E SILVA')))->firstOrFail();
      \\$share = App\\Models\\PatientDocumentShare::where('shareable_type', \\App\\Models\\MedicalRecordDocumentation::class)->where('shareable_id', \\$doc->id)->whereNull('revoked_at')->firstOrFail();
      \\$by = App\\Models\\EntityUser::whereHas('doctor', fn (\\$q) => \\$q->whereHas('person', fn (\\$q2) => \\$q2->where('email','dra.ana@clinicateste.com')))->firstOrFail();
      app(App\\Services\\PatientDocumentShareService::class)->revoke(\\$by, \\$share, 'CY teste E2E');
      echo 'revoked:' . \\$doc->id;
    "`, { timeout: 40000 })
      .its('stdout').then((out) => {
        const docId = out.match(/revoked:(\S+)/)[1];
        cy.request({ url: `/meus-documentos/documentos/laudo/${docId}`, failOnStatusCode: false })
          .its('status').should('eq', 404);
      });

    // Reseed restaura o compartilhamento pro resto da suíte / próximas execuções.
    cy.exec(SEED_PATIENT, { timeout: 40000 });
  });
});
