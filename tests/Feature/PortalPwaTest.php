<?php

namespace Tests\Feature;

use Tests\TestCase;

class PortalPwaTest extends TestCase
{
    public function test_manifest_scopes_the_portal(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('portal.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/portal', $manifest['start_url']);
        $this->assertSame('/portal', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
    }

    public function test_offline_page_is_public_and_carries_no_member_data(): void
    {
        $this->withoutVite();

        $this->get(route('portal.offline'))->assertOk()
            ->assertSee(__('app.portal.offline'))
            ->assertSee('data-install-banner', false)
            ->assertDontSee(route('portal.lock'), false);
    }

    public function test_service_worker_never_caches_portal_pages(): void
    {
        $sw = (string) file_get_contents(public_path('portal-sw.js'));

        $this->assertStringContainsString("'/portal/offline'", $sw);
        $this->assertStringContainsString("'portal-shell-'", $sw);
    }
}
