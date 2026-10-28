<?php

declare(strict_types=1);

use App\Domains\AI\Providers\GeminiProvider;
use App\DTOs\AI\AiRequestData;
use App\Enums\AI\{AiRiskLevel, AiRunMode};
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    config()->set('services.gemini.api_key', 'test-key');
    config()->set('ai.providers.gemini.model', 'gemini-2.0-flash');
});

test('GeminiProvider envia imagem como inline_data (base64), não file_uri', function () {
    Http::fake([
        '*' => Http::response([
            'candidates'    => [['content' => ['parts' => [['text' => 'Achados compatíveis com...']]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            'modelVersion'  => 'gemini-2.0-flash',
        ], 200),
    ]);

    $request = new AiRequestData(
        workflow: 'eye_image_analysis',
        mode: AiRunMode::Economy,
        userPrompt: 'Analisar a imagem ocular.',
        systemPrompt: 'Você é um oftalmologista.',
        riskLevel: AiRiskLevel::Medium,
        attachments: [['mime_type' => 'image/jpeg', 'data' => base64_encode('fake-bytes')]],
    );

    app(GeminiProvider::class)->generate($request);

    Http::assertSent(function ($req) {
        $parts  = data_get($req->data(), 'contents.0.parts', []);
        $inline = collect($parts)->firstWhere('inline_data.data', base64_encode('fake-bytes'));

        return $inline !== null
            && data_get($inline, 'inline_data.mime_type') === 'image/jpeg'
            && collect($parts)->every(fn ($p) => ! isset($p['file_data']));
    });
});
