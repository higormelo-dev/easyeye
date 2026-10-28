<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use App\Domains\ClinicalReview\Services\ReviewInvitationService;
use Illuminate\Foundation\Http\FormRequest;

/** Criar um convite de revisão (e enviá-lo). O PIN é opcional, digitado ou gerado pelo sistema. */
class StoreClinicalReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reviewer_id'          => ['required', 'uuid'],
            'version_ids'          => ['required', 'array', 'min:1', 'max:20'],
            'version_ids.*'        => ['uuid', 'distinct'],
            'access_code'          => ['nullable', 'string', 'max:30'],
            'generate_access_code' => ['sometimes', 'boolean'],
            'expires_in_days'      => ['nullable', 'integer', 'min:1', 'max:' . ReviewInvitationService::MAX_EXPIRY_DAYS],
        ];
    }
}
