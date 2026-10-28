<?php

declare(strict_types=1);

namespace App\Support\ClinicalSafety;

use App\Models\{Medicine, Patient, PatientAllergy};
use App\Services\Medicines\CmedPresentationParser;
use Illuminate\Support\Collection;

/**
 * Checagem de alergia na prescrição (Fase 2 / A4) — regra ÚNICA do servidor.
 *
 * Bate a receita com as alergias ATIVAS do paciente (informadas ou
 * confirmadas):
 *
 *  - `ingredient`: princípio ativo/nome do item contém a substância alérgica
 *    (ou o contrário — alergia "Maxitrol (dexametasona, neomicina)" pega o
 *    item "dexametasona");
 *  - `class`: a alergia é de uma classe (penicilina, sulfa, AINE…) ou de um
 *    membro dela, e o item/texto tem OUTRO membro da classe;
 *  - `text`: o texto livre da receita cita a substância (sem acento, palavra
 *    inteira);
 *  - `brand`: o texto cita um nome comercial do catálogo cujo princípio ativo
 *    é a substância ou da classe ("Voltaren" com alergia a diclofenaco);
 *  - `eye_drops`: alergia ao conservante (cloreto de benzalcônio) e a receita
 *    tem colírio — o conservante não aparece no princípio ativo do catálogo.
 *
 * Aviso, não bloqueio: quem chama exige o motivo para seguir.
 */
final class AllergyConflictChecker
{
    /** Teto de apresentações do catálogo lidas para achar nomes comerciais. */
    private const BRAND_LOOKUP_LIMIT = 5000;

    /** Nomes de produto que são só a forma farmacêutica — nunca viram "marca". */
    private const GENERIC_NAME_WORDS = ['colirio', 'pomada', 'solucao', 'gel', 'comprimido', 'comprimidos', 'gotas', 'xarope', 'creme', 'capsula', 'capsulas', 'suspensao'];

    /** Termo mínimo para a comparação "item contido na alergia". */
    private const REVERSE_MIN_LENGTH = 4;

    /**
     * Alergias ativas do paciente.
     *
     * @return Collection<int, PatientAllergy>
     */
    public function activeAllergies(Patient $patient): Collection
    {
        return PatientAllergy::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $patient->entity_id)
            ->where('patient_id', $patient->getKey())
            ->whereNull('deleted_at')
            ->active()
            ->with('medicine:id,active_ingredient')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Termos de cada alergia ativa: os da própria substância e os das classes
     * a que ela pertence.
     *
     * @param Collection<int, PatientAllergy> $allergies
     *
     * @return list<array{allergy: PatientAllergy, terms: list<string>, classes: list<string>, class_labels: list<string>, class_members: list<string>, eye_drops: bool}>
     */
    public function profiles(Collection $allergies): array
    {
        $classes  = ClinicalRules::allergyClasses();
        $profiles = [];

        foreach ($allergies as $allergy) {
            // Substância digitada solta (sem catálogo nem princípio ativo
            // próprio) pode vir com a dose junto ("Dipirona 500 mg") — a
            // receita com dose diferente ("Dipirona 1 g") não bate mais
            // porque a comparação exige a frase inteira. active_ingredient
            // (catálogo ou campo próprio) não tem esse problema: dose vive em
            // `concentration`, campo separado (16ª revisão Codex, P1).
            $terms = array_values(array_unique([
                ...TermNormalizer::terms((string) preg_replace('/\s*\d.*$/u', '', (string) $allergy->substance) ?: $allergy->substance),
                ...TermNormalizer::terms($allergy->active_ingredient),
                ...TermNormalizer::terms($allergy->medicine?->active_ingredient),
            ]));

            $matched = [];

            foreach ($classes as $key => $class) {
                foreach ($terms as $term) {
                    foreach ([...$class['members'], ...$class['aliases']] as $classTerm) {
                        if (TermNormalizer::contains($term, $classTerm)) {
                            $matched[$key] = $class;

                            continue 3;
                        }
                    }
                }
            }

            $members = [];

            foreach ($matched as $class) {
                foreach ($class['members'] as $member) {
                    if (! in_array($member, $terms, true)) {
                        $members[$member] = true;
                    }
                }
            }

            $profiles[] = [
                'allergy'       => $allergy,
                'terms'         => $terms,
                'classes'       => array_keys($matched),
                'class_labels'  => array_values(array_map(fn (array $c) => $c['label'], $matched)),
                'class_members' => array_keys($members),
                'eye_drops'     => collect($matched)->contains(fn (array $c) => $c['eye_drops']),
            ];
        }

        return $profiles;
    }

    /**
     * Conflitos da receita: itens estruturados + texto livre/HTML final.
     *
     * @param array<int, array{medicine_id?: ?string, name?: ?string, active_ingredient?: ?string}> $items
     *
     * @return list<array<string, mixed>>
     */
    public function check(Patient $patient, array $items, ?string $text): array
    {
        $profiles = $this->profiles($this->activeAllergies($patient));

        if ($profiles === []) {
            return [];
        }

        $conflicts = [];
        $add       = function (array $profile, string $match, string $matched, ?string $class = null) use (&$conflicts): void {
            /** @var PatientAllergy $allergy */
            $allergy = $profile['allergy'];
            $key     = $allergy->getKey() . '|' . $match . '|' . mb_strtolower($matched);

            $conflicts[$key] ??= [
                'allergy_id'     => (string) $allergy->getKey(),
                'substance'      => $allergy->substance,
                'reaction'       => $allergy->reaction,
                'severity'       => $allergy->severity?->value,
                'severity_label' => $allergy->severity?->label(),
                'status'         => $allergy->status->value,
                'status_label'   => $allergy->status->label(),
                'match'          => $match,
                'matched'        => $matched,
                'class'          => $class,
            ];
        };

        foreach ($this->resolveItems($patient, $items) as $item) {
            foreach ($profiles as $profile) {
                $this->checkItem($profile, $item, $add);
            }
        }

        $textCore = TermNormalizer::core($text);

        if ($textCore !== '') {
            $brands        = $this->brandNames($patient, $profiles);
            $eyeDropsFound = $this->eyeDropMentioned($patient, $textCore);

            foreach ($profiles as $index => $profile) {
                $this->checkText($profile, $textCore, $brands[$index] ?? [], $eyeDropsFound, $add);
            }
        }

        return array_values($conflicts);
    }

    /**
     * Dados da checagem para a TELA (receituário): termos já normalizados e a
     * mesma lista de palavras ignoradas — a tela só compara; o servidor refaz
     * tudo ao emitir.
     *
     * @return array{stopwords: list<string>, allergies: list<array<string, mixed>>}
     */
    public function clientCheck(Patient $patient): array
    {
        return [
            'stopwords' => TermNormalizer::stopwordList(),
            'allergies' => array_map(fn (array $p) => [
                'id'             => (string) $p['allergy']->getKey(),
                'substance'      => $p['allergy']->substance,
                'reaction'       => $p['allergy']->reaction,
                'severity'       => $p['allergy']->severity?->value,
                'severity_label' => $p['allergy']->severity?->label(),
                'status'         => $p['allergy']->status->value,
                'status_label'   => $p['allergy']->status->label(),
                'terms'          => $p['terms'],
                'class_members'  => $p['class_members'],
                'class_labels'   => $p['class_labels'],
                'eye_drops'      => $p['eye_drops'],
            ], $this->profiles($this->activeAllergies($patient))),
        ];
    }

    /**
     * Itens da receita com o princípio ativo do CATÁLOGO quando há
     * medicine_id (da clínica ou global — de outra clínica é ignorado) e o
     * que veio da tela como reserva.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return list<array{label: string, ingredient: string, name: string, eye_drop: bool}>
     */
    private function resolveItems(Patient $patient, array $items): array
    {
        $ids = collect($items)->pluck('medicine_id')->filter(fn ($id) => is_string($id) && $id !== '')->unique()->values();

        $medicines = $ids->isEmpty() ? collect() : Medicine::query()
            ->with('presentation:id,name')
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $patient->entity_id))
            ->get()
            ->keyBy(fn (Medicine $m) => (string) $m->getKey());

        $resolved = [];

        foreach ($items as $item) {
            $medicine   = $medicines->get((string) ($item['medicine_id'] ?? ''));
            $name       = (string) ($medicine?->name ?? $item['name'] ?? '');
            $ingredient = (string) ($medicine?->active_ingredient ?? $item['active_ingredient'] ?? '');

            if (trim($name . $ingredient) === '') {
                continue;
            }

            $resolved[] = [
                'label'      => trim($name . ($medicine?->concentration ? ' ' . $medicine->concentration : '')) ?: $ingredient,
                'ingredient' => $ingredient,
                'name'       => $name,
                'eye_drop'   => $this->isEyeDrop($medicine, $name),
            ];
        }

        return $resolved;
    }

    private function isEyeDrop(?Medicine $medicine, string $name): bool
    {
        if ($medicine?->isEyeDrop()) {
            return true;
        }

        return TermNormalizer::contains(TermNormalizer::core($name), 'colirio');
    }

    /**
     * @param array<string, mixed>                                                   $profile
     * @param array{label: string, ingredient: string, name: string, eye_drop: bool} $item
     */
    private function checkItem(array $profile, array $item, callable $add): void
    {
        $parts = array_values(array_unique([
            ...TermNormalizer::terms($item['ingredient']),
            ...TermNormalizer::terms($item['name']),
        ]));

        foreach ($parts as $part) {
            foreach ($profile['terms'] as $term) {
                $reverse = mb_strlen($part) >= self::REVERSE_MIN_LENGTH && TermNormalizer::contains($term, $part);

                if (TermNormalizer::contains($part, $term) || $reverse) {
                    $add($profile, 'ingredient', $item['label']);

                    return;
                }
            }
        }

        foreach ($parts as $part) {
            foreach ($profile['class_members'] as $member) {
                if (TermNormalizer::contains($part, $member)) {
                    $add($profile, 'class', $item['label'], implode('; ', $profile['class_labels']));

                    return;
                }
            }
        }

        if ($profile['eye_drops'] && $item['eye_drop']) {
            $add($profile, 'eye_drops', $item['label'], implode('; ', $profile['class_labels']));
        }
    }

    /**
     * @param array<string, mixed>  $profile
     * @param array<string, string> $brands  nome comercial (core) => rótulo
     */
    private function checkText(array $profile, string $textCore, array $brands, bool $eyeDropsFound, callable $add): void
    {
        foreach ($profile['terms'] as $term) {
            if (TermNormalizer::contains($textCore, $term)) {
                $add($profile, 'text', $term);
            }
        }

        foreach ($profile['class_members'] as $member) {
            if (TermNormalizer::contains($textCore, $member)) {
                $add($profile, 'class', $member, implode('; ', $profile['class_labels']));
            }
        }

        foreach ($brands as $brandCore => $label) {
            if (TermNormalizer::contains($textCore, $brandCore)) {
                $add($profile, 'brand', $label);
            }
        }

        if ($profile['eye_drops'] && $eyeDropsFound) {
            $add($profile, 'eye_drops', 'colírio', implode('; ', $profile['class_labels']));
        }
    }

    /**
     * Texto livre tem "colírio" (palavra) OU o nome comercial de um colírio
     * do catálogo (global + clínica) — independe de bater com alergia
     * nenhuma: o conservante (benzalcônio) não é princípio ativo de ninguém,
     * então brandNames() (que exige o princípio ativo bater com a alergia)
     * nunca achava a marca. "Lacrima Plus — solução oftálmica" não tem a
     * palavra "colírio" escrita, e sem item estruturado (medicine_id) o
     * catálogo nunca era consultado (11ª revisão Codex, P1). `search_text`
     * (não `pharmaceutical_form`) é quem sabe isso de verdade pra TODO item
     * do catálogo — CMED importado grava a forma farmacêutica, mas um
     * colírio cadastrado à mão (OphthalmicMedicinesSeeder) só marca a
     * apresentação, deixando `pharmaceutical_form` nulo; `search_text` já
     * inclui a apresentação (e, quando a forma bate, a própria palavra
     * "colírio"), então cobre os dois jeitos sem reimplementar
     * Medicine::isEyeDrop() aqui (12ª revisão Codex, P1).
     *
     * `search_text` guarda a tradução ("colírio"/"eye drops") no idioma de
     * QUEM SALVOU o remédio — cadastro feito com a tela em inglês grava "eye
     * drops", sem a palavra "colirio" (sem acento) que o filtro procurava, e
     * o item ficava de fora da checagem de texto livre pra sempre, mesmo com
     * o catálogo certo. `pharmaceutical_form` é o código CMED (não traduz) —
     * sempre bate, qualquer idioma em que o cadastro foi salvo (14ª revisão
     * Codex, P1).
     */
    private function eyeDropMentioned(Patient $patient, string $textCore): bool
    {
        if (TermNormalizer::contains($textCore, 'colirio')) {
            return true;
        }

        // Sem o filtro `active`: remédio DESATIVADO no catálogo (descontinuado)
        // continua citado em modelo/receita já existente — soft delete
        // (SoftDeletes) continua de fora, só o inativo-mas-não-excluído entra
        // (16ª revisão Codex, P1).
        $names = Medicine::query()
            ->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $patient->entity_id))
            ->where(fn ($q) => $q
                ->where('search_text', 'like', '%colirio%')
                // Suspensão/gel/pomada oftálmica cadastrada à mão (sem
                // pharmaceutical_form) — search_text leva a apresentação
                // (Medicine::searchTextFor()), mesmo risco de conservante
                // (17ª revisão Codex, P1).
                ->orWhere('search_text', 'like', '%oftalm%')
                ->orWhereIn('pharmaceutical_form', CmedPresentationParser::EYE_DROP_FORMS))
            ->limit(self::BRAND_LOOKUP_LIMIT)
            ->pluck('name');

        foreach ($names as $name) {
            $brandCore = $this->brandCore((string) $name);

            if ($brandCore !== null && TermNormalizer::contains($textCore, $brandCore)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nomes comerciais do catálogo (global + clínica) cujo princípio ativo é
     * a substância da alergia ou um membro da classe — uma consulta só para
     * todas as alergias.
     *
     * @param list<array<string, mixed>> $profiles
     *
     * @return array<int, array<string, string>> índice do perfil => [core do nome => nome]
     */
    private function brandNames(Patient $patient, array $profiles): array
    {
        $allTerms = collect($profiles)
            ->flatMap(fn (array $p) => [...$p['terms'], ...$p['class_members']])
            ->filter(fn (string $t) => mb_strlen($t) >= self::REVERSE_MIN_LENGTH)
            ->unique()
            ->values();

        if ($allTerms->isEmpty()) {
            return [];
        }

        // Sem o filtro `active` (mesmo motivo do eyeDropMentioned() acima).
        $rows = Medicine::query()
            ->whereNotNull('active_ingredient')
            ->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $patient->entity_id))
            ->where(function ($q) use ($allTerms) {
                foreach ($allTerms as $term) {
                    $q->orWhere('search_text', 'like', self::termLikePattern($term));
                }
            })
            ->limit(self::BRAND_LOOKUP_LIMIT)
            ->get(['name', 'active_ingredient']);

        $out = [];

        foreach ($rows as $row) {
            $brandCore = $this->brandCore((string) $row->name);

            if ($brandCore === null) {
                continue;
            }

            $ingredientCore = TermNormalizer::core((string) $row->active_ingredient);

            foreach ($profiles as $index => $profile) {
                foreach ([...$profile['terms'], ...$profile['class_members']] as $term) {
                    if (! TermNormalizer::contains($ingredientCore, $term)) {
                        continue;
                    }

                    // Nome genérico ("DICLOFENACO SÓDICO") já é coberto pela
                    // busca da própria substância no texto.
                    if (! TermNormalizer::contains($brandCore, $term)) {
                        $out[$index][$brandCore] = trim((string) $row->name);
                    }

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * `%palavra1%palavra2%…` em vez de `%termo inteiro%` (3ª revisão Codex,
     * P1): $term já passou por TermNormalizer::core() e perdeu conectivos
     * ("hidroxido de aluminio" → "hidroxido aluminio"), mas o search_text do
     * catálogo (Medicine::normalizeSearch(), sem esse filtro) ainda tem a
     * palavra no meio — "...hidroxido DE aluminio...". Comparar como
     * sequência rígida nunca achava o MAALOX, e a alergia por nome comercial
     * não disparava. Cada palavra isolada por "%" acha a substância
     * independente do que (não) sobrou entre elas; termo de uma palavra só
     * fica igual a antes.
     */
    private static function termLikePattern(string $term): string
    {
        return '%' . implode('%', explode(' ', $term)) . '%';
    }

    /** Nome comercial sem dose/apresentação ("VOLTAREN 50 MG" → "voltaren"). */
    private function brandCore(string $name): ?string
    {
        $tokens = [];

        foreach (explode(' ', TermNormalizer::core($name)) as $token) {
            if ($token === '' || preg_match('/\d/', $token)) {
                break;
            }

            $tokens[] = $token;
        }

        $core = implode(' ', $tokens);

        if (mb_strlen($core) < self::REVERSE_MIN_LENGTH || in_array($core, self::GENERIC_NAME_WORDS, true)) {
            return null;
        }

        return $core;
    }
}
