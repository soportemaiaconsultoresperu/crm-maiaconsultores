<?php

namespace Tests\Feature\Admin;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LeadService;
use Database\Seeders\AdditionalPermissionsSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * B08 — Audit viewer service tests (RF-USR-007, ADR-008).
 *
 * Three rules pin down the contract:
 * - Filtering by `subject_id` narrows the paginator to the entries
 *   tied to a specific record.
 * - Filtering by `user_id` (causer) narrows to entries caused by one
 *   user.
 * - The paginator returns at most `PER_PAGE` items and orders them
 *   newest-first.
 */
class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuditService $service;

    private User $admin;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(AdditionalPermissionsSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->service = app(AuditService::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->otherUser = User::factory()->create(['is_active' => true]);
    }

    private function validLeadData(): array
    {
        $source = LeadSource::firstOrCreate(['slug' => 'web'], ['name' => 'Web', 'sort' => 1]);

        return [
            'person_type' => 'natural',
            'first_name' => 'Auditable',
            'last_name' => 'Sujeto',
            'doc_type' => 'dni',
            'doc_number' => '11223344',
            'phone' => '987654321',
            'email' => 'auditable@maia.test',
            'source_id' => $source->id,
        ];
    }

    public function test_filter_by_subject_id_returns_only_that_records_entries(): void
    {
        $leadService = app(LeadService::class);

        $leadA = $leadService->create($this->validLeadData(), $this->admin);
        $leadB = $leadService->create(
            $this->validLeadData() + ['doc_number' => '44332211'],
            $this->otherUser,
        );

        // Assign to generate an extra activitylog row per lead.
        $newOwner = User::factory()->create();
        $leadService->assign($leadA, $newOwner, $this->admin);
        $leadService->assign($leadB, $newOwner, $this->otherUser);

        $paginatorA = $this->service->query(['subject_type' => Lead::class, 'subject_id' => $leadA->id], $this->admin);
        $paginatorB = $this->service->query(['subject_type' => Lead::class, 'subject_id' => $leadB->id], $this->admin);

        $this->assertGreaterThan(0, $paginatorA->total());
        $this->assertGreaterThan(0, $paginatorB->total());

        foreach ($paginatorA->items() as $row) {
            $this->assertSame((int) $row->subject_id, $leadA->id);
            $this->assertSame(Lead::class, $row->subject_type);
        }
    }

    public function test_filter_by_user_id_returns_only_entries_caused_by_that_user(): void
    {
        $leadService = app(LeadService::class);

        $leadA = $leadService->create($this->validLeadData(), $this->admin);
        $leadB = $leadService->create(
            $this->validLeadData() + ['doc_number' => '44332211'],
            $this->otherUser,
        );

        $newOwner = User::factory()->create();
        $leadService->assign($leadA, $newOwner, $this->admin);
        $leadService->assign($leadB, $newOwner, $this->otherUser);

        $paginator = $this->service->query(['user_id' => $this->admin->id], $this->admin);

        $this->assertGreaterThan(0, $paginator->total());

        foreach ($paginator->items() as $row) {
            $this->assertSame($this->admin->id, (int) $row->causer_id);
        }

        // The admin caused events must NOT include those of the other user.
        $otherPaginator = $this->service->query(['user_id' => $this->otherUser->id], $this->admin);
        foreach ($otherPaginator->items() as $row) {
            $this->assertSame($this->otherUser->id, (int) $row->causer_id);
        }
    }

    public function test_show_loads_subject_and_causer_relations(): void
    {
        $leadService = app(LeadService::class);

        $lead = $leadService->create($this->validLeadData(), $this->admin);
        $newOwner = User::factory()->create();
        $leadService->assign($lead, $newOwner, $this->admin);

        $log = Activity::query()
            ->where('subject_type', Lead::class)
            ->where('subject_id', $lead->id)
            ->where('event', 'lead-reassigned')
            ->first();

        $this->assertNotNull($log);

        $loaded = $this->service->show($log);

        $this->assertTrue($loaded->relationLoaded('causer'));
        $this->assertTrue($loaded->relationLoaded('subject'));
        $this->assertSame($this->admin->id, $loaded->causer->id);
        $this->assertSame($lead->id, $loaded->subject->id);
    }

    public function test_audit_log_export_instantiates_and_returns_seven_spanish_columns(): void
    {
        // The B02-Tanda B spreadsheet consumer expects seven columns
        // (Fecha, Evento, Sujeto, ID del sujeto, Usuario, Descripción,
        // Propiedades). Pinning the headings here keeps the export and
        // the audit view in sync.
        $export = new \App\Exports\AuditLogExport();

        $this->assertSame(
            ['Fecha', 'Evento', 'Sujeto', 'ID del sujeto', 'Usuario', 'Descripción', 'Propiedades'],
            $export->headings(),
        );
    }

    public function test_pagination_returns_25_items_per_page_newest_first(): void
    {
        // Generate 30 distinct user-creation events so the audit table has
        // enough rows for a paginator to demonstrate the PER_PAGE=25 limit.
        for ($i = 0; $i < 30; $i++) {
            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => User::class,
                'subject_id' => $this->admin->id,
                'causer_type' => User::class,
                'causer_id' => $this->admin->id,
                'event' => 'noise-test',
                'description' => "Synthetic entry #{$i}",
                'properties' => ['i' => $i],
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }

        $paginator = $this->service->query([], $this->admin);

        $this->assertSame(25, $paginator->perPage());
        $this->assertGreaterThanOrEqual(30, $paginator->total());
        $this->assertCount(25, $paginator->items());

        // Newest first: the synthetic entry #0 is the most recent.
        $items = $paginator->items();
        $firstCreated = $items[0]->created_at;
        $lastCreated = $items[count($items) - 1]->created_at;
        $this->assertGreaterThanOrEqual(
            $lastCreated->getTimestamp(),
            $firstCreated->getTimestamp(),
            'Newest entries must come first.'
        );
    }
}