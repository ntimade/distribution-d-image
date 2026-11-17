<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Refuse l'ouverture de WhatsApp ou des SMS tant que l'envoi des messages est désactivé (config/messaging.php).
class EnsureMessagingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('messaging.enabled')) {
            return back()->with('error', __('Envoi des messages WhatsApp et SMS désactivé'));
        }

        return $next($request);
    }
}
