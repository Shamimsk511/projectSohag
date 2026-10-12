<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardRequest;
use App\Services\ReportService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, ReportService $reports): View
    {
        $day = $request->day();

        return view('admin.dashboard', [
            'day' => $day,
            'figures' => $reports->dashboard($day),
        ]);
    }
}
