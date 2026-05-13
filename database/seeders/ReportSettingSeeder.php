<?php

namespace Database\Seeders;

use App\Enums\{PaperSize, ReportSettingStatus};
use App\Models\{ReportCategory, ReportSetting};
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ReportSettingSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Ensure categories exist first
        $this->call(ReportCategorySeeder::class);

        $categories = ReportCategory::pluck('id', 'slug');

        $defaults = [
            'entity_id'         => null,
            'paper_size'        => PaperSize::A5,
            'font_family'       => 'Arial, Helvetica, sans-serif',
            'font_size'         => 12,
            'margin_top'        => 0,
            'margin_right'      => 1.5,
            'margin_bottom'     => 0,
            'margin_left'       => 1.5,
            'patient_font_size' => 16,
            'patient_alignment' => 'center',
            'patient_color'     => '#FF0000',
            // Cabeçalho
            'show_header'         => true,
            'header_show_logo'    => true,
            'header_show_name'    => true,
            'header_show_address' => false,
            'header_show_phone'   => false,
            // Assinatura
            'show_signature'      => true,
            'signature_show_name' => true,
            'signature_show_crm'  => true,
            'signature_show_rqe'  => false,
            'signature_bottom'    => 1.5,
            'signature_alignment' => 'center',
            // Rodapé
            'show_logo'           => true,
            'show_footer'         => true,
            'footer_show_address' => false,
            'footer_show_phone'   => false,
            // Versionamento
            'version'      => 1,
            'status'       => ReportSettingStatus::Published,
            'published_at' => now(),
            'active'       => true,
        ];

        // Cada entrada pode sobrescrever defaults. 'title' é chave de busca. 'category' referencia slug.
        $settings = [
            // ── Laudos ──────────────────────────────────────────────────────
            ['title' => 'LAUDO DE TONÔMETRIA', 'category' => 'laudos'],
            ['title' => 'LAUDO DE ECOBIOMETRIA', 'category' => 'laudos'],
            ['title' => 'LAUDO DE GONIOSCOPIA', 'category' => 'laudos'],
            ['title' => 'LAUDO DE ECOGRAFIA', 'category' => 'laudos'],
            ['title' => 'LAUDO OFTALMOLÓGICO', 'category' => 'laudos'],
            ['title' => 'LAUDO DE ANGIOFLUORESCEINOGRAFIA', 'category' => 'laudos', 'paper_size' => PaperSize::A4],
            // ── Exames especializados ────────────────────────────────────────
            ['title' => 'MICROSCOPIA ESPECULAR', 'category' => 'exames-especializados'],
            ['title' => 'PAQUIMETRIA CORNEANA', 'category' => 'exames-especializados'],
            ['title' => 'TOPOGRAFIA CORNEANA', 'category' => 'exames-especializados'],
            ['title' => 'CAMPIMETRIA COMPUTADORIZADA', 'category' => 'exames-especializados'],
            ['title' => 'CURVA TENSIONAL DIÁRIA', 'category' => 'exames-especializados'],
            ['title' => 'MAPEAMENTO DE RETINA', 'category' => 'exames-especializados', 'paper_size' => PaperSize::A4],
            ['title' => 'RETINOGRAFIA COLORIDA', 'category' => 'exames-especializados'],
            ['title' => 'OCT (TOMOGRAFIA DE COERÊNCIA ÓTICA)', 'category' => 'exames-especializados'],
            ['title' => 'PENTACAM', 'category' => 'exames-especializados'],
            ['title' => 'ESTUDO COMPUTADORIZADO DA LÁGRIMA', 'category' => 'exames-especializados'],
            ['title' => 'POTENCIAL DE ACUIDADE VISUAL', 'category' => 'exames-especializados'],
            // ── Prescrições ──────────────────────────────────────────────────
            ['title' => 'PRESCRIÇÃO DE LENTES', 'category' => 'receituarios'],
            ['title' => 'PRESCRIÇÃO DE MEDICAMENTOS', 'category' => 'receituarios'],
            ['title' => 'RECEITUÁRIO DE PTERÍGIO', 'category' => 'receituarios'],
            ['title' => 'RECEITUÁRIO DE CATARATA', 'category' => 'receituarios'],
            // ── Atestados ────────────────────────────────────────────────────
            ['title' => 'ATESTADO MÉDICO', 'category' => 'atestados'],
            ['title' => 'ATESTADO DE COMPARECIMENTO', 'category' => 'atestados'],
            // ── Relatórios ───────────────────────────────────────────────────
            ['title' => 'RELATÓRIO OFTALMOLÓGICO', 'category' => 'relatorios'],
            // ── Solicitações / Procedimentos ─────────────────────────────────
            ['title' => 'SOLICITAÇÃO DE PROCEDIMENTOS', 'category' => 'procedimentos'],
            ['title' => 'AVALIAÇÃO CARDIOLÓGICA E RISCO CIRÚRGICO', 'category' => 'procedimentos'],
            ['title' => 'GLICEMIA DE JEJUM', 'category' => 'procedimentos'],
            ['title' => 'HEMOGRAMA COMPLETO E COAGULOGRAMA', 'category' => 'procedimentos'],
            // ── Procedimentos Laser ──────────────────────────────────────────
            ['title' => 'YAG LASER', 'category' => 'laser'],
            ['title' => 'FOTOCOAGULAÇÃO COM LASER DE ARGÔNIO', 'category' => 'laser'],
            // ── Triagem ──────────────────────────────────────────────────────
            ['title' => 'TESTE DO OLHINHO', 'category' => 'triagem'],
        ];

        foreach ($settings as $attrs) {
            $title        = $attrs['title'];
            $categorySlug = $attrs['category'] ?? null;
            $overrides    = array_diff_key($attrs, ['title' => '', 'category' => '']);

            if ($categorySlug && isset($categories[$categorySlug])) {
                $overrides['report_category_id'] = $categories[$categorySlug];
            }

            ReportSetting::updateOrCreate(
                ['title' => $title, 'entity_id' => null],
                array_merge($defaults, $overrides),
            );
        }
    }
}
