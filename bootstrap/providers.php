<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\AutomationServiceProvider;
use App\Providers\ConsentServiceProvider;
use App\Providers\EmailServiceProvider;
use App\Providers\IntegrationsServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\WhatsAppServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    IntegrationsServiceProvider::class,
    AutomationServiceProvider::class,
    ConsentServiceProvider::class,
    EmailServiceProvider::class,
    WhatsAppServiceProvider::class,
    NotificationServiceProvider::class,
];