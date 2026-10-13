<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Public homepage; a signed-in user also gets a link to their work area.
     */
    public function __invoke(Request $request): View
    {
        return view('home', [
            'workspaceRoute' => $request->user()?->homeRoute(),
        ]);
    }
}
