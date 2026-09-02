<?php

namespace App\Services;

use App\Events\V2\LeadConverted;
use App\Exceptions\ConversionException;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lead → customer conversion (ADR-001, RF-LEAD-013).
 *
 * Everything happens inside ONE database transaction: customer creation
 * (CLI code, norms, converted_from_lead_id, converted_at), the optional
 * first contact, the lead status change to "convertido" and the audit
 * entries on both sides. The lead record is preserved so its activities
 * and documents remain reachable from the customer timeline
 * (CustomerService::history).
 */
class LeadConversionService
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly ContactService $contacts,
    ) {}

    /**
     * Convert a lead into a NEW customer row.
     *
     * @param  array<string, mixed>  $customerData  Customer attributes
     *        (person_type, legal_name, doc fields, ...).
     * @param  array<string, mixed>|null  $contactData  Optional first
     *        contact; when provided it becomes the primary contact.
     *
     * @throws ConversionException When the lead was already converted or is
     *         in a final status (never double-converts).
     * @throws \InvalidArgumentException When the minimum customer/contact
     *         invariants do not hold (the whole transaction rolls back).
     */
        public function convert(Lead $lead, array $customerData, User $actor, ?array $contactData = null): Customer
        {
            $customer = DB::transaction(function () use ($lead, $customerData, $actor, $contactData): Customer {
                // Lock the persisted lead before checking conversion state. This
                // serializes competing conversion requests for the same lead.
                $lockedLead = Lead::query()
                    ->lockForUpdate()
                    ->findOrFail($lead->getKey());

                $this->assertConvertible($lockedLead);

                $customerData['converted_from_lead_id'] = $lockedLead->id;
                $customerData['converted_at'] = now();
                // The lead owner keeps the account by default (ADR-001 spirit:
                // the commercial relationship continuity).
                $customerData['owner_id'] ??= $lockedLead->owner_id ?? $actor->id;

                /** @var Customer $customer */
                $customer = $this->customers->create($customerData, $actor);

                $contactData ??= $this->contactDataFromLead($lockedLead);

                if ($contactData !== null) {
                    $contactData['is_primary'] = true;
                    $this->contacts->create($customer, $contactData, $actor);
                }

                $lockedLead->status_id = $this->convertedStatusId();
                $lockedLead->updated_by = $actor->id;
                $lockedLead->save();

                activity()
                    ->performedOn($lockedLead)
                    ->causedBy($actor)
                    ->event('lead-converted')
                    ->withProperties(['customer_code' => $customer->code])
                    ->log("Lead {$lockedLead->code} convertido a cliente {$customer->code}");

                activity()
                    ->performedOn($customer)
                    ->causedBy($actor)
                    ->event('customer-created-from-lead')
                    ->withProperties(['lead_code' => $lockedLead->code])
                    ->log("Cliente {$customer->code} creado a partir del lead {$lockedLead->code}");

                return $customer->refresh();
            });

            $lead->refresh();

            // V2 (B12): automation engine emission after the transaction
            // commits. Never inside DB::transaction.
            event(new LeadConverted($lead, $customer, $actor));

            return $customer;
        }

        /**
         * @return array<string, mixed>|null
         */
        private function contactDataFromLead(Lead $lead): ?array
        {
            $contact = $lead->primaryContact;

            if ($contact === null) {
                return null;
            }

            return [
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'position' => $contact->position,
                'phone' => $contact->phone,
                'whatsapp' => $contact->whatsapp,
                'email' => $contact->email,
            ];
        }

        /**
         * A lead is convertible only while it is NOT in a final status and no
         * customer already references it (ADR-001: exactly one conversion).
         */
    private function assertConvertible(Lead $lead): void
    {
        if ($lead->status?->is_final) {
            throw new ConversionException(
                "Lead {$lead->code} is in the final status \"{$lead->status->slug}\" and cannot be converted."
            );
        }

        if ($lead->convertedCustomers()->exists()) {
            throw new ConversionException(
                "Lead {$lead->code} was already converted to a customer."
            );
        }
    }

    /**
     * Id of the "convertido" catalog status (is_final = 1).
     */
    private function convertedStatusId(): int
    {
        return LeadStatus::query()->where('slug', 'convertido')->value('id')
            ?? throw new \RuntimeException('Lead status "convertido" is not seeded.');
    }
}
