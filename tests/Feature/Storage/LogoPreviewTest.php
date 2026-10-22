<?php

namespace Tests\Feature\Storage;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\AdditionalPermissionsSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Company logo preview through the private disk.
 *
 * The preview endpoint must serve the stored image bytes with the right
 * content type and stay behind the same settings authorization, regardless of
 * the configured storage backend.
 */
class LogoPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdditionalPermissionsSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');
    }

    public function test_logo_preview_returns_the_stored_image_with_its_content_type(): void
    {
        Storage::fake('local');

        // 1x1 PNG so the response body is a real image, not an arbitrary blob.
        $bytes = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        Storage::disk('local')->put('company/logo-test.png', $bytes);

        Setting::query()->updateOrCreate(
            ['key' => 'company.logo_path'],
            ['value' => 'company/logo-test.png', 'type' => 'string', 'group' => 'company'],
        );

        $response = $this->actingAs($this->admin)
            ->get('/admin/settings/logo/preview');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertSame($bytes, $response->getContent());
    }

    public function test_logo_preview_is_forbidden_without_settings_access(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('company/logo-test.png', 'PNG');

        Setting::query()->updateOrCreate(
            ['key' => 'company.logo_path'],
            ['value' => 'company/logo-test.png', 'type' => 'string', 'group' => 'company'],
        );

        // The seeded vendedor holds neither settings.view nor settings.manage.
        $this->actingAs($this->vendedor)
            ->get('/admin/settings/logo/preview')
            ->assertForbidden();
    }
}
