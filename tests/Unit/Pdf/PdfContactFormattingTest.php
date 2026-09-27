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
