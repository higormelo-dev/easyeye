(function () {
    window.{{ config('datatables-html.namespace', 'LaravelDataTables') }} = window.{{ config('datatables-html.namespace', 'LaravelDataTables') }} || {};
    function init() {
        window.{{ config('datatables-html.namespace', 'LaravelDataTables') }}["%1$s"] = window.jQuery("#%1$s").DataTable(%2$s);
    }
    if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.DataTable === 'function') {
        init();
    } else {
        window.addEventListener('load', init);
    }
})();
@foreach ($scripts as $script)
@include($script)
@endforeach
