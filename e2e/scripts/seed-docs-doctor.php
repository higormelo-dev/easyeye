<?php

use App\Enums\DocumentationType;
use App\Models\{Covenant, Doctor, Entity, EntityUser, ExamType, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, Schedule};

// Seed para as CAPTURAS do manual do médico: paciente com nome apresentável
// + agendamento de hoje com a Dra. Ana pronto para "Iniciar atendimento" +
// e-mail (Portal do Paciente) + prontuário ASSINADO com 1 documentação
// (Compartilhar laudo) + 2 exames de imagem (Comparar / Compartilhar exame).
// Idempotente; limpeza: clean-docs-doctor.php.

$ent = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$ana = Doctor::whereHas('person', fn ($q) => $q->where('email', 'dra.ana@clinicateste.com'))->firstOrFail();
$anaEntityUser = EntityUser::findOrFail($ana->entity_user_id);

require __DIR__ . '/clean-docs-doctor.php';

$pe = People::create([
    'full_name' => 'MARIANA COSTA E SILVA',
    'cellphone' => '11998761234',
    'email'     => 'mariana.silva@easyeye-demo.test',
]);
$cov = Covenant::where('entity_id', $ent->id)->first() ?? Covenant::firstOrFail();
$pat = Patient::create([
    'entity_id'   => $ent->id, 'person_id' => $pe->id,
    'covenant_id' => $cov->id, 'active' => true,
]);

// Slot livre pra Dra. Ana (mesma trava de seed-cydoc.php: unique
// doctor_id+date_time colide com a agenda demo nos minutos redondos).
$slot = now()->startOfMinute();

for ($i = 0; $i < 30; $i++) {
    $busy = DB::table('schedules')->where('doctor_id', $ana->id)
        ->where('date_time', $slot->format('Y-m-d H:i:s'))
        ->whereNull('deleted_at')->where('active', true)->exists();

    if (! $busy) {
        break;
    }
    $slot = $slot->copy()->addMinute();
}
$sch = Schedule::create([
    'entity_id' => $ent->id, 'doctor_id' => $ana->id, 'patient_id' => $pat->id,
    'full_name' => 'MARIANA COSTA E SILVA', 'date_time' => $slot,
    'situation' => 2, 'active' => true,
]);

// Prontuário assinado + 1 documentação — habilita "Compartilhar laudo com
// o paciente" (o toggle exige medicalRecord->isSigned()). SEM schedule_id
// de propósito: prontuário walk-in, desacoplado do agendamento de hoje —
// senão o botão "Iniciar atendimento" (usado pelo manual/testes de agenda)
// reabriria este registro JÁ ASSINADO/BLOQUEADO em vez de criar um novo.
$record = MedicalRecord::create([
    'entity_id'      => $ent->id,
    'patient_id'     => $pat->id,
    'doctor_id'      => $ana->id,
    'main_complaint' => 'Consulta de rotina — sem queixas agudas.',
]);
$record->sign($anaEntityUser);

$doc = MedicalRecordDocumentation::create([
    'medical_record_id' => $record->id,
    'patient_id'         => $pat->id,
    'doctor_id'          => $ana->id,
    'type'               => DocumentationType::Certificate,
    'title'              => 'Atestado Médico',
    'content'            => '<p>CY-DEMO: atestado de comparecimento — 1 dia.</p>',
]);

// 2 exames de imagem (reaproveita o arquivo de um exame já existente no
// disco — evita depender de upload real só para a demonstração).
$src = PatientExam::whereNotNull('archive')->latest()->first();
$examType = ExamType::first();

if ($src) {
    // "Hoje" (horas atrás) para caírem no filtro de período padrão
    // ("Hoje") do Gerenciador de Imagens sem precisar trocar o SearchSelect.
    foreach ([1 => 2, 2 => 5] as $laterality => $hoursAgo) {
        PatientExam::create([
            'patient_id'        => $pat->id,
            'exam_id'           => $examType?->id,
            'archive'           => $src->archive,
            'display_archive'   => $src->display_archive,
            'thumb_archive'     => $src->thumb_archive,
            'laterality'        => $laterality,
            'name'              => 'Retinografia',
            'active'            => true,
            'exam_performed_at' => now()->subHours($hoursAgo),
        ]);
    }
}

echo 'docsdoc:', $sch->id, ':doc:', $doc->id;
