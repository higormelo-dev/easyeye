<?php

use App\Domains\AI\Services\{AiProviderSettings, SpeechTranscriptionService};
use App\Enums\DraftStatus;
use App\Models\{Covenant, Doctor, Entity, EntityUser, ExamType, EyeImageReportDraft, Patient, PatientExam, People, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

// Seed do fluxo de LAUDOS (Fase 5, spec clinic.reports — contrato no topo do spec):
//  - paciente CY-REPORTS PACIENTE com dois exames de imagem ATIVOS (OD e OE) em FIXTURE_DATE;
//  - um rascunho de laudo SUBMETIDO, criado pela secretária e endereçado à Dra. Ana, também em FIXTURE_DATE;
//  - ditado disponível: o Mistral entra entre os provedores habilitados no Manager (só preenche vaga;
//    o original fica no estado e o clean devolve).
// FIXTURE_DATE = 2026-10-01 10:00:00 America/Sao_Paulo (13:00:00 UTC): created_at e exam_performed_at dos
// exames e do rascunho são gravados EXATAMENTE nesse instante (o spec visita o período explícito 2026-10-01).
// Segurança: guard de ambiente/banco; por padrão nada vai ao bucket remoto (imagens no disco local dedicado
// storage/app/e2e-eye-images); SÓ com E2E_EXAM_DISK=s3 explícito no ambiente do comando as imagens vão ao disco
// "s3" que a tela serve, sob e2e/eye-images/<run-id>/, com cada caminho registrado no estado (o clean apaga só os
// registrados, nunca por prefixo); o vínculo da secretária é só VALIDADO (não é alterado); tudo o que cria fica
// registrado em storage/app/e2e-eye-images-state.json e o clean apaga por esses ids + entity_id. Cada registro do
// banco (paciente+pessoa, tipo de exame, cada exame, rascunho) nasce com o id no estado NA MESMA TRANSAÇÃO
// (eiRecord): falha ao gravar o estado = rollback do registro + exit 1.
// FALHA (exit 1, mensagem em stderr) em qualquer pré-condição não atendida ou se o PNG não ficar legível.
// A médica e a secretária são as do cenário padrão do e2e (IntegratorTestClinicSeeder).
//
// Uso: php artisan tinker --execute="require 'e2e/scripts/seed-eye-images.php';"
$eiSeedPreclean = true; // 1ª execução: sem estado = nada a limpar (o clean avulso, sem estado, aborta)
require base_path('e2e/scripts/clean-eye-images.php'); // guard + limpeza de execução anterior (pelo estado)

// Restos de versões antigas (sem estado) NÃO são apagados por nome: aborta e pede a limpeza legada explícita.
$stale = Patient::withTrashed()->whereHas('person', fn ($q) => $q->withTrashed()->where('full_name', 'CY-REPORTS PACIENTE'))->exists();

if ($stale) {
    eiFail('existe paciente CY-REPORTS PACIENTE sem estado correspondente (resto de versão antiga): rode antes a limpeza legada explícita — E2E_LEGACY_CLEAN=1 php artisan tinker --execute="require \'e2e/scripts/clean-eye-images.php\';".');
}

$fixture = CarbonImmutable::parse('2026-10-01 10:00:00', 'America/Sao_Paulo')->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');

$ent = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->first() ?? eiFail('clínica "TESTE INTEGRADOR" não existe neste banco (rode o IntegratorTestClinicSeeder).');
$ana = Doctor::whereHas('person', fn ($q) => $q->where('email', 'dra.ana@clinicateste.com'))
    ->whereHas('entityUser', fn ($q) => $q->where('entity_id', $ent->id))->first() ?? eiFail('Dra. Ana (dra.ana@clinicateste.com) não existe na clínica de teste.');

// Pré-condição (sem alterar nada): a secretária tem de estar ATIVA e como secretária nesta clínica.
$secretary     = User::where('email', 'secretaria@clinicateste.com')->first() ?? eiFail('usuária secretaria@clinicateste.com não existe.');
$secretaryLink = EntityUser::query()->where('entity_id', $ent->id)->where('user_id', $secretary->id)->first();

if (! $secretaryLink || ! $secretaryLink->active || $secretaryLink->rule !== 'secretary') {
    eiFail('o vínculo de secretaria@clinicateste.com na clínica de teste não está ativo como "secretary" — o seed não altera perfis; corrija o cenário padrão do e2e.');
}

eiSaveState(['entity_id' => $ent->id]);

// Cada registro do banco nasce JUNTO com o id dele no estado, na mesma transação (eiRecord): se a gravação do
// estado falhar, o registro é desfeito e o seed aborta — nunca sobra linha que o clean não saiba apagar.
$pat = eiRecord(
    'o paciente',
    function () use ($ent) {
        $pe = People::create(['full_name' => 'CY-REPORTS PACIENTE', 'cellphone' => '11955552222']);

        return Patient::create(['entity_id' => $ent->id, 'person_id' => $pe->id, 'covenant_id' => Covenant::first()?->id, 'active' => true]);
    },
    fn (Patient $pat) => ['patient_id' => $pat->id, 'person_id' => $pat->person_id],
);

$existingType = ExamType::query()->where('entity_id', $ent->id)->where('name', 'CY-REPORTS RETINOGRAFIA')->first();

if ($existingType) {
    $type = $existingType;
    eiSaveState(['exam_type_id' => $type->id, 'exam_type_created' => false]); // não é nosso: o clean não o apaga
} else {
    $type = eiRecord(
        'o tipo de exame',
        fn () => ExamType::create(['entity_id' => $ent->id, 'name' => 'CY-REPORTS RETINOGRAFIA', 'category' => 0, 'active' => true]),
        fn (ExamType $type) => ['exam_type_id' => $type->id, 'exam_type_created' => true],
    );
}

// PNG 1x1: tem de ficar LEGÍVEL (nada de put silencioso). Disco: o LOCAL dedicado (padrão) ou, só com
// E2E_EXAM_DISK=s3 explícito no ambiente do comando, o disco "s3" que a tela serve, sob
// e2e/eye-images/<run-id>/. Cada caminho é registrado no estado ANTES de gravar (o clean apaga só eles).
$png      = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$diskName = eiRequestedDisk();
$disk     = eiDisk($diskName);
$runId    = strtolower(Str::random(10));
$prefix   = $diskName === 's3' ? "e2e/eye-images/{$runId}" : 'cy-reports';
$files    = [];
$paths    = [];
eiSaveState(['disk' => $diskName, 'run_id' => $runId]);

foreach ([[1, 'OD'], [2, 'OE']] as [$laterality, $label]) {
    $path = "{$prefix}/{$pat->id}-{$label}.png";

    $files[] = ['disk' => $diskName, 'path' => $path];
    eiSaveState(['files' => $files]);

    try {
        $written  = $disk->put($path, $png); // o disco s3 deste projeto tem throw=false: devolve false em falha
        $readBack = $disk->get($path);
    } catch (Throwable $e) {
        eiFail("não foi possível gravar/ler o PNG {$path} no disco {$diskName} (" . class_basename($e) . ': ' . $e->getMessage() . ').');
    }

    if ($written === false || ! is_string($readBack) || $readBack !== $png || ! str_starts_with($readBack, "\x89PNG")) {
        eiFail("o PNG {$path} gravado no disco {$diskName} não ficou legível (conteúdo diferente do gravado ou put recusado).");
    }

    $paths[$label] = $path;
}

$examIds = [];

foreach ([[1, 'OD', $paths['OD']], [2, 'OE', $paths['OE']]] as [$laterality, $label, $path]) {
    $exam = eiRecord(
        "o exame {$label}",
        function () use ($pat, $type, $path, $label, $laterality, $fixture) {
            $exam = PatientExam::create([
                'patient_id'        => $pat->id,
                'exam_id'           => $type->id,
                'archive'           => $path,
                'name'              => "CY-REPORTS {$label}",
                'laterality'        => $laterality,
                'active'            => true,
                'exam_performed_at' => $fixture,
            ]);
            // created_at explícito (o Eloquent grava "agora").
            DB::table('patient_exams')->where('id', $exam->id)->update(['created_at' => $fixture, 'updated_at' => $fixture, 'exam_performed_at' => $fixture]);

            return $exam;
        },
        fn (PatientExam $exam) => ['exam_ids' => [...$examIds, (string) $exam->id]],
    );
    $examIds[] = (string) $exam->id;
}

$draft = eiRecord(
    'o rascunho',
    function () use ($ent, $pat, $examIds, $ana, $secretary, $fixture) {
        $draft = EyeImageReportDraft::create([
            'entity_id'          => $ent->id,
            'patient_id'         => $pat->id,
            'exam_ids'           => $examIds,
            'resolved_doctor_id' => $ana->id,
            'title'              => 'Laudo CY-REPORTS',
            'content'            => '<p>Retinografia sem alterações relevantes em ambos os olhos.</p>',
            'status'             => DraftStatus::Submitted,
        ]);
        // created_by não é preenchível em massa e o tinker não tem usuário logado.
        DB::table('eye_image_report_drafts')->where('id', $draft->id)->update([
            'created_by' => $secretary->id, 'updated_by' => $secretary->id,
            'created_at' => $fixture, 'updated_at' => $fixture, 'submitted_at' => $fixture,
        ]);

        return $draft;
    },
    fn (EyeImageReportDraft $draft) => ['draft_id' => $draft->id],
);

// Ditado: habilita o Mistral no Manager (só em vaga livre ou trocando o adjudicador; o original fica no estado).
$settings = app(AiProviderSettings::class);
$roles    = $settings->roleAssignments();

if (! in_array('mistral', array_filter($roles), true)) {
    eiSaveState(['roles_before' => $roles]);
    $slot         = array_search(null, $roles, true) ?: 'adjudicator';
    $roles[$slot] = 'mistral';
    $settings->setRoleAssignments($roles);
}

$warnings = [];

if (! config('ai.transcription.enabled')) {
    $warnings[] = 'AI_TRANSCRIPTION_ENABLED=true no .env do servidor (config:clear depois)';
}

if (blank(config('services.mistral.api_key'))) {
    $warnings[] = 'MISTRAL_API_KEY no .env (qualquer valor serve: o áudio do spec é interceptado e nunca sai)';
}

if ($warnings === [] && ! app(SpeechTranscriptionService::class)->isAvailable()) {
    $warnings[] = 'o ditado não ficou disponível (SpeechTranscriptionService::resolve() = null): confira AI_TRANSCRIPTION_PROVIDER=mistral e AI_MISTRAL_BASE_URL';
}

// A tela serve as imagens dos exames SÓ pelo disco "s3" (URL temporária).
if ($diskName === 's3') {
    $warnings[] = "imagens gravadas no disco s3 em e2e/eye-images/{$runId}/ (2 caminhos registrados no estado; o clean apaga só esses)";
} else {
    $warnings[] = "imagens no disco LOCAL dedicado (storage/app/e2e-eye-images): a tela NÃO vai carregá-las (ela serve exames pelo disco \"s3\"); no dev atual rode com E2E_EXAM_DISK=s3 explícito no ambiente do comando: E2E_EXAM_DISK=s3 php artisan tinker --execute=\"require 'e2e/scripts/seed-eye-images.php';\"";
}

$stored = DB::table('patient_exams')->where('patient_id', $pat->id)->pluck('created_at', 'name')->all();
echo "seed-eye-images ok: paciente {$pat->id}, rascunho {$draft->id} (submitted, Dra. Ana); FIXTURE_DATE gravada como {$fixture} (" . config('app.timezone') . '): ' . json_encode($stored) . "\n";

foreach ($warnings as $warning) {
    echo "AVISO: {$warning}\n";
}
