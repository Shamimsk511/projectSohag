<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Send the signed-in user to the area their role works in.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $route = $request->user()?->homeRoute();

        abort_if($route === null, 403);

        return redirect()->route($route);
    }
}
