<?php

namespace Tests\Feature\Admin\DemoData;

use App\Jobs\V2\SendOutboundDelivery;
use App\Models\DemoDataBatch;
use App\Models\DemoDataRecord;
use App\Models\Document;
use App\Models\Lead;
use App\Models\Notification\OutboundDelivery;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Quotation;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DemoData\DemoDataDependencyPreview;
use App\Services\DemoData\DemoDataGenerator;
use App\Services\DemoData\DemoDataGuard;
use App\Services\DemoData\DemoDataPurger;
use App\Services\Notification\NotificationService;
use App\Services\OpportunityService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SupportCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoDataManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SupportCatalogSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_access_demo_data_screen_and_user_without_permission_cannot(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->givePermissionTo('demo-data.manage');
        $seller = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->get(route('admin.demo-data.index'))->assertOk()->assertSee('Datos de demostración');
        $this->actingAs($seller)->get(route('admin.demo-data.index'))->assertForbidden();
        $this->actingAs($seller)->post(route('admin.demo-data.load'))->assertForbidden();
    }

    public function test_full_generation_creates_related_demo_data_and_ledger_records(): void
    {
        Storage::fake((string) config('filesystems.docs_disk', 'docs'));
        $admin = User::factory()->create(['is_active' => true]);

        $batch = app(DemoDataGenerator::class)->generate(DemoDataDependencyPreview::ALL_MODULES, $admin);

        $this->assertSame(DemoDataBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(3, $batch->records()->where('module', 'users')->where('table_name', 'users')->count());
        $this->assertSame(10, $batch->records()->where('module', 'products')->where('table_name', 'products')->count());
        $this->assertSame(45, $batch->records()->where('module', 'leads')->where('table_name', 'leads')->count());
        $this->assertSame(15, $batch->records()->where('module', 'customers')->where('table_name', 'customers')->count());
        $this->assertSame(24, $batch->records()->where('module', 'contacts')->where('table_name', 'contacts')->count());
        $this->assertSame(25, $batch->records()->where('module', 'opportunities')->where('table_name', 'opportunities')->count());
        $this->assertSame(60, $batch->records()->where('module', 'activities')->where('table_name', 'activities')->count());
        $this->assertSame(16, $batch->records()->where('module', 'quotations')->where('table_name', 'quotations')->count());
        $this->assertSame(20, $batch->records()->where('module', 'documents')->where('table_name', 'documents')->count());
        $this->assertSame(3, $batch->records()->where('module', 'campaigns')->where('table_name', 'campaign_runs')->count());
        $this->assertSame(30, $batch->records()->where('module', 'campaigns')->where('table_name', 'campaign_action_items')->count());
        $this->assertSame(18, $batch->records()->where('module', 'support')->where('table_name', 'support_tickets')->count());
        $this->assertTrue(User::where('email', 'carla.rojas@maia-demo.example')->where('is_active', false)->exists());
        $this->assertSame(0, $batch->records()->where('module', 'automations')->count());

        $quotation = Quotation::query()->where('number', 'like', 'DEMO-Q-%')->firstOrFail();
        $this->assertNotNull($quotation->customer_id);
        $this->assertNotNull($quotation->opportunity_id);
        $this->assertTrue(app(DemoDataGuard::class)->isDemo($quotation));

        $document = Document::query()->firstOrFail();
        $this->assertTrue(Storage::disk($document->disk)->exists($document->path));

        foreach (['leads' => 'code', 'customers' => 'code', 'opportunities' => 'code', 'products' => 'code', 'quotations' => 'number'] as $table => $column) {
            $this->assertLessThanOrEqual(20, (int) DB::table($table)->where($column, 'like', 'DEMO-%')->max(DB::raw('LENGTH('.$column.')')));
        }
    }

    public function test_module_generation_creates_demo_dependencies_without_using_real_records(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $realLead = Lead::query()->create([
            'code' => 'REAL-L-001',
            'person_type' => 'natural',
            'first_name' => 'Real',
            'last_name' => 'Lead',
            'source_id' => \App\Models\LeadSource::where('slug', 'web')->value('id'),
            'status_id' => \App\Models\LeadStatus::where('slug', 'nuevo')->value('id'),
            'owner_id' => $admin->id,
            'entered_at' => now(),
        ]);

        $batch = app(DemoDataGenerator::class)->generate(['quotations'], $admin);

        $this->assertDatabaseHas('leads', ['id' => $realLead->id, 'code' => 'REAL-L-001']);
        $this->assertFalse(app(DemoDataGuard::class)->isDemo($realLead));
        $this->assertGreaterThan(0, $batch->records()->whereIn('module', ['users', 'products', 'customers', 'contacts', 'opportunities', 'quotations'])->count());
    }

    public function test_unique_ledger_constraint_prevents_record_in_two_batches(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $batch = app(DemoDataGenerator::class)->generate(['leads'], $admin);
        $leadRecord = $batch->records()->where('module', 'leads')->firstOrFail();
        $otherBatch = DemoDataBatch::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'scenario_name' => 'Other',
            'status' => DemoDataBatch::STATUS_RUNNING,
            'modules' => ['leads'],
            'record_counts' => [],
            'started_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DemoDataRecord::query()->create([
            'batch_id' => $otherBatch->id,
            'module' => 'leads',
            'table_name' => $leadRecord->table_name,
            'model_type' => $leadRecord->model_type,
            'record_id' => $leadRecord->record_id,
        ]);
    }

    public function test_delete_removes_only_demo_data_and_physical_files(): void
    {
        Storage::fake((string) config('filesystems.docs_disk', 'docs'));
        $admin = User::factory()->create(['is_active' => true]);
        $batch = app(DemoDataGenerator::class)->generate(['documents'], $admin);
        $document = Document::query()->firstOrFail();
        $path = $document->path;
        $disk = $document->disk;
        $realUserId = $admin->id;

        app(DemoDataPurger::class)->delete($batch);

        $this->assertDatabaseHas('users', ['id' => $realUserId]);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertFalse(Storage::disk($disk)->exists($path));
        $this->assertSame(0, DemoDataRecord::query()->where('batch_id', $batch->id)->count());
    }

    public function test_delete_removes_unledgered_opportunity_stage_histories_created_after_generation(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $batch = app(DemoDataGenerator::class)->generate(['opportunities'], $admin);
        $opportunity = Opportunity::query()
            ->where('code', 'like', 'DEMO-O-%')
            ->whereHas('stage', fn ($query) => $query->ofType('open'))
            ->firstOrFail();
        $nextStage = PipelineStage::query()
            ->ofType('open')
            ->whereKeyNot($opportunity->stage_id)
            ->firstOrFail();

        app(OpportunityService::class)->changeStage($opportunity, $nextStage, $admin);

        $historyIds = $opportunity->refresh()->stageHistories()->pluck('id');
        $this->assertCount(2, $historyIds);
        $this->assertSame(1, $batch->records()
            ->where('model_type', \App\Models\OpportunityStageHistory::class)
            ->whereIn('record_id', $historyIds)
            ->count());

        app(DemoDataPurger::class)->delete($batch);

        $this->assertDatabaseMissing('opportunities', ['id' => $opportunity->id]);
        $this->assertDatabaseCount('opportunity_stage_histories', 0);
    }

    public function test_outbound_guard_blocks_pre_dispatch_and_job_processing(): void
    {
        Bus::fake([SendOutboundDelivery::class]);
        $admin = User::factory()->create(['is_active' => true]);
        $batch = app(DemoDataGenerator::class)->generate(['leads'], $admin);
        $lead = Lead::query()->where('code', 'like', 'DEMO-L-%')->firstOrFail();

        $delivery = app(NotificationService::class)->dispatch([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'nobody@maia-demo.example',
            'related_entity_type' => Lead::class,
            'related_entity_id' => $lead->id,
            'payload' => ['subject' => 'Demo', 'body' => 'Blocked'],
        ]);

        $this->assertSame(OutboundDelivery::STATUS_SKIPPED, $delivery->status);
        Bus::assertNotDispatched(SendOutboundDelivery::class);

        $queued = OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'nobody@maia-demo.example',
            'related_entity_type' => Lead::class,
            'related_entity_id' => $lead->id,
            'status' => OutboundDelivery::STATUS_QUEUED,
            'attempts' => 0,
            'idempotency_key' => sha1('demo-job'),
        ]);

        (new SendOutboundDelivery($queued->id))->handle(app(NotificationService::class));
        $this->assertSame(OutboundDelivery::STATUS_SKIPPED, $queued->refresh()->status);
    }
}
