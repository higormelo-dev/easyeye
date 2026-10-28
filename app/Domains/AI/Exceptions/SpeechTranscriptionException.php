<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use RuntimeException;

/**
 * Falha do ditado por voz. A mensagem é sempre a chave de tradução
 * (lang/*\/eye_images.php) — nunca texto do provedor, que pode ecoar o áudio
 * ou o transcrito.
 */
final class SpeechTranscriptionException extends RuntimeException
{
    public const UNAVAILABLE = 'eye_images.dictation_unavailable';

    public const INVALID_AUDIO = 'eye_images.dictation_invalid_audio';

    public const TOO_LONG = 'eye_images.dictation_too_long';

    public const NO_SPEECH = 'eye_images.dictation_no_speech';

    public const PROVIDER_FAILED = 'eye_images.dictation_provider_failed';

    /** A trilha de auditoria não gravou: o áudio não pode sair sem ela. */
    public const AUDIT_FAILED = 'eye_images.dictation_audit_failed';

    /** @param array<string, scalar> $replace */
    public function __construct(public readonly string $translationKey, public readonly array $replace = [])
    {
        parent::__construct($translationKey);
    }

    public function userMessage(): string
    {
        return __($this->translationKey, $this->replace);
    }

    public function httpStatus(): int
    {
        return match ($this->translationKey) {
            self::UNAVAILABLE, self::AUDIT_FAILED => 503,
            self::PROVIDER_FAILED                 => 502,
            default                               => 422,
        };
    }
}
