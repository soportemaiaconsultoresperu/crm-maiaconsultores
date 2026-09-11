<?php

namespace Tests\Feature\Courses;

use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\User;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Slice 6.g — navigation exposure for authorized users only.
 *
 * This unit only links and reveals: no route, policy, permission or
 * application class is touched. These tests prove both halves of the
 * requirement — an authorized user discovers every screen built by units
 * 6.a/6.b/6.c by clicking, and a user without `course-talks.view` neither
 * sees the sidebar entry nor reaches any module screen by URL (403, never
 * 200 and never 500).
 */
class CourseTalksNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function moduleViewer(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('course-talks.view');

        return $user;
    }

    private function moduleManager(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo([
            'course-talks.view',
            'course-talks.activities.manage',
            'course-talks.editions.manage',
            'course-talks.participants.manage',
            'course-talks.attendance.manage',
        ]);

        return $user;
    }

    /**
     * @return array{0: CourseActivity, 1: CourseEdition}
     */
    private function editionGraph(?int $responsibleUserId = null): array
    {
        $activity = CourseActivity::factory()->create([
            'code' => 'CUR-NAV-001',
            'name' => 'Curso de navegación',
        ]);

        $edition = CourseEdition::factory()->for($activity, 'activity')->create([
            'code' => 'ED-NAV-001',
            'responsible_user_id' => $responsibleUserId,
        ]);

        return [$activity, $edition];
    }

    /**
     * Every GET screen the slice 6.a/6.b/6.c units built and this unit exposes,
     * mapped route name => URL so the denial matrix and the click-through
     * assertions share one source of truth.
     *
     * @return array<string, string>
     */
    private function moduleScreens(CourseActivity $activity, CourseEdition $edition): array
    {
        return [
            'course-talks.activities.index' => route('course-talks.activities.index'),
            'course-talks.activities.create' => route('course-talks.activities.create'),
            'course-talks.activities.show' => route('course-talks.activities.show', $activity),
            'course-talks.editions.create' => route('course-talks.editions.create', $activity),
            'course-talks.editions.show' => route('course-talks.editions.show', $edition),
            'course-talks.editions.teachers' => route('course-talks.editions.teachers', $edition),
            'course-talks.editions.sessions' => route('course-talks.editions.sessions', $edition),
            'course-talks.enrollments.index' => route('course-talks.enrollments.index', $edition),
            'course-talks.enrollments.create' => route('course-talks.enrollments.create', $edition),
            'course-talks.attendance.index' => route('course-talks.attendance.index', $edition),
        ];
    }

    /**
     * The rendered opening tag of the module sidebar entry, or '' when the
     * entry was not rendered at all.
     */
    private function sidebarEntry(TestResponse $response): string
    {
        preg_match('/<a\b[^>]*data-testid="sidebar-course-talks"[^>]*>/', (string) $response->getContent(), $matches);

        return $matches[0] ?? '';
    }

    public function test_authorized_user_sees_the_module_sidebar_entry_linking_to_the_activity_list(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $response = $this->actingAs($this->moduleViewer())
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('Cursos y charlas');

        $entry = $this->sidebarEntry($response);
        $this->assertNotSame('', $entry, 'The module sidebar entry was not rendered for a user holding course-talks.view.');
        $this->assertStringContainsString('href="'.route('course-talks.activities.index').'"', $entry);
        // The entry follows the AdminLTE convention: decorative icon, Spanish label.
        $response->assertSee('nav-icon bi bi-mortarboard', false);
        $response->assertSee('<p>Cursos y charlas</p>', false);
    }

    public function test_sidebar_entry_is_active_on_module_screens_and_inactive_on_other_screens(): void
    {
        $this->seed(CoursePermissionsSeeder::class);
        $viewer = $this->moduleViewer();
        [, $edition] = $this->editionGraph();

        $dashboardEntry = $this->sidebarEntry($this->actingAs($viewer)->get(route('dashboard'))->assertOk());
        $this->assertStringNotContainsString('active', $dashboardEntry, 'The entry must not be highlighted outside the module.');

        foreach ([route('course-talks.activities.index'), route('course-talks.editions.show', $edition)] as $url) {
            $entry = $this->sidebarEntry($this->actingAs($viewer)->get($url)->assertOk());
            $this->assertStringContainsString('nav-link active', $entry, "The entry must be active on {$url}.");
            $this->assertStringContainsString('aria-current="page"', $entry, "The active entry must expose aria-current on {$url}.");
        }

        // The shared sidebar keeps its pre-existing entries for the same user.
        $moduleHtml = (string) $this->actingAs($viewer)->get(route('course-talks.activities.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Prospectos', $moduleHtml);
        $this->assertStringContainsString('Clientes', $moduleHtml);
    }

    public function test_user_without_module_view_permission_does_not_see_the_sidebar_entry(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $response = $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame('', $this->sidebarEntry($response), 'A user without course-talks.view must not see the module entry.');
        $response->assertDontSee('Cursos y charlas');
    }

    public function test_user_with_an_unrelated_permission_still_does_not_see_the_sidebar_entry(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permission::findOrCreate('users.view', 'web'));

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame('', $this->sidebarEntry($response), 'Module visibility must depend on the module permission, not on holding any permission.');
        $response->assertDontSee('Cursos y charlas');
    }

    public function test_sidebar_entry_stays_hidden_from_an_edition_responsible_user_without_the_module_permission(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $responsible = User::factory()->create(['is_active' => true]);
        [, $edition] = $this->editionGraph($responsible->id);

        $this->assertFalse($responsible->hasPermissionTo('course-talks.view'));

        $response = $this->actingAs($responsible)->get(route('dashboard'))->assertOk();
        $this->assertSame('', $this->sidebarEntry($response), 'The entry mirrors the module index requirement (viewAny), not edition-scoped access.');

        // Edition-scoped access itself is unchanged by this unit.
        $this->actingAs($responsible)->get(route('course-talks.editions.show', $edition))->assertOk();
    }

    public function test_guest_cannot_reach_the_module_and_never_sees_its_entry(): void
    {
        $this->get(route('course-talks.activities.index'))->assertRedirect(route('login'));

        $login = $this->get(route('login'))->assertOk();
        $this->assertSame('', $this->sidebarEntry($login));
        $login->assertDontSee('Cursos y charlas');
    }

    public function test_user_without_module_view_permission_is_denied_every_module_screen_by_url(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $stranger = User::factory()->create(['is_active' => true]);
        [$activity, $edition] = $this->editionGraph();

        foreach ($this->moduleScreens($activity, $edition) as $routeName => $url) {
            $response = $this->actingAs($stranger)->get($url);
            $status = $response->getStatusCode();

            $this->assertSame(403, $status, "{$routeName} must be forbidden for a user without course-talks.view (got {$status}).");
            $this->assertNotSame(200, $status, "{$routeName} must not leak content to an unauthorized user.");
            $this->assertNotSame(500, $status, "{$routeName} must fail closed with 403, not with a server error.");

            // Fail closed must also mean "fail silently": the denial page
            // carries no module data at all.
            $response->assertDontSee('Curso de navegación');
            $response->assertDontSee('ED-NAV-001');
        }
    }

    public function test_sidebar_entry_requires_the_view_permission_not_a_module_management_permission(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $operator = User::factory()->create(['is_active' => true]);
        $operator->givePermissionTo('course-talks.attendance.manage');

        $this->assertFalse($operator->can('viewAny', CourseActivity::class));

        $response = $this->actingAs($operator)->get(route('dashboard'))->assertOk();
        $this->assertSame('', $this->sidebarEntry($response), 'A module management permission must not reveal the module entry without course-talks.view.');

        $this->actingAs($operator)->get(route('course-talks.activities.index'))->assertForbidden();
    }

    public function test_module_screens_link_back_so_the_navigation_has_no_dead_end(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $manager = $this->moduleManager();
        [$activity, $edition] = $this->editionGraph();

        $roundTrips = [
            route('course-talks.activities.create') => route('course-talks.activities.index'),
            route('course-talks.editions.create', $activity) => route('course-talks.activities.show', $activity),
            route('course-talks.editions.teachers', $edition) => route('course-talks.editions.show', $edition),
            route('course-talks.editions.sessions', $edition) => route('course-talks.editions.show', $edition),
            route('course-talks.enrollments.index', $edition) => route('course-talks.editions.show', $edition),
            route('course-talks.enrollments.create', $edition) => route('course-talks.enrollments.index', $edition),
            route('course-talks.attendance.index', $edition) => route('course-talks.editions.show', $edition),
        ];

        foreach ($roundTrips as $screen => $backTo) {
            $html = (string) $this->actingAs($manager)->get($screen)->assertOk()->getContent();
            $this->assertStringContainsString('href="'.$backTo.'"', $html, "Screen {$screen} must link back to {$backTo}.");
        }
    }

    public function test_every_slice_six_screen_is_reachable_by_clicking_for_a_full_module_manager(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $manager = $this->moduleManager();
        [$activity, $edition] = $this->editionGraph();

        // Sidebar → activity list.
        $dashboard = $this->actingAs($manager)->get(route('dashboard'))->assertOk();
        $this->assertStringContainsString('href="'.route('course-talks.activities.index').'"', $this->sidebarEntry($dashboard));

        // Activity list → activity detail and the activity creation form.
        $indexHtml = (string) $this->actingAs($manager)->get(route('course-talks.activities.index'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('course-talks.activities.show', $activity).'"', $indexHtml);
        $this->assertStringContainsString('href="'.route('course-talks.activities.create').'"', $indexHtml);

        // Activity detail → edition detail and the edition creation form.
        $activityHtml = (string) $this->actingAs($manager)->get(route('course-talks.activities.show', $activity))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('course-talks.editions.show', $edition).'"', $activityHtml);
        $this->assertStringContainsString('href="'.route('course-talks.editions.create', $activity).'"', $activityHtml);

        // Edition detail → teachers, sessions, participants list, participant
        // creation form and the attendance matrix.
        $editionHtml = (string) $this->actingAs($manager)->get(route('course-talks.editions.show', $edition))->assertOk()->getContent();
        foreach ([
            route('course-talks.editions.teachers', $edition),
            route('course-talks.editions.sessions', $edition),
            route('course-talks.enrollments.index', $edition),
            route('course-talks.enrollments.create', $edition),
            route('course-talks.attendance.index', $edition),
        ] as $url) {
            $this->assertStringContainsString('href="'.$url.'"', $editionHtml, "The edition detail must link {$url}.");
        }

        // Every linked screen really opens for the same authorized user.
        foreach ($this->moduleScreens($activity, $edition) as $url) {
            $this->actingAs($manager)->get($url)->assertOk();
        }
    }

    public function test_edition_detail_hides_management_links_from_a_module_viewer_while_the_routes_stay_forbidden(): void
    {
        $this->seed(CoursePermissionsSeeder::class);

        $viewer = $this->moduleViewer();
        [, $edition] = $this->editionGraph();

        $editionHtml = (string) $this->actingAs($viewer)->get(route('course-talks.editions.show', $edition))->assertOk()->getContent();

        // Read surfaces stay discoverable for a module viewer.
        $this->assertStringContainsString('href="'.route('course-talks.enrollments.index', $edition).'"', $editionHtml);
        $this->assertStringContainsString('href="'.route('course-talks.attendance.index', $edition).'"', $editionHtml);

        // Management surfaces must not be advertised to a viewer.
        foreach ([
            route('course-talks.editions.teachers', $edition),
            route('course-talks.editions.sessions', $edition),
            route('course-talks.enrollments.create', $edition),
        ] as $url) {
            $this->assertStringNotContainsString('href="'.$url.'"', $editionHtml, "A viewer must not be offered {$url}.");
        }

        // Revealing nothing changed authorization: the hidden routes stay 403.
        $this->actingAs($viewer)->get(route('course-talks.editions.teachers', $edition))->assertForbidden();
        $this->actingAs($viewer)->get(route('course-talks.editions.sessions', $edition))->assertForbidden();
        $this->actingAs($viewer)->get(route('course-talks.enrollments.create', $edition))->assertForbidden();
    }
}
