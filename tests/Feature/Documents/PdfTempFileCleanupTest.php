<?php

declare(strict_types=1);

/**
 * generateDocumentation() limpa o rodapé temporário mesmo quando a
 * geração do PDF falha — achado via auditoria de encerramento, EE-10: o
 * unlink só rodava DEPOIS do inline() ter sucesso, sem finally. Um
 * wkhtmltopdf travado ou com erro deixava o .html (com endereço/telefone
 * da clínica) pra trás pra sempre.
 */

use App\Enums\{ClientRule, DocumentationType};
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, People, ReportSetting, User};
use App\Services\MedicalRecordPdfService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $this->doctor     = Doctor::create([
        'entity_user_id' => $this->entityUser->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
    $this->record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'x',
    ]);

    // show_footer tem default true na migration — basta existir, ativo.
    ReportSetting::create([
        'entity_id'   => $this->entity->id,
        'title'       => 'Padrão',
        'active'      => true,
        'footer_text' => 'Clínica Teste',
    ]);

    $this->doc = MedicalRecordDocumentation::create([
        'medical_record_id' => $this->record->id,
        'patient_id'        => $this->patient->id,
        'doctor_id'         => $this->doctor->id,
        'type'              => DocumentationType::Prescription,
        'title'             => 'Receituário',
        'content'           => '<p>Colírio X — 1 gota 3x ao dia.</p>',
    ]);
});

function eeDocFooterTempFiles(): array
{
    return glob(sys_get_temp_dir() . '/eeye_doc_ftr_*') ?: [];
}

it('geração normal com rodapé limpa o temporário depois do sucesso (sem regressão)', function () {
    SnappyPdf::fake();

    $before = eeDocFooterTempFiles();
    app(MedicalRecordPdfService::class)->generateDocumentation($this->doc->fresh());

    expect(eeDocFooterTempFiles())->toBe($before);
});

it('[GAP] exceção durante a geração ainda limpa o temporário do rodapé — finally (EE-10)', function () {
    // Dublê que falha só no passo final (inline/download) — tudo antes
    // (setPaper/setOption/loadView) precisa continuar encadeando normalmente.
    $throwing = new class() {
        public function __call(string $name, array $arguments): mixed
        {
            if (in_array($name, ['inline', 'download'], true)) {
                throw new RuntimeException('Falha simulada do wkhtmltopdf.');
            }

            return $this;
        }
    };
    SnappyPdf::swap($throwing);

    $before = eeDocFooterTempFiles();

    expect(fn () => app(MedicalRecordPdfService::class)->generateDocumentation($this->doc->fresh()))
        ->toThrow(RuntimeException::class, 'Falha simulada do wkhtmltopdf.');

    expect(eeDocFooterTempFiles())->toBe($before);
});
