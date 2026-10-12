<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PartyPhotoUploadRequest;
use App\Models\Party;
use App\Services\PartyPhotoStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * FilePond "process" and "revert" endpoints. The token returned here is what the party form submits.
 */
class PartyPhotoUploadController extends Controller
{
    public function __construct(private readonly PartyPhotoStore $photos) {}

    public function store(PartyPhotoUploadRequest $request): Response
    {
        $file = $request->file('photo');
        abort_unless($file instanceof UploadedFile, 422);

        $token = $this->photos->storeTemporary($file);
        $request->session()->push(PartyPhotoStore::SESSION_KEY, $token);

        return response($token, 200, ['Content-Type' => 'text/plain']);
    }

    public function destroy(Request $request): Response
    {
        Gate::authorize('create', Party::class);

        $token = trim($request->getContent());
        $tokens = $request->session()->get(PartyPhotoStore::SESSION_KEY, []);

        if (in_array($token, $tokens, true)) {
            $this->photos->discardTemporary($token);
            $request->session()->put(PartyPhotoStore::SESSION_KEY, array_values(array_diff($tokens, [$token])));
        }

        return response()->noContent();
    }
}
