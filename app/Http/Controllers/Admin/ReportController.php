<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DailyCollectionReportRequest;
use App\Http\Requests\Admin\MissedPartialReportRequest;
use App\Http\Requests\Admin\MonthlyReportRequest;
use App\Http\Requests\Admin\OutstandingReportRequest;
use App\Http\Requests\Admin\ReportAccessRequest;
use App\Http\Requests\Admin\ReportPeriodRequest;
use App\Models\Area;
use App\Models\User;
use App\Services\ReportService;
use App\Services\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * Reports hub: every report as a tile, grouped by what it answers.
     */
    public function index(ReportAccessRequest $request): View
    {
        return view('admin.reports.index');
    }

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

    public function missedPartial(MissedPartialReportRequest $request, ReportService $reports, WhatsAppService $whatsapp): View
    {
        $report = $reports->missedAndPartial($request->periodFrom(), $request->periodTo(), $request->status(), $request->officer());

        return view('admin.reports.missed-partial', [
            'from' => $request->periodFrom(),
            'to' => $request->periodTo(),
            'status' => $request->status(),
            'officer' => $request->officer(),
            'officers' => User::activeFieldOfficers()->orderBy('name')->get(['id', 'name']),
            'report' => $report,
            'reminded' => $whatsapp->reminderLabels(
                array_map(fn (array $line): int => $line['schedule']->id, $report['rows']),
                Carbon::parse(today(config('app.display_timezone'))->toDateString()),
            ),
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

    public function position(ReportAccessRequest $request, ReportService $reports): View
    {
        return view('admin.reports.position', [
            'position' => $reports->position(Carbon::parse(today(config('app.display_timezone'))->toDateString())),
        ]);
    }

    public function monthly(MonthlyReportRequest $request, ReportService $reports): View
    {
        return view('admin.reports.monthly', [
            'month' => $request->month(),
            'report' => $reports->monthly($request->month()),
        ]);
    }

    public function loanRegister(ReportPeriodRequest $request, ReportService $reports): View
    {
        return view('admin.reports.loan-register', [
            'from' => $request->periodFrom(),
            'to' => $request->periodTo(),
            'report' => $reports->loanRegister($request->periodFrom(), $request->periodTo()),
        ]);
    }

    public function withdrawalRegister(ReportPeriodRequest $request, ReportService $reports): View
    {
        return view('admin.reports.withdrawal-register', [
            'from' => $request->periodFrom(),
            'to' => $request->periodTo(),
            'report' => $reports->withdrawalRegister($request->periodFrom(), $request->periodTo()),
        ]);
    }

    public function dailyCashBook(ReportPeriodRequest $request, ReportService $reports): View
    {
        return view('admin.reports.daily-cash-book', [
            'from' => $request->periodFrom(),
            'to' => $request->periodTo(),
            'report' => $reports->dailyCashBook($request->periodFrom(), $request->periodTo()),
        ]);
    }
}
