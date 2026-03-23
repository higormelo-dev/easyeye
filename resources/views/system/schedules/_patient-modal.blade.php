<div x-data="crudForm({
    storeUrl:  '{{ route('panel.patients.store') }}',
    updateUrl: '{{ route('panel.patients.index') }}',
    fields: {
        covenant_id: '', card_number: '', skin_id: '', iris_id: '',
        active: true, name: '', nickname: '', national_registry: '',
        birth_date: '', gender: '', marital_status: '', email: '',
        mother_name: '', father_name: '', state_registry: '',
        state_registry_agency: '', state_registry_initial: '',
        state_registry_date: '', telephone: '', cellphone: '',
        whatsapp: false, zipcode: '', address: '', number: '',
        complement: '', district: '', city: '', state: '', country: ''
    }
})"
     @edit-patient.window="loadAndEdit(
         '{{ url('panel/patients') }}/' + $event.detail.id + '/edit-data',
         '{{ url('panel/patients') }}/' + $event.detail.id,
         'patientModal'
     )">

    @include('system.patients._form-modal', [
        'covenants'       => $patientCovenants,
        'skinTypes'       => $skinTypes,
        'irisTypes'       => $irisTypes,
        'genders'         => $genders,
        'maritalStatuses' => $maritalStatuses,
        'statesOfBrazil'  => $statesOfBrazil,
    ])

</div>
