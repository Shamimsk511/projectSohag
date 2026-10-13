<?php

namespace App\Http\Controllers;

use App\Enums\WhatsAppKind;
use App\Http\Requests\LogWhatsAppRequest;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Models\Transaction;
use App\Services\WhatsAppService;
use App\Support\WhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Records that a WhatsApp button was tapped (admin and field areas) and returns the label to show.
 */
class WhatsAppLogController extends Controller
{
    public function __invoke(LogWhatsAppRequest $request, WhatsAppService $whatsapp): JsonResponse
    {
        $kind = $request->kind();
        $schedule = null;
        $transaction = null;

        if ($kind === WhatsAppKind::Portal) {
            $party = Party::query()->with('portalAccess')->findOrFail($request->integer('party_id'));
            Gate::authorize('managePortal', $party);
            abort_if($party->portalAccess === null, 422);
        } elseif ($kind === WhatsAppKind::Reminder) {
            $schedule = CollectionSchedule::query()->with('party')->findOrFail($request->integer('schedule_id'));
            Gate::authorize('remind', $schedule);
            $party = $schedule->party;
        } else {
            $transaction = Transaction::query()->with(['party', 'account'])->findOrFail($request->integer('transaction_id'));
            Gate::authorize($kind === WhatsAppKind::Receipt ? 'viewReceipt' : 'shareNotice', $transaction);
            abort_if($transaction->reversal()->exists(), 422);
            abort_if($kind === WhatsAppKind::Receipt && $transaction->type->isOffice(), 422);
            $party = $transaction->party;
        }
        abort_if(WhatsApp::link($party?->phone, '') === null, 422);
        assert($party !== null);

        $log = $whatsapp->record($kind, $party, $schedule, $transaction, $this->actor());

        return response()->json(['label' => $whatsapp->label($log)]);
    }
}
