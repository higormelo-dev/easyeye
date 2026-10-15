<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use App\Domains\ClinicalReview\Models\ClinicalReviewer;
use App\Domains\ClinicalReview\Support\ReviewerIdentity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro/edição de Revisor Clínico. Os números e o e-mail são comparados NORMALIZADOS
 * (ReviewerIdentity): "12.345", "012345" e "12345" são o mesmo CRM — duplicidade é checada
 * depois da normalização, não sobre o texto digitado.
 */
class ClinicalReviewerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'min:3', 'max:160'],
            'email'      => ['required', 'string', 'email:rfc', 'max:190'],
            'crm_number' => ['required', 'string', 'regex:/^[\d.\-\s]{1,15}$/', 'max:15'],
            'crm_uf'     => ['required', 'string', Rule::in(ReviewerIdentity::UFS)],
            'rqe'        => ['required', 'string', 'regex:/^[\d.\-\s]{1,15}$/', 'max:15'],
            'specialty'  => ['nullable', 'string', 'max:80'],
            'phone'      => ['nullable', 'string', 'regex:/^[\d()+\-\s]{8,20}$/'],
            'notes'      => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $data    = ReviewerIdentity::normalize($this->validated());
                $current = $this->route('reviewer');

                if ($data['crm_number'] === '' || $data['rqe'] === '') {
                    $validator->errors()->add($data['crm_number'] === '' ? 'crm_number' : 'rqe', __('manager_reviews.validation.number_required'));

                    return;
                }

                $others = ClinicalReviewer::query()->when($current, fn ($query) => $query->where('id', '!=', $current));

                if ((clone $others)->whereRaw('lower(email) = ?', [$data['email']])->exists()) {
                    $validator->errors()->add('email', __('manager_reviews.validation.email_taken'));
                }

                if ((clone $others)->where('crm_uf', $data['crm_uf'])->where('crm_number', $data['crm_number'])->exists()) {
                    $validator->errors()->add('crm_number', __('manager_reviews.validation.crm_taken'));
                }
            },
        ];
    }
}
