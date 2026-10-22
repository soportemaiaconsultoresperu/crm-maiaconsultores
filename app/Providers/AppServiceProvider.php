<?php

namespace App\Providers;

use App\Services\Content\ContentFieldValueUsage;
use App\Services\Content\DatabaseContentFieldValueUsage;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // T6b: activate the field-type immutability defined in T5a by resolving
        // the real value-usage implementation instead of the null default.
        $this->app->bind(ContentFieldValueUsage::class, DatabaseContentFieldValueUsage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // AdminLTE 4 / Bootstrap 5 pagination views (ADR-010).
        Paginator::useBootstrapFive();
    }
}
