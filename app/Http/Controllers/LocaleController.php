<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Remember the chosen language for this browser session and go back.
     */
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $request->session()->put(SetLocale::SESSION_KEY, $locale);

        return redirect()->back(fallback: route('home'));
    }
}
