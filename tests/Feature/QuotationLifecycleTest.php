<?php

namespace Tests\Feature;

use App\Exceptions\InvalidOperationException;
use App\Models\Tax;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RF-COT-004/006: state transitions, duplicate and the "only-draft-is-
 * editable" invariant.
 */
class QuotationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private QuotationService $service;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\AdditionalPermissionsSeeder::class);

        $this->service = app(QuotationService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            'items' => [
                [
                    'description' => 'Servicio',
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
                ],
            ],
        ], $overrides);
    }

    public function test_create_then_send_then_accept(): void
    {
        $quotation = $this->service->create($this->validData(), $this->actor);
        $this->assertSame('draft', $quotation->status);

        $sent = $this->service->send($quotation, $this->actor);
        $this->assertSame('sent', $sent->status);
        $this->assertNotNull($sent->issued_at);

        $accepted = $this->service->accept($sent, $this->actor);
        $this->assertSame('accepted', $accepted->status);
        $this->assertNotNull($accepted->accepted_at);
    }

    public function test_reject_records_reason_and_status(): void
    {
        $quotation = $this->service->create($this->validData(), $this->actor);
        $sent = $this->service->send($quotation, $this->actor);

        $rejected = $this->service->reject($sent, $this->actor, 'Cliente eligió otra propuesta');

        $this->assertSame('rejected', $rejected->status);

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', \App\Models\Quotation::class)
            ->where('subject_id', $rejected->id)
            ->where('event', 'quotation-rejected')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Cliente eligió otra propuesta', $log->properties['reason']);
    }

    public function test_duplicate_clones_items_and_resets_status(): void
    {
        $igv = Tax::where('slug', 'gravado-igv')->firstOrFail();

        $original = $this->service->create($this->validData([
            'lead_id' => \App\Models\Lead::factory()->create()->id,
            'items' => [
                ['description' => 'Item original', 'quantity' => 2, 'unit_price' => 500, 'tax_id' => $igv->id],
            ],
        ]), $this->actor);

        $sent = $this->service->send($original, $this->actor);

        $clone = $this->service->duplicate($sent, $this->actor);

        $this->assertNotSame($original->number, $clone->number);
        $this->assertSame('draft', $clone->status);
        $this->assertNull($clone->accepted_at);
        $this->assertSame($original->lead_id, $clone->lead_id);
        $this->assertSame($original->currency_code, $clone->currency_code);

        $this->assertSame(1, $clone->items()->count());
        $item = $clone->items()->first();
        $this->assertSame('Item original', $item->description);
        $this->assertSame('1000.00', (string) $item->line_subtotal);
        $this->assertSame('180.00', (string) $item->line_tax);
        $this->assertSame($igv->name, $item->tax_name);
    }

    public function test_update_on_rejected_throws_invalid_operation(): void
    {
        $quotation = $this->service->create($this->validData(), $this->actor);
        $rejected = $this->service->reject($quotation, $this->actor, 'Motivo');

        $this->expectException(InvalidOperationException::class);

        $this->service->update($rejected, [
            'terms' => 'Editar no permitido',
            'items' => [
                ['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'tax_id' => null],
            ],
        ], $this->actor);
    }

    public function test_update_on_draft_rewrites_items(): void
    {
        $quotation = $this->service->create($this->validData([
            'items' => [
                ['description' => 'Original 1', 'quantity' => 1, 'unit_price' => 100, 'tax_id' => null],
                ['description' => 'Original 2', 'quantity' => 2, 'unit_price' => 200, 'tax_id' => null],
            ],
        ]), $this->actor);

        $this->assertSame(2, $quotation->items()->count());

        $updated = $this->service->update($quotation, [
            'terms' => 'Términos actualizados',
            'items' => [
                ['description' => 'Reemplazo único', 'quantity' => 5, 'unit_price' => 50, 'tax_id' => null],
            ],
        ], $this->actor);

        $items = $updated->items()->get();
        $this->assertCount(1, $items);
        $this->assertSame('Reemplazo único', $items->first()->description);
        $this->assertSame('250.00', (string) $updated->subtotal);
        $this->assertSame('250.00', (string) $updated->total);
        $this->assertSame('Términos actualizados', $updated->terms);
    }
}
