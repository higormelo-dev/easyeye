<?php

use App\Providers\{AppServiceProvider, AuthServiceProvider, BillingServiceProvider, RecallServiceProvider, SubscriptionServiceProvider, TenantScopeServiceProvider, TissServiceProvider};
use Spatie\Html\HtmlServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    SubscriptionServiceProvider::class,
    TissServiceProvider::class,
    BillingServiceProvider::class,
    RecallServiceProvider::class,
    TenantScopeServiceProvider::class,
    HtmlServiceProvider::class,
];
