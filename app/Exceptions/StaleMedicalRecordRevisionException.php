<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Lançada ao assinar um prontuário com um clinical_revision diferente do
 * atual — outra aba/usuário alterou o prontuário, um documento ou um anexo
 * depois que quem está assinando carregou a tela, e a revisão que o médico
 * revisou não é mais a mais recente (CFM Res. 2.227/2018 — a assinatura
 * atesta que o MÉDICO revisou o conteúdo que está assinando, achado via
 * revisão adversarial Codex). Rejeita a assinatura em vez de travar em
 * silêncio um conteúdo nunca revisado.
 */
class StaleMedicalRecordRevisionException extends RuntimeException
{
}
