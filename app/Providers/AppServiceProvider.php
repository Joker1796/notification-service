<?php

namespace App\Providers;

use App\Providers\Notification\MockEmailProvider;
use App\Providers\Notification\MockSmsProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Concrete providers are auto-resolved by the DI container.
        // NotificationProviderFactory receives them via constructor injection.
    }

    public function boot(): void {}
}
