<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Closure;



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
    public function boot()
{
    \Event::listen(function (\App\Events\MessageSent $event) {
        \Log::info('MessageSent event fired', [
            'message_id' => $event->message->id,
            'conversation_id' => $event->message->conversation_id,
        ]);
    });
}

}
