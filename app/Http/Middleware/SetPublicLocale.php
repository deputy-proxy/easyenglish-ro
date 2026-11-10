<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPublicLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('locale')) {
            $request->session()->put('locale', 'ro');
        }

        app()->setLocale('ro');
        CarbonImmutable::setLocale('ro');

        return $next($request);
    }
}
