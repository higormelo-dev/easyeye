<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, DoctorDocumentTemplate, Entity, EntityUser, MedicalRecord, Patient, People, ReportSettingContent, User};
use App\Models\MedicalRecordDocumentation;
use App\Services\Documents\PersonalTemplateDeidentifier;
use App\Services\TemplateVariableResolver;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
 * Revisão Codex — modelos PESSOAIS do médico:
 *  P1-1 modelo salvo pelo editor não leva dado do paciente de origem (volta às
 *       variáveis no servidor e resolve para o paciente NOVO ao aplicar);
 *       sobrou nome/CPF/código → recusa (LGPD);
 *  P2-5 nome e limite conferidos no tipo REAL (o editor manda "report");
 *  P2-6 cada tela só lista/usa o formato que entende (modais = texto + itens;
 *       editor = todos, texto vira HTML).
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'America/Sao_Paulo'));
    $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class, ReportSettingVariableSeeder::class]);

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true, 'city' => 'Campinas', 'state' => 'SP']);
    $this->docEu  = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor = doctorRecordForEntityUser($this->docEu);

    $covenant = Covenant::factory()->create();
    $this->a  = Patient::create([
        'entity_id' => $this->entity->id, 'covenant_id' => $covenant->id, 'active' => true,
        'person_id' => People::factory()->create([
            'full_name' => 'MARIA APARECIDA SOUZA', 'birth_date' => '1950-03-04', 'national_registry' => '52998224725',
        ])->id,
    ]);
    $this->b = Patient::create([
        'entity_id' => $this->entity->id, 'covenant_id' => $covenant->id, 'active' => true,
        'person_id' => People::factory()->create([
            'full_name' => 'JOSÉ ANTÔNIO PEREIRA', 'birth_date' => '1962-11-20', 'national_registry' => '11144477735',
        ])->id,
    ]);
    $this->recordA = MedicalRecord::create(['entity_id' => $this->entity->id, 'patient_id' => $this->a->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'x']);
    $this->recordB = MedicalRecord::create(['entity_id' => $this->entity->id, 'patient_id' => $this->b->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'y']);

    $global = fn (string $title, string $slug) => ReportSettingContent::query()->where('slug', $slug)
        ->whereHas('reportSetting', fn ($q) => $q->withoutGlobalScopes()->whereNull('entity_id')->where('title', $title))->firstOrFail();

    $this->prescription = $global('PRESCRIÇÃO DE MEDICAMENTOS', 'padrao');
    $this->report       = ReportSettingContent::query()->where('slug', 'declaracao_medica')
        ->whereHas('reportSetting', fn ($q) => $q->withoutGlobalScopes()->whereNull('entity_id'))->firstOrFail();
});

afterEach(fn () => Carbon::setTestNow());

function ptdAs(object $test, ?EntityUser $eu = null): object
{
    $eu ??= $test->docEu;

    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

function ptdPreview(object $test, Patient $patient, MedicalRecord $record, string $contentId, array $extra = []): string
{
    return ptdAs($test)->postJson(route('panel.patients.medicalrecords.template-preview', [$patient, $record]), [
        'report_setting_content_id' => $contentId, ...$extra,
    ])->assertOk()->json('content');
}

function ptdSave(object $test, array $data)
{
    return ptdAs($test)->postJson(route('panel.doctor-document-templates.store'), ['kind' => 'report', ...$data]);
}

describe('P1-1 — modelo pessoal não guarda dado do paciente', function () {
    it('salvo no paciente A e aplicado no B: nada de A, tudo de B, e o texto do médico intacto', function () {
        // Prévia do editor para A, com data alternativa — e o médico edita.
        $html = ptdPreview($this, $this->a, $this->recordA, $this->prescription->id, ['document_date' => '2026-10-01']);
        expect($html)->toContain('MARIA APARECIDA SOUZA')->toContain('01/10/2026');

        $mine = '<p>Nascida em 04/03/1950, <em>retorno em 30 dias</em> &amp; colírio lubrificante 4x/dia (PIO 14 mmHg).</p>';
        $id   = ptdSave($this, [
            'name'                      => 'Olho seco', 'body' => $html . $mine,
            'report_setting_content_id' => $this->prescription->id, 'medical_record_id' => $this->recordA->id, 'document_date' => '2026-10-01',
        ])->assertCreated()->assertJsonPath('data.kind', 'prescription')->json('data.id');

        $body = DoctorDocumentTemplate::query()->withoutGlobalScopes()->findOrFail($id)->body;
        expect($body)->toContain('{{PACIENTE_NOME}}')->toContain('{{DATA_ATUAL}}')->toContain('{{PACIENTE_DATA_NASCIMENTO}}')
            ->not->toContain('MARIA')->not->toContain('04/03/1950')->not->toContain('01/10/2026')
            // Texto do médico preservado; "14" (curto, genérico) não vira variável.
            ->toContain('<em>retorno em 30 dias</em> &amp; colírio lubrificante 4x/dia (PIO 14 mmHg)');

        $applied = ptdPreview($this, $this->b, $this->recordB, $this->prescription->id, ['template_id' => $id]);

        expect($applied)->toContain('JOSÉ ANTÔNIO PEREIRA')->toContain('20/11/1962')->toContain('07/10/2026')
            ->toContain('<em>retorno em 30 dias</em> &amp; colírio lubrificante 4x/dia (PIO 14 mmHg)')
            ->not->toContain('MARIA')->not->toContain('APARECIDA')->not->toContain('04/03/1950')
            ->not->toContain('529')->not->toContain('{{');
    });

    it('nome em outra caixa vira variável; sobrando nome partido por tag, sem acento, CPF ou código → recusa', function () {
        $base = ['name' => 'Xx', 'report_setting_content_id' => $this->report->id, 'medical_record_id' => $this->recordA->id];

        ptdSave($this, [...$base, 'body' => '<p>Maria Aparecida Souza, retorno.</p>'])->assertCreated();
        expect(DoctorDocumentTemplate::query()->withoutGlobalScopes()->sole()->body)->toBe('<p>{{PACIENTE_NOME}}, retorno.</p>');

        // CPF formatado = valor de {{PACIENTE_CPF}} → vira variável (como o nome).
        ptdSave($this, [...$base, 'name' => 'Cpf', 'body' => '<p>CPF 529.982.247-25</p>'])->assertCreated();
        expect(DoctorDocumentTemplate::query()->withoutGlobalScopes()->where('name', 'Cpf')->sole()->body)
            ->toBe('<p>CPF {{PACIENTE_CPF}}</p>');

        $leaks = [
            '<p><strong>Maria</strong> Aparecida Souza</p>',
            '<p>Maria</p><p>Aparecida Souza</p>',
            '<p>CPF 52998224725</p>',
            '<p>Prontuário ' . $this->a->fresh()->code . '</p>',
        ];

        foreach ($leaks as $body) {
            ptdSave($this, [...$base, 'name' => 'Yy' . md5($body), 'body' => $body])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('body')
                ->assertJsonFragment(['body' => [__('clinical_documents.templates.patient_data_left', [
                    'items' => str_contains($body, 'CPF') ? 'CPF' : (str_contains($body, 'PAC-') ? 'código do paciente' : 'nome completo'),
                ])]]);
        }

        // Nome sem acento de paciente com acento também é dado dele.
        ptdAs($this)->postJson(route('panel.doctor-document-templates.store'), [
            ...$base, 'kind' => 'report', 'name' => 'Zz', 'medical_record_id' => $this->recordB->id, 'body' => '<p>Jose <b>Antonio</b> Pereira</p>',
        ])->assertUnprocessable()->assertJsonValidationErrors('body');

        expect(DoctorDocumentTemplate::query()->withoutGlobalScopes()->count())->toBe(2); // o do nome + o do CPF formatado (aceitos)
    });

    it('"Atualizar com o conteúdo atual" (PUT) também volta às variáveis', function () {
        $id = ptdSave($this, ['name' => 'Laudo', 'body' => '<p>Texto</p>', 'report_setting_content_id' => $this->report->id, 'medical_record_id' => $this->recordA->id])->json('data.id');

        ptdAs($this)->putJson(route('panel.doctor-document-templates.update', $id), [
            'body' => '<p>MARIA APARECIDA SOUZA voltou</p>', 'medical_record_id' => $this->recordA->id,
        ])->assertOk()->assertJsonPath('data.body', '<p>{{PACIENTE_NOME}} voltou</p>');

        ptdAs($this)->putJson(route('panel.doctor-document-templates.update', $id), ['body' => '<p>Sem prontuário</p>'])
            ->assertUnprocessable()->assertJsonValidationErrors('medical_record_id');
    });

    it('editor exige o prontuário de origem DESTA clínica (outra clínica → recusado)', function () {
        $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $foreign = MedicalRecord::create([
            'entity_id'      => $other->id,
            'doctor_id'      => doctorRecordForEntityUser(createEntityUser($other, User::factory()->create(), ClientRule::Doctor->value))->id,
            'patient_id'     => Patient::create(['entity_id' => $other->id, 'person_id' => People::factory()->create()->id, 'covenant_id' => Covenant::factory()->create()->id, 'active' => true])->id,
            'main_complaint' => 'z',
        ]);

        ptdSave($this, ['name' => 'Aa', 'body' => '<p>x</p>', 'report_setting_content_id' => $this->report->id])
            ->assertUnprocessable()->assertJsonValidationErrors('medical_record_id');
        ptdSave($this, ['name' => 'Bb', 'body' => '<p>x</p>', 'report_setting_content_id' => $this->report->id, 'medical_record_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('medical_record_id');
    });

    it('texto dos modais com o prontuário junto também não guarda o nome do paciente', function () {
        ptdAs($this)->postJson(route('panel.doctor-document-templates.store'), [
            'kind' => 'prescription', 'name' => 'Receita', 'body' => 'Para Maria Aparecida Souza: colírio X', 'medical_record_id' => $this->recordA->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('body');

        ptdAs($this)->postJson(route('panel.doctor-document-templates.store'), [
            'kind' => 'prescription', 'name' => 'Receita', 'body' => 'Colírio X 4x/dia', 'medical_record_id' => $this->recordA->id,
        ])->assertCreated();
    });

    it('limiar: valor curto ou só de números curtos nunca vira variável; datas e textos longos viram', function () {
        $resolver = Mockery::mock(TemplateVariableResolver::class);
        $resolver->shouldReceive('identityReplacements')->andReturn([
            '{{PIO_OD}}'         => '14',
            '{{REFRACAO_OD}}'    => '-2,00',
            '{{OLHO}}'           => 'OD',
            '{{PACIENTE_IDADE}}' => '76 anos',
            '{{DATA_ATUAL}}'     => '07/10/2026',
            '{{CLINICA_NOME}}'   => "Olhos D'Ávila & Cia",
            '{{PACIENTE_NOME}}'  => 'MARIA APARECIDA SOUZA',
        ]);

        // Atributo com valor elegível (8+ dígitos) mas SEM dado do paciente:
        // prova que a troca não entra em atributo mesmo quando o valor bateria
        // a regra de elegibilidade — sem usar o nome do paciente aqui, que a
        // conferência de P1-1b (abaixo) recusaria por ser um vazamento real.
        $out = (new PersonalTemplateDeidentifier($resolver))->deidentify(
            '<p title="07/10/2026">PIO 14, ref -2,00 OD, 76 anos, em 07/10/2026 na Olhos D&#039;Ávila &amp; Cia. Maria&nbsp;Aparecida Souza</p>',
            $this->report,
            $this->recordA,
            $this->doctor,
            $this->entity,
        );

        expect($out)->toBe('<p title="07/10/2026">PIO 14, ref -2,00 OD, {{PACIENTE_IDADE}}, em {{DATA_ATUAL}} na {{CLINICA_NOME}}. {{PACIENTE_NOME}}</p>');
    });
});

describe('P1-1b — dado do paciente escondido num ATRIBUTO também é recusado (2ª revisão Codex, LGPD)', function () {
    it('nome/CPF só no atributo de um link (href/title) é recusado; atributo inofensivo salva normal', function () {
        $base = ['name' => 'Xx', 'report_setting_content_id' => $this->report->id, 'medical_record_id' => $this->recordA->id];

        // `plain()` descarta a tag inteira (inclusive atributos) — sem a
        // extração de atributos, nenhum destes era detectado e o modelo era
        // salvo com o nome/CPF/código do paciente A reutilizável para outro.
        // Só `a[href|title|target]` sobrevive à sanitização (profile
        // "medical" em config/purifier.php não permite atributo extra em
        // mais nenhuma tag) — por isso os dois casos usam `<a>`. `href` leva
        // o CÓDIGO (sem espaço): um nome com espaço no href seria
        // normalizado (%20) pelo Purifier antes mesmo de chegar aqui — o
        // vazamento real por link é o identificador, não o texto exibido.
        $leaksViaAttribute = [
            'href'  => '<p><a href="/patients/xxx?code=' . $this->a->fresh()->code . '">Ver</a></p>',
            'title' => '<p><a href="#" title="Maria Aparecida Souza">Ver</a></p>',
            'cpf'   => '<p><a href="#" title="CPF 529.982.247-25">Ver</a></p>',
        ];

        foreach ($leaksViaAttribute as $label => $body) {
            ptdSave($this, [...$base, 'name' => 'Leak' . md5($label . $body), 'body' => $body])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('body');
        }

        expect(DoctorDocumentTemplate::query()->withoutGlobalScopes()->count())->toBe(0);

        // Atributo sem dado do paciente: não fica mais estrito do que antes.
        ptdSave($this, [...$base, 'name' => 'Harmless', 'body' => '<p><a href="#section-1" title="Ver mais">Ver</a> retorno em 30 dias.</p>'])
            ->assertCreated();
    });

    it('nome num atributo genérico (data-*, não só os conhecidos) também é recusado — direto no serviço, sem o filtro de tags do editor', function () {
        $resolver = Mockery::mock(TemplateVariableResolver::class);
        $resolver->shouldReceive('identityReplacements')->andReturn(['{{PACIENTE_NOME}}' => 'MARIA APARECIDA SOUZA']);
        $deidentifier = new PersonalTemplateDeidentifier($resolver);

        // `assertClean()` (texto dos modais) não passa por sanitizador algum
        // — é o caminho mais direto para provar que a extração de atributo é
        // GENÉRICA (qualquer `attr="valor"`), não uma lista de nomes conhecidos.
        expect(fn () => $deidentifier->assertClean('<span data-paciente="Maria Aparecida Souza">x</span>', $this->recordA))
            ->toThrow(ValidationException::class);

        // Não deve lançar — atributo sem dado do paciente não fica mais estrito do que antes.
        $deidentifier->assertClean('<span data-note="qualquer coisa">x</span>', $this->recordA);
    });
});

describe('2ª revisão P1-A — campos estruturados do modelo (receita e pedido) também não guardam dado do paciente', function () {
    // [tipo, payload com o dado de A, chave do erro esperada]
    dataset('campos com dado do paciente A', [
        'receita: nome digitado do item' => ['prescription', ['items' => [['name' => 'Colírio manipulado p/ MARIA APARECIDA SOUZA']]], 'payload.items.0.name'],
        'receita: princípio ativo livre' => ['prescription', ['items' => [['name' => 'Fórmula', 'active_ingredient' => 'CPF 529.982.247-25']]], 'payload.items.0.active_ingredient'],
        'pedido: exame digitado'         => ['procedure', ['items' => [['name' => 'Campimetria — Maria Aparecida Souza']]], 'payload.items.0.name'],
        'pedido: indicação livre'        => ['procedure', ['items' => [['name' => 'Campimetria']], 'indication_text' => 'Paciente Maria Aparecida Souza com PIO alta'], 'payload.indication_text'],
        'pedido: observações (CPF)'      => ['procedure', ['items' => [['name' => 'Campimetria']], 'notes' => 'Autorização do CPF 52998224725'], 'payload.notes'],
        'pedido: observações (código)'   => ['procedure', ['items' => [['name' => 'Campimetria']], 'notes' => 'Ver prontuário @code'], 'payload.notes'],
    ]);

    it('recusa ao salvar, apontando o campo', function (string $kind, array $payload, string $field) {
        $payload = json_decode(str_replace('@code', (string) $this->a->fresh()->code, json_encode($payload)), true);

        ptdAs($this)->postJson(route('panel.doctor-document-templates.store'), [
            'kind' => $kind, 'name' => 'Modelo A', 'body' => 'Texto do modelo', 'payload' => $payload, 'medical_record_id' => $this->recordA->id,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(DoctorDocumentTemplate::query()->withoutGlobalScopes()->count())->toBe(0);
    })->with('campos com dado do paciente A');

    it('nome do paciente entre "< >" no corpo de um modelo de TEXTO (receita/pedido) também é recusado — não é tag, é o paciente (12ª revisão Codex, P2)', function () {
        ptdAs($this)->postJson(route('panel.doctor-document-templates.store'), [
            'kind'              => 'prescription', 'name' => 'Modelo com colchete', 'body' => 'Para <MARIA APARECIDA SOUZA>: colírio X',
            'medical_record_id' => $this->recordA->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('body');

        expect(DoctorDocumentTemplate::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('"Atualizar com o conteúdo atual" (PUT) com dado do paciente nos itens também é recusado', function () {
        $id = ptdAs($this)->postJson(route('panel.doctor-document-templates.store'), [
            'kind' => 'procedure', 'name' => 'Glaucoma', 'body' => 'Campimetria', 'payload' => ['items' => [['name' => 'Campimetria']]], 'medical_record_id' => $this->recordA->id,
        ])->assertCreated()->json('data.id');

        ptdAs($this)->putJson(route('panel.doctor-document-templates.update', $id), [
            'body' => 'Campimetria', 'payload' => ['items' => [['name' => 'Campimetria']], 'notes' => 'MARIA APARECIDA SOUZA'], 'medical_record_id' => $this->recordA->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('payload.notes');

        // Itens sem o prontuário de origem: recusado.
        ptdAs($this)->putJson(route('panel.doctor-document-templates.update', $id), ['payload' => ['items' => [['name' => 'OCT']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('medical_record_id');
        // Renomear também passou a precisar do prontuário — o nome entra na
        // mesma checagem de dado do paciente (9ª revisão Codex, P2).
        ptdAs($this)->putJson(route('panel.doctor-document-templates.update', $id), ['name' => 'Glaucoma rotina'])
            ->assertUnprocessable()->assertJsonValidationErrors('medical_record_id');

        ptdAs($this)->putJson(route('panel.doctor-document-templates.update', $id), ['name' => 'Glaucoma rotina', 'medical_record_id' => $this->recordA->id])
            ->assertOk()->assertJsonPath('data.name', 'Glaucoma rotina');
    });

    it('modal sem o prontuário de origem não salva modelo', function () {
        ptdAs($this)->postJson(route('panel.doctor-document-templates.store'), ['kind' => 'procedure', 'name' => 'Sem origem', 'body' => 'Campimetria'])
            ->assertUnprocessable()->assertJsonValidationErrors('medical_record_id');
    });

    it('A salva o pedido limpo; B aplica e emite: nada de A, texto do médico intacto', function () {
        $id = ptdAs($this)->postJson(route('panel.doctor-document-templates.store'), [
            'kind'              => 'procedure', 'name' => 'Glaucoma', 'body' => 'Campimetria',
            'payload'           => ['items' => [['name' => 'Campimetria']], 'indication_text' => 'Suspeita de glaucoma', 'notes' => 'Retorno em 30 dias'],
            'medical_record_id' => $this->recordA->id,
        ])->assertCreated()->json('data.id');

        // Modal de B lista o modelo (é o que vai para a tela dele).
        $listed = ptdAs($this)->getJson(route('panel.doctor-document-templates.index', ['kind' => 'procedure', 'format' => 'text']))->assertOk()->json('data.0');
        $raw    = json_encode($listed, JSON_UNESCAPED_UNICODE);
        expect($raw)->not->toContain('MARIA')->not->toContain('Maria')->not->toContain('52998224725')->not->toContain('529.982')
            ->and($listed['payload'])->toMatchArray(['indication_text' => 'Suspeita de glaucoma', 'notes' => 'Retorno em 30 dias']);

        // B emite com os itens do modelo.
        ptdAs($this)->postJson(route('panel.patients.medicalrecords.quick-actions.issue', [$this->b, $this->recordB, 'procedure-request']), [
            'items' => [['name' => 'Campimetria']], 'indication_text' => $listed['payload']['indication_text'], 'notes' => $listed['payload']['notes'], 'template_id' => $id,
        ])->assertCreated();

        $html = (string) MedicalRecordDocumentation::query()->where('medical_record_id', $this->recordB->id)->value('content');
        expect($html)->toContain('JOSÉ ANTÔNIO PEREIRA')->toContain('Retorno em 30 dias')->not->toContain('MARIA');
    });
});

describe('P2-5 — nome e limite no tipo real do modelo-base', function () {
    it('receita criada pelo editor colide com receita de mesmo nome e respeita o limite de receitas', function () {
        ptdAs($this)->postJson(route('panel.doctor-document-templates.store'), ['kind' => 'prescription', 'name' => 'Olho seco', 'body' => 'Colírio', 'medical_record_id' => $this->recordA->id])->assertCreated();

        $fromEditor = ['name' => 'OLHO SECO', 'body' => '<p>Colírio</p>', 'report_setting_content_id' => $this->prescription->id, 'medical_record_id' => $this->recordA->id];

        ptdSave($this, $fromEditor)->assertUnprocessable()->assertJsonValidationErrors('name')
            ->assertJsonFragment(['name' => [__('clinical_documents.templates.duplicate')]]);

        config(['clinical_documents.personal_templates.max_per_kind' => 1]);

        // Limite de receitas atingido (1): a do editor também é receita.
        ptdSave($this, [...$fromEditor, 'name' => 'Outra'])->assertUnprocessable()
            ->assertJsonFragment(['name' => [__('clinical_documents.templates.limit', ['max' => 1])]]);

        // Limite de relatórios não barra receita (e vice-versa).
        DoctorDocumentTemplate::query()->withoutGlobalScopes()->delete();
        ptdSave($this, ['name' => 'Laudo', 'body' => '<p>x</p>', 'report_setting_content_id' => $this->report->id, 'medical_record_id' => $this->recordA->id])->assertCreated();
        ptdSave($this, [...$fromEditor, 'name' => 'Receita do editor'])->assertCreated()->assertJsonPath('data.kind', 'prescription');
    });
});

describe('P2-6 — cada tela só usa o formato que entende', function () {
    beforeEach(function () {
        $this->html = ptdSave($this, [
            'name'                      => 'Receita do editor', 'body' => '<p>Colírio <strong>X</strong></p>',
            'report_setting_content_id' => $this->prescription->id, 'medical_record_id' => $this->recordA->id,
        ])->assertCreated()->json('data.id');
        $this->text = ptdAs($this)->postJson(route('panel.doctor-document-templates.store'), [
            'kind'    => 'prescription', 'name' => 'Receita do modal', 'body' => "Colírio <Y>\n1 gota 4x/dia",
            'payload' => ['items' => [['name' => 'Colírio Y']]], 'medical_record_id' => $this->recordA->id,
        ])->assertCreated()->json('data.id');
    });

    it('modal de receita (format=text) não lista o HTML do editor; o editor lista os dois', function () {
        $modal = ptdAs($this)->getJson(route('panel.doctor-document-templates.index', ['kind' => 'prescription', 'format' => 'text']))->assertOk()->json('data');
        expect(collect($modal)->pluck('id')->all())->toBe([$this->text]);

        $editor = ptdAs($this)->getJson(route('panel.patients.medicalrecords.templates', [$this->a, $this->recordA]))->assertOk()->json('0.contents');
        expect(collect($editor)->pluck('template_id')->sort()->values()->all())->toBe(collect([$this->html, $this->text])->sort()->values()->all());

        ptdAs($this)->getJson(route('panel.doctor-document-templates.index', ['format' => 'pdf']))->assertUnprocessable();
    });

    it('emitir receita pelo modal com o modelo HTML do editor → 422; o de texto serve', function () {
        $url = route('panel.patients.medicalrecords.quick-actions.issue', [$this->a, $this->recordA, 'medication-prescription']);

        ptdAs($this)->postJson($url, ['content' => 'Colírio X', 'template_id' => $this->html])
            ->assertUnprocessable()->assertJsonFragment(['template_id' => [__('clinical_documents.templates.wrong_consumer')]]);
        ptdAs($this)->postJson($url, ['content' => 'Colírio Y', 'template_id' => $this->text])->assertCreated();
    });

    it('pedido de exames com modelo de receita → 422 (outro tipo)', function () {
        ptdAs($this)->postJson(route('panel.patients.medicalrecords.quick-actions.issue', [$this->a, $this->recordA, 'procedure-request']), [
            'items' => [['name' => 'Campimetria']], 'template_id' => $this->text,
        ])->assertUnprocessable()->assertJsonFragment(['template_id' => [__('clinical_documents.templates.base_mismatch')]]);
    });

    it('no editor, o modelo de texto do modal vira HTML (escapado, com quebras de linha)', function () {
        // Base do modelo do modal = modelo de receita da ação (da clínica).
        $base    = (string) DoctorDocumentTemplate::query()->withoutGlobalScopes()->findOrFail($this->text)->report_setting_content_id;
        $applied = ptdPreview($this, $this->b, $this->recordB, $base, ['template_id' => $this->text]);

        expect($applied)->toContain('Colírio &lt;Y&gt;<br />')->toContain('1 gota 4x/dia')->toContain('JOSÉ ANTÔNIO PEREIRA');
    });
});
