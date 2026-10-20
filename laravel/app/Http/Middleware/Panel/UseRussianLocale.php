<?php

namespace App\Http\Middleware\Panel;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The panel UI is Russian; the rest of the app keeps config('app.locale'). */
class UseRussianLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('ru');
        Carbon::setLocale('ru');

        return $next($request);
    }
}
