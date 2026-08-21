<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Models live under app/Domain/<Context>/Models, not app/Models, so
        // Laravel's default guess (Database\Factories\Domain\...\XFactory)
        // never resolves. Every factory lives flat in database/factories and
        // is named after the model's class basename.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\Factories\\'.class_basename($modelName).'Factory'
        );
    }
}
