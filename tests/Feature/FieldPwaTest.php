<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class FieldPwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_scopes_the_app_to_field_and_lists_existing_icons(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/field', $manifest['start_url']);
        $this->assertSame('/field', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);

        $sizes = [];
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/'))) ?: [0, 0];
            $this->assertSame($icon['sizes'], "{$width}x{$height}");
            $sizes[] = $icon['sizes'].' '.($icon['purpose'] ?? 'any');
        }
        $this->assertContains('192x192 any', $sizes);
        $this->assertContains('512x512 any', $sizes);
        $this->assertContains('512x512 maskable', $sizes);
    }

    public function test_field_layout_links_manifest_and_registers_versioned_service_worker(): void
    {
        $this->withoutVite();
        Vite::useManifestFilename('pwa-test-manifest.json');
        $manifest = public_path('build/pwa-test-manifest.json');
        @mkdir(dirname($manifest), recursive: true);
        file_put_contents($manifest, '{}');

        try {
            $this->actingAs(User::factory()->withRole(Role::FieldOfficer)->create())
                ->get('/field')
                ->assertOk()
                ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
                ->assertSee('apple-touch-icon', false)
                ->assertSee('data-sw="/field-sw.js?v='.md5('{}').'"', false);
        } finally {
            @unlink($manifest);
        }
    }

    public function test_signed_in_pages_carry_the_user_id_for_the_offline_queue(): void
    {
        $this->withoutVite();
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();

        $html = (string) $this->actingAs($officer)->get('/field')->assertOk()
            ->assertSee('data-user-id="'.$officer->id.'"', false)
            ->getContent();

        $this->assertSame(1, preg_match('#<script type="application/json" id="field-strings">(.*?)</script>#s', $html, $match));
        $this->assertSame(__('app.queue'), json_decode($match[1], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_sync_status_page_is_a_signed_in_field_page_rendered_from_the_queue(): void
    {
        $this->withoutVite();

        $this->get(route('field.sync'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->withRole(Role::Manager)->create())->get(route('field.sync'))->assertForbidden();

        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $this->actingAs($officer)->get(route('field.sync'))
            ->assertOk()
            ->assertSee(__('app.queue.title'))
            ->assertSee('data-user-id="'.$officer->id.'"', false)
            ->assertSee('data-sync-page', false)
            ->assertSee('<template id="queue-item">', false)
            ->assertSee('js-sync-now', false);
        $this->actingAs($officer)->get(route('field.home'))->assertSee(route('field.sync'));
    }

    public function test_service_worker_is_not_registered_while_vite_runs_hot(): void
    {
        $hotFile = tempnam(sys_get_temp_dir(), 'vite-hot');
        file_put_contents($hotFile, 'http://vite.test');
        Vite::useHotFile($hotFile);

        try {
            $this->actingAs(User::factory()->withRole(Role::FieldOfficer)->create())
                ->get('/field')
                ->assertOk()
                ->assertDontSee('data-sw=', false);
        } finally {
            @unlink($hotFile);
        }
    }

    public function test_offline_page_is_public_and_has_no_logout_form(): void
    {
        $this->withoutVite();

        $this->get('/field/offline')
            ->assertOk()
            ->assertSee(__('app.field.offline_title'))
            ->assertSee('data-offline-today', false)
            ->assertSee('<template id="offline-row">', false)
            ->assertSee('data-offline-collect', false)
            ->assertSee('data-offline-receipt', false)
            ->assertSee('id="field-strings"', false)
            ->assertDontSee('data-user-id', false)
            ->assertDontSee(route('logout'), false);

        $this->actingAs(User::factory()->withRole(Role::FieldOfficer)->create())
            ->get('/field/offline')
            ->assertOk()
            ->assertDontSee(route('logout'), false);
    }
}
