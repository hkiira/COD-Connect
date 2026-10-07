<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // a status change of an order that came from WooCommerce is pushed back to the store
        \App\Models\Order::observe(\App\Observers\OrderObserver::class);
    }
}
