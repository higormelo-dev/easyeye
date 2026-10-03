<?php

namespace App\Http\Requests\Api;

use App\Models\IntegratorCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegratorCommandAckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $command = IntegratorCommand::where('integrator_id', $this->attributes->get('integrator')?->id)->findOrFail($this->route('command'));

        if ($command->status !== 'pending') {
            return ['status' => ['required', Rule::in(['completed', 'failed'])]];
        }
        $keys = match($command->type) {
            'resync_now' => ['scanned_folders', 'queued_files', 'sent', 'failed_or_blocked'],'reload_config' => ['reloaded', 'semantics'],'run_diagnostics' => ['schema_version', 'app_version', 'pending', 'failed', 'blocked', 'capabilities', 'watcher_state', 'mwl_state', 'disk_free_bytes', 'ingest_pending', 'quarantined', 'rejected', 'devices', 'configuration_generation', 'total_devices', 'devices_truncated'],default => [],
        };

        if ($this->input('status') === 'failed') {
            $keys = ['error_code', 'error'];
        }
        $rules = ['status' => ['required', Rule::in(['completed', 'failed'])], 'result' => ['nullable', 'array:' . implode(',', $keys)], 'result.error_code' => ['nullable', 'string', Rule::in(['diagnostics_failed', 'resync_failed', 'reload_failed', 'unsupported_command', 'scope_changed', 'command_expired', 'command_failed'])], 'result.error' => ['nullable', 'string', 'max:500'], 'result.app_version' => ['nullable', 'string', 'max:32'], 'result.semantics' => ['nullable', Rule::in(['configuration_read_on_use'])], 'result.reloaded' => ['nullable', 'boolean:strict'], 'result.schema_version' => ['nullable', 'integer', 'in:1'], 'result.capabilities' => ['nullable', 'array:pdf,rpa,dicom,emr'], 'result.devices' => ['nullable', 'array', 'max:100'], 'result.devices.*' => ['array:equipment_id,remote_equipment_id,last_observed_at,last_accepted_at,rejected_count,issue,issue_code'], 'result.devices.*.equipment_id' => ['required', 'integer', 'min:1'], 'result.devices.*.remote_equipment_id' => ['nullable', 'uuid'], 'result.devices.*.last_observed_at' => ['nullable', 'date'], 'result.devices.*.last_accepted_at' => ['nullable', 'date'], 'result.devices.*.rejected_count' => ['nullable', 'integer', 'between:0,4294967295'], 'result.devices.*.issue' => ['nullable', Rule::in(['dicom_invalid_or_unavailable', 'source_or_spool_unavailable', 'upload_size_exceeded', 'unsupported_file_type', 'format_incomplete', 'file_not_ready', 'pdf_tools_unavailable', 'pdf_parse_failed', 'pdf_identity_conflict', 'pdf_resource_limit', 'emr_invalid_structure', 'emr_duplicate_field', 'emr_unsupported_field', 'emr_encoding_invalid'])]];

        foreach (['pdf', 'rpa', 'dicom', 'emr'] as $field) {
            $rules['result.capabilities.' . $field] = ['nullable', 'boolean:strict'];
        }

        foreach (['watcher_state', 'mwl_state'] as $field) {
            $rules['result.' . $field] = ['nullable', Rule::in(['unknown', 'active', 'inactive'])];
        }

        foreach (['scanned_folders', 'queued_files', 'sent', 'failed_or_blocked', 'pending', 'failed', 'blocked', 'disk_free_bytes', 'ingest_pending', 'quarantined', 'rejected', 'configuration_generation'] as $field) {
            $rules['result.' . $field] = ['nullable', 'integer', 'between:0,9223372036854775807'];
        }
        $rules['result.devices.*.issue_code'] = $rules['result.devices.*.issue'];
        $rules['result.total_devices']        = ['nullable', 'integer', 'between:0,4294967295'];
        $rules['result.devices_truncated']    = ['nullable', 'boolean:strict'];

        return $rules;
    }

    public function withValidator($v): void
    {
        $v->after(function ($v) {
            if (strlen($this->getContent()) > 16384) {
                $v->errors()->add('result', 'Resultado excede 16KiB.');
            }
        });
    }
}
