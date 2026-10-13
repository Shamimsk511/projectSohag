<?php

namespace App\Services;

use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class PartyService
{
    public function __construct(
        private readonly PartyPhotoStore $photos,
        private readonly AuditLogger $audit,
        private readonly AssignmentService $assignments,
    ) {}

    /**
     * Create a party, give it its P-00001 code, attach the uploaded photo if any and hand it to a
     * field officer when one is given (same DB transaction: all or nothing).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?string $photoToken, User $actor, ?User $officer = null): Party
    {
        $photoPath = $photoToken === null ? null : $this->photos->claim($photoToken);

        try {
            return DB::transaction(function () use ($attributes, $photoPath, $actor, $officer): Party {
                $party = Party::create([...$attributes, 'photo_path' => $photoPath]);
                $party->forceFill(['party_code' => Party::codeFor($party->id)])->save();
                $this->audit->created('party.created', $party, $actor);

                if ($officer !== null) {
                    $this->assignments->assign($party, $officer, $actor);
                }

                return $party;
            });
        } catch (Throwable $e) {
            $this->photos->delete($photoPath);

            throw $e;
        }
    }

    /**
     * Update a party. A new photo replaces the old one, whose file is removed once the row is saved.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Party $party, array $attributes, ?string $photoToken, User $actor): Party
    {
        $oldPhoto = $party->photo_path;

        if ($photoToken !== null) {
            $attributes['photo_path'] = $this->photos->claim($photoToken);
        }

        try {
            DB::transaction(function () use ($party, $attributes, $actor): void {
                $party->update($attributes);
                $this->audit->updated('party.updated', $party, $actor);
            });
        } catch (Throwable $e) {
            $this->photos->delete($photoToken === null ? null : $attributes['photo_path']);

            throw $e;
        }

        if ($photoToken !== null) {
            $this->photos->delete($oldPhoto);
        }

        return $party;
    }
}
