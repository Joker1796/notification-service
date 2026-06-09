<?php

namespace App\Providers;

use App\Providers\Notification\MockEmailProvider;
use App\Providers\Notification\MockSmsProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MockSmsProvider::class, fn () => new MockSmsProvider());
        $this->app->bind(MockEmailProvider::class, fn () => new MockEmailProvider());
    }

    public function boot(): void {}
}
