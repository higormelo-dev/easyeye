<?php

namespace App\Enums;

/**
 * Tipos de consentimento coletados do paciente.
 * LGPD Art. 7 e Art. 11 — tratamento de dados pessoais e sensíveis.
 */
enum ConsentType: string
{
    case DataCollection        = 'data_collection';      // Coleta e armazenamento de dados pessoais
    case SensitiveData         = 'sensitive_data';        // Dados de saúde (dado sensível — Art. 11)
    case DataSharing           = 'data_sharing';          // Compartilhamento com convênios/terceiros
    case MinorGuardian         = 'minor_guardian';        // Representação legal de menor de idade
    case ImageAndVoice         = 'image_and_voice';       // Uso de imagem/voz (exames com foto/vídeo)
    case ResearchParticipation = 'research';             // Participação em pesquisa clínica
    case CareReminders         = 'care_reminders';        // Lembretes de retorno por WhatsApp (Fase 6, ADR 0002 item 9)

    public function label(): string
    {
        return (string) __('consent.types.' . $this->value . '.label');
    }

    public function description(): string
    {
        return (string) __('consent.types.' . $this->value . '.description');
    }

    /** Indica se este tipo de consentimento é obrigatório para o atendimento */
    public function isRequired(): bool
    {
        return in_array($this, [self::DataCollection, self::SensitiveData]);
    }
}
