<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\{MedicalRecord, User};
use App\Services\{DayExtensionService, ProcedureSolicitationService};
use Carbon\{CarbonImmutable, CarbonInterface};
use Illuminate\Validation\ValidationException;

/**
 * Atestados oftalmológicos (Fase 2 / B1) — monta as variáveis dos modelos e os
 * dados estruturados (metadata) de cada atestado. O TEXTO do documento é em
 * português, como os modelos do seeder (documento brasileiro); a tela é
 * traduzida.
 *
 * Variáveis de BLOCO (parágrafo inteiro) têm texto de reserva: se a clínica
 * personalizou o modelo e tirou a variável, o conteúdo vai no fim do
 * documento — CID autorizado, orientação de dilatação e horário nunca somem.
 */
class CertificateBuilder
{
    /** @var array<string, string> período/turno do comparecimento */
    public const PERIODS = [
        'morning'   => 'no período da manhã',
        'afternoon' => 'no período da tarde',
        'evening'   => 'no período da noite',
        'full_day'  => 'em período integral',
    ];

    /** @var array<string, string> conclusão da aptidão visual */
    public const CONCLUSIONS = [
        'fit'                 => 'APTO(A)',
        'fit_with_correction' => 'APTO(A) COM USO DE CORREÇÃO ÓPTICA (óculos ou lentes de contato)',
        'unfit'               => 'INAPTO(A)',
    ];

    /** @var array<string, string> finalidade da aptidão visual */
    public const PURPOSES = [
        'work'        => 'trabalho',
        'sport'       => 'prática esportiva',
        'public_exam' => 'concurso público',
        'other'       => 'outra',
    ];

    public function __construct(
        private readonly DayExtensionService $dayExtension,
        private readonly ProcedureSolicitationService $solicitation,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * `fallbacks`: texto que vai no fim do documento quando o modelo
     * (personalizado pela clínica) não tem a variável.
     *
     * @return array{replacements: array<string, string>, fallbacks: array<string, string>, metadata: array<string, mixed>}
     */
    public function build(string $action, array $payload, MedicalRecord $record, CarbonInterface $documentDay): array
    {
        return match ($action) {
            'medical-certificate'        => $this->medical($payload, $documentDay),
            'attendance-certificate'     => $this->attendance($payload, $documentDay),
            'companion-certificate'      => $this->companion($payload, $documentDay),
            'visual-fitness-certificate' => $this->visualFitness($payload, $record),
            default                      => ['replacements' => [], 'fallbacks' => [], 'metadata' => []],
        };
    }

    /**
     * Atestado médico (afastamento) com CID OPCIONAL: só sai com a
     * autorização do paciente registrada (Res. CFM 1.658/2002, art. 5º — a
     * concordância deve constar no atestado).
     *
     * @param array<string, mixed> $payload
     */
    private function medical(array $payload, CarbonInterface $documentDay): array
    {
        $days  = max(1, (int) ($payload['days'] ?? 1));
        $start = trim((string) ($payload['date'] ?? ''));
        $start = $start !== '' ? $start : $documentDay->format('d/m/Y');

        $metadata = ['days' => $days, 'start_date' => $start];
        $cidBlock = '';

        $cidCode = strtoupper(trim((string) ($payload['cid'] ?? '')));

        if ($cidCode !== '') {
            // Defesa extra (o controller já exige): sem autorização, sem CID.
            if (! filter_var($payload['cid_authorized'] ?? false, FILTER_VALIDATE_BOOL)) {
                throw ValidationException::withMessages(['cid_authorized' => __('clinical_documents.certificates.cid_authorization_required')]);
            }

            try {
                $cid = $this->solicitation->resolveCids([$cidCode])[0];
            } catch (ValidationException) {
                throw ValidationException::withMessages(['cid' => __('clinical_documents.request.cid_invalid', ['code' => $cidCode])]);
            }

            $user = auth()->user();

            $cidBlock = '<p>CID-10: <strong>' . e($cid['code']) . '</strong> — incluído com autorização expressa do(a) paciente '
                . '(Res. CFM nº 1.658/2002, art. 5º).</p>';

            $metadata['cid'] = [
                'code'        => $cid['code'],
                'description' => $cid['description'],
                'authorized'  => true,
                'recorded_by' => $user instanceof User ? (string) $user->getKey() : null,
                'recorded_at' => now()->toIso8601String(),
            ];
        }

        return [
            'replacements' => [
                '{{DIAS_AFASTAMENTO}}'         => (string) $days,
                '{{DIAS_AFASTAMENTO_EXTENSO}}' => $this->dayExtension->spell($days),
                '{{DATA_ATUAL}}'               => $start,
                '{{CID_ATESTADO}}'             => $cidBlock,
            ],
            'fallbacks' => ['{{CID_ATESTADO}}' => $cidBlock],
            'metadata'  => $metadata,
        ];
    }

    /**
     * Comparecimento com horário (de/até) ou período, e orientação de
     * dilatação pupilar (não dirigir/operar máquinas por N horas).
     *
     * @param array<string, mixed> $payload
     */
    private function attendance(array $payload, CarbonInterface $documentDay): array
    {
        [$date, $schedule, $times] = $this->visit($payload, $documentDay);

        $dilation = filter_var($payload['dilation'] ?? false, FILTER_VALIDATE_BOOL);
        $hours    = $dilation ? max(1, (int) ($payload['dilation_hours'] ?? config('clinical_documents.dilation_hours.default', 4))) : null;

        $dilationBlock = $dilation
            ? '<p>Paciente submetido(a) a dilatação pupilar (midríase) nesta data: recomenda-se não dirigir veículos nem operar máquinas pelas próximas '
                . $hours . ' (' . $this->feminine($this->dayExtension->spell((int) $hours)) . ') ' . ($hours === 1 ? 'hora' : 'horas') . '.</p>'
            : '';

        return [
            'replacements' => [
                '{{DATA_COMPARECIMENTO}}' => $date,
                // Modelo antigo da clínica usa {{DATA_ATUAL}} para o dia.
                '{{DATA_ATUAL}}'             => $date,
                '{{HORARIO_COMPARECIMENTO}}' => $schedule,
                '{{ORIENTACAO_DILATACAO}}'   => $dilationBlock,
            ],
            'fallbacks' => [
                '{{HORARIO_COMPARECIMENTO}}' => $this->scheduleParagraph($times),
                '{{ORIENTACAO_DILATACAO}}'   => $dilationBlock,
            ],
            'metadata' => [
                ...$times,
                'date'           => $date,
                'dilation'       => $dilation,
                'dilation_hours' => $hours,
            ],
        ];
    }

    /**
     * Atestado de acompanhante: quem acompanhou o paciente, documento
     * (opcional), data e horário/período.
     *
     * @param array<string, mixed> $payload
     */
    private function companion(array $payload, CarbonInterface $documentDay): array
    {
        [$date, $schedule, $times] = $this->visit($payload, $documentDay);

        $name     = trim((string) ($payload['companion_name'] ?? ''));
        $document = trim((string) ($payload['companion_document'] ?? ''));

        return [
            'replacements' => [
                '{{ACOMPANHANTE_NOME}}' => self::text($name),
                // Aposto entre vírgulas: "João, portador(a) do documento X, esteve".
                '{{ACOMPANHANTE_DOCUMENTO}}' => $document !== '' ? ', portador(a) do documento ' . self::text($document) . ',' : '',
                '{{DATA_COMPARECIMENTO}}'    => $date,
                '{{HORARIO_COMPARECIMENTO}}' => $schedule,
            ],
            'fallbacks' => ['{{HORARIO_COMPARECIMENTO}}' => $this->scheduleParagraph($times)],
            'metadata'  => [
                ...$times,
                'date'               => $date,
                'companion_name'     => $name,
                'companion_document' => $document !== '' ? $document : null,
            ],
        ];
    }

    /**
     * Aptidão visual: AV sem/com correção PUXADA DO PRONTUÁRIO (servidor —
     * nunca da tela), conclusão e finalidade. Sem AV registrada no prontuário
     * o atestado não sai (aptidão sem acuidade não tem base).
     *
     * @param array<string, mixed> $payload
     */
    private function visualFitness(array $payload, MedicalRecord $record): array
    {
        $acuity = $this->acuity($record);

        if (array_filter([...$acuity['without'], ...$acuity['with']]) === []) {
            throw ValidationException::withMessages(['conclusion' => __('clinical_documents.certificates.acuity_required')]);
        }

        $conclusion = (string) ($payload['conclusion'] ?? '');
        $purpose    = (string) ($payload['purpose'] ?? '');
        $other      = trim((string) ($payload['purpose_other'] ?? ''));
        $purposeTxt = $purpose === 'other' ? $other : (self::PURPOSES[$purpose] ?? '');

        $cell  = 'border:1px solid #999;padding:4px 8px;text-align:center;';
        $value = fn (?string $v) => self::text($v ?? '—');
        $table = '<table style="border-collapse:collapse;margin:0.4em 0 0.8em;">'
            . '<thead><tr><th style="' . $cell . '"></th><th style="' . $cell . '">Sem correção</th><th style="' . $cell . '">Com correção</th></tr></thead>'
            . '<tbody>'
            . '<tr><th style="' . $cell . '">OD</th><td style="' . $cell . '">' . $value($acuity['without']['right']) . '</td><td style="' . $cell . '">' . $value($acuity['with']['right']) . '</td></tr>'
            . '<tr><th style="' . $cell . '">OE</th><td style="' . $cell . '">' . $value($acuity['without']['left']) . '</td><td style="' . $cell . '">' . $value($acuity['with']['left']) . '</td></tr>'
            . '</tbody></table>';

        return [
            'replacements' => [
                '{{FINALIDADE_APTIDAO}}'     => self::text($purposeTxt),
                '{{CONCLUSAO_APTIDAO}}'      => e(self::CONCLUSIONS[$conclusion] ?? ''),
                '{{TABELA_ACUIDADE_VISUAL}}' => $table,
            ],
            'fallbacks' => ['{{TABELA_ACUIDADE_VISUAL}}' => $table],
            'metadata'  => [
                'conclusion'    => $conclusion,
                'purpose'       => $purpose,
                'purpose_other' => $purpose === 'other' ? $other : null,
                'visual_acuity' => $acuity,
            ],
        ];
    }

    /**
     * AV do prontuário (nomes do catálogo de acuidade).
     *
     * @return array{without: array{right: ?string, left: ?string}, with: array{right: ?string, left: ?string}}
     */
    public function acuity(MedicalRecord $record): array
    {
        $record->loadMissing([
            'visualAcuityTypeWithoutCorrectionRight',
            'visualAcuityTypeWithoutCorrectionLeft',
            'visualAcuityTypeWitCorrectionRight',
            'visualAcuityTypeWitCorrectionLeft',
        ]);

        return [
            'without' => [
                'right' => $record->visualAcuityTypeWithoutCorrectionRight?->name,
                'left'  => $record->visualAcuityTypeWithoutCorrectionLeft?->name,
            ],
            'with' => [
                'right' => $record->visualAcuityTypeWitCorrectionRight?->name,
                'left'  => $record->visualAcuityTypeWitCorrectionLeft?->name,
            ],
        ];
    }

    /**
     * Dia (d/m/Y), trecho de horário/período e os campos estruturados.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function visit(array $payload, CarbonInterface $documentDay): array
    {
        $raw  = trim((string) ($payload['date'] ?? ''));
        $date = $raw !== ''
            ? CarbonImmutable::createFromFormat('!Y-m-d', $raw)->format('d/m/Y')
            : $documentDay->format('d/m/Y');

        $from   = trim((string) ($payload['time_from'] ?? ''));
        $to     = trim((string) ($payload['time_to'] ?? ''));
        $period = (string) ($payload['period'] ?? '');

        $schedule = match (true) {
            $from !== '' && $to !== ''    => ", das {$from} às {$to}",
            isset(self::PERIODS[$period]) => ', ' . self::PERIODS[$period],
            default                       => '',
        };

        return [$date, $schedule, [
            'time_from' => $from !== '' ? $from : null,
            'time_to'   => $to !== '' ? $to : null,
            'period'    => isset(self::PERIODS[$period]) ? $period : null,
        ]];
    }

    /**
     * Horário/período em parágrafo próprio (texto de reserva do modelo sem a
     * variável {{HORARIO_COMPARECIMENTO}}).
     *
     * @param array{time_from: ?string, time_to: ?string, period: ?string} $times
     */
    private function scheduleParagraph(array $times): string
    {
        return match (true) {
            $times['time_from'] !== null && $times['time_to'] !== null => '<p>Horário: das ' . $times['time_from'] . ' às ' . $times['time_to'] . '.</p>',
            $times['period'] !== null                                  => '<p>Período: ' . self::PERIODS[$times['period']] . '.</p>',
            default                                                    => '',
        };
    }

    /**
     * Texto digitado na tela: HTML escapado e chaves de variável
     * neutralizadas — "{{PACIENTE_CPF}}" digitado no nome do acompanhante
     * não pode virar o CPF no documento (o resolver roda depois).
     */
    public static function text(string $value): string
    {
        return str_replace(['{{', '}}'], ['{ {', '} }'], e($value));
    }

    /** "um"/"dois" → "uma"/"duas" (horas é feminino). */
    private function feminine(string $spelled): string
    {
        return (string) preg_replace(['/\bum$/u', '/\bdois$/u'], ['uma', 'duas'], $spelled);
    }
}
