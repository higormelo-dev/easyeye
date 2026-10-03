<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegratorQueueHealthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pending_count'       => ['required', 'integer', 'min:0'],
            'failed_count'        => ['required', 'integer', 'min:0'],
            'blocked_count'       => ['required', 'integer', 'min:0'],
            'sent_last_24h_count' => ['required', 'integer', 'min:0'],

            'operational'                                  => ['nullable', 'array:version,scope,heartbeat_at,capabilities,watcher_state,mwl_state,ingest_pending,quarantined,acquisition_rejected,unconfirmed_sent,originals_pending_remote_archive,oldest_pending_at,disk_free_bytes,devices,next_action', 'required_array_keys:version,scope,ingest_pending,quarantined,acquisition_rejected,devices,next_action'],
            'operational.version'                          => ['required_with:operational', 'string', 'max:32'],
            'operational.scope'                            => ['required_with:operational', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'operational.heartbeat_at'                     => ['nullable', 'date'],
            'operational.capabilities'                     => ['nullable', 'array:folder_capture,dicom_storage,dicom_mwl,ocr,rpa', 'required_array_keys:folder_capture,dicom_storage,dicom_mwl,ocr,rpa'],
            'operational.capabilities.folder_capture'      => ['required_with:operational.capabilities', 'boolean:strict'],
            'operational.capabilities.dicom_storage'       => ['required_with:operational.capabilities', 'boolean:strict'],
            'operational.capabilities.dicom_mwl'           => ['required_with:operational.capabilities', 'boolean:strict'],
            'operational.capabilities.ocr'                 => ['required_with:operational.capabilities', 'boolean:strict'],
            'operational.capabilities.rpa'                 => ['required_with:operational.capabilities', 'boolean:strict'],
            'operational.watcher_state'                    => ['nullable', Rule::in(['unknown', 'active', 'inactive'])],
            'operational.mwl_state'                        => ['nullable', Rule::in(['unknown', 'active', 'inactive'])],
            'operational.ingest_pending'                   => ['required_with:operational', 'integer', 'between:0,4294967295'],
            'operational.quarantined'                      => ['required_with:operational', 'integer', 'between:0,4294967295'],
            'operational.acquisition_rejected'             => ['required_with:operational', 'integer', 'between:0,4294967295'],
            'operational.unconfirmed_sent'                 => ['nullable', 'integer', 'between:0,4294967295'],
            'operational.originals_pending_remote_archive' => ['nullable', 'integer', 'between:0,4294967295'],
            'operational.oldest_pending_at'                => ['nullable', 'date'],
            'operational.disk_free_bytes'                  => ['nullable', 'integer', 'min:0'],
            'operational.devices'                          => [Rule::when($this->input('operational') !== null, ['present']), 'array', 'max:100'],
            'operational.devices.*'                        => ['array:equipment_id,remote_equipment_id,last_observed_at,last_accepted_at,rejected_count,issue'],
            'operational.devices.*.equipment_id'           => ['required', 'integer', 'min:1', 'distinct'],
            'operational.devices.*.remote_equipment_id'    => ['nullable', 'uuid', Rule::exists('entity_integrator_equipments', 'id')->where('integrator_id', $this->attributes->get('integrator')?->id)],
            'operational.devices.*.last_observed_at'       => ['nullable', 'date'],
            'operational.devices.*.last_accepted_at'       => ['nullable', 'date'],
            'operational.devices.*.rejected_count'         => ['required', 'integer', 'between:0,4294967295'],
            'operational.devices.*.issue'                  => ['nullable', Rule::in(['dicom_invalid_or_unavailable', 'source_or_spool_unavailable', 'upload_size_exceeded', 'unsupported_file_type'])],
            'operational.next_action'                      => ['required_with:operational', Rule::in([
                'verificar gravação do spool; journal preservado',
                'verificar recusas de aquisição por aparelho',
                'verificar aparelho/pasta se aquisições esperadas não chegarem',
                'confirmar recebimento remoto dos exames sem recibo',
                'preservar originais locais; arquivamento remoto pendente',
            ])],

            // O cliente já limita isto antes de sincronizar — o teto aqui
            // é defesa em profundidade, não o mecanismo principal de
            // controle de tamanho.
            'problems'                       => ['present', 'array', 'max:50'],
            'problems.*.id'                  => ['required', 'integer'],
            'problems.*.file_name'           => ['required', 'string', 'max:255'],
            'problems.*.status'              => ['required', 'string', Rule::in(['failed', 'blocked'])],
            'problems.*.schedule_identifier' => ['nullable', 'string', 'max:32'],
            'problems.*.patient_identifier'  => ['nullable', 'string', 'max:64'],
            'problems.*.last_error'          => ['nullable', 'string', 'max:500'],
            'problems.*.attempts'            => ['required', 'integer', 'min:0'],
            'problems.*.api_status'          => ['nullable', 'integer'],
            'problems.*.updated_at'          => ['required', 'date'],
        ];
    }
}
