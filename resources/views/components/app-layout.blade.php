<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{{ config('app.name') }}</title></head>
<body>
@isset($header)<header>{{ $header }}</header>@endisset
{{ $slot }}
</body>
</html>
