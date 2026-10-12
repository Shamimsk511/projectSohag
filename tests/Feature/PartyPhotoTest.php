<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Party;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartyPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->manager = User::factory()->withRole(Role::Manager)->create();
    }

    private function uploadPhoto(int $width = 2000, int $height = 1500): string
    {
        $response = $this->actingAs($this->manager)
            ->post(route('admin.uploads.party-photo.store'), ['photo' => UploadedFile::fake()->image('me.png', $width, $height)])
            ->assertOk();

        return $response->getContent() ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $photoToken): array
    {
        return [
            'name' => 'Karim',
            'phone' => '01711223344',
            'address' => 'Village',
            'area_id' => Area::factory()->create()->id,
            'status' => 'active',
            'photo' => $photoToken,
        ];
    }

    public function test_uploaded_photo_is_resized_reencoded_and_stored_privately(): void
    {
        $token = $this->uploadPhoto();

        $this->actingAs($this->manager)->post(route('admin.parties.store'), $this->payload($token))->assertSessionHasNoErrors();

        $party = Party::sole();
        $this->assertNotNull($party->photo_path);
        $this->assertStringStartsWith('parties/', $party->photo_path);
        Storage::disk('local')->assertExists($party->photo_path);

        [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($party->photo_path) ?? '') ?: [0, 0, 0];
        $this->assertSame(800, $width);
        $this->assertSame(600, $height);
        $this->assertSame(IMAGETYPE_JPEG, $type);

        $this->assertSame([], Storage::disk('local')->files('tmp/party-photos'));
    }

    public function test_small_photo_is_not_upscaled(): void
    {
        $token = $this->uploadPhoto(300, 200);
        $this->actingAs($this->manager)->post(route('admin.parties.store'), $this->payload($token));

        [$width] = getimagesizefromstring(Storage::disk('local')->get(Party::sole()->photo_path ?? '') ?? '') ?: [0];
        $this->assertSame(300, $width);
    }

    public function test_upload_accepts_filepond_payload_with_metadata_field_of_the_same_name(): void
    {
        $this->actingAs($this->manager)
            ->call('POST', route('admin.uploads.party-photo.store'), ['photo' => '{}'], [], ['photo' => UploadedFile::fake()->image('me.png', 100, 100)])
            ->assertOk();
    }

    public function test_non_image_upload_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('admin.uploads.party-photo.store'), ['photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')])
            ->assertUnprocessable();
    }

    public function test_token_from_another_session_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->withSession([])
            ->post(route('admin.parties.store'), $this->payload('9b2f6c1e-1111-4222-8333-444455556666'))
            ->assertSessionHasErrors('photo');
    }

    public function test_reverting_an_upload_deletes_the_temporary_file(): void
    {
        $token = $this->uploadPhoto();
        Storage::disk('local')->assertExists("tmp/party-photos/{$token}.jpg");

        $this->actingAs($this->manager)
            ->call('DELETE', route('admin.uploads.party-photo.destroy'), [], [], [], [], $token)
            ->assertNoContent();

        Storage::disk('local')->assertMissing("tmp/party-photos/{$token}.jpg");
    }

    public function test_replacing_photo_deletes_the_old_file(): void
    {
        $this->actingAs($this->manager)->post(route('admin.parties.store'), $this->payload($this->uploadPhoto()));
        $party = Party::sole();
        $oldPath = $party->photo_path;

        $this->actingAs($this->manager)
            ->put(route('admin.parties.update', $party), $this->payload($this->uploadPhoto()))
            ->assertSessionHasNoErrors();

        $party->refresh();
        $this->assertNotSame($oldPath, $party->photo_path);
        Storage::disk('local')->assertMissing($oldPath ?? '');
        Storage::disk('local')->assertExists($party->photo_path ?? '');
    }

    public function test_photo_is_served_only_to_authorised_users(): void
    {
        $this->actingAs($this->manager)->post(route('admin.parties.store'), $this->payload($this->uploadPhoto()));
        $party = Party::sole();

        $this->actingAs($this->manager)->get(route('admin.parties.photo', $party))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $this->actingAs($officer)->get(route('admin.parties.photo', $party))->assertForbidden();

        auth()->logout();
        $this->get(route('admin.parties.photo', $party))->assertRedirect(route('login'));
    }

    public function test_accountant_cannot_upload(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();

        $this->actingAs($accountant)
            ->post(route('admin.uploads.party-photo.store'), ['photo' => UploadedFile::fake()->image('me.png')])
            ->assertForbidden();
    }
}
