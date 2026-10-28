<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ExamSource;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\{Rule, Validator};

/**
 * Payload de "Importar exame externo" (Gerenciador de exames): upload
 * manual de N arquivos de exame para um paciente, sem passar pelo
 * integrador/equipamento. Autorização (Gate EntityGate::ImportExternalExam)
 * é feita no controller — mesmo padrão de ExamDiagnosisController.
 *
 * Todo id relacional (patient_id, exam_id, doctor_id,
 * entity_integrator_equipment_id) é validado com escopo de
 * session('selected_entity_id') — nunca um `exists` cru — para não confiar
 * em id vindo do form sem escopo de entidade (multi-tenant).
 */
class ImportExternalExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');

        return [
            'patient_id' => [
                'required',
                'uuid',
                Rule::exists('patients', 'id')->where(
                    fn ($query) => $query->where('entity_id', $entityId)->whereNull('deleted_at'),
                ),
            ],
            // Tipo de exame: catálogo da entity OU template global (entity_id
            // null) — mesmo critério de PatientExamRequest::exam_identifier /
            // PatientExamService::examFindByIdOrCode.
            'exam_id' => [
                'required',
                'uuid',
                Rule::exists('exam_types', 'id')->where(
                    fn ($query) => $query->where(fn ($w) => $w->where('entity_id', $entityId)->orWhereNull('entity_id'))
                        ->whereNull('deleted_at'),
                ),
            ],
            'exam_performed_at' => ['required', 'date', 'before_or_equal:today'],
            // Lateralidade: 1=OD, 2=OE, null/ausente=AO — mesmo shape/regra de
            // Api\ExamRequest e Api\PatientExamRequest (não há enum dedicado,
            // é convenção de inteiro replicada nos 3 pontos de entrada de
            // PatientExam::laterality).
            'laterality' => ['nullable', 'integer', 'in:0,1,2'],
            // Olho POR ARQUIVO (Fase 5 / 7b) — índice-alinhado com `files`
            // (ImportExternalExamModal.vue::syncFormFiles()). Opcional: ausente
            // ou null num índice cai no `laterality` único acima (upload manual
            // simples, sem pedal, continua mandando só o campo de sempre). Chave
            // separada de 'files.*' de propósito — nunca reaproveitar o mesmo
            // namespace de 'files', que já tem regra explícita documentada
            // acima sobre substituir (não somar) o wildcard.
            'files_laterality'   => ['nullable', 'array'],
            'files_laterality.*' => ['nullable', 'integer', 'in:0,1,2'],
            // doctor_id referencia a tabela `doctors` (PatientExam::doctor()),
            // não `users` — escopado via doctors.entity_user_id -> entity_users.entity_id.
            'doctor_id' => [
                'nullable',
                'uuid',
                Rule::exists('doctors', 'id')->where(
                    fn ($query) => $query->whereNull('deleted_at')
                        ->whereIn('entity_user_id', function ($sub) use ($entityId) {
                            $sub->select('id')->from('entity_users')->where('entity_id', $entityId);
                        }),
                ),
            ],
            // Equipamento real (opcional) — só preenchido quando o usuário souber
            // qual equipamento cadastrado gerou o arquivo. Escopo via
            // integrator_id -> entity_integrators.entity_user_integrator_id ->
            // entity_user_integrators.entity_id (mesma cadeia percorrida por
            // ExamManagerController::availableEquipments() via integrator.user).
            // Note: entity_user_integrators é a tabela de "usuário de API do
            // integrador", distinta de entity_users (usuários do painel).
            'entity_integrator_equipment_id' => [
                'nullable',
                'uuid',
                Rule::exists('entity_integrator_equipments', 'id')->where(
                    fn ($query) => $query->whereNull('deleted_at')
                        ->whereIn('integrator_id', function ($sub) use ($entityId) {
                            $sub->select('entity_integrators.id')
                                ->from('entity_integrators')
                                ->whereIn('entity_user_integrator_id', function ($sub2) use ($entityId) {
                                    $sub2->select('id')->from('entity_user_integrators')->where('entity_id', $entityId);
                                });
                        }),
                ),
            ],
            'external_origin' => ['nullable', 'string', 'max:255'],
            'files'           => ['required', 'array', 'min:1', 'max:10'],
            // 'max:' fica de fora do array — teto diferente por tipo (ver
            // closure abaixo): imagem/PDF continuam em 10 MB (comportamento
            // de sempre), webm/mp4 (câmera do navegador) usam o teto
            // configurável de vídeo, bem maior. NUNCA declarar 'files.0',
            // 'files.1'... como chave própria aqui — isso SUBSTITUI (não
            // soma) as regras do wildcard 'files.*' pra aquele índice,
            // perdendo file/mimes silenciosamente (achado nesta rodada: o
            // teste do .exe passava a aceitar o arquivo depois disso).
            'files.*' => [
                'file',
                'mimes:jpg,jpeg,png,pdf,webm,mp4',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    // guessExtension() (baseado no MIME de verdade — getMimeType(),
                    // o mesmo que validateMimes() usa pra regra "mimes:" acima)
                    // nunca getClientOriginalExtension() (nome do arquivo, 100%
                    // controlado pelo cliente): uma imagem/PDF renomeado pra
                    // .mp4/.webm passava "mimes:" (conteúdo real já é um tipo
                    // permitido) mas usava o teto de VÍDEO (bem maior) em vez do
                    // teto de 10 MB de imagem/PDF — achado via revisão Codex.
                    $isVideo = in_array(strtolower((string) $value->guessExtension()), ['webm', 'mp4'], true);
                    $maxKb   = $isVideo ? (int) config('eye_image_capture.capture.max_video_kb') : 10240;

                    if ($value->getSize() > $maxKb * 1024) {
                        $fail(__('eye_images.import_file_too_large', ['max_mb' => round($maxKb / 1024, 1)]));
                    }
                },
            ],
            // Origem POR ARQUIVO, índice-alinhada com `files` (como files_laterality):
            // 'external_import' (upload manual) ou 'browser_capture' (foto/vídeo
            // pela câmera) — nunca 'integrator', reservado ao pipeline real do
            // equipamento. Lote misto (upload + câmera) tem origem diferente em
            // cada arquivo; entrada ausente num índice vale 'external_import'
            // (fora do repasse): na dúvida, nunca promove um arquivo a captura
            // da clínica. Entradas além do último arquivo são ignoradas.
            'files_source'   => ['nullable', 'array'],
            'files_source.*' => ['nullable', Rule::in([ExamSource::ExternalImport->value, ExamSource::BrowserCapture->value])],
            // Campo ÚNICO do lote, de versões antigas do formulário: marcava TODOS
            // os arquivos como captura se UM só viesse da câmera (o scan externo
            // virava produção pagável). Recusa em vez de adivinhar a origem de
            // cada arquivo — o formulário recarregado manda `files_source`.
            // (Vazio passa: o formulário antigo mandava `source=''` em upload puro.)
            'source' => ['prohibited'],
            // Mesmo shape/regra de UpdateExamDiagnosisRequest — mantém consistência
            // entre "Diagnóstico do exame" e "Importar exame externo".
            'diagnoses'        => ['nullable', 'array', 'max:20'],
            'diagnoses.*.code' => ['nullable', 'string', 'max:10'],
            // Escopado por entity_id — mesmo motivo/padrão de UpdateExamDiagnosisRequest.
            'diagnoses.*.custom_diagnosis_id' => [
                'nullable',
                'uuid',
                Rule::exists('entity_custom_diagnoses', 'id')->where(
                    fn ($query) => $query->where('entity_id', $entityId)->whereNull('deleted_at'),
                ),
            ],
            'diagnoses.*.description' => ['required', 'string', 'max:500'],
            'diagnoses.*.is_primary'  => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source.prohibited' => __('eye_images.import_source_outdated'),
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('diagnoses', []) as $index => $item) {
                $code   = is_array($item) ? ($item['code'] ?? null) : null;
                $custom = is_array($item) ? ($item['custom_diagnosis_id'] ?? null) : null;

                if (blank($code) && blank($custom)) {
                    $validator->errors()->add(
                        "diagnoses.{$index}",
                        'Informe o código CID-10 ou um diagnóstico customizado.',
                    );
                }
            }
        });
    }
}
