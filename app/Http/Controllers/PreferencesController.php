<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserPreference;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

/**
 * Preferências pessoais do usuário (item MELHORIA "mais humano" — ver
 * UserPreference). Um único endpoint genérico de merge parcial em vez de
 * uma rota por preferência: cada widget de personalização (ordem do
 * Dashboard, atalhos favoritos, e futuramente toggle de notícias/playlist)
 * chama o mesmo PATCH com só a chave que mudou.
 */
class PreferencesController extends Controller
{
    /**
     * Allowlist de chaves aceitas no bag. Único ponto a tocar quando uma
     * nova preferência entrar (ex.: `news_enabled`, `playlist_url`) —
     * evita que o client grave uma chave/payload arbitrário.
     */
    private const ALLOWED_KEYS = [
        'dashboard_widget_order',
        'favorite_shortcuts',
        'medical_record_layout',
        // Modelo pessoal do prontuário em TEXTO LIVRE (ticket "simplificar
        // texto livre"): um texto salvo pelo médico (ex.: esqueleto HDA:/AP:/
        // AV:/BIO:/FO:/HD:/CD:) que pré-preenche a caixa nos próximos
        // atendimentos. Diferente do layout estruturado acima.
        'free_text_template',
    ];

    /**
     * Seções personalizáveis do prontuário (espelha SECTION_DEFS em
     * MedicalRecordForm.vue). Queixa/antecedentes e Campos adicionais são
     * fixos — não entram aqui de propósito (main_complaint é obrigatória).
     *
     * @var list<string>
     */
    private const RECORD_SECTIONS = [
        'cromatica_ppc_cover', 'av_sem_tono', 'dinamica', 'estatica',
        'adicao', 'av_com', 'biomicroscopia', 'fundoscopia', 'obs_geral',
    ];

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'dashboard_widget_order'      => ['sometimes', 'array'],
            'dashboard_widget_order.*'    => ['string'],
            'favorite_shortcuts'          => ['sometimes', 'array'],
            'favorite_shortcuts.*.key'    => ['required_with:favorite_shortcuts', 'string'],
            'favorite_shortcuts.*.hidden' => ['sometimes', 'boolean'],
            // Prontuário personalizado por médico: modo default de abertura
            // (padrão EasyEye / meu modelo / texto livre) + layout customizado
            // (ordem por coluna + seções ocultas). Chaves de seção validadas
            // contra a lista fixa — client não grava chave arbitrária no bag.
            'medical_record_layout'                 => ['sometimes', 'array'],
            'medical_record_layout.default_mode'    => ['required_with:medical_record_layout', Rule::in(['default', 'custom', 'free'])],
            'medical_record_layout.custom'          => ['sometimes', 'nullable', 'array'],
            'medical_record_layout.custom.left'     => ['sometimes', 'array'],
            'medical_record_layout.custom.left.*'   => [Rule::in(self::RECORD_SECTIONS)],
            'medical_record_layout.custom.right'    => ['sometimes', 'array'],
            'medical_record_layout.custom.right.*'  => [Rule::in(self::RECORD_SECTIONS)],
            'medical_record_layout.custom.hidden'   => ['sometimes', 'array'],
            'medical_record_layout.custom.hidden.*' => [Rule::in(self::RECORD_SECTIONS)],
            'free_text_template'                    => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        // Request::only() já filtra pra só as chaves permitidas — qualquer
        // outra coisa no body é ignorada silenciosamente (não é erro do
        // client, só não faz parte do bag ainda).
        $partial = $request->only(self::ALLOWED_KEYS);

        if (empty($partial)) {
            return response()->json([
                'message' => 'Nenhuma preferência reconhecida enviada.',
            ], 422);
        }

        $pref = UserPreference::mergeFor($request->user(), $partial);

        return response()->json(['data' => $pref->data]);
    }
}
