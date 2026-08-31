<?php

// Resolve a URL de edição do prontuário mais recente do paciente CY-DOC.
// Uso: php artisan tinker --execute="require 'e2e/scripts/mr-url-cydoc.php';"

$p = \App\Models\Patient::whereHas('person', fn ($q) => $q->where('full_name', 'CY-DOC PACIENTE'))->firstOrFail();
$m = \App\Models\MedicalRecord::where('patient_id', $p->id)->latest('created_at')->firstOrFail();

echo 'mr:/panel/patients/' . $p->id . '/medicalrecords/' . $m->id . '/edit';
