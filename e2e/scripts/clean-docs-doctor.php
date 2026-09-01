<?php
// Remove a paciente de demonstração do manual do médico (e tudo ligado a ela).
$peOld = App\Models\People::withTrashed()->where('full_name', 'MARIANA COSTA E SILVA')->first();
if ($peOld) {
    $patOld = App\Models\Patient::withTrashed()->where('person_id', $peOld->id)->first();
    if ($patOld) {
        foreach (App\Models\MedicalRecord::withTrashed()->where('patient_id', $patOld->id)->get() as $r) {
            DB::table('medical_record_documentations')->where('medical_record_id', $r->id)->delete();
            DB::table('medical_record_files')->where('medical_record_id', $r->id)->delete();
            $r->forceDelete();
        }
        DB::table('schedules')->where('patient_id', $patOld->id)->delete();
        $patOld->forceDelete();
    }
    $peOld->forceDelete();
}
echo 'docsdoc-clean:ok;';
