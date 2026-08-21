<?php

namespace App\Services;

use App\Events\V2\ContactDeactivated;
use App\Events\V2\ContactPrimaryChanged;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\User;
use App\Support\NormalizesContactData;
use Illuminate\Support\Facades\DB;

/**
 * Contact business logic (B03, Tanda A). The single-active-primary-per-
 * customer rule (RF-CON-002) is guaranteed transactionally: primary
 * reassignment unsets the previous primary and sets the new one inside the
 * same transaction, with a dedicated audit entry. Deactivation never
 * auto-promotes another contact; primariness is reassigned explicitly
 * through setPrimary().
 */
class ContactService
{
    use NormalizesContactData;

    /**
     * Create a contact for a customer. When is_primary is requested, any
     * previous active primary of the same customer is unset in the same
     * transaction (RF-CON-002).
     */
    public function create(Customer $customer, array $data, User $actor): Contact
    {
        $this->assertCreatable($data);

        return DB::transaction(function () use ($customer, $data, $actor): Contact {
            $data['customer_id'] = $customer->id;
            $data['is_active'] = $data['is_active'] ?? true;

            $makePrimary = ! empty($data['is_primary']);
            $data['is_primary'] = false;

            $data = self::applyNormalizations($data, null, [
                'email' => 'email_norm',
            ]);

            $contact = new Contact($data);
            $contact->created_by = $actor->id;
            $contact->updated_by = $actor->id;
            $contact->save();

            if ($makePrimary) {
                $this->promote($contact, $actor);
            }

            return $contact->refresh();
        });
    }

    /**
     * Update a contact and recompute email_norm.
     */
    public function update(Contact $contact, array $data, User $actor): Contact
    {
        DB::transaction(function () use ($contact, $data, $actor): void {
            unset($data['customer_id'], $data['created_by'], $data['updated_by']);

            $contact->fill($data);
            self::applyNormalizations($contact->getAttributes(), $contact, [
                'email' => 'email_norm',
            ]);
            $contact->updated_by = $actor->id;
            $contact->save();
        });

        return $contact->refresh();
    }

    /**
     * Move primariness to the given contact in ONE transaction (RF-CON-002):
     * the previous active primary (if any) is unset, the target becomes the
     * primary, and an audit entry records old/new contact ids.
     */
public function setPrimary(Contact $contact, User $actor): Contact
    {
        $previousPrimaryId = Contact::query()
            ->where('customer_id', $contact->customer_id)
            ->where('is_primary', true)
            ->whereKeyNot($contact->getKey())
            ->value('id');

        DB::transaction(function () use ($contact, $actor): void {
            $this->promote($contact, $actor);
        });

        $contact->refresh();

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new ContactPrimaryChanged($contact, $actor, $previousPrimaryId !== null ? (int) $previousPrimaryId : null));

        return $contact;
    }

    /**
     * Deactivate (soft delete) a contact with a mandatory reason
     * (RF-CON-003). If it was the primary contact, the customer is left
     * WITHOUT a primary: no auto-promotion, the next setPrimary() call
     * assigns primariness explicitly.
     */
    public function deactivate(Contact $contact, User $actor, string $reason): Contact
    {
        DB::transaction(function () use ($contact, $actor, $reason): void {
            $wasPrimary = $contact->is_primary;

            $contact->updated_by = $actor->id;
            $contact->delete();

            activity()
                ->performedOn($contact)
                ->causedBy($actor)
                ->event('contact-deactivated')
                ->withProperties([
                    'reason' => $reason,
                    'was_primary' => $wasPrimary,
                ])
->log("Contacto {$contact->first_name} {$contact->last_name} desactivado: {$reason}");
        });

        // V2 (B12): automation engine emission after the transaction
        // commits. Never inside DB::transaction.
        event(new ContactDeactivated($contact, $actor));

        return $contact;
    }

    /**
     * Guard used by tests and future UI checks: at most ONE active primary
     * contact per customer (RF-CON-002 invariant).
     *
     * @throws \RuntimeException When more than one active primary exists.
     */
    public function assertSinglePrimary(Customer $customer): void
    {
        $count = $customer->contacts()->where('is_primary', true)->count();

        if ($count > 1) {
            throw new \RuntimeException(
                "Customer {$customer->code} has {$count} primary contacts; expected at most one."
            );
        }
    }

    /**
     * Unset the current active primary (if any) and promote the target.
     */
    private function promote(Contact $contact, User $actor): void
    {
        $previous = Contact::query()
            ->where('customer_id', $contact->customer_id)
            ->where('is_primary', true)
            ->whereKeyNot($contact->getKey())
            ->lockForUpdate()
            ->get();

        foreach ($previous as $old) {
            $old->is_primary = false;
            $old->updated_by = $actor->id;
            $old->save();
        }

        if (! $contact->is_primary) {
            $contact->is_primary = true;
            $contact->updated_by = $actor->id;
            $contact->save();
        }

        activity()
            ->performedOn($contact->customer)
            ->causedBy($actor)
            ->event('contact-primary-changed')
            ->withProperties([
                'old_contact_id' => $previous->first()?->id,
                'new_contact_id' => $contact->id,
            ])
            ->log("Contacto principal de {$contact->customer->code} actualizado");
    }

    /**
     * Minimum service-level invariants; exhaustive validation runs earlier
     * in ContactStoreRequest (Tanda B wires the HTTP layer).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertCreatable(array $data): void
    {
        foreach (['first_name', 'last_name'] as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("{$field} is required.");
            }
        }
    }
}
