<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-layout="mini"
      data-sidebar="light"
      data-topbar="white"
      data-color="info">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- LGPD/CFM: painel autenticado contém dados de paciente (PII + dados
         sensíveis de saúde). Bloqueia indexação por buscadores e prévia
         em redes sociais — qualquer link compartilhado por engano de um
         médico não deve gerar cache externo com PII. --}}
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>{{ config('app.name', 'EasyEye') }}</title>
    {{-- Favicon: SVG escalonável (browsers modernos) + ICO fallback + PNG 192 PWA --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="192x192" href="{{ asset('favicon-192.png') }}">
    {{-- Tema salvo pelo toggle do header (AppLayout.toggleDark grava em
         localStorage). Aplicar ANTES do theme-script/CSS: sem isso o dark
         mode morre a cada full reload — o theme-script só lê o atributo
         data-bs-theme já presente no <html> + sessionStorage próprio. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('ee-panel-theme');
                if (t !== 'dark' && t !== 'light') return;
                document.documentElement.setAttribute('data-bs-theme', t);
                // theme-script dá precedência ao __THEME_CONFIG__ do
                // sessionStorage sobre o atributo — sincronizar, senão ele
                // reaplica o tema antigo por cima deste boot.
                var raw = sessionStorage.getItem('__THEME_CONFIG__');
                if (raw) {
                    var cfg = JSON.parse(raw);
                    cfg.theme = t;
                    sessionStorage.setItem('__THEME_CONFIG__', JSON.stringify(cfg));
                }
            } catch (e) { /* storage bloqueado: segue tema default */ }
        })();
    </script>
    {{-- Theme config: executa antes do paint para evitar flash de tema --}}
    <script src="{{ asset('js/preclinic-theme-script.js') }}"></script>
    {{-- jQuery síncrono: vendor.js + alguns plugins Bootstrap dependem de $ global
         disponível antes dos ES modules executarem. --}}
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    @routes
    @vite(['resources/css/vendor.css', 'resources/css/system.scss', 'resources/js/vendor.js', 'resources/js/panel.js'])
    @inertiaHead
</head>
<body>
    {{-- Globals consumidos por componentes Vue (AppLayout, Patients/Index,
         Schedules/CalendarView, LiveStatusBar, SlotPicker). --}}
    <script>
        window.translations = {
            messages: {
                "register":             "{{ __('actions.messages.register') }}",
                "edit":                 "{{ __('actions.messages.edit') }}",
                "view":                 "{{ __('actions.messages.view') }}",
                "delete_confirm_title": "{{ __('actions.messages.delete_confirm_title') }}",
                "delete_confirm_text":  "{{ __('actions.messages.delete_confirm_text') }}",
                "restore_confirm_title":"{{ __('actions.messages.restore_confirm_title') }}",
                "restore_confirm_text": "{{ __('actions.messages.restore_confirm_text') }}",
                "confirm_yes":          "{{ __('actions.messages.confirm_yes') }}",
                "confirm_no":           "{{ __('actions.messages.confirm_no') }}"
            },
            actions: {
                "covertesttype":        "{{ mb_convert_case(__('actions.covertesttype'), MB_CASE_LOWER, 'UTF-8') }}",
                "surgerytype":          "{{ mb_convert_case(__('actions.surgerytype'), MB_CASE_LOWER, 'UTF-8') }}",
                "skintype":             "{{ mb_convert_case(__('actions.skintype'), MB_CASE_LOWER, 'UTF-8') }}",
                "iristype":             "{{ mb_convert_case(__('actions.iristype'), MB_CASE_LOWER, 'UTF-8') }}",
                "visittype":            "{{ mb_convert_case(__('actions.visittype'), MB_CASE_LOWER, 'UTF-8') }}",
                "covenant":             "{{ mb_convert_case(__('actions.covenant'), MB_CASE_LOWER, 'UTF-8') }}",
                "doctor":               "{{ mb_convert_case(__('actions.doctor'), MB_CASE_LOWER, 'UTF-8') }}",
                "patient":              "{{ mb_convert_case(__('actions.patient'), MB_CASE_LOWER, 'UTF-8') }}",
                "user":                 "{{ mb_convert_case(__('actions.user'), MB_CASE_LOWER, 'UTF-8') }}",
                "colorvisiontype":      "{{ mb_convert_case(__('actions.colorvisiontype'), MB_CASE_LOWER, 'UTF-8') }}",
                "additiontype":         "{{ mb_convert_case(__('actions.additiontype'), MB_CASE_LOWER, 'UTF-8') }}",
                "visualacuitytype":     "{{ mb_convert_case(__('actions.visualacuitytype'), MB_CASE_LOWER, 'UTF-8') }}",
                "lense":                "{{ mb_convert_case(__('actions.lense'), MB_CASE_LOWER, 'UTF-8') }}",
                "nearpointconvergence": "{{ mb_convert_case(__('actions.nearpointconvergence'), MB_CASE_LOWER, 'UTF-8') }}"
            }
        };
        window.sessionLifetimeMs = {{ config('session.lifetime') * 60 * 1000 }};
        window.sessionLocale     = '{{ str_replace('_', '-', app()->getLocale()) }}';
    </script>

    @inertia
</body>
</html>
