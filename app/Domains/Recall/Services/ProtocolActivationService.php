<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Enums\{ProtocolVersionState as State, RecallTaskKind};
use App\Domains\Recall\Exceptions\{ProtocolActivationException, ProtocolGovernanceException};
use App\Domains\Recall\Models\{RecallClinicProtocol, RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Support\{ProtocolContentSchema, ValidationEvidence};
use App\Models\{Doctor, Entity, User};
use Illuminate\Support\Facades\DB;

/**
 * A clínica ADOTA uma versão validada de um protocolo (ADR 0002, itens 3 e 8): ato clínico do MÉDICO (Gate
 * DecideRecall), com a prova da validação reconferida na hora, os parâmetros DENTRO da faixa que o Revisor
 * validou e o retrato do que ele viu (versão, hash, quem validou, os parâmetros) gravado junto.
 *
 * Só a versão LIBERADA é ativada; quem já adotou uma versão que depois foi substituída (superseded) continua nela
 * e pode ajustar parâmetros sem trocar de versão — a troca é uma nova ativação, com a mesma prova. A adoção nunca
 * muda o conteúdo por baixo da clínica: guarda a versão e o hash.
 *
 * Ordem de locks (global): protocolo → versões (id crescente) → ativação da clínica. Dois médicos ativando o mesmo
 * protocolo ao mesmo tempo: o 2º espera e relê (nunca duas adoções ativas — índice único parcial).
 */
class ProtocolActivationService
{
    public const DEACTIVATION_REASON_MIN = 10;

    /**
     * @param array{tasks?: array<string, array{interval?: mixed, enabled?: mixed}>, auto_contact?: mixed} $input
     *
     * @throws ProtocolActivationException
     */
    public function activate(Entity $entity, string $protocolId, string $versionId, array $input, bool $acknowledged, User $actor, ?Doctor $doctor = null): RecallClinicProtocol
    {
        if (! $acknowledged) {
            throw ProtocolActivationException::notAcknowledged();
        }

        return DB::transaction(function () use ($entity, $protocolId, $versionId, $input, $actor, $doctor): RecallClinicProtocol {
            $protocol = RecallProtocol::query()->whereKey($protocolId)->lockForUpdate()->first();

            if ($protocol === null || $protocol->status !== RecallProtocol::STATUS_ACTIVE) {
                throw ProtocolActivationException::protocolUnavailable();
            }

            $versions = RecallProtocolVersion::query()->where('protocol_id', $protocol->id)->orderBy('id')->lockForUpdate()->get()->keyBy(static fn (RecallProtocolVersion $version): string => (string) $version->id);
            $target   = $versions->get($versionId);

            if ($target === null) {
                throw ProtocolActivationException::versionMismatch();
            }

            $existing = RecallClinicProtocol::query()->withoutGlobalScopes()
                ->where('entity_id', $entity->id)
                ->where('protocol_id', $protocol->id)
                ->whereNull('deactivated_at')
                ->lockForUpdate()
                ->first();

            // Nova adoção ou troca de versão: só a liberada. Ajustar parâmetros na versão que já adotou (liberada ou
            // substituída, ainda em uso) não exige que ela seja a mais nova.
            $sameVersion = $existing !== null && (string) $existing->version_id === (string) $target->id;

            if (! ($sameVersion ? $target->state->isInUse() : $target->state === State::Released)) {
                throw ProtocolActivationException::versionNotReleased($target->state->value);
            }

            try {
                ValidationEvidence::assert($target);
            } catch (ProtocolGovernanceException $e) {
                throw ProtocolActivationException::noValidationEvidence($e->getMessage());
            }

            $parameters = $this->parameters($target, (array) ($input['tasks'] ?? []));

            $attributes = [
                'version_id'             => $target->id,
                'adopted_content_hash'   => $target->content_hash,
                'parameters'             => $parameters,
                'auto_contact'           => (bool) ($input['auto_contact'] ?? false),
                'activated_by'           => $actor->getKey(),
                'activated_by_doctor_id' => $doctor?->getKey(),
                'activation_snapshot'    => $this->snapshot($target, $parameters, $doctor, $actor),
                'activated_at'           => now(),
            ];

            if ($existing !== null) {
                $existing->forceFill($attributes)->save();

                return $existing->refresh();
            }

            return RecallClinicProtocol::query()->withoutGlobalScopes()->create($attributes + ['entity_id' => $entity->id, 'protocol_id' => $protocol->id]);
        });
    }

    /**
     * @throws ProtocolActivationException
     */
    public function deactivate(Entity $entity, string $protocolId, string $reason, User $actor): RecallClinicProtocol
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        if (mb_strlen($reason) < self::DEACTIVATION_REASON_MIN) {
            throw ProtocolActivationException::reasonRequired();
        }

        return DB::transaction(function () use ($entity, $protocolId, $reason, $actor): RecallClinicProtocol {
            $adoption = RecallClinicProtocol::query()->withoutGlobalScopes()
                ->where('entity_id', $entity->id)
                ->where('protocol_id', $protocolId)
                ->whereNull('deactivated_at')
                ->lockForUpdate()
                ->first();

            if ($adoption === null) {
                throw ProtocolActivationException::notAdopted();
            }

            $adoption->forceFill(['deactivated_at' => now(), 'deactivated_by' => $actor->getKey(), 'deactivation_reason' => mb_substr($reason, 0, 500)])->save();

            return $adoption->refresh();
        });
    }

    /**
     * Os parâmetros que a clínica escolheu, validados contra a faixa da versão: intervalo inteiro entre o mínimo e o
     * máximo validados, tarefa obrigatória sempre ligada, tarefa opcional só ligada se a clínica a ligou, nenhuma
     * tarefa desconhecida. O que a clínica não informou vale o padrão validado.
     *
     * @param array<string, mixed> $asked
     *
     * @return array{tasks: array<string, array{interval: int, enabled: bool}>}
     *
     * @throws ProtocolActivationException
     */
    public function parameters(RecallProtocolVersion $version, array $asked): array
    {
        $content = ProtocolContentSchema::normalize($version->contentArray());
        $tasks   = [];
        $errors  = [];
        $known   = [];

        foreach ((array) $content['tasks'] as $task) {
            $key = (string) $task['key'];

            if ($task['kind'] === RecallTaskKind::TreatmentCourse->value) {
                continue; // o curso de tratamento tem a própria tela; não é parâmetro de lista
            }

            $known[]  = $key;
            $given    = is_array($asked[$key] ?? null) ? $asked[$key] : [];
            $range    = (array) $task['interval'];
            $interval = array_key_exists('interval', $given) && $given['interval'] !== '' && $given['interval'] !== null ? $given['interval'] : (int) $range['default'];

            if (! is_int($interval) && ! (is_string($interval) && ctype_digit($interval))) {
                $errors["tasks.{$key}.interval"] = 'interval_not_integer';
            } elseif ((int) $interval < (int) $range['min'] || (int) $interval > (int) $range['max']) {
                $errors["tasks.{$key}.interval"] = 'interval_out_of_range';
            }

            $required = (bool) ($task['required'] ?? false);
            $enabled  = array_key_exists('enabled', $given) ? filter_var($given['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : $required;

            if ($enabled === null) {
                $errors["tasks.{$key}.enabled"] = 'enabled_not_boolean';
            } elseif ($required && $enabled === false) {
                $errors["tasks.{$key}.enabled"] = 'required_task_cannot_be_disabled';
            }

            $tasks[$key] = ['interval' => (int) $interval, 'enabled' => (bool) $enabled];
        }

        foreach (array_keys($asked) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $errors["tasks.{$key}"] = 'unknown_task';
            }
        }

        if ($errors !== []) {
            throw ProtocolActivationException::invalidParameters($errors);
        }

        return ['tasks' => $tasks];
    }

    /**
     * O retrato do que o médico viu ao ativar: quem ativou, a versão e o hash, a prova da validação e os
     * parâmetros. Guardado junto da adoção — a clínica pode provar depois com o que e por quem foi ativada.
     *
     * @param array{tasks: array<string, array{interval: int, enabled: bool}>} $parameters
     *
     * @return array<string, mixed>
     */
    private function snapshot(RecallProtocolVersion $version, array $parameters, ?Doctor $doctor, User $actor): array
    {
        return [
            'doctor' => [
                'doctor_id' => $doctor?->getKey(),
                'name'      => $doctor?->person?->full_name ?? (string) $actor->name,
                'record'    => $doctor?->record,
            ],
            'version'    => (int) $version->version,
            'hash'       => (string) $version->content_hash,
            'evidence'   => ValidationEvidence::summary($version),
            'parameters' => $parameters,
        ];
    }
}
