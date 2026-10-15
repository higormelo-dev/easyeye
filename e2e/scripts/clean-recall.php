<?php

use App\Models\Entity;
use App\Models\{MedicalRecord, Patient, People};
use App\Models\WhatsApp\WhatsAppSetting;

// Limpeza do fluxo CY-RCL (Fase 6): paciente, prontuário, condições, retornos, convites, consentimento,
// modelos de orientação e adoção do protocolo feita pelo spec. NÃO desfaz o protocolo liberado nem a feature
// do plano (são catálogo/plano, inofensivos); o canal de convite mock é removido.
$ent = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->first();

if ($ent) {
    DB::table('recall_clinic_protocols')->where('entity_id', $ent->id)->delete();
    DB::table('care_guidance_templates')->where('entity_id', $ent->id)->where('title', 'like', 'CY-RCL%')->delete();
    WhatsAppSetting::query()->withoutGlobalScopes()->where('entity_id', $ent->id)->where('app_id', 'like', 'recall-app-%')->delete();
}

$pe = People::withTrashed()->where('full_name', 'CY-RCL PACIENTE')->first();

if ($pe) {
    $pat = Patient::withTrashed()->where('person_id', $pe->id)->first();

    if ($pat) {
        // Filhos antes dos pais (ordem das chaves estrangeiras).
        if (Schema::hasTable('recall_invite_items') && Schema::hasTable('recall_invites')) {
            DB::table('recall_invite_items')
                ->whereIn('recall_invite_id', DB::table('recall_invites')->where('patient_id', $pat->id)->select('id'))
                ->delete();
        }

        foreach ([
            'whatsapp_messages', 'patient_recall_events', 'patient_recall_blocks', 'recall_invites', 'patient_recalls',
            'patient_conditions', 'intravitreal_applications', 'intravitreal_courses', 'care_guidance_issues',
            'patient_consents', 'recall_dirty_patients',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'patient_id')) {
                DB::table($table)->where('patient_id', $pat->id)->delete();
            }
        }

        foreach (MedicalRecord::withTrashed()->where('patient_id', $pat->id)->get() as $r) {
            DB::table('medical_record_documentations')->where('medical_record_id', $r->id)->delete();
            DB::table('medical_record_files')->where('medical_record_id', $r->id)->delete();
            $r->forceDelete();
        }
        DB::table('schedules')->where('patient_id', $pat->id)->delete();
        $pat->forceDelete();
    }
    $pe->forceDelete();
}
