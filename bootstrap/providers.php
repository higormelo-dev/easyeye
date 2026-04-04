<?php

use App\Providers\{AppServiceProvider, AuthServiceProvider, SubscriptionServiceProvider, TissServiceProvider};
use Spatie\Html\HtmlServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    SubscriptionServiceProvider::class,
    TissServiceProvider::class,
    HtmlServiceProvider::class,
];
