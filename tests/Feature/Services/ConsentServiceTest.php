<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Contact;
use App\Models\ConsentRecord;
use App\Models\SuppressionEntry;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B21 — ConsentService RED-first unit tests.
 *
 * Covers 8 scenarios from the B20 baseline plan:
 *   1. isEligible with active consent + no suppression → true
 *   2. isEligible with no consent → false
 *   3. isEligible with active consent + active suppression (opt_out) → false (suppression wins)
 *   4. isEligible with active consent + global suppression (channel=NULL) → false
 *   5. grant creates a new record when none exists
 *   6. grant is idempotent — second call returns existing active row, no duplicate
 *   7. revoke marks the active record as 'revoked' with reason and timestamp
 *   8. recordSuppression creates entry with reason and is queryable by channel
 *   9. removeSuppression deletes the entry
 *  10. isEligible is false for a revoked consent even without suppression
 */
class ConsentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConsentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ConsentService::class);
    }

    private function contact(): Contact
    {
        return Contact::factory()->create(['is_active' => true]);
    }

    public function test_isEligible_with_active_consent_and_no_suppression_returns_true(): void
    {
        $contact = $this->contact();
        $this->service->grant([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'channel' => 'email',
            'source' => 'web_form',
            'evidence' => 'https://example.com/consent#abc123',
            'purpose' => 'marketing_newsletter',
        ]);

        $this->assertTrue($this->service->isEligible($contact, 'email', 'marketing_newsletter'));
    }

    public function test_isEligible_with_no_consent_returns_false(): void
    {
        $contact = $this->contact();

        $this->assertFalse($this->service->isEligible($contact, 'email', 'marketing_newsletter'));
    }

    public function test_isEligible_with_active_consent_but_opt_out_suppression_returns_false(): void
    {
        $contact = $this->contact();
        $this->service->grant([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'channel' => 'email',
            'source' => 'web_form',
            'evidence' => 'url-1',
            'purpose' => 'marketing_newsletter',
        ]);
        $this->service->recordSuppression($contact, SuppressionEntry::REASON_OPT_OUT, 'email', 'campaign_link');

        $this->assertFalse($this->service->isEligible($contact, 'email', 'marketing_newsletter'));
    }

    public function test_isEligible_with_global_suppression_blocks_all_channels(): void
    {
        $contact = $this->contact();
        $this->service->grant([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'channel' => 'email',
            'source' => 'web_form',
            'evidence' => 'url-2',
            'purpose' => 'marketing_newsletter',
        ]);
        $this->service->grant([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'channel' => 'whatsapp',
            'source' => 'web_form',
            'evidence' => 'url-2b',
            'purpose' => 'marketing_newsletter',
        ]);
        $this->service->recordSuppression($contact, SuppressionEntry::REASON_COMPLAINT, null, 'provider_webhook');

        $this->assertFalse($this->service->isEligible($contact, 'email', 'marketing_newsletter'));
        $this->assertFalse($this->service->isEligible($contact, 'whatsapp', 'marketing_newsletter'));
    }

    public function test_grant_creates_a_new_record(): void
    {
        $contact = $this->contact();
        $consent = $this->service->grant([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'channel' => 'email',
            'source' => 'web_form',
            'evidence' => 'url-3',
            'purpose' => 'marketing_newsletter',
        ]);

        $this->assertNotNull($consent->id);
        $this->assertSame('active', $consent->status);
        $this->assertSame('email', $consent->channel);
    }

    public function test_grant_is_idempotent(): void
    {
        $contact = $this->contact();
        $attrs = [
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'channel' => 'email',
            'source' => 'web_form',
            'evidence' => 'url-4',
            'purpose' => 'marketing_newsletter',
        ];

        $first = $this->service->grant($attrs);
        $second = $this->service->grant($attrs);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ConsentRecord::query()->count());
    }

    public function test_revoke_marks_active_record_as_revoked_with_reason(): void
    {
        $contact = $this->contact();
        $consent = $this->service->grant([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'channel' => 'email',
            'source' => 'web_form',
            'evidence' => 'url-5',
            'purpose' => 'marketing_newsletter',
        ]);
        $revoked = $this->service->revoke($consent, 'Subject requested removal via webform');

        $this->assertSame('revoked', $revoked->status);
        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame('Subject requested removal via webform', $revoked->revoked_reason);
        $this->assertFalse($this->service->isEligible($contact, 'email', 'marketing_newsletter'));
    }

    public function test_recordSuppression_creates_entry_queryable_by_channel(): void
    {
        $contact = $this->contact();
        $entry = $this->service->recordSuppression($contact, SuppressionEntry::REASON_BOUNCE, 'email', 'provider_webhook');

        $this->assertNotNull($entry->id);
        $this->assertSame('bounce', $entry->reason);
        $this->assertSame('email', $entry->channel);

        $this->assertDatabaseHas('suppression_entries', [
            'id' => $entry->id,
            'reason' => 'bounce',
            'channel' => 'email',
        ]);
    }

    public function test_removeSuppression_deletes_the_entry(): void
    {
        $contact = $this->contact();
        $this->service->recordSuppression($contact, SuppressionEntry::REASON_OPT_OUT, 'email', 'campaign_link');
        $this->assertSame(1, SuppressionEntry::query()->count());

        $deleted = $this->service->removeSuppression($contact, 'email');
        $this->assertSame(1, $deleted);
        $this->assertSame(0, SuppressionEntry::query()->count());
    }

    public function test_isEligible_with_revoked_consent_returns_false(): void
    {
        $contact = $this->contact();
        $consent = $this->service->grant([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'channel' => 'email',
            'source' => 'web_form',
            'evidence' => 'url-6',
            'purpose' => 'marketing_newsletter',
        ]);
        $this->service->revoke($consent, 'test');

        $this->assertFalse($this->service->isEligible($contact, 'email', 'marketing_newsletter'));
    }
}
