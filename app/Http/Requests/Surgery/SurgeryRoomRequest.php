<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Models\Surgery\SurgeryRoom;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SurgeryRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [
                $req, 'string', 'min:1', 'max:100',
                // Nome único por local sem diferenciar maiúsculas (o índice do banco
                // também vale: a corrida entre duas requisições cai em SurgeryFacilityService).
                function (string $attribute, mixed $value, Closure $fail): void {
                    $taken = SurgeryRoom::query()
                        ->where('surgery_facility_id', $this->facilityId())
                        ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                        ->when($this->route('room'), fn ($q, $room) => $q->whereKeyNot($room->getKey()))
                        ->exists();

                    if ($taken) {
                        $fail((string) __('surgery.validation.room_name_taken'));
                    }
                },
            ],
            'clinic_resource_id' => ['nullable', 'uuid'],
            'active'             => ['sometimes', 'boolean'],
        ];
    }

    private function facilityId(): ?string
    {
        $facility = $this->route('facility');

        return $facility?->getKey() ?? $this->route('room')?->surgery_facility_id;
    }
}
