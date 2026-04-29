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
}
