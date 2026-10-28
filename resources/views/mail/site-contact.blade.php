<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><title>{{ __('site.contact.form.mail_subject') }}</title></head>
<body>
    <h1>{{ __('site.contact.form.mail_subject') }}</h1>
    <dl>
        @foreach (['name', 'email', 'phone', 'is_client', 'role', 'segment'] as $field)
            @if (!empty($submission[$field]))
                <dt>{{ __("site.contact.form.{$field}") }}</dt>
                <dd>{{ $submission[$field] }}</dd>
            @endif
        @endforeach
    </dl>
    <h2>{{ __('site.contact.form.message') }}</h2>
    <p style="white-space: pre-wrap; overflow-wrap: anywhere;">{{ $submission['message'] }}</p>
</body>
</html>
