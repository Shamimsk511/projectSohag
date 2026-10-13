<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardRequest;
use App\Models\Transaction;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, ReportService $reports): View
    {
        return view('admin.dashboard', $this->data($request->day(), $reports));
    }

    /**
     * The part of the dashboard that refreshes by itself (figures, officers, activity feed), as HTML.
     */
    public function live(DashboardRequest $request, ReportService $reports): View
    {
        return view('admin.dashboard._live', $this->data($request->day(), $reports));
    }

    /**
     * @return array{day: Carbon, figures: array<string, mixed>, activity: Collection<int, Transaction>}
     */
    private function data(Carbon $day, ReportService $reports): array
    {
        return [
            'day' => $day,
            'figures' => $reports->dashboard($day),
            'activity' => $reports->activity($day),
        ];
    }
}
