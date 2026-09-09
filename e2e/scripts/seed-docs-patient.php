<?php

use App\Models\{Doctor, Entity, EntityUser, MedicalRecord, MedicalRecordDocumentation, MedicalRecordFile, Patient, PatientAccount, PatientExam, People};
use App\Services\PatientDocumentShareService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Seed para o manual e os testes do PORTAL DO PACIENTE: reaproveita o mesmo
// fixture do manual do médico (MARIANA COSTA E SILVA — prontuário assinado +
// 1 documentação + 2 exames de imagem) e adiciona:
//   1) um anexo (arquivo real na disk 'private', pra mostrar os 3 tipos de
//      documento compartilhável — laudo/exame/anexo — no portal);
//   2) a PatientAccount (login do paciente);
//   3) os 3 PatientDocumentShare (laudo + exame + anexo), via o MESMO
//      service usado pelo staff — sem atalho de INSERT direto.
// Idempotente; limpeza: clean-docs-patient.php (que já chama clean-docs-doctor.php).

require __DIR__ . '/seed-docs-doctor.php';

$ent = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$ana = Doctor::whereHas('person', fn ($q) => $q->where('email', 'dra.ana@clinicateste.com'))->firstOrFail();
$anaEntityUser = EntityUser::findOrFail($ana->entity_user_id);

$pe  = People::where('full_name', 'MARIANA COSTA E SILVA')->firstOrFail();
$pat = Patient::where('entity_id', $ent->id)->where('person_id', $pe->id)->firstOrFail();
$doc = MedicalRecordDocumentation::where('patient_id', $pat->id)->latest('created_at')->firstOrFail();
$record = MedicalRecord::findOrFail($doc->medical_record_id);
$exams  = PatientExam::where('patient_id', $pat->id)->orderBy('laterality')->get();

// Anexo real na disk 'private' (mesmo padrão de MedicalRecordFilesController::store) —
// PNG 1x1 mínimo, só pra existir de verdade e a pré-visualização de imagem funcionar.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
$filePath = "medical-records/{$record->id}/" . Str::uuid() . '.png';
Storage::disk('private')->put($filePath, $png);

$file = MedicalRecordFile::create([
    'medical_record_id' => $record->id,
    'patient_id'         => $pat->id,
    'file_path'          => $filePath,
    'original_name'      => 'retinografia-anexo-demo.png',
    'mime_type'           => 'image/png',
    'file_size'           => strlen($png),
]);

// PatientAccount (login do portal) — mesmo e-mail já usado pelo fixture do
// médico. active=true + email_verified_at preenchido: pula o fluxo de
// convite/aceite pra login direto nas capturas (o fluxo de convite em si já
// é demonstrado a partir de um convite NOVO, sem conta, nos testes/manuais
// dos perfis clínicos — aqui simulamos a conta JÁ criada).
// email_verified_at/active ficam fora do $fillable de propósito (só
// InvitationController::store pode setá-los no fluxo real) — forceFill
// aqui replica o MESMO padrão pra não depender de mass assignment.
$account = PatientAccount::updateOrCreate(
    ['person_id' => $pe->id],
    ['email' => $pe->email, 'password' => 'PortalPaciente@123'],
);
$account->forceFill(['email_verified_at' => now(), 'active' => true])->save();

// Compartilhamentos — mesmo service que o staff usa (respeita a regra de
// laudo assinado, idempotente se já compartilhado).
$service = app(PatientDocumentShareService::class);
$service->grant($anaEntityUser, $doc, $pat);
if ($exams->isNotEmpty()) {
    $service->grant($anaEntityUser, $exams->first(), $pat);
}
$service->grant($anaEntityUser, $file, $pat);

echo 'docspat:', $account->id, ':file:', $file->id;
