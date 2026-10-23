<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // The app had no scheduler at all before this. Without it the sweep
        // exists but never runs, and bookings nobody answered would sit open
        // forever, quietly inflating the count of live enquiries.
        $schedule->command('parcel:sweep-bookings')->hourly();

        // An auction whose time is up has to close itself. The service that
        // closes one existed from the start; nothing called it, so every
        // expired lot stayed LIVE — which meant the storefront was selling
        // auctions that had already closed and the console's live count was
        // wrong. Every minute, because an auction that closes late is an
        // auction that shows a dead countdown.
        $schedule->command('lots:close-expired')->everyMinute();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
