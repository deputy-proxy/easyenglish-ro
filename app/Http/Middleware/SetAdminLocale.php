<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('en');
        CarbonImmutable::setLocale('en');

        return $next($request);
    }
}
