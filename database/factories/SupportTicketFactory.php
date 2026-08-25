<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\SupportCategory;
use App\Models\SupportChannel;
use App\Models\SupportPriority;
use App\Models\SupportStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportTicket> */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $customer = Customer::factory()->create();
        $contact = Contact::factory()->forCustomer($customer)->create();

        return [
            'code' => sprintf('SUP-%s-%05d', now()->format('Y'), fake()->unique()->numberBetween(1, 99999)),
            'title' => fake()->sentence(4),
            'customer_id' => $customer->id,
            'requester_contact_id' => $contact->id,
            'type_id' => SupportTicketType::factory(),
            'category_id' => SupportCategory::factory(),
            'channel_id' => SupportChannel::factory(),
            'priority_id' => SupportPriority::factory(),
            'status_id' => SupportStatus::factory(),
            'responsible_id' => null,
            'team_id' => null,
            'description' => fake()->paragraph(),
            'impact' => null,
            'general_observations' => null,
            'cancel_reason' => null,
            'reopen_reason' => null,
            'assigned_at' => null,
            'first_responded_at' => null,
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }
}
