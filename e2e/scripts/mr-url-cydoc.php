<?php

use App\Models\{MedicalRecord, Patient};

// Resolve a URL de edição do prontuário mais recente do paciente CY-DOC.
// Uso: php artisan tinker --execute="require 'e2e/scripts/mr-url-cydoc.php';"

$p = Patient::whereHas('person', fn ($q) => $q->where('full_name', 'CY-DOC PACIENTE'))->firstOrFail();
$m = MedicalRecord::where('patient_id', $p->id)->latest('created_at')->firstOrFail();

echo 'mr:/panel/patients/' . $p->id . '/medicalrecords/' . $m->id . '/edit';
