<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBillingEnabled
{
    /**
     * The billing pages exist only while پیگیر charges. Free, they answer
     * "not found" rather than showing prices nobody is asked to pay.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('payment.enabled'), 404);

        return $next($request);
    }
}
