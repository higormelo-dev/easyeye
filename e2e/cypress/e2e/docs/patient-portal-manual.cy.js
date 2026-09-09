// GERADOR DE SCREENSHOTS do manual do Portal do Paciente (área externa,
// guard "patient" — não é teste de regressão). Consumido por
// docs/manual-portal-paciente/README.md. Rodar sob demanda:
//   php artisan tinker --execute="require 'e2e/scripts/seed-docs-patient.php';"
//   cd e2e && npx cypress run --browser chrome --config excludeSpecPattern=__none__ --spec cypress/e2e/docs/patient-portal-manual.cy.js
//   php artisan tinker --execute="require 'e2e/scripts/clean-docs-doctor.php';"

const shot = (name) => cy.screenshot(name, { capture: 'viewport', overwrite: true });

describe('Manual do Portal do Paciente — capturas', () => {
  it('01 acesso: tela de login', () => {
    cy.visit('/portal-paciente/login');
    cy.contains('h4', 'Portal do Paciente', { timeout: 15000 }).should('be.visible');
    cy.wait(400);
    shot('01-login');
  });

  it('02 esqueci minha senha', () => {
    cy.visit('/portal-paciente/esqueci-senha');
    cy.contains('h4', 'Esqueci minha senha', { timeout: 15000 }).should('be.visible');
    cy.wait(300);
    shot('02-esqueci-senha');

    cy.get('input[type=email]').type('mariana.silva@easyeye-demo.test');
    cy.contains('button', 'Enviar link').click();
    cy.contains('.alert-success', /./i, { timeout: 15000 }).should('be.visible');
    cy.wait(300);
    shot('03-esqueci-senha-enviado');
  });

  it('03 redefinir senha (a partir do link do e-mail)', () => {
    // Token real via o mesmo broker do controller (patients) — o e-mail em
    // si não é interceptável no ambiente de teste, então geramos o token
    // exatamente como o Notification faria.
    cy.exec(`cd .. && php artisan tinker --execute="\\$acc = \\App\\Models\\PatientAccount::where('email','mariana.silva@easyeye-demo.test')->firstOrFail(); echo 'token:' . \\Illuminate\\Support\\Facades\\Password::broker('patients')->createToken(\\$acc);"`, { timeout: 40000 })
      .its('stdout').then((out) => {
        const token = out.match(/token:(\S+)/)[1];
        cy.visit(`/portal-paciente/redefinir-senha/${token}?email=${encodeURIComponent('mariana.silva@easyeye-demo.test')}`);
      });
    cy.contains('h4', 'Criar nova senha', { timeout: 15000 }).should('be.visible');
    cy.wait(300);
    shot('04-redefinir-senha');
  });

  it('04 convite de acesso: criar conta a partir do link da clínica', () => {
    // Pessoa demo SEM PatientAccount — só pra este passo (convite pendente).
    // URL::temporarySignedRoute replica exatamente o link que o e-mail de
    // convite (PatientPortalInvitation) envia.
    cy.exec(`cd .. && php artisan tinker --execute="
      \\$old = \\App\\Models\\People::withTrashed()->where('email','convite.demo@easyeye-demo.test')->first();
      if (\\$old) {
        \\App\\Models\\Patient::withTrashed()->where('person_id',\\$old->id)->forceDelete();
        DB::table('patient_accounts')->where('person_id',\\$old->id)->delete();
        \\$old->forceDelete();
      }
      \\$ent = \\App\\Models\\Entity::where('name','like','%TESTE INTEGRADOR%')->firstOrFail();
      \\$cov = \\App\\Models\\Covenant::where('entity_id',\\$ent->id)->first() ?? \\App\\Models\\Covenant::firstOrFail();
      \\$pe = \\App\\Models\\People::create(['email'=>'convite.demo@easyeye-demo.test','full_name'=>'CARLOS DEMO CONVITE','cellphone'=>'11999998888']);
      \\App\\Models\\Patient::create(['entity_id'=>\\$ent->id,'person_id'=>\\$pe->id,'covenant_id'=>\\$cov->id,'active'=>true]);
      echo 'url:' . \\Illuminate\\Support\\Facades\\URL::temporarySignedRoute('patient-portal.invitation.accept', now()->addDays(3), ['person_id'=>\\$pe->id]);
    "`, { timeout: 40000 })
      .its('stdout').then((out) => {
        const url = out.match(/url:(\S+)/)[1];
        cy.visit(url);
      });
    cy.contains('h4', 'Bem-vindo', { timeout: 15000 }).should('be.visible');
    cy.wait(300);
    shot('05-convite-criar-senha');

    cy.get('input[autocomplete=new-password]').eq(0).type('ContaDemo@123');
    cy.get('input[autocomplete=new-password]').eq(1).type('ContaDemo@123');
    cy.contains('button', 'Criar minha conta').click();
    // Sucesso: login automático + redirect pro dashboard (sem documentos —
    // aproveita pra mostrar o estado vazio da lista de documentos).
    cy.contains('Minhas Clínicas', { timeout: 15000 }).should('be.visible');
    cy.wait(300);
    shot('06-convite-aceito-dashboard');

    cy.contains('Ver documentos').click();
    cy.contains('Nenhum documento liberado', { timeout: 15000 }).should('be.visible');
    cy.wait(300);
    shot('07-clinica-sem-documentos');

    // Limpa a conta demo (pra não poluir reexecuções do gerador) — a próxima
    // it() já faz cy.visit() e reautentica do zero, sem depender de logout.
    cy.exec(`cd .. && php artisan tinker --execute="
      \\$pe = \\App\\Models\\People::where('email','convite.demo@easyeye-demo.test')->first();
      if (\\$pe) {
        \\App\\Models\\Patient::where('person_id',\\$pe->id)->forceDelete();
        DB::table('patient_accounts')->where('person_id',\\$pe->id)->delete();
        \\$pe->forceDelete();
      }
      echo 'demo-convite-clean:ok';
    "`, { failOnNonZeroExit: false, timeout: 40000 });
  });

  it('05 login com e-mail e senha (conta já existente)', () => {
    cy.exec(`cd .. && php artisan tinker --execute="require 'e2e/scripts/seed-docs-patient.php';"`, { timeout: 40000 })
      .its('stdout').should('include', 'docspat:');

    cy.visit('/portal-paciente/login');
    cy.get('input[type=email]').type('mariana.silva@easyeye-demo.test');
    cy.get('input[autocomplete=current-password]').type('SenhaErrada@000');
    cy.contains('button', 'Entrar').click();
    cy.contains('.alert-danger', 'Credenciais inválidas', { timeout: 15000 }).should('be.visible');
    cy.wait(300);
    shot('08-login-credenciais-invalidas');

    cy.get('input[type=email]').clear().type('mariana.silva@easyeye-demo.test');
    cy.get('input[autocomplete=current-password]').clear().type('PortalPaciente@123');
    cy.contains('button', 'Entrar').click();
    cy.contains('Minhas Clínicas', { timeout: 15000 }).should('be.visible');
  });
});

// Sessão autenticada cacheada (cy.loginAs) em vez de refazer o login em cada
// it() — o teste 05 acima já demonstrou o formulário de login de verdade.
describe('Manual do Portal do Paciente — capturas autenticadas', () => {
  beforeEach(() => cy.loginAs('patient'));

  it('06 dashboard: minhas clínicas', () => {
    cy.visit('/meus-documentos');
    cy.contains('Minhas Clínicas', { timeout: 15000 }).should('be.visible');
    cy.wait(400);
    shot('09-dashboard-minhas-clinicas');
  });

  it('07 clínica: documentos liberados (laudo, exame e anexo)', () => {
    cy.visit('/meus-documentos');
    cy.contains('Ver documentos').click();
    cy.contains('CLÍNICA TESTE INTEGRADOR', { timeout: 15000 }).should('be.visible');
    cy.get('.list-group-item', { timeout: 15000 }).should('have.length.at.least', 3);
    cy.wait(400);
    shot('10-clinica-documentos');
  });

  it('08 visualizar laudo (PDF)', () => {
    cy.visit('/meus-documentos');
    cy.contains('Ver documentos').click();
    cy.contains('.list-group-item', 'Laudo', { timeout: 15000 })
      .contains('a, button', 'Ver').click();
    cy.get('iframe[title="Documento"]', { timeout: 15000 }).should('be.visible');
    cy.wait(500);
    shot('11-visualizar-laudo-pdf');
  });

  it('09 visualizar exame (imagem)', () => {
    cy.visit('/meus-documentos');
    cy.contains('Ver documentos').click();
    cy.contains('.list-group-item', 'Exame', { timeout: 15000 })
      .contains('a, button', 'Ver').click();
    cy.get('img[alt]', { timeout: 15000 }).should('be.visible');
    cy.wait(500);
    shot('12-visualizar-exame-imagem');
  });

  it('10 sair (logout)', () => {
    cy.visit('/meus-documentos');
    cy.get('.navbar button.btn-link').click();
    cy.contains('.dropdown-menu', 'Sair', { timeout: 10000 }).should('be.visible');
    cy.wait(300);
    shot('13-menu-usuario-sair');

    cy.contains('.dropdown-menu button, .dropdown-menu a', 'Sair').click();
    cy.contains('h4', 'Portal do Paciente', { timeout: 15000 }).should('be.visible');

    // Limpeza total do fixture compartilhada com o manual do médico.
    cy.exec(`cd .. && php artisan tinker --execute="require 'e2e/scripts/clean-docs-doctor.php';"`, { failOnNonZeroExit: false, timeout: 40000 });
  });
});
