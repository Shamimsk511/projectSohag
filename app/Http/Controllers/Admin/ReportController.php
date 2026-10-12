<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DailyCollectionReportRequest;
use App\Http\Requests\Admin\MissedPartialReportRequest;
use App\Http\Requests\Admin\MonthlyReportRequest;
use App\Http\Requests\Admin\OutstandingReportRequest;
use App\Http\Requests\Admin\ReportPeriodRequest;
use App\Models\Area;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function dailyCollection(DailyCollectionReportRequest $request, ReportService $reports): View
    {
        return view('admin.reports.daily-collection', [
            'day' => $request->day(),
            'officer' => $request->officer(),
            'officers' => User::activeFieldOfficers()->orderBy('name')->get(['id', 'name']),
            'report' => $reports->dailyCollection($request->day(), $request->officer()),
        ]);
    }

    public function officerPerformance(ReportPeriodRequest $request, ReportService $reports): View
    {
        return view('admin.reports.officer-performance', [
            'from' => $request->periodFrom(),
            'to' => $request->periodTo(),
            'lines' => $reports->officerPerformance($request->periodFrom(), $request->periodTo()),
        ]);
    }

    public function outstanding(OutstandingReportRequest $request, ReportService $reports): View
    {
        return view('admin.reports.outstanding', [
            'area' => $request->area(),
            'overdueOnly' => $request->overdueOnly(),
            'areas' => Area::query()->orderBy('name')->get(['id', 'name']),
            'today' => today(config('app.display_timezone')),
            'report' => $reports->outstanding(
                Carbon::parse(today(config('app.display_timezone'))->toDateString()),
                $request->area(),
                $request->overdueOnly(),
            ),
        ]);
    }

    public function missedPartial(MissedPartialReportRequest $request, ReportService $reports): View
    {
        return view('admin.reports.missed-partial', [
            'from' => $request->periodFrom(),
            'to' => $request->periodTo(),
            'status' => $request->status(),
            'officer' => $request->officer(),
            'officers' => User::activeFieldOfficers()->orderBy('name')->get(['id', 'name']),
            'report' => $reports->missedAndPartial($request->periodFrom(), $request->periodTo(), $request->status(), $request->officer()),
        ]);
    }

    public function cashReconciliation(ReportPeriodRequest $request, ReportService $reports): View
    {
        return view('admin.reports.cash-reconciliation', [
            'from' => $request->periodFrom(),
            'to' => $request->periodTo(),
            'report' => $reports->cashReconciliation($request->periodFrom(), $request->periodTo()),
        ]);
    }

    public function monthly(MonthlyReportRequest $request, ReportService $reports): View
    {
        return view('admin.reports.monthly', [
            'month' => $request->month(),
            'report' => $reports->monthly($request->month()),
        ]);
    }
}
