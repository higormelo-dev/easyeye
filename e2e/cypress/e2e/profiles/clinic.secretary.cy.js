// Perfil clinic.secretary — secretaria@clinicateste.com (rule secretary).
// Matriz: menu = base (Dashboard, Agendas, Pacientes) + Médicos + Imagens
// oftálmicas + Assistente de IA (LINK DIRETO /panel/ai/usage, sem submenu).
// Sem Financeiro, sem Relatórios, sem Configurações, sem Controle de acesso.
// Pode LER prontuários mas não escrever — nenhuma escrita clínica aqui.
// Fontes: app/Support/PanelNavigation.php ($canSeeDoctors inclui secretary;
// $isFinancial/$isAdmin excluem) + middleware permission/entity.role nas rotas.

// O painel carrega em mini-sidebar (html[data-layout="mini"] → body.mini-sidebar
// via preclinic-theme-script.js) e o hover expande via jQuery (body.expand-menu,
// preclinic-script.js). O mouseover sintético do próprio cy.click dispara essa
// expansão NO MEIO do click e desloca os itens (~1 item pra baixo) — clicando o
// vizinho errado. Fix: expandir ANTES, esperar a animação e só então clicar.
function clickMenuLink(hrefPart) {
  // O layout pode estar em MINI (expande no hover, itens deslocam durante a
  // animação) ou já EXPANDIDO (preferência persistida no navegador). No mini,
  // expande e espera a LARGURA estabilizar; em ambos, o clique é NATIVO no
  // nó já resolvido ($a[0].click()) — dispara no elemento certo mesmo se a
  // posição mudar entre a resolução e o clique (era a causa de abrir a
  // página vizinha no app interativo).
  cy.get('body').then(($b) => {
    if ($b.hasClass('mini-sidebar')) {
      cy.get('#sidebar').trigger('mouseover');
      cy.get('body').should('have.class', 'expand-menu');
      cy.get('#sidebar').should(($s) => {
        expect($s[0].getBoundingClientRect().width, 'sidebar expandida').to.be.greaterThan(180);
      });
      cy.wait(150);
    }
  });
  cy.get(`#sidebar-menu a[href*="${hrefPart}"]`).first()
    .scrollIntoView()
    .should('be.visible')
    .then(($a) => { $a[0].click(); });
}

describe('Perfil clinic.secretary — acesso e navegação', () => {
  beforeEach(() => {
    cy.loginAs('clinic.secretary');
  });

  // ── (a) Landing ────────────────────────────────────────────────────────────
  it('landing pós-login: /panel/dashboard monta com a entity no sidebar', () => {
    // O cy.session de loginAs já assertou a URL /panel/dashboard no login real.
    cy.visit('/panel/dashboard');
    cy.expectPanelPage('.page-dashboard');
    cy.contains('Personalizar').should('be.visible');
    cy.get('.sidebar-top h6').should('contain.text', 'CLÍNICA TESTE INTEGRADOR');
  });

  // ── Menu: composição exata do perfil ───────────────────────────────────────
  it('menu tem exatamente os itens do perfil (com IA link direto, sem Financeiro/Relatórios/Configurações)', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();

    // Presentes (por href — mais estável que texto).
    cy.get('#sidebar-menu a[href*="/panel/dashboard"]').should('exist');
    cy.get('#sidebar-menu a[href*="/panel/schedules"]').should('exist');
    cy.get('#sidebar-menu a[href*="/panel/patients"]').should('exist');
    cy.get('#sidebar-menu a[href*="/panel/doctors"]').should('exist');
    cy.get('#sidebar-menu a[href*="/panel/eye-images"]').should('exist');
    // IA para secretary = link DIRETO para o dashboard de uso (sem submenu de prompts).
    cy.get('#sidebar-menu a[href*="/panel/ai/usage"]').should('exist');
    cy.get('#sidebar-menu a[href*="/panel/setting/ai-prompts"]').should('not.exist');

    // Ausentes (menu ≠ autorização, mas para secretary a matriz nega ambos).
    cy.get('#sidebar-menu a[href*="/panel/financial"]').should('not.exist');
    cy.get('#sidebar-menu a[href*="/panel/reports"]').should('not.exist');
    cy.get('#sidebar-menu a[href*="/panel/accesscontrol"]').should('not.exist');
    cy.get('#sidebar-menu a[href*="/panel/setting"]').should('not.exist');
    cy.get('#sidebar-menu a[href*="/panel/manager"]').should('not.exist');
  });

  // ── (b) Allowed — navegando pelos links reais do menu ──────────────────────
  it('Agendas: link do menu abre /panel/schedules', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    clickMenuLink('/panel/schedules');
    cy.url().should('include', '/panel/schedules');
    cy.expectPanelPage();
    cy.contains('h4', 'Agenda').should('be.visible');
  });

  it('Pacientes: link do menu abre /panel/patients', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    clickMenuLink('/panel/patients');
    cy.url().should('include', '/panel/patients');
    cy.expectPanelPage();
    cy.contains('h4', 'Pacientes').should('be.visible');
  });

  it('Médicos: link do menu abre /panel/doctors', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    clickMenuLink('/panel/doctors');
    cy.url().should('include', '/panel/doctors');
    cy.expectPanelPage();
    cy.contains('h4', 'Médicos').should('be.visible');
  });

  it('Imagens oftálmicas: link do menu abre /panel/eye-images', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    clickMenuLink('/panel/eye-images');
    cy.url().should('include', '/panel/eye-images');
    cy.expectPanelPage();
    cy.contains('h4', 'Imagens oftálmicas').should('be.visible');
  });

  it('Assistente de IA: link direto do menu abre /panel/ai/usage', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();
    clickMenuLink('/panel/ai/usage');
    cy.url().should('include', '/panel/ai/usage');
    cy.expectPanelPage();
    cy.contains('h4', 'Assistente de IA').should('be.visible');
  });

  // ── (c) Forbidden ──────────────────────────────────────────────────────────
  it('financeiro: /panel/financial/cash-flow nega (permission financial.manage/admin/financial)', () => {
    cy.expectForbidden('/panel/financial/cash-flow');
  });

  it('relatórios: /panel/reports nega (mesmo middleware do financeiro)', () => {
    cy.expectForbidden('/panel/reports');
  });

  it('controle de acesso: /panel/accesscontrol/users nega (entity.role admin)', () => {
    cy.expectForbidden('/panel/accesscontrol/users');
  });

  it('prompts de IA: /panel/setting/ai-prompts nega (entity.role doctor — secretary vê IA mas não prompts)', () => {
    cy.expectForbidden('/panel/setting/ai-prompts');
  });

  it('convênios: /panel/setting/covenants nega (permission settings.manage)', () => {
    cy.expectForbidden('/panel/setting/covenants');
  });

  it('manager: /panel/manager/entities redireciona pro dashboard com flash de área exclusiva do SaaS', () => {
    // Modo 302 + flash (não 403): validar a UX real via cy.visit.
    cy.visit('/panel/manager/entities');
    cy.url().should('include', '/panel/dashboard');
    // Flash auto-dismiss em 6s: assertar imediatamente.
    cy.get('.alert.alert-danger', { timeout: 4000 })
      .should('contain.text', 'Esta área é exclusiva do administrador do SaaS.');
    cy.expectPanelPage();
  });

  // ── (d) Interações não-destrutivas nas áreas centrais ──────────────────────
  it('Pacientes: busca reage e modal "Novo paciente" abre e fecha sem salvar', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage('Pacientes');

    // Busca (debounce 400ms → router.get com ?search=): termo impossível
    // não altera nada no banco e prova a reação da lista.
    cy.get('input[placeholder^="Buscar por nome"]').type('zzz-cy-inexistente');
    cy.url({ timeout: 10000 }).should('include', 'search=zzz-cy-inexistente');
    cy.contains('Nenhum paciente encontrado.').should('be.visible');
    cy.get('input[placeholder^="Buscar por nome"]').clear();
    cy.url({ timeout: 10000 }).should('not.include', 'zzz-cy-inexistente');

    // Modal de criação (OffcanvasPanel .ee-modal__dialog): abrir e FECHAR sem salvar.
    cy.contains('button', 'Novo paciente').click();
    cy.get('.ee-modal__dialog').should('be.visible');
    cy.get('.ee-modal__header .btn-close').click();
    cy.get('.ee-modal__dialog').should('not.exist');
  });

  it('Agendas: modal "Novo" agendamento abre e fecha sem salvar', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.contains('h4', 'Agenda').should('be.visible');

    // Botão "Novo" (texto exato — ícone não tem texto) abre CenteredModal.
    cy.contains('button', /^\s*Novo\s*$/).click();
    cy.get('.ee-modal__dialog').should('be.visible');
    cy.get('.ee-modal__header .btn-close').click();
    cy.get('.ee-modal__dialog').should('not.exist');
  });

  it('Médicos: busca reage sem alterar dados', () => {
    cy.visit('/panel/doctors');
    cy.expectPanelPage('Médicos');

    cy.get('input[placeholder^="Buscar por nome"]').type('zzz-cy-inexistente');
    cy.url({ timeout: 10000 }).should('include', 'search=zzz-cy-inexistente');
    cy.get('input[placeholder^="Buscar por nome"]').clear();
    cy.url({ timeout: 10000 }).should('not.include', 'zzz-cy-inexistente');
  });

  // ── (e) Logout ─────────────────────────────────────────────────────────────
  it('logout pelo dropdown volta ao site público', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();

    cy.get('.profile-dropdown a.dropdown-toggle').click();
    cy.get('.profile-dropdown .dropdown-menu').should('be.visible');
    cy.get('.profile-dropdown button.dropdown-item.text-danger')
      .filter((_, el) => /Sair/.test(el.textContent) && !/impersona/i.test(el.textContent))
      .first()
      .click();

    cy.url({ timeout: 20000 }).should('match', /\/(login)?$/);
    cy.get('#sidebar-menu').should('not.exist');
  });
});

// ═══════════════════════════════════════════════════════════════════════════
// PROCEDIMENTOS COMPLETOS — tudo que a conta de secretária FAZ, ponta a ponta.
// Dados "CY-SEC" criados e removidos pela própria UI (zero resíduo).
// Obrigatórios REAIS do backend (PatientRequest): name + covenant_id (aba
// Clínico); ScheduleRequest: doctor_id + date_time (slot) + full_name.
// Fora de escopo deliberado: criar médico real (gera credencial de login),
// confirmar importação (dispara job), lançar recebimento em caixa, escrita
// clínica (ato médico).
// ═══════════════════════════════════════════════════════════════════════════

const STAMP = String(Date.now()).slice(-7); // única por execução
const UNIQ = `CY-SEC ${STAMP}`;

// Se o modal não fechar, falha mostrando as mensagens de validação do DOM —
// diagnóstico direto em vez de "modal ainda existe".
function assertModalSaved() {
  cy.wait(1500);
  cy.document().then((doc) => {
    const dlg = doc.querySelector('.ee-modal__dialog');
    if (dlg) {
      const errs = [...dlg.querySelectorAll('.invalid-feedback, .text-danger, .is-invalid + div')]
        .map((e) => e.textContent.trim())
        // Ignora os asteriscos de "obrigatório" dos labels e rótulos curtos —
        // só mensagens de validação de verdade.
        .filter((t) => t.length > 3 && t !== '*');
      expect([...new Set(errs)], 'validação do servidor no modal').to.deep.eq([]);
    }
  });
  cy.get('.ee-modal__dialog', { timeout: 12000 }).should('not.exist');
}

function cpfValido(seed) {
  const n = [];
  let s = seed;
  for (let i = 0; i < 9; i++) { s = (s * 9301 + 49297) % 233280; n.push(s % 10); }
  const dv = (base, factor) => {
    let total = 0;
    base.forEach((d, i) => { total += d * (factor - i); });
    const r = (total * 10) % 11;
    return r === 10 ? 0 : r;
  };
  n.push(dv(n, 10));
  n.push(dv(n, 11));
  return n.join('');
}
const CPF = cpfValido(Date.now() % 900000 + 100000);

describe('Perfil clinic.secretary — procedimentos completos', () => {
  beforeEach(() => {
    cy.loginAs('clinic.secretary');
  });

  // ════════ PACIENTES: ciclo de vida completo ════════
  it('paciente: criar (Nome na aba Pessoal + Convênio na aba Clínico)', () => {
    cy.visit('/panel/patients');
    cy.expectPanelPage('Pacientes');

    cy.contains('button', 'Novo paciente').click();
    cy.get('.ee-modal__dialog').should('be.visible');

    // Aba Pessoal — required do servidor: nome, nascimento, gênero,
    // estado civil, CPF, e-mail (PatientRequest, required_without:type_method).
    cy.get('.ee-modal__dialog').contains('label', 'Nome completo')
      .parent().find('input:visible').type(`${UNIQ} PACIENTE`);
    cy.get('.ee-modal__dialog').contains('label', 'Data de nascimento')
      .parent().find('input:visible').type('1990-05-15');
    cy.get('.ee-modal__dialog').contains('label', 'Gênero')
      .parent().find('.multiselect:visible').click();
    cy.get('.multiselect-option:visible', { timeout: 10000 }).first().click({ force: true });
    cy.get('.ee-modal__dialog').contains('label', 'Estado civil')
      .parent().find('.multiselect:visible').click();
    cy.get('.multiselect-option:visible', { timeout: 10000 }).first().click({ force: true });
    cy.get('.ee-modal__dialog').contains('label', 'CPF')
      .parent().find('input:visible').type(CPF);
    cy.get('.ee-modal__dialog').contains('label', 'E-mail')
      .parent().find('input:visible').type(`cysec.${STAMP}@gmail.com`);

    // Aba Contato — celular (required; whatsapp é checkbox boolean).
    cy.get('.ee-modal__dialog .nav-tabs').contains('button', 'Contato').click();
    cy.get('.ee-modal__dialog').contains('label', 'Celular')
      .parent().find('input:visible').type('11977776666');

    // Aba Clínico — convênio (required).
    cy.get('.ee-modal__dialog .nav-tabs').contains('button', 'Clínico').click();
    cy.get('.ee-modal__dialog').contains('label', 'Convênio')
      .parent().find('.multiselect:visible').click();
    cy.get('.multiselect-option:visible', { timeout: 10000 }).first().click({ force: true });

    cy.get('.ee-modal__dialog button.btn-primary').last().click();
    assertModalSaved();

    cy.get('input[placeholder^="Buscar por nome"]').type(UNIQ);
    cy.url({ timeout: 10000 }).should('include', 'search=');
    cy.contains('td', `${UNIQ} PACIENTE`, { timeout: 10000 }).should('be.visible');
  });

  it('paciente: editar apelido e conferir no drawer de detalhe', () => {
    cy.visit(`/panel/patients?search=${encodeURIComponent(UNIQ)}`);
    cy.expectPanelPage('Pacientes');
    cy.contains('td', `${UNIQ} PACIENTE`, { timeout: 10000 }).should('be.visible');

    cy.contains('tr', `${UNIQ} PACIENTE`).find('button:visible')
      .filter((_, el) => !!el.querySelector('i.ti-dots-vertical'))
      .first().click();
    cy.get('.dropdown-menu.show').contains('.dropdown-item', 'Editar').click();
    cy.get('.ee-modal__dialog').should('be.visible');
    cy.get('.ee-modal__dialog').contains('label', 'Apelido')
      .parent().find('input:visible').clear().type('CY Editado');
    cy.get('.ee-modal__dialog button.btn-primary').last().click();
    cy.get('.ee-modal__dialog', { timeout: 15000 }).should('not.exist');

    cy.contains('tr', `${UNIQ} PACIENTE`).find('[title="Visualizar"]').first().click();
    cy.contains(`${UNIQ} PACIENTE`, { timeout: 10000 }).should('be.visible');
    cy.get('body').type('{esc}');
  });

  it('paciente: prontuário abre para a secretária (leitura)', () => {
    cy.visit(`/panel/patients?search=${encodeURIComponent(UNIQ)}`);
    cy.contains('tr', `${UNIQ} PACIENTE`, { timeout: 10000 })
      .find('a[title="Prontuário"]').first()
      .invoke('attr', 'href').then((href) => {
        cy.visit(href);
        cy.expectPanelPage();
        cy.contains(`${UNIQ} PACIENTE`).should('be.visible');
      });
  });

  it('paciente: desativar (badge Não), reativar (Sim) e excluir (badge Excluído + Restaurar)', () => {
    cy.visit(`/panel/patients?search=${encodeURIComponent(UNIQ)}`);
    cy.contains('td', `${UNIQ} PACIENTE`, { timeout: 10000 }).should('be.visible');

    const openRowMenu = () => cy.contains('tr', `${UNIQ} PACIENTE`)
      .find('button:visible')
      .filter((_, el) => !!el.querySelector('i.ti-dots-vertical'))
      .first().click();

    // Desativar → badge de status vira "Não" (a linha permanece listada).
    openRowMenu();
    cy.get('.dropdown-menu.show').contains('.dropdown-item', 'Desativar').click();
    cy.contains('tr', `${UNIQ} PACIENTE`, { timeout: 10000 })
      .contains('Não').should('be.visible');

    // Reativar → badge "Sim".
    openRowMenu();
    cy.get('.dropdown-menu.show').contains('.dropdown-item', 'Ativar').click();
    cy.contains('tr', `${UNIQ} PACIENTE`, { timeout: 10000 })
      .contains('Sim').should('be.visible');

    // Excluir (soft delete) → linha continua na busca com badge "Excluído"
    // e ação de Restaurar disponível — é o design da tela (auditável).
    cy.on('window:confirm', () => true);
    openRowMenu();
    cy.get('.dropdown-menu.show').contains('.dropdown-item', 'Excluir').click();
    cy.contains('tr', `${UNIQ} PACIENTE`, { timeout: 10000 })
      .contains('Excluído').should('be.visible');
    cy.contains('tr', `${UNIQ} PACIENTE`)
      .find('[title="Restaurar"]').should('exist');

    // Limpeza definitiva (soft-deletados desta e de execuções anteriores).
    cy.exec(
      `cd .. && php artisan tinker --execute="` +
      `\$p = App\\Models\\People::where('full_name','like','CY-SEC%')->pluck('id');` +
      `App\\Models\\Patient::withTrashed()->whereIn('person_id', \$p)->forceDelete();` +
      `echo App\\Models\\People::whereIn('id', \$p)->forceDelete();"`,
      { failOnNonZeroExit: false, timeout: 30000 },
    );
  });

  // ════════ IMPORTAÇÃO DE PACIENTES (bug 500 do FeatureStatus corrigido) ════
  it('importação: template baixa, upload gera preview, cancelar não persiste', () => {
    cy.visit('/panel/patients/import');
    cy.expectPanelPage();

    cy.request('/panel/patients/import/template').then((r) => {
      expect(r.status).to.eq(200);
      expect(String(r.headers['content-type'])).to.match(/csv|text|octet/);
    });

    // Separador oficial do template é ponto-e-vírgula.
    const csv = `nome;cpf;data_nascimento\n${UNIQ} IMPORT;${CPF};1991-01-01\n`;
    cy.get('input[type=file]').first().selectFile(
      { contents: Cypress.Buffer.from(csv), fileName: `cy-import-${STAMP}.csv`, mimeType: 'text/csv' },
      { force: true },
    );
    cy.intercept('POST', '**/panel/patients/import').as('uploadCsv');
    cy.contains('button', 'Enviar').should('not.be.disabled').click();
    cy.wait('@uploadCsv').then((i) => {
      const body = typeof i.response.body === 'string'
        ? i.response.body.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 400)
        : JSON.stringify(i.response.body).slice(0, 400);
      expect(i.response.statusCode, `upload: ${body}`).to.be.lessThan(500);
    });

    cy.contains(`cy-import-${STAMP}.csv`, { timeout: 20000 }).should('be.visible');

    // Preview pendente → Cancelar (btn-outline-danger, com confirm nativo).
    cy.on('window:confirm', () => true);
    cy.get('button.btn-outline-danger').contains(/Cancelar/i).click({ force: true });
    cy.contains('Revise as primeiras linhas', { timeout: 15000 }).should('not.exist');
  });

  // ════════ AGENDA: fila, recados, seleção, agendamento ════════
  it('fila de espera: adicionar entrada CY, ver no painel e remover', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();

    cy.get('button:has(i.fa-user-plus)').first().click();
    cy.get('.ee-modal__dialog').should('be.visible');

    cy.get('.ee-modal__dialog .multiselect:visible').first().click();
    cy.get('.multiselect-option:visible').contains(/ana/i).first().click({ force: true });
    cy.get('.ee-modal__dialog').contains('label', 'Nome completo')
      .parent().find('input:visible').type(`${UNIQ} FILA`);

    // Botão do footer do OffcanvasPanel (btn-warning "Adicionar à fila").
    cy.intercept('POST', '**/panel/waiting-list').as('storeWl');
    cy.get('.ee-modal__dialog button.btn-warning:visible')
      .first().click();
    cy.wait('@storeWl').then((i) => {
      expect(i.response.statusCode, `store fila: ${JSON.stringify(i.response.body).slice(0, 300)}`)
        .to.be.lessThan(400);
    });
    cy.get('.ee-modal__dialog', { timeout: 15000 }).should('not.exist');

    cy.get('button:has(i.fa-hourglass-half)').first().click();
    cy.contains(`${UNIQ} FILA`, { timeout: 10000 }).should('be.visible');

    cy.on('window:confirm', () => true);
    cy.contains(`${UNIQ} FILA`).parents('li, .card, [class*=item]').first()
      .find('button')
      .filter((_, el) => /remov|excluir|trash/i.test((el.title || '') + el.className))
      .first().click({ force: true });
    cy.contains(`${UNIQ} FILA`, { timeout: 10000 }).should('not.exist');
  });

  it('mural de recados: publicar e excluir o próprio recado', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();

    cy.get('button:has(i.fa-bullhorn)').first().click();
    // Painel INLINE na página (div.border.border-primary com header azul).
    cy.get('div.border.border-primary', { timeout: 10000 }).should('be.visible')
      .within(() => {
        cy.get('button.btn-light:has(i.fa-plus)').click();
      });
    cy.get('textarea[placeholder="Digite o recado…"]', { timeout: 10000 })
      .should('be.visible').type(`${UNIQ} recado`);
    cy.contains('button', 'Publicar').click();
    cy.get('div.border.border-primary').contains(`${UNIQ} recado`, { timeout: 10000 })
      .should('be.visible');

    cy.on('window:confirm', () => true);
    cy.get('div.border.border-primary').contains('p', `${UNIQ} recado`)
      .closest('.d-flex').find('button.btn-outline-danger').first().click({ force: true });
    cy.get('div.border.border-primary').contains(`${UNIQ} recado`, { timeout: 10000 })
      .should('not.exist');
  });

  it('agenda: busca, modo seleção em massa e alternância lista/calendário', () => {
    cy.visit('/panel/schedules');
    cy.expectPanelPage();

    cy.get('input[placeholder]').filter((_, el) => /buscar|paciente/i.test(el.placeholder))
      .first().type('zzz-cy-nada');
    cy.wait(600);
    cy.get('input[placeholder]').filter((_, el) => /buscar|paciente/i.test(el.placeholder))
      .first().clear();

    cy.get('button:has(i.fa-check-square)').first().click();
    cy.contains('button', /Confirmar/i).should('be.disabled');
    cy.get('button:has(i.fa-times)').first().click();

    cy.get('button:has(i.ti-calendar)').first().click();
    cy.get('button:has(i.ti-list)').first().click();
    cy.expectPanelPage();
  });

  it('agenda: novo agendamento — com slot cria e CANCELA; sem slot cobre estado vazio', () => {
    // Pré-limpeza: agendamentos CY órfãos de execuções interrompidas ocupam
    // slots e causam "Já existe um agendamento" — remove antes de começar.
    cy.exec(
      `cd .. && php artisan tinker --execute="echo DB::table('schedules')->where('full_name','like','CY-SEC%')->delete();"`,
      { failOnNonZeroExit: false, timeout: 30000 },
    );
    cy.visit('/panel/schedules');
    cy.expectPanelPage();

    cy.contains('button', /^\s*Novo\s*$/).click();
    cy.get('.ee-modal__dialog').should('be.visible');

    cy.get('.ee-modal__dialog .multiselect:visible').first().click();
    cy.get('.multiselect-option:visible').contains(/ana/i).first().click({ force: true });

    // Data determinística: PRÓXIMA segunda-feira (dia com escala da Ana).
    // Em domingo o picker ainda oferece a grade do dia sem escala (gap
    // picker×server já anotado) e os primeiros slots caem em cima dos seeds.
    const mon = new Date();
    mon.setDate(mon.getDate() + (((8 - mon.getDay()) % 7) || 7));
    const monday = mon.toISOString().slice(0, 10);
    cy.get('.ee-modal__dialog input[type=date]').first()
      .invoke('val', monday).trigger('input').trigger('change');

    cy.wait(1200); // SlotPicker consulta horários do médico
    cy.get('.ee-modal__dialog').then(($m) => {
      const slots = $m.find('button').filter((_, el) => /^\d{2}:\d{2}$/.test(el.textContent.trim()));
      if (slots.length > 0) {
        // Paciente rápido: o campo "Nome livre" (form.full_name) fica visível
        // enquanto nenhum paciente do autocomplete foi vinculado.
        cy.get('.ee-modal__dialog').contains('label', /^Nome/)
          .parent().find('input:visible').type(`${UNIQ} AGENDA`);

        // Slots do fim para o começo, com RETRY: em runner interativo o
        // horário pode estar ocupado/no passado (dados vivos) — 422 de
        // conflito tenta o próximo slot; qualquer outro erro falha exibindo
        // o corpo da resposta.
        // Do INÍCIO da lista (slots do dia/horário visível — os do fim podem
        // cair em dia sem escala; o retry pula ocupados/passados).
        const candidates = [...slots].slice(0, 6);
        const trySlot = (idx) => {
          cy.wrap(candidates[idx]).click({ force: true });
          cy.intercept('POST', '**/panel/schedules').as(`storeSched${idx}`);
          cy.get('.ee-modal__dialog button.btn-primary.px-4').click();
          cy.wait(`@storeSched${idx}`, { timeout: 20000 }).then((i) => {
            const code = i.response.statusCode;
            if (code < 400) return;
            const body = JSON.stringify(i.response.body).slice(0, 300);
            const retryable = code === 422 && /existe|hor[áa]rio|passad|anterior/i.test(body);
            expect(
              retryable && idx + 1 < candidates.length,
              `store agenda (slot ${idx + 1}/${candidates.length}): HTTP ${code} — ${body}`
            ).to.be.true;
            trySlot(idx + 1);
          });
        };
        trySlot(0);
        assertModalSaved();

        // O agendamento nasceu na SEGUNDA — navegar a agenda para essa data
        // (a lista abre no dia corrente).
        cy.visit(`/panel/schedules?date=${monday}`);
        cy.expectPanelPage();
        cy.contains(`${UNIQ} AGENDA`, { timeout: 15000 }).should('be.visible');
        // Cancelar (limpeza) via dropdown de situação do card.
        cy.contains('.schedule-card', `${UNIQ} AGENDA`)
          .find('button')
          .filter((_, el) => /situa|alterar/i.test(el.title || '') || el.querySelector('i.fa-list-ul'))
          .first().click({ force: true });
        cy.contains('.dropdown-item', /Cancelad/i).click({ force: true });
        // CancelModal (bootstrap #cancelScheduleModal) pede motivo.
        cy.get('#cancelScheduleModal:visible, .ee-modal__dialog:visible', { timeout: 10000 })
          .last().within(() => {
            cy.get('textarea, input[type=text]').first().type('CY cancelamento de teste');
            cy.contains('button', /Confirmar|Cancelar agendamento|Salvar/i).click({ force: true });
          });
        cy.contains('.schedule-card', `${UNIQ} AGENDA`, { timeout: 15000 })
          .should('contain.text', 'Cancelado');
      } else {
        cy.log('SlotPicker sem horários para o médico — cobrindo o estado vazio');
        cy.get('.ee-modal__header .btn-close').click();
        cy.get('.ee-modal__dialog').should('not.exist');
      }
    });
  });

  // ════════ MÉDICOS ════════
  it('médicos: drawer de detalhe e página de horários de atendimento', () => {
    cy.visit('/panel/doctors');
    cy.expectPanelPage('Médicos');

    cy.get('[title="Visualizar"]').first().click();
    cy.contains(/CRM|Registro/i, { timeout: 10000 }).should('be.visible');
    cy.get('body').type('{esc}');

    cy.get('a[title="Horários de atendimento"]').first()
      .invoke('attr', 'href').then((href) => {
        cy.visit(href);
        cy.expectPanelPage();
        cy.contains(/Horário|Segunda|Domingo/i).should('be.visible');
      });
  });

  it('médicos: cadastrar/editar/excluir são EXCLUSIVOS do admin — secretária recebe 403', () => {
    // Design de segurança do sistema (Gate ManageSettings em store/update/
    // destroy do DoctorsController): cadastro de médico CRIA CREDENCIAL DE
    // LOGIN — só o admin da clínica pode. A rota aceita secretary (bloco
    // patients.manage) mas o Gate refina. Este teste PROVA a negação.
    cy.visit('/panel/doctors');
    cy.expectPanelPage('Médicos');

    // (a) Form vazio não fecha (validação client) — cobertura de UX.
    cy.contains('button', /Novo/i).first().click();
    cy.get('.ee-modal__dialog').should('be.visible');
    cy.get('.ee-modal__dialog button.btn-primary').last().click();
    cy.get('.ee-modal__dialog').should('be.visible');
    cy.get('.ee-modal__dialog').find('.is-invalid, .text-danger, .invalid-feedback')
      .should('exist');

    // (b) Form COMPLETO e válido → servidor nega com 403 (nada é criado).
    const medCpf = cpfValido((Date.now() % 900000) + 77);
    cy.get('.ee-modal__dialog').contains('label', 'Nome completo')
      .parent().find('input:visible').type(`${UNIQ} MEDICO NEGADO`);
    cy.get('.ee-modal__dialog').contains('label', 'Apelido')
      .parent().find('input:visible').type('CYMED');
    cy.get('.ee-modal__dialog').contains('label', 'CPF')
      .parent().find('input:visible').type(medCpf);
    cy.get('.ee-modal__dialog').contains('label', 'Data de nascimento')
      .parent().find('input:visible').type('1980-04-20');
    cy.get('.ee-modal__dialog').contains('label', 'Gênero')
      .parent().find('.multiselect:visible').click();
    cy.get('.multiselect-option:visible').first().click({ force: true });
    cy.get('.ee-modal__dialog').contains('label', 'Estado civil')
      .parent().find('.multiselect:visible').click();
    cy.get('.multiselect-option:visible').first().click({ force: true });
    cy.get('.ee-modal__dialog').contains('label', 'E-mail')
      .parent().find('input:visible').type(`cymed.${STAMP}@easyeye.test`);

    cy.get('.ee-modal__dialog .nav-tabs button').eq(2).click(); // Contato
    cy.get('.ee-modal__dialog').contains('label', 'Celular')
      .parent().find('input:visible').type('11966665555');

    cy.get('.ee-modal__dialog .nav-tabs button').eq(1).click(); // Profissional
    cy.get('.ee-modal__dialog').contains('label', 'CRM')
      .parent().find('input:visible').type(STAMP);
    cy.get('.ee-modal__dialog').contains('label', 'Especialidade')
      .parent().find('input:visible').type(`${STAMP}1`);
    cy.get('.ee-modal__dialog input[type=color]')
      .invoke('val', '#1a2b3c').trigger('input').trigger('change');

    cy.get('.ee-modal__dialog .nav-tabs button').eq(3).click(); // Acesso
    cy.get('.ee-modal__dialog').contains('label', /^Senha/)
      .parent().find('input:visible').type(`CySenha@${STAMP}!`);
    cy.get('.ee-modal__dialog').contains('label', 'Confirmar senha')
      .parent().find('input:visible').type(`CySenha@${STAMP}!`);

    cy.intercept('POST', '**/panel/doctors').as('storeDoctor');
    cy.get('.ee-modal__dialog button.btn-primary').last().click();
    // Inertia: 403 do Gate vira redirect-back (302) com flash de erro.
    cy.wait('@storeDoctor').its('response.statusCode').should('be.oneOf', [302, 303, 403]);
    cy.get('.alert.alert-danger, .ee-modal__dialog', { timeout: 6000 }).should('exist');
    cy.get('.ee-modal__header .btn-close').click({ force: true });
    // Nada foi criado.
    cy.visit(`/panel/doctors?search=${encodeURIComponent('MEDICO NEGADO')}`);
    cy.contains('td', 'MEDICO NEGADO').should('not.exist');

    // (c) Excluir médico existente → 403; Dra. Ana permanece.
    cy.visit('/panel/doctors');
    cy.expectPanelPage('Médicos');
    cy.on('window:confirm', () => true);
    cy.intercept('DELETE', '**/panel/doctors/**').as('deleteDoctor');
    cy.contains('tr', /ANA/i).find('button:visible')
      .filter((_, el) => !!el.querySelector('i.ti-dots-vertical'))
      .first().click();
    cy.get('.dropdown-menu.show').contains('.dropdown-item', 'Excluir').click();
    // Navegação Inertia: o handler global converte o 403 do Gate em
    // redirect-back (302) com flash de erro — é a UX real da negação.
    cy.wait('@deleteDoctor').its('response.statusCode').should('be.oneOf', [302, 303, 403]);
    cy.get('.alert.alert-danger', { timeout: 6000 }).should('be.visible');
    cy.visit('/panel/doctors');
    cy.contains('tr', /ANA/i).should('exist');
  });

  it('médicos: secretária configura ESCALA e BLOQUEIOS (permitido) em médico de teste', () => {
    // O médico de teste nasce por infraestrutura (cadastro pela UI é 403 para
    // a secretária — coberto acima). Aqui validamos o que ela PODE: escala de
    // atendimento e bloqueios (rotas do bloco admin,doctor,secretary).
    const seedCmd = `cd .. && php artisan tinker --execute="require 'e2e/scripts/seed-cymed.php';"`;
    cy.exec(seedCmd, { timeout: 40000 });

    // Escala: habilitar Terça com faixa 14:00–18:00 e salvar.
    cy.visit('/panel/doctors?search=CY-MED');
    cy.expectPanelPage('Médicos');
    cy.contains('tr', 'CY-MED').find('a[title="Horários de atendimento"]')
      .first().invoke('attr', 'href').then((href) => {
        cy.visit(href);
        cy.expectPanelPage();

        cy.contains('label', /^Ter/).parent().find('input[type=checkbox]').check({ force: true });
        // Botão "+ faixa" fica no header do dia (btn-outline-info) e só
        // renderiza com o dia ativo — texto varia por tradução, usar a classe.
        cy.contains('label', /^Ter/).closest('.d-flex')
          .find('button.btn-outline-info', { timeout: 10000 }).click();
        // Faixa default 08:00–12:00 → mudar para 14:00–18:00.
        // type() em input[type=time] é flaky no headless; invoke('val') +
        // trigger('input') atualiza o v-model deterministicamente.
        cy.get('input[type=time]').first()
          .invoke('val', '14:00').trigger('input').trigger('change');
        cy.get('input[type=time]').eq(1)
          .invoke('val', '18:00').trigger('input').trigger('change');

        cy.intercept('PUT', '**/work-schedule').as('syncSchedule');
        cy.contains('button', /Salvar Escala|Salvar/).click();
        cy.wait('@syncSchedule').its('response.statusCode').should('be.lessThan', 300);

        // Persistiu: recarrega e a faixa está lá.
        cy.reload();
        cy.expectPanelPage();
        cy.contains('label', /^Ter/).parent().find('input[type=checkbox]').should('be.checked');
        cy.get('input[type=time]').should(($els) => {
          const values = [...$els].map((el) => el.value);
          expect(values, `faixas persistidas: ${values.join(', ')}`).to.include('14:00');
        });

        // Bloqueio: amanhã 09:00–10:00, motivo CY; criar e remover.
        const tomorrow = new Date(Date.now() + 86400000);
        const d = tomorrow.toISOString().slice(0, 10);
        cy.contains(/Bloqueios/).parent().find('button').first().click();
        cy.get('input[type=datetime-local]').eq(0).type(`${d}T09:00`);
        cy.get('input[type=datetime-local]').eq(1).type(`${d}T10:00`);
        cy.get('textarea:visible, input[placeholder*="otivo"]').first()
          .type(`CY bloqueio ${STAMP}`, { force: true });
        cy.intercept('POST', '**/blocks').as('storeBlock');
        cy.contains('button', /Adicionar Bloqueio/).click();
        cy.wait('@storeBlock').its('response.statusCode').should('be.lessThan', 300);
        cy.contains(`CY bloqueio ${STAMP}`, { timeout: 10000 }).should('be.visible');

        cy.on('window:confirm', () => true);
        cy.intercept('DELETE', '**/blocks/**').as('destroyBlock');
        cy.contains(`CY bloqueio ${STAMP}`)
          .closest('div:has(button.btn-outline-danger)')
          .find('button.btn-outline-danger').last().click({ force: true });
        cy.wait('@destroyBlock').its('response.statusCode').should('be.lessThan', 300);
        cy.contains(`CY bloqueio ${STAMP}`, { timeout: 10000 }).should('not.exist');
      });

    // O médico de teste aparece no painel lateral da agenda.
    cy.visit('/panel/schedules');
    cy.expectPanelPage();
    cy.contains(/CY-MED/i, { timeout: 10000 }).should('be.visible');

    // Limpeza total por infraestrutura (escala, vínculo, user, pessoa).
    const cleanCmd = `cd .. && php artisan tinker --execute="require 'e2e/scripts/clean-cymed.php';"`;
    cy.exec(cleanCmd, { failOnNonZeroExit: false, timeout: 40000 });
  });

  // ════════ IMAGENS OFTÁLMICAS ════════
  it('imagens: modal de importar exame externo (botão Novo) abre e fecha', () => {
    cy.visit('/panel/eye-images');
    cy.expectPanelPage('Imagens oftálmicas');

    cy.contains('button', /^\s*Novo\s*$/).first().click();
    cy.get('.ee-modal__dialog', { timeout: 10000 }).should('be.visible');
    cy.get('.ee-modal__header .btn-close').first().click();
    cy.get('.ee-modal__dialog').should('not.exist');
  });

  // ════════ UTILITÁRIOS AJAX (sessão autenticada) ════════
  // Endpoints AJAX que as telas consomem por trás (sem página própria):
  // autocomplete de CID-10 e de medicamentos + JSON da fila de espera.
  // cy.request autenticada valida permissão da secretária + contrato (200/shape) —
  // se quebrarem, dropdowns e o painel da fila morrem silenciosamente na UI.
  it('APIs internas (autocomplete CID-10/medicamentos + fila de espera) respondem para a secretária', () => {
    cy.visit('/panel/dashboard');
    cy.expectPanelPage();

    cy.request('/panel/cid10/search?q=catarata').then((r) => {
      expect(r.status).to.eq(200);
      expect(r.body).to.satisfy((b) => Array.isArray(b) || Array.isArray(b.data));
    });
    cy.request('/panel/medicines/search?q=olho').its('status').should('eq', 200);
    cy.request('/panel/waiting-list').then((r) => {
      expect(r.status).to.eq(200);
      expect(r.body).to.have.property('data');
    });
  });
});
