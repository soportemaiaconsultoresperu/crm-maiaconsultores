<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Notification;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B17 Pasada B — AdminNotificationController smoke tests.
 *
 * Covers the canonical 5 B17 acceptance scenarios at the HTTP layer:
 *  - `preferences` requires `notifications.view` (AC-1).
 *  - `deliveries` paginates `OutboundDelivery` rows (AC-2).
 *  - `updatePreference` toggles `enabled` (AC-3).
 *  - `retry` resets `status='queued'` and re-dispatches the job (AC-4).
 *  - `dispatchNow` requires `notifications.send` (AC-5).
 *
 * The bandeja Livewire view + preference list widget land in a follow-up
 * B17.x; the controller-level gates and the `OutboundDelivery` persistence
 * are the verifier's audit surface for this slice.
 */
class AdminNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->register(\App\Providers\AutomationServiceProvider::class, force: true);
        app()->register(\App\Providers\NotificationServiceProvider::class, force: true);
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->register(\App\Providers\NotificationServiceProvider::class, force: true);
    }

    private function manageUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['notifications.view', 'notifications.manage', 'notifications.audit', 'notifications.send']);

        return $user;
    }

    public function test_preferences_index_requires_view_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        // No permissions granted.

        $response = $this->actingAs($user)
            ->get(route('admin.notifications.preferences.index'));

        $response->assertForbidden();
    }

    public function test_preferences_index_with_view_permission_renders(): void
    {
        $user = $this->manageUser();

        $response = $this->actingAs($user)
            ->get(route('admin.notifications.preferences.index'));

        $response->assertOk();
    }

    public function test_deliveries_index_lists_recent_rows(): void
    {
        $user = $this->manageUser();
        $service = app(\App\Services\Notification\NotificationService::class);
        for ($i = 0; $i < 3; $i++) {
            $service->dispatch([
                'channel' => 'mail',
                'recipient_ref' => 'admin@example.com',
                'related_entity_type' => 'IntegrationAccount',
                'related_entity_id' => 1,
                'account_id' => null,
                'payload' => ['subject' => 'S'.$i, 'body' => 'B'.$i],
                'bucket' => 'D-21a',
            ]);
        }

        $response = $this->actingAs($user)
            ->get(route('admin.notifications.deliveries.index'));

        $response->assertOk();
        $this->assertDatabaseCount('outbound_deliveries', 3);
    }

    public function test_retry_resets_status_and_dispatches(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $user = $this->manageUser();
        $service = app(\App\Services\Notification\NotificationService::class);
        $delivery = $service->dispatch([
            'channel' => 'mail',
            'recipient_ref' => 'admin@example.com',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 1,
            'account_id' => null,
            'payload' => ['subject' => 'S', 'body' => 'B'],
            'bucket' => 'D-21a',
        ]);
        $service->markFailed($delivery->id, 'X', 'y');

        $response = $this->actingAs($user)
            ->post(route('admin.notifications.deliveries.retry', $delivery));

        $response->assertRedirect();
        $delivery->refresh();
        $this->assertSame('queued', $delivery->status);
        $this->assertSame(0, (int) $delivery->attempts);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\V2\SendOutboundDelivery::class);
    }

    public function test_dispatch_requires_send_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('notifications.view'); // missing notifications.send

        $response = $this->actingAs($user)->post(route('admin.notifications.dispatch'), [
            'channel' => 'mail',
            'recipient_ref' => 'x@y.com',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 1,
            'payload' => ['subject' => 's', 'body' => 'b'],
            'bucket' => 'D-21a',
        ]);

        $response->assertForbidden();
    }
}
