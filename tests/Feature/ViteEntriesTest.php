<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class ViteEntriesTest extends TestCase
{
    use RefreshDatabase;

    private string $hotFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotFile = tempnam(sys_get_temp_dir(), 'vite-hot');
        file_put_contents($this->hotFile, 'http://vite.test');
        Vite::useHotFile($this->hotFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->hotFile);

        parent::tearDown();
    }

    public function test_guest_is_redirected_from_field_to_login(): void
    {
        $this->get('/field')->assertRedirect('/login');
    }

    public function test_admin_layout_loads_only_the_admin_entry(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('http://vite.test/resources/js/admin.js', false)
            ->assertDontSee('resources/js/field.js', false);
    }

    public function test_field_layout_loads_only_the_field_entry(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/field')
            ->assertOk()
            ->assertSee('http://vite.test/resources/js/field.js', false)
            ->assertDontSee('resources/js/admin.js', false)
            ->assertDontSee('app-wrapper', false);
    }
}
