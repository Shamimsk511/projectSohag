<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardRequest;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\ReportService;
use App\Services\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, ReportService $reports, WhatsAppService $whatsapp): View
    {
        return view('admin.dashboard', $this->data($request->day(), $reports, $whatsapp) + [
            'position' => $request->user()?->can(Permission::ViewAllReports->value)
                ? $reports->position(Carbon::parse(today(config('app.display_timezone'))->toDateString()))
                : null,
            'readyToClose' => $request->user()?->can('create', Account::class) ? $reports->readyToClose() : [],
        ]);
    }

    /**
     * The part of the dashboard that refreshes by itself (figures, officers, activity feed), as HTML.
     */
    public function live(DashboardRequest $request, ReportService $reports, WhatsAppService $whatsapp): View
    {
        return view('admin.dashboard._live', $this->data($request->day(), $reports, $whatsapp));
    }

    /**
     * @return array{day: Carbon, figures: array<string, mixed>, activity: Collection<int, Transaction>, reminded: array<int, string>}
     */
    private function data(Carbon $day, ReportService $reports, WhatsAppService $whatsapp): array
    {
        $figures = $reports->dashboard($day);
        $ids = collect($figures['unpaid']['rows'])
            ->merge(collect($figures['officers'])->flatMap(fn (array $officer): array => $officer['members']))
            ->map(fn (array $line): int => $line['schedule']->id)->unique()->values()->all();

        return [
            'day' => $day,
            'figures' => $figures,
            'activity' => $reports->activity($day),
            'reminded' => $whatsapp->reminderLabels($ids, $day),
        ];
    }
}
