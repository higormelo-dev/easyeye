<?php
// Limpeza total do fluxo CY-DOC (prontuários não assinados, agendamentos,
// paciente, pessoa).
$pe = App\Models\People::withTrashed()->where('full_name', 'CY-DOC PACIENTE')->first();
if ($pe) {
    $pat = App\Models\Patient::withTrashed()->where('person_id', $pe->id)->first();
    if ($pat) {
        foreach (App\Models\MedicalRecord::withTrashed()->where('patient_id', $pat->id)->get() as $r) {
            DB::table('medical_record_documentations')->where('medical_record_id', $r->id)->delete();
            DB::table('medical_record_files')->where('medical_record_id', $r->id)->delete();
            $r->forceDelete();
        }
        DB::table('schedules')->where('patient_id', $pat->id)->delete();
        $pat->forceDelete();
    }
    $pe->forceDelete();
}
echo 'limpo';
