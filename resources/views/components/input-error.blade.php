@foreach ((array) ($messages ?? []) as $message)
    <span {{ $attributes }}>{{ $message }}</span>
@endforeach
