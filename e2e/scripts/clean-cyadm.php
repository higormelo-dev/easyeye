<?php

use App\Domains\Tiss\Models\{TissGlosa, TissGlosaAppeal};
use App\Models\{AdditionType, CashClose, ClinicResource, ColorVisionType, Covenant, CoverTestType, Doctor, Entity, EntityIolLens, EntityUser, FinancialCashEntry, IolLensModel, IrisType, Lense, NearPointConvergence, Notice, Patient, People, ProcedurePrice, Role, ScheduleEvent, SkinType, SurgeryType, User, UserPreference, VisitType, VisualAcuityType, WaitingList};

// Limpeza total dos dados CY-ADM do spec clinic.admin.
// forceDelete em tudo (delete da UI é soft e as listagens são withTrashed).
// SEMPRE escopado à entidade de teste — nunca varre outras clínicas.

$ent = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$eid = $ent->id;

// ── Catálogos genéricos (10) — nome LIKE CY-ADM% na entidade ────────────────
foreach ([
    VisitType::class, SurgeryType::class,
    SkinType::class, AdditionType::class,
    ColorVisionType::class, CoverTestType::class,
    IrisType::class, Lense::class,
    NearPointConvergence::class, VisualAcuityType::class,
] as $model) {
    $model::withTrashed()->where('entity_id', $eid)->where('name', 'like', 'CY-ADM%')->forceDelete();
}

// ── Convênios de teste (nome vira MAIÚSCULAS no backend) + preços ───────────
$covIds = Covenant::withTrashed()->where('entity_id', $eid)
    ->where('name', 'like', 'CY-ADM%')->pluck('id');

if ($covIds->isNotEmpty()) {
    ProcedurePrice::withTrashed()->where('entity_id', $eid)
        ->whereIn('covenant_id', $covIds)->forceDelete();
    Covenant::withTrashed()->whereIn('id', $covIds)->forceDelete();
}

// ── Recursos/salas + escala e bloqueios filhos ──────────────────────────────
$resIds = ClinicResource::withTrashed()->where('entity_id', $eid)
    ->where('name', 'like', 'CY-ADM%')->pluck('id');

if ($resIds->isNotEmpty()) {
    DB::table('resource_work_schedules')->whereIn('resource_id', $resIds)->delete();
    DB::table('resource_blocks')->whereIn('resource_id', $resIds)->delete();
    ClinicResource::withTrashed()->whereIn('id', $resIds)->forceDelete();
}

// ── Lentes IOL da entidade + modelo global criado pelo form livre ───────────
EntityIolLens::withTrashed()->where('entity_id', $eid)
    ->where(fn ($q) => $q->where('manufacturer', 'like', 'CY-ADM%')->orWhere('model_name', 'like', 'CY-ADM%'))
    ->forceDelete();
IolLensModel::where('manufacturer', 'like', 'CY-ADM%')->delete();

// ── Usuários CY-ADM (EntityUser soft + User) e perfis RBAC ──────────────────
$uIds = User::where('email', 'like', 'cy-adm%')->pluck('id');

if ($uIds->isNotEmpty()) {
    EntityUser::withTrashed()->whereIn('user_id', $uIds)->forceDelete();
    User::whereIn('id', $uIds)->forceDelete();
}
Role::withTrashed()->where('entity_id', $eid)->where('name', 'like', 'CY-ADM%')->forceDelete();

// ── Financeiro: lançamentos, fechamentos e glosas de teste ──────────────────
FinancialCashEntry::withTrashed()->where('entity_id', $eid)
    ->where('description', 'like', 'CY-ADM%')->forceDelete();
CashClose::withTrashed()->where('entity_id', $eid)
    ->where('notes', 'like', 'CY-ADM%')->forceDelete();
$glosaIds = TissGlosa::withTrashed()->where('entity_id', $eid)
    ->where('glosa_description', 'like', 'CY-ADM%')->pluck('id');

if ($glosaIds->isNotEmpty()) {
    TissGlosaAppeal::whereIn('glosa_id', $glosaIds)->delete();
    TissGlosa::withTrashed()->whereIn('id', $glosaIds)->forceDelete();
}

// ── Médico CY-ADM criado via UI (People/User/EntityUser/Doctor + filhos) ────
$drPeople = People::withTrashed()->where('full_name', 'like', 'CY-ADM%')->pluck('id');

if ($drPeople->isNotEmpty()) {
    $drIds = Doctor::withTrashed()->whereIn('person_id', $drPeople)->pluck('id');

    if ($drIds->isNotEmpty()) {
        DB::table('doctor_work_schedules')->whereIn('doctor_id', $drIds)->delete();
        DB::table('schedule_blocks')->whereIn('doctor_id', $drIds)->delete();
        DB::table('schedules')->whereIn('doctor_id', $drIds)->delete();
        Doctor::withTrashed()->whereIn('id', $drIds)->forceDelete();
    }
}

// ── Paciente CY-ADM + agendamentos/fila ligados ─────────────────────────────
$pePat = People::withTrashed()->where('full_name', 'like', 'CY-ADM%')->pluck('id');

if ($pePat->isNotEmpty()) {
    $patIds = Patient::withTrashed()->whereIn('person_id', $pePat)->pluck('id');

    if ($patIds->isNotEmpty()) {
        DB::table('patient_exams')->whereIn('patient_id', $patIds)->delete();
        DB::table('schedules')->whereIn('patient_id', $patIds)->delete();
        Patient::withTrashed()->whereIn('id', $patIds)->forceDelete();
    }
    People::withTrashed()->whereIn('id', $pePat)->forceDelete();
}
WaitingList::where('entity_id', $eid)->where('full_name', 'like', 'CY-ADM%')->delete();

// ── Mural, eventos de agenda, compras de crédito IA de teste ────────────────
Notice::where('entity_id', $eid)->where('content', 'like', 'CY-ADM%')->delete();
ScheduleEvent::where('entity_id', $eid)->where('title', 'like', 'CY-ADM%')->delete();

// ── Higiene de conta do admin de teste ──────────────────────────────────────
// Visitar o setup de 2FA grava secret pendente; garantir 2FA desativado e a
// entidade sem obrigatoriedade (enabled_at) para não travar os outros specs.
$admin = User::where('email', 'admin@clinicateste.com')->first();

if ($admin) {
    $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $admin->name === 'CY-ADM RENOMEADO' && $admin->forceFill(['name' => mb_strtoupper('Admin Clínica')])->save();
    UserPreference::where('user_id', $admin->id)->delete();
}
$ent->requires_two_factor && $ent->forceFill(['requires_two_factor' => false])->save();

echo 'cyadm-clean:ok';
