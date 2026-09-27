{{--
    Footer HTML para wkhtmltopdf (--footer-html).
    Renderizado em contexto próprio, separado do PDF principal.

    Variáveis esperadas:
        $address     (string|null)  Endereço completo formatado
        $telephone   (string|null)  Dígitos do telefone fixo
        $cellphone   (string|null)  Dígitos do celular
        $email       (string|null)
        $fontFamily  (string)       Família da fonte (default: Arial)
--}}
@php
    // BrazilianFormat em vez de função declarada no Blade: `function` aqui dá
    // "Cannot redeclare" quando o rodapé é renderizado 2x no mesmo processo.
    $contacts = array_filter([
        ($telephone ?? null) ? 'Tel: ' . \App\Support\BrazilianFormat::phone($telephone) : null,
        ($cellphone ?? null) ? 'Cel: ' . \App\Support\BrazilianFormat::phone($cellphone) : null,
        $email ?? null,
    ]);
@endphp
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:5px 0 0;font-family:{{ $fontFamily ?? 'Arial' }},Helvetica,sans-serif;font-size:7.5pt;color:#888;text-align:center;border-top:1px solid #ddd;">
    @if($address ?? null)
        <div>{{ $address }}</div>
    @endif
    @if(count($contacts))
        <div>{{ implode(' · ', $contacts) }}</div>
    @endif
</body>
</html>
