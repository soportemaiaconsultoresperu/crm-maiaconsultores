<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Services\LeadDuplicateFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadDuplicateFinderTest extends TestCase
{
    use RefreshDatabase;

    private LeadDuplicateFinder $finder;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finder = new LeadDuplicateFinder();
        $this->owner = User::factory()->create();
    }

    public function test_same_document_is_critical(): void
    {
        $existing = Lead::factory()->forOwner($this->owner)->create([
            'doc_type' => 'dni',
            'doc_number' => '12345678',
            'doc_number_norm' => '12345678',
        ]);

        $result = $this->finder->check(['doc_number' => '12.345.678']);

        $this->assertTrue($result->hasCritical());
        $this->assertFalse($result->isEmpty());

        $match = $result->critical[0];
        $this->assertSame($existing->id, $match['id']);
        $this->assertSame($existing->code, $match['code']);
        $this->assertSame('doc_number_norm', $match['field']);
        $this->assertArrayHasKey('full_name', $match);
    }

    public function test_same_email_is_warning_only(): void
    {
        Lead::factory()->forOwner($this->owner)->create([
            'email' => 'Maria.Lopez@Example.com',
            'email_norm' => 'maria.lopez@example.com',
        ]);

        $result = $this->finder->check(['email' => '  maria.lopez@example.com ']);

        $this->assertFalse($result->hasCritical());
        $this->assertTrue($result->hasWarnings());
        $this->assertSame('email_norm', $result->warnings[0]['field']);
    }

    public function test_phone_variants_match_through_norm(): void
    {
        Lead::factory()->forOwner($this->owner)->create([
            'phone' => '+51 987 654 321',
            'phone_norm' => '51987654321',
            'doc_number' => null,
            'doc_number_norm' => null,
            'email' => null,
            'email_norm' => null,
        ]);

        $result = $this->finder->check(['phone' => '51987654321']);

        $this->assertTrue($result->hasWarnings());
        $this->assertSame('phone_norm', $result->warnings[0]['field']);
    }

    public function test_whatsapp_match_is_warning(): void
    {
        Lead::factory()->forOwner($this->owner)->create([
            'whatsapp' => '+51 987 000 111',
            'whatsapp_norm' => '51987000111',
            'doc_number' => null,
            'doc_number_norm' => null,
            'email' => null,
            'email_norm' => null,
            'phone' => null,
            'phone_norm' => null,
        ]);

        $result = $this->finder->check(['whatsapp' => '51 987 000 111']);

        $this->assertTrue($result->hasWarnings());
        $this->assertSame('whatsapp_norm', $result->warnings[0]['field']);
    }

    public function test_different_data_has_no_matches(): void
    {
        Lead::factory()->forOwner($this->owner)->create([
            'doc_type' => 'dni',
            'doc_number' => '11111111',
            'doc_number_norm' => '11111111',
            'email' => 'otro@example.com',
            'email_norm' => 'otro@example.com',
        ]);

        $result = $this->finder->check([
            'doc_number' => '22222222',
            'email' => 'distinto@example.com',
            'phone' => '51900000000',
        ]);

        $this->assertTrue($result->isEmpty());
    }

    public function test_ignore_excludes_the_lead_itself(): void
    {
        $lead = Lead::factory()->forOwner($this->owner)->create([
            'doc_type' => 'dni',
            'doc_number' => '12345678',
            'doc_number_norm' => '12345678',
            'email' => 'propio@example.com',
            'email_norm' => 'propio@example.com',
        ]);

        $result = $this->finder->check([
            'doc_number' => $lead->doc_number,
            'email' => $lead->email,
        ], $lead);

        $this->assertTrue($result->isEmpty());
    }

    public function test_find_in_row_returns_first_match_by_doc_email_or_phone(): void
    {
        $byDoc = Lead::factory()->forOwner($this->owner)->create([
            'doc_number' => '76543210',
            'doc_number_norm' => '76543210',
        ]);

        $this->assertSame($byDoc->id, $this->finder->findInRow(['doc_number' => '76.543.210'])?->id);

        $byEmail = Lead::factory()->forOwner($this->owner)->create([
            'doc_number' => null,
            'doc_number_norm' => null,
            'email' => 'fila@example.com',
            'email_norm' => 'fila@example.com',
        ]);

        $this->assertSame($byEmail->id, $this->finder->findInRow(['email' => 'FILA@example.com'])?->id);

        $this->assertNull($this->finder->findInRow([
            'doc_number' => null,
            'email' => null,
            'phone' => '51900000000',
            'whatsapp' => null,
        ]));
    }

    public function test_soft_deleted_leads_are_ignored(): void
    {
        Lead::factory()->forOwner($this->owner)->create([
            'doc_number' => '99999999',
            'doc_number_norm' => '99999999',
        ])->delete();

        $result = $this->finder->check(['doc_number' => '99999999']);

        $this->assertTrue($result->isEmpty());
    }
}
