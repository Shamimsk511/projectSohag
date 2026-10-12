<?php

namespace App\Services;

use App\Models\Party;
use Illuminate\Support\Facades\DB;
use Throwable;

class PartyService
{
    public function __construct(private readonly PartyPhotoStore $photos) {}

    /**
     * Create a party, give it its P-00001 code and attach the uploaded photo if any.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?string $photoToken): Party
    {
        $photoPath = $photoToken === null ? null : $this->photos->claim($photoToken);

        try {
            return DB::transaction(function () use ($attributes, $photoPath): Party {
                $party = Party::create([...$attributes, 'photo_path' => $photoPath]);
                $party->forceFill(['party_code' => Party::codeFor($party->id)])->save();

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
    public function update(Party $party, array $attributes, ?string $photoToken): Party
    {
        $oldPhoto = $party->photo_path;

        if ($photoToken !== null) {
            $attributes['photo_path'] = $this->photos->claim($photoToken);
        }

        try {
            $party->update($attributes);
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
