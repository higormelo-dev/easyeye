<?php

declare(strict_types=1);

use App\Models\{Entity, PurchaseOrder, ReportSetting, Supplier};
use App\Services\MedicalRecordPdfService;
use Tests\TestCase;

/**
 * Telefone/CPF/CNPJ/CEP são gravados só com dígitos (FormRequests normalizam a
 * máscara do front). Os PDFs precisam formatar na exibição — senão o rodapé,
 * o cabeçalho da documentação e o pedido de compra imprimem "6133334444".
 *
 * Só modelos em memória (sem banco): nada aqui toca o Postgres de teste.
 */
uses(TestCase::class);

function pdfEntity(array $attributes = []): Entity
{
    return (new Entity())->forceFill(array_merge([
        'name'      => 'CLINICA TESTE',
        'telephone' => '6133334444',
        'cellphone' => '61999998888',
        'zipcode'   => '01310100',
        'city'      => 'BRASILIA',
        'state'     => 'DF',
    ], $attributes));
}

function invokePdfFooter(string $method, array $arguments): string
{
    $service = app(MedicalRecordPdfService::class);
    $path    = (new ReflectionMethod($service, $method))->invoke($service, ...$arguments);

    try {
        return (string) file_get_contents($path);
    } finally {
        @unlink($path);
    }
}

it('formata telefone, celular e CEP no rodapé dos PDFs clínicos', function () {
    $html = invokePdfFooter('buildFooterFile', [pdfEntity(), null]);

    expect($html)
        ->toContain('Tel: (61) 3333-4444')
        ->toContain('Cel: (61) 99999-8888')
        ->toContain('CEP 01310-100');
});

it('EE-14: rótulos Tel/Cel do rodapé respeitam o locale (pt_BR e en) — telefone em si nunca converte, é dado BR', function () {
    app()->setLocale('en');

    $html = invokePdfFooter('buildFooterFile', [pdfEntity(), null]);

    expect($html)
        ->toContain('Phone: (61) 3333-4444')
        ->toContain('Mobile: (61) 99999-8888');

    app()->setLocale('pt_BR');
});

it('renderiza o rodapé mais de uma vez no mesmo processo (sem função declarada no Blade)', function () {
    $partial = fn () => view('pdf.partials.footer', [
        'address'   => null,
        'telephone' => '6133334444',
        'cellphone' => null,
        'email'     => null,
    ])->render();

    expect($partial())->toContain('Tel: (61) 3333-4444')
        ->and($partial())->toContain('Tel: (61) 3333-4444');
});

it('formata o telefone no rodapé da documentação e cai para o celular quando o fixo está vazio', function () {
    $setting = (new ReportSetting())->forceFill([
        'show_footer'       => true,
        'footer_show_phone' => true,
        'footer_text'       => 'CLINICA TESTE',
    ]);

    expect(invokePdfFooter('buildDocumentationFooterFile', [$setting, pdfEntity()]))
        ->toContain('(61) 3333-4444')
        ->and(invokePdfFooter('buildDocumentationFooterFile', [$setting, pdfEntity(['telephone' => ''])]))
        ->toContain('(61) 99999-8888');
});

function renderPurchaseOrderPdf(Entity $entity): string
{
    $supplier = (new Supplier())->forceFill([
        'name'     => 'FORNECEDOR TESTE',
        'document' => '12345678000190',
        'phone'    => '6133334444',
    ]);

    $po = (new PurchaseOrder())->forceFill([
        'code'         => 'PO-0001',
        'status'       => 'draft',
        'total_amount' => 0,
    ]);
    $po->setRelation('supplier', $supplier);
    $po->setRelation('items', collect());

    return view('pdf.stock_purchase_order', [
        'po'          => $po,
        'entity'      => $entity,
        'generatedAt' => now(),
    ])->render();
}

it('formata CNPJ do comprador e documento/telefone do fornecedor no pedido de compra', function () {
    $html = renderPurchaseOrderPdf(pdfEntity(['national_registration' => '11222333000181']));

    expect($html)
        ->toContain('CNPJ: 11.222.333/0001-81')
        ->toContain('Documento: 12.345.678/0001-90')
        ->toContain('Telefone: (61) 3333-4444');
});

it('imprime CNPJ alfanumérico como gravado (não mutila as letras)', function () {
    $html = renderPurchaseOrderPdf(pdfEntity(['national_registration' => '12ABC345000195']));

    expect($html)->toContain('CNPJ: 12ABC345000195');
});

/**
 * tempnam() + concatenar ".html" no resultado CRIA um arquivo (vazio, o do
 * tempnam()) e ESCREVE em outro (o .html) — o primeiro nunca tinha mais
 * nenhuma referência pra limpar: um temporário órfão por PDF com rodapé,
 * sempre (achado via auditoria de encerramento, EE-10).
 */
function eePdfOrphanTempFiles(): array
{
    // Qualquer "eeye_*_" SEM ".html" no fim é exatamente o que tempnam()
    // deixava — glob() com negação de sufixo não existe, filtra em PHP.
    $all = glob(sys_get_temp_dir() . '/eeye_*') ?: [];

    return array_values(array_filter($all, fn ($f) => ! str_ends_with($f, '.html')));
}

it('[GAP] montar o rodapé não deixa o arquivo vazio do tempnam() órfão (EE-10)', function () {
    $before = eePdfOrphanTempFiles();

    $path = (new ReflectionMethod(app(MedicalRecordPdfService::class), 'buildFooterFile'))
        ->invoke(app(MedicalRecordPdfService::class), pdfEntity(), null);

    try {
        expect(eePdfOrphanTempFiles())->toBe($before);
    } finally {
        @unlink($path);
    }
});

it('arquivo temporário do rodapé é criado com permissões restritas (0600) — EE-10', function () {
    $path = (new ReflectionMethod(app(MedicalRecordPdfService::class), 'buildFooterFile'))
        ->invoke(app(MedicalRecordPdfService::class), pdfEntity(), null);

    try {
        expect(fileperms($path) & 0777)->toBe(0600);
    } finally {
        @unlink($path);
    }
});

it('EE-14: rodapé nativo do wkhtmltopdf (--footer-right, generatePdf() do prontuário completo) respeita o locale', function () {
    // [page]/[topage] são placeholders do PRÓPRIO wkhtmltopdf (substituídos
    // por ele depois da paginação, nunca pelo Laravel) — preservados
    // literais na tradução; só o texto em volta muda por locale. generatePdf()
    // em si não é re-testado aqui: exige o binário wkhtmltopdf de verdade e
    // um MedicalRecord com todas as relações, sem teste de ponta a ponta
    // existente hoje (nem antes desta mudança) — a única lógica NOVA deste
    // achado é esta chamada a __(), já coberta diretamente.
    expect(__('pdf.page_footer_native'))->toBe('Pág. [page] de [topage]');

    app()->setLocale('en');
    expect(__('pdf.page_footer_native'))->toBe('Page [page] of [topage]');
    app()->setLocale('pt_BR');
});

it('timeout do Snappy é finito, nunca "false" (sem limite) — EE-10', function () {
    expect(config('snappy.pdf.timeout'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('snappy.image.timeout'))->toBeInt()->toBeGreaterThan(0);
});
