<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    private array $allowed = ['en', 'sq', 'mk'];

    public function handle(Request $request, Closure $next)
    {
        $lang = $request->query('lang')
            ?? $request->header('X-Locale')
            ?? $this->fromAcceptLanguage($request->header('Accept-Language'));

        if (!in_array($lang, $this->allowed, true)) {
            $lang = 'en';
        }

        app()->setLocale($lang);

        return $next($request);
    }

    private function fromAcceptLanguage(?string $al): string
    {
        if (!$al) return 'en';
        $first = strtolower(substr(trim($al), 0, 2));
        return in_array($first, $this->allowed, true) ? $first : 'en';
    }
}
