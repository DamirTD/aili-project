<?php

namespace App\Providers;

use App\Application\Diagnosis\Queries\AnalyzeDiagnosisHandler;
use App\Application\Diagnosis\Queries\AnalyzeDiagnosisQuery;
use App\Application\Shared\QueryBus\InMemoryQueryBus;
use App\Application\Shared\QueryBus\QueryBus;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(QueryBus::class, function ($app): QueryBus {
            return new InMemoryQueryBus($app, [
                AnalyzeDiagnosisQuery::class => AnalyzeDiagnosisHandler::class,
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
