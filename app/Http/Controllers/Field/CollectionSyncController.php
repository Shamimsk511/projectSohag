<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Http\Requests\Field\SyncCollectionsRequest;
use App\Services\CollectionSyncService;
use Illuminate\Http\JsonResponse;

/**
 * Receives the PWA's offline collection queue (docs/PWA.md "Offline sync protocol").
 */
class CollectionSyncController extends Controller
{
    public function __invoke(SyncCollectionsRequest $request, CollectionSyncService $sync): JsonResponse
    {
        return response()->json(['results' => $sync->sync($request->items(), $this->actor())]);
    }
}
