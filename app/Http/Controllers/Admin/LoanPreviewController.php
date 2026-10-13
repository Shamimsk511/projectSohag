<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoanPreviewRequest;
use App\Services\CollectionService;
use App\Support\Numerals;
use Illuminate\Http\JsonResponse;

/**
 * Live preview on the loan open form: installments and estimated dates, computed exactly as opening
 * and the schedule generator will. Display only.
 */
class LoanPreviewController extends Controller
{
    public function __invoke(LoanPreviewRequest $request, CollectionService $collections): JsonResponse
    {
        $installments = $request->installments();
        $plan = $request->collectionPlan();

        if ($installments === null || $plan === null) {
            return response()->json(['ready' => false]);
        }

        $dates = $collections->projectDates($plan->draft(), $plan->startsOn, $installments->count);
        $date = fn (int $index): ?string => isset($dates[$index]) ? Numerals::localize($dates[$index]->format('d/m/Y')) : null;

        return response()->json([
            'ready' => true,
            'contract' => $request->principalAmount()->plus($request->agreedExtra())->format(),
            'to_collect' => $installments->total->format(),
            'installment' => $installments->installment->format(),
            'installment_taka' => $installments->installment->toTaka(),
            'count' => $installments->count,
            'count_label' => Numerals::localize((string) $installments->count),
            'last_installment' => $installments->last()->format(),
            'first_date' => $date(0),
            'last_date' => $date(count($dates) - 1),
        ]);
    }
}
