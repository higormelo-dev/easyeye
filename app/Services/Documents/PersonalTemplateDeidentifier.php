<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\{Doctor, Entity, MedicalRecord, Patient, ReportSettingContent};
use App\Services\TemplateVariableResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Modelo PESSOAL reutilizável não guarda dado de paciente (Fase 2 / B3 +
 * revisão Codex P1-1, LGPD).
 *
 * O editor de Documentações trabalha sobre o texto JÁ RESOLVIDO para o
 * paciente da consulta (nome, datas, médico…). Ao salvar como modelo, o
 * servidor devolve esses valores às variáveis ({{PACIENTE_NOME}},
 * {{DATA_ATUAL}}…) — com o mesmo mapa do TemplateVariableResolver para
 * aquele prontuário — e, ao aplicar em outro paciente, a prévia resolve de
 * novo para o paciente NOVO.
 *
 * Troca só o que é seguro trocar:
 *  - só em texto (nunca dentro de tag/atributo) e só palavra inteira;
 *  - valor com letras: no mínimo MIN_TEXT_LENGTH caracteres ("45 anos" sim,
 *    "OD" não);
 *  - valor só de números/pontuação (refração, PIO, eixo): só com
 *    MIN_DIGITS dígitos ou mais — datas dd/mm/aaaa, CPF, telefone. "14" ou
 *    "-2,00" nunca viram variável às cegas;
 *  - datas nos formatos que o resolver escreve (dd/mm/aaaa, por extenso,
 *    "Cidade/UF, D de mês de AAAA"), tanto para a data do documento quanto
 *    para hoje;
 *  - valor igual para duas variáveis (ex.: DATA_ATUAL e DATA_EXAME no mesmo
 *    dia): vence a que aparece no modelo-base.
 *
 * Depois da troca, se ainda sobrar o nome completo, o CPF ou o código do
 * paciente (ex.: nome partido por negrito, CPF digitado em outro formato,
 * sem acento), o salvamento é recusado com mensagem clara.
 */
final class PersonalTemplateDeidentifier
{
    public const MIN_TEXT_LENGTH = 4;

    public const MIN_DIGITS = 8;

    private const PLACEHOLDER = '/(\{\{[A-Z_][A-Z0-9_]*\}\})/u';

    public function __construct(private readonly TemplateVariableResolver $resolver)
    {
    }

    /**
     * HTML do editor → HTML com variáveis no lugar dos dados do atendimento.
     *
     * @throws ValidationException se ainda restar identificação do paciente
     */
    public function deidentify(
        string $html,
        ReportSettingContent $base,
        MedicalRecord $record,
        Doctor $issuer,
        Entity $entity,
        ?CarbonInterface $documentDate = null,
    ): string {
        $patient = $this->patient($record);
        $doctor  = $record->doctor()->withoutGlobalScopes()->first() ?? $issuer;

        $html = $this->replaceInText($html, $this->pairs($base, $patient, $doctor, $entity, $record, $documentDate));

        $this->assertNoPatientData($html, $patient);

        return $html;
    }

    /**
     * Texto dos modais (receita/pedido): não passa pelo resolver; só confere
     * que não ficou identificação do paciente.
     *
     * @throws ValidationException
     */
    public function assertClean(string $body, MedicalRecord $record): void
    {
        // Corpo de modelo TEXT (receita/pedido): campo puro, não HTML — igual
        // assertCleanFields() já faz pros itens do payload. "<MARIA SOUZA>"
        // escrito à mão não é tag só porque tem "<" e ">"; sem raw, plain()
        // descartava como se fosse marcação e o nome sobrevivia literal até
        // a tela que reaplica o modelo, vazando pra outro paciente (12ª
        // revisão Codex, P2).
        $this->assertNoPatientData($body, $this->patient($record), raw: true);
    }

    /**
     * Campos de texto livre dos itens estruturados (indicação, observações,
     * nome digitado de exame/medicamento…): não passam pelo resolver — as
     * observações saem como texto, com `{{` neutralizado —, então não dá para
     * trocar por variável: com dado do paciente, recusa apontando o campo
     * (2ª revisão, P1-A).
     *
     * @param array<string, array{0: string, 1: string}> $fields chave do erro → [rótulo, texto]
     *
     * @throws ValidationException
     */
    public function assertCleanFields(array $fields, MedicalRecord $record): void
    {
        $patient = $this->patient($record);
        $errors  = [];

        foreach ($fields as $key => [$label, $text]) {
            $found = $this->patientDataIn($text, $patient, raw: true);

            if ($found !== []) {
                $errors[$key] = __('clinical_documents.templates.patient_data_field', ['field' => $label, 'items' => implode(', ', $found)]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Pares [regex, variável], valores mais longos primeiro (o nome completo
     * antes de um pedaço dele).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(ReportSettingContent $base, Patient $patient, Doctor $doctor, Entity $entity, MedicalRecord $record, ?CarbonInterface $documentDate): array
    {
        $dates = $documentDate === null ? [null] : [$documentDate, null];
        $raw   = (string) $base->content;

        /** @var array<string, array{placeholder: string, rank: int, value: string}> $byValue */
        $byValue = [];
        $order   = 0;

        foreach ($dates as $date) {
            foreach ($this->resolver->identityReplacements($base, $patient, $doctor, $entity, $record, $date) as $placeholder => $value) {
                $value = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? $value);

                if (! $this->eligible($value)) {
                    continue;
                }

                $key  = mb_strtolower($value);
                $rank = (str_contains($raw, $placeholder) ? 0 : 100000) + $order++;

                if (! isset($byValue[$key]) || $rank < $byValue[$key]['rank']) {
                    $byValue[$key] = ['placeholder' => $placeholder, 'rank' => $rank, 'value' => $value];
                }
            }
        }

        uasort($byValue, fn (array $a, array $b) => mb_strlen($b['value']) <=> mb_strlen($a['value']));

        return array_values(array_map(fn (array $v) => [$this->pattern($v['value']), $v['placeholder']], $byValue));
    }

    private function eligible(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (preg_match('/\p{L}/u', $value) !== 1) {
            return preg_match_all('/\d/u', $value) >= self::MIN_DIGITS;
        }

        return mb_strlen($value) >= self::MIN_TEXT_LENGTH;
    }

    /** Regex do valor como aparece no HTML (entidades, &nbsp;, maiúsculas). */
    private function pattern(string $value): string
    {
        $out   = '';
        $space = false;

        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (preg_match('/[\s\x{00A0}]/u', $char) === 1) {
                if (! $space) {
                    $out .= '(?:\s|\x{00A0}|&nbsp;|&#160;)+';
                }
                $space = true;

                continue;
            }

            $space = false;
            $out .= match ($char) {
                '&'     => '(?:&amp;|&)',
                '"'     => '(?:&quot;|")',
                "'"     => "(?:'|&\\#0?39;|&apos;|\x{2019})",
                default => preg_quote($char, '/'),
            };
        }

        return '/(?<![\p{L}\p{N}])' . $out . '(?![\p{L}\p{N}])/iu';
    }

    /**
     * Troca só nos trechos de TEXTO — nunca dentro de tags nem dentro de uma
     * variável já colocada.
     *
     * @param list<array{0: string, 1: string}> $pairs
     */
    private function replaceInText(string $html, array $pairs): string
    {
        if ($pairs === []) {
            return $html;
        }

        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        foreach ($parts as $i => $part) {
            if ($part === '' || str_starts_with($part, '<')) {
                continue;
            }

            foreach ($pairs as [$pattern, $placeholder]) {
                $pieces = preg_split(self::PLACEHOLDER, $part, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$part];

                foreach ($pieces as $j => $piece) {
                    if ($piece !== '' && preg_match(self::PLACEHOLDER, $piece) !== 1) {
                        $pieces[$j] = preg_replace($pattern, $placeholder, $piece) ?? $piece;
                    }
                }

                $part = implode('', $pieces);
            }

            $parts[$i] = $part;
        }

        return implode('', $parts);
    }

    /**
     * Nome completo, CPF ou código do paciente que sobraram (sem acento, sem
     * diferenciar maiúsculas, mesmo partidos por tags).
     *
     * @throws ValidationException
     */
    private function assertNoPatientData(string $html, Patient $patient, bool $raw = false): void
    {
        $found = $this->patientDataIn($html, $patient, $raw);

        if ($found !== []) {
            throw ValidationException::withMessages([
                'body' => __('clinical_documents.templates.patient_data_left', ['items' => implode(', ', $found)]),
            ]);
        }
    }

    /**
     * O que do paciente aparece no texto (rótulos traduzidos).
     *
     * @param bool $raw texto puro (campo de formulário): confere também sem
     *                  tirar "tags" — "<Maria …>" num campo de texto é texto
     *
     * @return list<string>
     */
    private function patientDataIn(string $html, Patient $patient, bool $raw = false): array
    {
        $person = $patient->person;
        $texts  = [$this->plain($html, ''), $this->plain($html, ' '), $this->attributeValues($html)];
        $found  = [];

        if ($raw) {
            $texts[] = $this->normalize(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $name = $this->normalize((string) ($person?->full_name ?? ''));

        if (mb_strlen($name) >= self::MIN_TEXT_LENGTH && $this->containsWord($texts, $name)) {
            $found[] = __('clinical_documents.templates.patient_data_name');
        }

        $cpf = preg_replace('/\D/', '', (string) ($person?->national_registry ?? '')) ?? '';

        if (strlen($cpf) === 11) {
            foreach ($texts as $text) {
                preg_match_all('/(?<!\d)\d{3}[.\s]?\d{3}[.\s]?\d{3}[-.\s]?\d{2}(?!\d)/', $text, $matches);

                if (in_array($cpf, array_map(fn ($m) => preg_replace('/\D/', '', $m), $matches[0]), true)) {
                    $found[] = __('clinical_documents.templates.patient_data_cpf');

                    break;
                }
            }
        }

        $code = $this->normalize((string) ($patient->code ?? ''));

        if ($code !== '' && $this->containsWord($texts, $code)) {
            $found[] = __('clinical_documents.templates.patient_data_code');
        }

        return $found;
    }

    private function plain(string $html, string $tagGlue): string
    {
        $text = preg_replace('/<[^>]*>/u', $tagGlue, $html) ?? $html;

        return $this->normalize(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Valores de atributo (href, title, alt, data-*…) — `plain()` descarta a
     * tag inteira, inclusive os atributos; dado do paciente escondido ali
     * (ex.: link com o nome na URL, title com o nome) escapava da
     * conferência (2ª revisão Codex, P1-1b — LGPD). Genérico: qualquer
     * atributo, não só os conhecidos.
     */
    private function attributeValues(string $html): string
    {
        preg_match_all('/[a-zA-Z_:][-a-zA-Z0-9_:.]*\s*=\s*(["\'])(.*?)\1/su', $html, $matches);

        return $this->normalize(html_entity_decode(implode(' ', $matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function normalize(string $text): string
    {
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text;

        return trim(Str::lower(Str::ascii($text)));
    }

    /** @param list<string> $texts */
    private function containsWord(array $texts, string $needle): bool
    {
        // Espaço opcional: "<p>João</p><p>Silva</p>" sem espaço entre as tags.
        $pattern = '/(?<![a-z0-9])' . str_replace(' ', ' ?', preg_quote($needle, '/')) . '(?![a-z0-9])/';

        foreach ($texts as $text) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function patient(MedicalRecord $record): Patient
    {
        $patient = $record->patient()->withoutGlobalScopes()->firstOrFail();
        $patient->loadMissing('person');

        return $patient;
    }
}
