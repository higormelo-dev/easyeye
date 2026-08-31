<?php
// Limpeza total dos dados CY-ADM do spec clinic.admin.
// forceDelete em tudo (delete da UI é soft e as listagens são withTrashed).
// SEMPRE escopado à entidade de teste — nunca varre outras clínicas.

$ent = App\Models\Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$eid = $ent->id;

// ── Catálogos genéricos (10) — nome LIKE CY-ADM% na entidade ────────────────
foreach ([
    App\Models\VisitType::class, App\Models\SurgeryType::class,
    App\Models\SkinType::class, App\Models\AdditionType::class,
    App\Models\ColorVisionType::class, App\Models\CoverTestType::class,
    App\Models\IrisType::class, App\Models\Lense::class,
    App\Models\NearPointConvergence::class, App\Models\VisualAcuityType::class,
] as $model) {
    $model::withTrashed()->where('entity_id', $eid)->where('name', 'like', 'CY-ADM%')->forceDelete();
}

// ── Convênios de teste (nome vira MAIÚSCULAS no backend) + preços ───────────
$covIds = App\Models\Covenant::withTrashed()->where('entity_id', $eid)
    ->where('name', 'like', 'CY-ADM%')->pluck('id');
if ($covIds->isNotEmpty()) {
    App\Models\ProcedurePrice::withTrashed()->where('entity_id', $eid)
        ->whereIn('covenant_id', $covIds)->forceDelete();
    App\Models\Covenant::withTrashed()->whereIn('id', $covIds)->forceDelete();
}

// ── Recursos/salas + escala e bloqueios filhos ──────────────────────────────
$resIds = App\Models\ClinicResource::withTrashed()->where('entity_id', $eid)
    ->where('name', 'like', 'CY-ADM%')->pluck('id');
if ($resIds->isNotEmpty()) {
    DB::table('resource_work_schedules')->whereIn('resource_id', $resIds)->delete();
    DB::table('resource_blocks')->whereIn('resource_id', $resIds)->delete();
    App\Models\ClinicResource::withTrashed()->whereIn('id', $resIds)->forceDelete();
}

// ── Lentes IOL da entidade + modelo global criado pelo form livre ───────────
App\Models\EntityIolLens::withTrashed()->where('entity_id', $eid)
    ->where(fn ($q) => $q->where('manufacturer', 'like', 'CY-ADM%')->orWhere('model_name', 'like', 'CY-ADM%'))
    ->forceDelete();
App\Models\IolLensModel::where('manufacturer', 'like', 'CY-ADM%')->delete();

// ── Usuários CY-ADM (EntityUser soft + User) e perfis RBAC ──────────────────
$uIds = App\Models\User::where('email', 'like', 'cy-adm%')->pluck('id');
if ($uIds->isNotEmpty()) {
    App\Models\EntityUser::withTrashed()->whereIn('user_id', $uIds)->forceDelete();
    App\Models\User::whereIn('id', $uIds)->forceDelete();
}
App\Models\Role::withTrashed()->where('entity_id', $eid)->where('name', 'like', 'CY-ADM%')->forceDelete();

// ── Financeiro: lançamentos, fechamentos e glosas de teste ──────────────────
App\Models\FinancialCashEntry::withTrashed()->where('entity_id', $eid)
    ->where('description', 'like', 'CY-ADM%')->forceDelete();
App\Models\CashClose::withTrashed()->where('entity_id', $eid)
    ->where('notes', 'like', 'CY-ADM%')->forceDelete();
$glosaIds = App\Domains\Tiss\Models\TissGlosa::withTrashed()->where('entity_id', $eid)
    ->where('glosa_description', 'like', 'CY-ADM%')->pluck('id');
if ($glosaIds->isNotEmpty()) {
    App\Domains\Tiss\Models\TissGlosaAppeal::whereIn('glosa_id', $glosaIds)->delete();
    App\Domains\Tiss\Models\TissGlosa::withTrashed()->whereIn('id', $glosaIds)->forceDelete();
}

// ── Médico CY-ADM criado via UI (People/User/EntityUser/Doctor + filhos) ────
$drPeople = App\Models\People::withTrashed()->where('full_name', 'like', 'CY-ADM%')->pluck('id');
if ($drPeople->isNotEmpty()) {
    $drIds = App\Models\Doctor::withTrashed()->whereIn('person_id', $drPeople)->pluck('id');
    if ($drIds->isNotEmpty()) {
        DB::table('doctor_work_schedules')->whereIn('doctor_id', $drIds)->delete();
        DB::table('schedule_blocks')->whereIn('doctor_id', $drIds)->delete();
        DB::table('schedules')->whereIn('doctor_id', $drIds)->delete();
        App\Models\Doctor::withTrashed()->whereIn('id', $drIds)->forceDelete();
    }
}

// ── Paciente CY-ADM + agendamentos/fila ligados ─────────────────────────────
$pePat = App\Models\People::withTrashed()->where('full_name', 'like', 'CY-ADM%')->pluck('id');
if ($pePat->isNotEmpty()) {
    $patIds = App\Models\Patient::withTrashed()->whereIn('person_id', $pePat)->pluck('id');
    if ($patIds->isNotEmpty()) {
        DB::table('patient_exams')->whereIn('patient_id', $patIds)->delete();
        DB::table('schedules')->whereIn('patient_id', $patIds)->delete();
        App\Models\Patient::withTrashed()->whereIn('id', $patIds)->forceDelete();
    }
    App\Models\People::withTrashed()->whereIn('id', $pePat)->forceDelete();
}
App\Models\WaitingList::where('entity_id', $eid)->where('full_name', 'like', 'CY-ADM%')->delete();

// ── Mural, eventos de agenda, compras de crédito IA de teste ────────────────
App\Models\Notice::where('entity_id', $eid)->where('content', 'like', 'CY-ADM%')->delete();
App\Models\ScheduleEvent::where('entity_id', $eid)->where('title', 'like', 'CY-ADM%')->delete();

// ── Higiene de conta do admin de teste ──────────────────────────────────────
// Visitar o setup de 2FA grava secret pendente; garantir 2FA desativado e a
// entidade sem obrigatoriedade (enabled_at) para não travar os outros specs.
$admin = App\Models\User::where('email', 'admin@clinicateste.com')->first();
if ($admin) {
    $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $admin->name === 'CY-ADM RENOMEADO' && $admin->forceFill(['name' => mb_strtoupper('Admin Clínica')])->save();
    App\Models\UserPreference::where('user_id', $admin->id)->delete();
}
$ent->requires_two_factor && $ent->forceFill(['requires_two_factor' => false])->save();

echo 'cyadm-clean:ok';
