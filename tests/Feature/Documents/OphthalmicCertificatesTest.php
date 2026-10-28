<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{AuditLog, Cid10Code, Covenant, Entity, EntityUser, MedicalRecord, MedicalRecordDocumentation, Patient, People, ReportSetting, ReportSettingContent, User, VisualAcuityType};
use App\Services\TemplateVariableResolver;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};

/*
 * Fase 2 / B1 — atestados oftalmológicos (quick actions do prontuário):
 * acompanhante, comparecimento com horário/período e dilatação, atestado
 * médico com CID SÓ com autorização do paciente (Res. CFM 1.658/2002, art.
 * 5º) e aptidão visual com a AV puxada do PRONTUÁRIO. Autor = quem emite;
 * prontuário assinado barra; PDF com o conteúdo.
 */
beforeEach(function () {
    $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class, ReportSettingVariableSeeder::class]);

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true, 'city' => 'Brasília', 'state' => 'DF']);
    $this->docEu   = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor  = doctorRecordForEntityUser($this->docEu);
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create(['full_name' => 'MARIA DA SILVA'])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
    $this->record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'Baixa visual',
    ]);
});

function fb1As(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

function fb1Issue(object $test, string $action, array $payload = [], ?EntityUser $eu = null)
{
    return fb1As($test, $eu ?? $test->docEu)->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [$test->patient, $test->record, $action]),
        $payload,
    );
}

function fb1Acuity(string $name): VisualAcuityType
{
    return VisualAcuityType::query()->create(['entity_id' => null, 'name' => $name, 'scale' => 1]);
}

describe('atestado de acompanhante', function () {
    it('emite com nome, documento, data e horário; texto escapado e sem variável injetada', function () {
        fb1Issue($this, 'companion-certificate', [
            'companion_name'     => 'João <b>Souza</b> {{PACIENTE_CPF}}',
            'companion_document' => 'RG 12.345.678-9',
            'date'               => now()->toDateString(),
            'time_from'          => '08:10',
            'time_to'            => '09:45',
        ])->assertCreated()->assertJsonPath('title', 'Atestado de Acompanhante');

        $doc = MedicalRecordDocumentation::query()->firstOrFail();

        expect($doc->content)->toContain('João &lt;b&gt;Souza&lt;/b&gt;')
            ->and($doc->content)->not->toContain('<b>Souza</b>')
            ->and($doc->content)->toContain('Souza&lt;/b&gt; { {PACIENTE_CPF} }, portador(a) do documento RG 12.345.678-9, esteve nesta clínica')
            ->and($doc->content)->toContain('das 08:10 às 09:45')
            ->and($doc->content)->toContain('acompanhando o(a) paciente MARIA DA SILVA')
            ->and($doc->content)->toContain(now()->format('d/m/Y'))
            ->and($doc->content)->not->toContain('{{')
            ->and($doc->metadata)->toMatchArray([
                'companion_name' => 'João <b>Souza</b> {{PACIENTE_CPF}}', 'companion_document' => 'RG 12.345.678-9',
                'time_from'      => '08:10', 'time_to' => '09:45',
            ])
            ->and((string) $doc->doctor_id)->toBe((string) $this->doctor->id);
    });

    it('exige o nome do acompanhante e horário final depois do inicial', function () {
        fb1Issue($this, 'companion-certificate', ['time_from' => '10:00', 'time_to' => '09:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['companion_name', 'time_to']);

        fb1Issue($this, 'companion-certificate', ['companion_name' => 'Ana', 'time_from' => '10:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('time_to');

        expect(MedicalRecordDocumentation::query()->count())->toBe(0);
    });

    it('aceita período/turno no lugar do horário', function () {
        fb1Issue($this, 'companion-certificate', ['companion_name' => 'Ana Lima', 'period' => 'afternoon'])->assertCreated();

        expect(MedicalRecordDocumentation::query()->value('content'))->toContain('no período da tarde');
    });
});

describe('atestado de comparecimento', function () {
    it('horário de/até e orientação de dilatação com N horas por extenso', function () {
        fb1Issue($this, 'attendance-certificate', [
            'time_from' => '13:05', 'time_to' => '14:20', 'dilation' => true, 'dilation_hours' => 6,
        ])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->firstOrFail();

        expect($doc->content)->toContain('no dia ' . now()->format('d/m/Y') . ', das 13:05 às 14:20')
            ->and($doc->content)->toContain('não dirigir veículos nem operar máquinas pelas próximas 6 (seis) horas')
            ->and($doc->metadata)->toMatchArray(['dilation' => true, 'dilation_hours' => 6, 'time_from' => '13:05', 'time_to' => '14:20']);
    });

    it('1 hora no singular e feminino; dilatação exige as horas (1 a 24)', function () {
        fb1Issue($this, 'attendance-certificate', ['dilation' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('dilation_hours');
        fb1Issue($this, 'attendance-certificate', ['dilation' => true, 'dilation_hours' => 25])
            ->assertUnprocessable()->assertJsonValidationErrors('dilation_hours');

        fb1Issue($this, 'attendance-certificate', ['dilation' => true, 'dilation_hours' => 1])->assertCreated();

        expect(MedicalRecordDocumentation::query()->value('content'))->toContain('próximas 1 (uma) hora.');
    });

    it('sem horário nem dilatação mantém o texto antigo (nada de variável sobrando)', function () {
        fb1Issue($this, 'attendance-certificate')->assertCreated();

        $content = MedicalRecordDocumentation::query()->value('content');

        expect($content)->toContain('no dia ' . now()->format('d/m/Y') . ', para procedimentos')
            ->and($content)->not->toContain('dilatação')
            ->and($content)->not->toContain('{{');
    });

    it('modelo personalizado pela clínica SEM as variáveis novas ainda recebe horário e dilatação no fim', function () {
        // A clínica nasce com cópia dos modelos globais (EntityObserver); ela
        // personaliza a cópia dela — sem as variáveis novas.
        $content = ReportSettingContent::query()
            ->where('slug', 'comparecimento')
            ->whereHas('reportSetting', fn ($q) => $q->withoutGlobalScopes()->where('entity_id', $this->entity->id)->where('title', 'ATESTADO DE COMPARECIMENTO'))
            ->firstOrFail();
        $content->forceFill(['content' => '<p>Esteve aqui {{PACIENTE_NOME}} em {{DATA_ATUAL}}.</p>'])->save();

        fb1Issue($this, 'attendance-certificate', ['time_from' => '08:00', 'time_to' => '09:00', 'dilation' => true, 'dilation_hours' => 4])->assertCreated();

        expect(MedicalRecordDocumentation::query()->value('content'))
            ->toContain('Esteve aqui MARIA DA SILVA')
            ->toContain('Horário: das 08:00 às 09:00.')
            ->toContain('pelas próximas 4 (quatro) horas');
    });
});

describe('atestado médico com CID', function () {
    beforeEach(function () {
        // Catálogo completo da CID-10 vem da migration; garante o código.
        Cid10Code::query()->firstOrCreate(['code' => 'H10.0'], ['description' => 'Conjuntivite mucopurulenta', 'source' => Cid10Code::SOURCE_DATASUS]);
    });

    it('CID sem a autorização do paciente → 422 e nada emitido', function () {
        fb1Issue($this, 'medical-certificate', ['days' => 2, 'cid' => 'H10.0'])
            ->assertUnprocessable()->assertJsonValidationErrors('cid_authorized');
        fb1Issue($this, 'medical-certificate', ['days' => 2, 'cid' => 'H10.0', 'cid_authorized' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('cid_authorized');

        expect(MedicalRecordDocumentation::query()->count())->toBe(0);
    });

    it('CID autorizado sai no atestado com a concordância expressa, fica no metadata e na auditoria', function () {
        fb1Issue($this, 'medical-certificate', ['days' => 3, 'cid' => 'h10.0', 'cid_authorized' => true])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->firstOrFail();

        expect($doc->content)->toContain('CID-10: <strong>H10.0</strong>')
            ->and($doc->content)->toContain('autorização expressa do(a) paciente')
            ->and($doc->metadata['cid'])->toMatchArray(['code' => 'H10.0', 'authorized' => true, 'recorded_by' => (string) $this->docEu->user_id])
            ->and(AuditLog::query()->where('event', 'cid_authorized')->where('auditable_id', $doc->id)->value('new_values'))
            ->toMatchArray(['cid' => 'H10.0', 'authorized' => true]);
    });

    it('sem CID não pede autorização nem imprime CID; CID fora do catálogo → 422', function () {
        fb1Issue($this, 'medical-certificate', ['days' => 1])->assertCreated();
        expect(MedicalRecordDocumentation::query()->value('content'))->not->toContain('CID-10');

        fb1Issue($this, 'medical-certificate', ['days' => 1, 'cid' => 'U99.9', 'cid_authorized' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('cid');
    });
});

describe('atestado de aptidão visual', function () {
    it('puxa a AV sem/com correção do PRONTUÁRIO (não da tela) e imprime conclusão e finalidade', function () {
        $this->record->update([
            'visual_acuity_without_correction_right_id' => fb1Acuity('20/40')->id,
            'visual_acuity_without_correction_left_id'  => fb1Acuity('20/50')->id,
            'visual_acuity_with_correction_right_id'    => fb1Acuity('20/20')->id,
        ]);

        fb1Issue($this, 'visual-fitness-certificate', [
            'conclusion' => 'fit_with_correction', 'purpose' => 'public_exam',
            // A tela não manda AV; se mandar, é ignorada. "20/15" nunca é data (mês 15): "20/10" colidia com a emissão em 20/10.
            'visual_acuity' => ['with' => ['right' => '20/15']],
        ])->assertCreated()->assertJsonPath('title', 'Atestado de Aptidão Visual');

        $doc = MedicalRecordDocumentation::query()->firstOrFail();

        expect($doc->content)->toContain('para fins de concurso público')
            // Data do EXAME = a do prontuário (não a do papel).
            ->and($doc->content)->toContain('exame oftalmológico em ' . $this->record->created_at->format('d/m/Y'))
            ->and($doc->content)->toContain('20/40')->toContain('20/50')->toContain('20/20')
            ->and($doc->content)->not->toContain('20/15')
            ->and($doc->content)->toContain('APTO(A) COM USO DE CORREÇÃO ÓPTICA')
            ->and($doc->metadata['visual_acuity'])->toBe([
                'without' => ['right' => '20/40', 'left' => '20/50'],
                'with'    => ['right' => '20/20', 'left' => null],
            ]);
    });

    it('finalidade "outra" exige o texto; conclusão e finalidade fora da lista → 422', function () {
        $this->record->update(['visual_acuity_with_correction_left_id' => fb1Acuity('20/25')->id]);

        fb1Issue($this, 'visual-fitness-certificate', ['conclusion' => 'fit', 'purpose' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('purpose_other');
        fb1Issue($this, 'visual-fitness-certificate', ['conclusion' => 'talvez', 'purpose' => 'moto'])
            ->assertUnprocessable()->assertJsonValidationErrors(['conclusion', 'purpose']);

        fb1Issue($this, 'visual-fitness-certificate', ['conclusion' => 'unfit', 'purpose' => 'other', 'purpose_other' => 'Habilitação náutica'])->assertCreated();
        expect(MedicalRecordDocumentation::query()->value('content'))->toContain('para fins de Habilitação náutica')->toContain('INAPTO(A)');
    });

    it('com data alternativa, a data do EXAME continua a do prontuário', function () {
        $this->record->update(['visual_acuity_with_correction_right_id' => fb1Acuity('20/20')->id]);
        $printed = now()->subDays(3);

        fb1Issue($this, 'visual-fitness-certificate', ['conclusion' => 'fit', 'purpose' => 'work', 'document_date' => $printed->toDateString()])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->firstOrFail();
        expect($doc->content)->toContain('exame oftalmológico em ' . $this->record->created_at->format('d/m/Y'))
            ->and($doc->document_date->toDateString())->toBe($printed->toDateString());
    });

    it('sem AV registrada no prontuário não emite', function () {
        fb1Issue($this, 'visual-fitness-certificate', ['conclusion' => 'fit', 'purpose' => 'work'])
            ->assertUnprocessable()->assertJsonValidationErrors('conclusion');

        expect(MedicalRecordDocumentation::query()->count())->toBe(0);
    });
});

describe('regras comuns dos atestados', function () {
    it('autor = médico que emite, não o dono do prontuário', function () {
        $otherEu = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
        $other   = doctorRecordForEntityUser($otherEu);

        fb1Issue($this, 'companion-certificate', ['companion_name' => 'Pedro Alves'], $otherEu)->assertCreated();

        expect((string) MedicalRecordDocumentation::query()->value('doctor_id'))->toBe((string) $other->id);
    });

    it('prontuário assinado barra todos os atestados novos', function () {
        $this->record->forceFill(['is_locked' => true])->saveQuietly();

        fb1Issue($this, 'companion-certificate', ['companion_name' => 'Pedro Alves'])->assertForbidden();
        fb1Issue($this, 'attendance-certificate', ['dilation' => true, 'dilation_hours' => 4])->assertForbidden();

        expect(MedicalRecordDocumentation::query()->count())->toBe(0);
    });

    it('secretária não emite atestado (Gate IssueReport)', function () {
        $secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);

        fb1Issue($this, 'companion-certificate', ['companion_name' => 'Pedro Alves'], $secretary)->assertForbidden();
    });

    it('PDF do atestado de acompanhante mostra o texto, o paciente e a assinatura de quem emitiu', function () {
        fb1Issue($this, 'companion-certificate', ['companion_name' => 'Pedro Alves', 'period' => 'morning'])->assertCreated();
        $doc = MedicalRecordDocumentation::query()->firstOrFail()->load(['patient.person', 'doctor.person', 'medicalRecord']);

        $setting = ReportSetting::query()->whereNull('entity_id')->where('title', 'ATESTADO DE ACOMPANHANTE')->firstOrFail();
        $html    = view('pdf.documentation', ['doc' => $doc, 'setting' => $setting, 'examSummary' => []])->render();

        expect($html)->toContain('Atestado de Acompanhante')
            ->toContain('Pedro Alves')
            ->toContain('no período da manhã')
            ->toContain('MARIA DA SILVA')
            ->toContain(e($doc->doctor->person->full_name));
    });
});

it('toda variável dos modelos de atestado/pedido está no catálogo de variáveis do resolver', function () {
    $listed = collect(app(TemplateVariableResolver::class)->getAvailableVariables())->flatten(1)->pluck('placeholder');

    $used = ReportSettingContent::query()
        ->whereHas('reportSetting', fn ($q) => $q->withoutGlobalScopes()->whereNull('entity_id')->whereIn('title', [
            'ATESTADO DE COMPARECIMENTO', 'ATESTADO MÉDICO', 'ATESTADO DE ACOMPANHANTE', 'ATESTADO DE APTIDÃO VISUAL', 'SOLICITAÇÃO DE PROCEDIMENTOS',
        ]))
        ->pluck('content')
        ->flatMap(fn ($content) => preg_match_all('/\{\{[A-Z_]+\}\}/', (string) $content, $m) ? $m[0] : [])
        ->unique()
        // Preenchidas na emissão pelo próprio fluxo antigo (dias do atestado).
        ->reject(fn ($p) => in_array($p, ['{{DIAS_AFASTAMENTO}}', '{{DIAS_AFASTAMENTO_EXTENSO}}'], true));

    expect($used->diff($listed)->values()->all())->toBe([]);
});
