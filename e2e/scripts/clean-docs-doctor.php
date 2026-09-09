<?php
// Remove a paciente de demonstração do manual do médico (e tudo ligado a ela).
$peOld = App\Models\People::withTrashed()->where('full_name', 'MARIANA COSTA E SILVA')->first();
if ($peOld) {
    $patOld = App\Models\Patient::withTrashed()->where('person_id', $peOld->id)->first();
    if ($patOld) {
        // Query builder (não Eloquent) para ignorar o guard de is_locked do
        // Signable no delete — registro assinado no seed do laudo compartilhável.
        $recordIds = DB::table('medical_records')->where('patient_id', $patOld->id)->pluck('id');
        DB::table('medical_record_documentations')->whereIn('medical_record_id', $recordIds)->delete();
        DB::table('medical_record_files')->whereIn('medical_record_id', $recordIds)->delete();
        DB::table('medical_records')->where('patient_id', $patOld->id)->delete();
        DB::table('patient_exams')->where('patient_id', $patOld->id)->delete();
        DB::table('patient_document_shares')->where('patient_id', $patOld->id)->delete();
        DB::table('schedules')->where('patient_id', $patOld->id)->delete();
        $patOld->forceDelete();
    }
    // Portal do Paciente: remove a conta se o convite chegou a ser aceito
    // durante os testes (senão o próximo convite reporta "já possui conta").
    DB::table('patient_accounts')->where('person_id', $peOld->id)->delete();
    $peOld->forceDelete();
}
echo 'docsdoc-clean:ok;';
