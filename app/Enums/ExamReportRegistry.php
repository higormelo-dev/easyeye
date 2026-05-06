<?php

namespace App\Enums;

/**
 * Registro central de exames clínicos que possuem documento associado.
 *
 * Consumido por:
 *   - MedicalRecordQuickActionService → mapear ação rápida → ReportSetting
 *   - resources/js/components/medicalRecordForm.js → render de botões
 *
 * Cada caso conhece o título do ReportSetting e o slug padrão do ReportSettingContent
 * usado na emissão. Variáveis dinâmicas (`{{...}}`) já são resolvidas via
 * TemplateVariableResolver a partir do registro do prontuário.
 */
enum ExamReportRegistry: string
{
    case Tonometry               = 'tonometry';
    case Ecografia               = 'ecografia';
    case MicroscopiaEspecular    = 'microscopia_especular';
    case Paquimetria             = 'paquimetria';
    case Retinografia            = 'retinografia';
    case Angiofluoresceinografia = 'angiofluoresceinografia';
    case Oct                     = 'oct';
    case Schirmer                = 'schirmer';
    case Pentacam                = 'pentacam';
    case StressCurve             = 'stress_curve';
    case CornealTopography       = 'corneal_topography';
    case ComputerCampimetry      = 'computer_campimetry';
    case Gonioscopia             = 'gonioscopia';
    case RetinalMapping          = 'retinal_mapping';
    case OphthalmologicalReport  = 'ophthalmological_report';
    case BrancoDeclaration       = 'declaracao_branco';
    case LensPrescription        = 'lens_prescription';

    /**
     * @return array{setting_title: string, slug: string, label: string, auto_populate: array<int,string>}
     */
    public function definition(): array
    {
        return match ($this) {
            self::Tonometry => [
                'setting_title' => 'LAUDO DE TONÔMETRIA',
                'slug'          => 'padrao',
                'label'         => 'Laudo de Tonômetria',
                'auto_populate' => ['tonometer_right', 'tonometer_left', 'tonometer_time'],
            ],
            self::Ecografia => [
                'setting_title' => 'LAUDO DE ECOGRAFIA',
                'slug'          => 'padrao',
                'label'         => 'Ecografia',
                'auto_populate' => [],
            ],
            self::MicroscopiaEspecular => [
                'setting_title' => 'MICROSCOPIA ESPECULAR',
                'slug'          => 'padrao',
                'label'         => 'Microscopia Especular',
                'auto_populate' => [],
            ],
            self::Paquimetria => [
                'setting_title' => 'PAQUIMETRIA CORNEANA',
                'slug'          => 'padrao',
                'label'         => 'Paquimetria',
                'auto_populate' => ['pachymetry_right', 'pachymetry_left'],
            ],
            self::Retinografia => [
                'setting_title' => 'RETINOGRAFIA COLORIDA',
                'slug'          => 'padrao',
                'label'         => 'Retinografia',
                'auto_populate' => [],
            ],
            self::Angiofluoresceinografia => [
                'setting_title' => 'LAUDO DE ANGIOFLUORESCEINOGRAFIA',
                'slug'          => 'padrao',
                'label'         => 'Angiofluoresceinografia',
                'auto_populate' => [],
            ],
            self::Oct => [
                'setting_title' => 'OCT (TOMOGRAFIA DE COERÊNCIA ÓTICA)',
                'slug'          => 'padrao',
                'label'         => 'OCT',
                'auto_populate' => [],
            ],
            self::Schirmer => [
                'setting_title' => 'ESTUDO COMPUTADORIZADO DA LÁGRIMA',
                'slug'          => 'padrao',
                'label'         => 'Schirmer / Lágrima',
                'auto_populate' => [],
            ],
            self::Pentacam => [
                'setting_title' => 'PENTACAM',
                'slug'          => 'padrao',
                'label'         => 'Pentacam',
                'auto_populate' => [],
            ],
            self::StressCurve => [
                'setting_title' => 'CURVA TENSIONAL DIÁRIA',
                'slug'          => 'padrao',
                'label'         => 'Curva Tensional Diária',
                'auto_populate' => ['tonometer_right', 'tonometer_left'],
            ],
            self::CornealTopography => [
                'setting_title' => 'TOPOGRAFIA CORNEANA',
                'slug'          => 'padrao',
                'label'         => 'Topografia Corneana',
                'auto_populate' => [],
            ],
            self::ComputerCampimetry => [
                'setting_title' => 'CAMPIMETRIA COMPUTADORIZADA',
                'slug'          => 'padrao',
                'label'         => 'Campimetria Computadorizada',
                'auto_populate' => [],
            ],
            self::Gonioscopia => [
                'setting_title' => 'LAUDO DE GONIOSCOPIA',
                'slug'          => 'padrao',
                'label'         => 'Gonioscopia',
                'auto_populate' => ['gonioscopy_right', 'gonioscopy_left'],
            ],
            self::RetinalMapping => [
                'setting_title' => 'MAPEAMENTO DE RETINA',
                'slug'          => 'padrao',
                'label'         => 'Mapeamento de Retina',
                'auto_populate' => [],
            ],
            self::OphthalmologicalReport => [
                'setting_title' => 'LAUDO OFTALMOLÓGICO',
                'slug'          => 'completo',
                'label'         => 'Laudo Oftalmológico',
                'auto_populate' => [
                    'tonometer_right', 'tonometer_left',
                    'visualAcuityTypeWithoutCorrectionRight.name', 'visualAcuityTypeWithoutCorrectionLeft.name',
                    'visualAcuityTypeWitCorrectionRight.name', 'visualAcuityTypeWitCorrectionLeft.name',
                    'dynamic_spherical_right', 'dynamic_spherical_left',
                    'dynamic_cylindrical_right', 'dynamic_cylindrical_left',
                    'dynamic_axis_right', 'dynamic_axis_left',
                    'additionType.name',
                ],
            ],
            self::BrancoDeclaration => [
                'setting_title' => 'RELATÓRIO OFTALMOLÓGICO',
                'slug'          => 'declaracao_medica',
                'label'         => 'Declaração em Branco',
                'auto_populate' => [],
            ],
            self::LensPrescription => [
                'setting_title' => 'PRESCRIÇÃO DE LENTES',
                'slug'          => 'dinamica_longe',
                'label'         => 'Receituário de Óculos',
                'auto_populate' => [
                    'dynamic_spherical_right', 'dynamic_spherical_left',
                    'dynamic_cylindrical_right', 'dynamic_cylindrical_left',
                    'dynamic_axis_right', 'dynamic_axis_left',
                    'static_spherical_right', 'static_spherical_left',
                    'static_cylindrical_right', 'static_cylindrical_left',
                    'static_axis_right', 'static_axis_left',
                    'lensAway.name', 'lensNear.name', 'observation_of_lenses',
                ],
            ],
        };
    }

    public function settingTitle(): string
    {
        return $this->definition()['setting_title'];
    }

    public function slug(): string
    {
        return $this->definition()['slug'];
    }

    public function label(): string
    {
        return $this->definition()['label'];
    }

    /** @return array<int, string> */
    public function autoPopulate(): array
    {
        return $this->definition()['auto_populate'];
    }

    /**
     * Cases que devem aparecer no hub visual "Laudos de Exame" (F4b).
     *
     * Tonometry tem botão dedicado (impressão direta no bloco de tonometria);
     * LensPrescription tem dropdown 4 modos no bloco de receituário óculos.
     * Demais 15 casos formam o hub principal de laudos.
     *
     * @return array<int, self>
     */
    public static function examsForHub(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => ! in_array($case, [self::Tonometry, self::LensPrescription], true),
        ));
    }

    /**
     * Ícone Font Awesome correspondente ao exame, p/ render do hub.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Ecografia               => 'fa-wave-square',
            self::MicroscopiaEspecular    => 'fa-microscope',
            self::Paquimetria             => 'fa-ruler-vertical',
            self::Retinografia            => 'fa-eye',
            self::Angiofluoresceinografia => 'fa-vial',
            self::Oct                     => 'fa-layer-group',
            self::Schirmer                => 'fa-tint',
            self::Pentacam                => 'fa-circle-notch',
            self::StressCurve             => 'fa-chart-line',
            self::CornealTopography       => 'fa-mountain',
            self::ComputerCampimetry      => 'fa-bullseye',
            self::Gonioscopia             => 'fa-search-plus',
            self::RetinalMapping          => 'fa-map',
            self::OphthalmologicalReport  => 'fa-file-medical-alt',
            self::BrancoDeclaration       => 'fa-file-signature',
            default                       => 'fa-file-medical',
        };
    }

    /**
     * Subtipos clínicos de um exame (F4e).
     *
     * Quando um exame tem múltiplas variantes de laudo (ex: Pentacam),
     * cada subtipo corresponde a um `ReportSettingContent.slug` distinto.
     * UI exibe dropdown de seleção antes de abrir o editor.
     *
     * Exames sem subtipos retornam array vazio → card simples no hub.
     *
     * @return array<int, array{slug: string, label: string}>
     */
    public function subtypes(): array
    {
        return match ($this) {
            self::Pentacam => [
                ['slug' => 'padrao', 'label' => 'Padrão'],
                ['slug' => 'ceratocone_oval', 'label' => 'Ceratocone Oval'],
                ['slug' => 'ceratocone_bow_tie', 'label' => 'Ceratocone Bow Tie'],
                ['slug' => 'ceratocone_avancado', 'label' => 'Ceratocone Avançado'],
                ['slug' => 'laudo_refrativo', 'label' => 'Laudo Refrativo'],
            ],
            default => [],
        };
    }

    public function hasSubtypes(): bool
    {
        return $this->subtypes() !== [];
    }

    /**
     * Resolve o slug do `ReportSettingContent` a usar dado um subtipo do payload.
     *
     * Regras:
     *  - Exame sem subtipos: ignora `$subtype`, sempre retorna `slug()` default.
     *  - Subtipo válido: retorna o slug correspondente.
     *  - Subtipo inválido/null em exame multi-variante: retorna `slug()` default
     *    (fallback resiliente; UI sempre força escolha, mas API tolera ausência).
     */
    public function resolveSubtypeSlug(?string $subtype): string
    {
        if (! $this->hasSubtypes()) {
            return $this->slug();
        }

        foreach ($this->subtypes() as $variant) {
            if ($variant['slug'] === $subtype) {
                return $variant['slug'];
            }
        }

        return $this->slug();
    }

    /**
     * Label do subtipo, se válido para o exame; null se não encontrado.
     */
    public function subtypeLabel(?string $subtype): ?string
    {
        if (! $this->hasSubtypes() || $subtype === null) {
            return null;
        }

        foreach ($this->subtypes() as $variant) {
            if ($variant['slug'] === $subtype) {
                return $variant['label'];
            }
        }

        return null;
    }
}
