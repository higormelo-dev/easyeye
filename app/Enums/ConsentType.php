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

    public function label(): string
    {
        return match ($this) {
            self::DataCollection        => 'Coleta e Armazenamento de Dados',
            self::SensitiveData         => 'Tratamento de Dados de Saúde',
            self::DataSharing           => 'Compartilhamento com Terceiros',
            self::MinorGuardian         => 'Representação de Menor de Idade',
            self::ImageAndVoice         => 'Uso de Imagem e Voz',
            self::ResearchParticipation => 'Participação em Pesquisa',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DataCollection        => 'Autorizo a coleta, armazenamento e uso dos meus dados pessoais para fins de atendimento clínico.',
            self::SensitiveData         => 'Autorizo o tratamento dos meus dados de saúde (diagnósticos, exames, prescrições) para fins de atendimento médico.',
            self::DataSharing           => 'Autorizo o compartilhamento dos meus dados com convênios e operadoras para fins de faturamento.',
            self::MinorGuardian         => 'Na qualidade de responsável legal, autorizo o tratamento dos dados do menor sob minha responsabilidade.',
            self::ImageAndVoice         => 'Autorizo o uso de imagens e registros de voz para fins de documentação clínica.',
            self::ResearchParticipation => 'Autorizo a utilização anonimizada dos meus dados para fins de pesquisa científica.',
        };
    }

    /** Indica se este tipo de consentimento é obrigatório para o atendimento */
    public function isRequired(): bool
    {
        return in_array($this, [self::DataCollection, self::SensitiveData]);
    }
}
