<x-crud-modal id="settingModal" :title="$titleController">
    <x-slot:body>
        @include('system.settings.form')
    </x-slot:body>
</x-crud-modal>
