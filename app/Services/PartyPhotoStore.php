<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Party photos on the private disk. Uploads are scaled down and re-encoded as JPEG on arrival
 * (which also drops EXIF such as GPS), parked under tmp/ until the party form is saved, then moved.
 */
class PartyPhotoStore
{
    /** Session key holding temp tokens issued to this session. */
    public const string SESSION_KEY = 'party_photo_tokens';

    private const int MAX_EDGE = 800;

    private const int QUALITY = 80;

    private const string TEMP_DIR = 'tmp/party-photos';

    private const string DIR = 'parties';

    /**
     * Resize and park an upload; returns the token the form submits.
     */
    public function storeTemporary(UploadedFile $file): string
    {
        $image = ImageManager::gd()->read($file->getRealPath());
        $image->scaleDown(self::MAX_EDGE, self::MAX_EDGE);

        $token = (string) Str::uuid();
        $this->disk()->put($this->tempPath($token), $image->toJpeg(self::QUALITY)->toString());

        return $token;
    }

    public function discardTemporary(string $token): void
    {
        $this->disk()->delete($this->tempPath($token));
    }

    /**
     * Move a parked upload to its permanent place and return the stored path.
     */
    public function claim(string $token): string
    {
        $path = self::DIR.'/'.$token.'.jpg';

        if (! $this->disk()->move($this->tempPath($token), $path)) {
            throw new RuntimeException("Temporary party photo {$token} is missing.");
        }

        return $path;
    }

    /**
     * Delete parked uploads older than the given age (abandoned forms); returns how many.
     */
    public function pruneTemporary(int $olderThanHours): int
    {
        $cutoff = now()->subHours($olderThanHours)->getTimestamp();
        $pruned = 0;

        foreach ($this->disk()->files(self::TEMP_DIR) as $path) {
            if ($this->disk()->lastModified($path) < $cutoff) {
                $this->disk()->delete($path);
                $pruned++;
            }
        }

        return $pruned;
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            $this->disk()->delete($path);
        }
    }

    private function tempPath(string $token): string
    {
        return self::TEMP_DIR.'/'.$token.'.jpg';
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
