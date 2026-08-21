<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\V2\TimeDriven\CustomerIdle;
use App\Models\Activity;
use App\Models\AutomationRule;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Scheduler-driven event emitter: finds active customers whose last
 * completed Activity is older than the rule-configured `idle_days` (default
 * 30) and emits CustomerIdle for each.
 */
class EmitCustomerIdle extends Command
{
    protected $signature = 'automation:emit-customer-idle {--idle-days=30 : Default idle days when no rule overrides it}';

    protected $description = 'Emit CustomerIdle events for customers with no recent completed activity.';

    public function handle(): int
    {
        $defaultIdleDays = (int) $this->option('idle-days');

        $rules = AutomationRule::query()
            ->active()
            ->forTrigger(CustomerIdle::class)
            ->get();

        $idleDays = $defaultIdleDays;

        foreach ($rules as $rule) {
            foreach ($rule->actions()->get() as $action) {
                $payload = (array) ($action->payload_json ?? []);
                if (! empty($payload['idle_days'])) {
                    $idleDays = max(1, (int) $payload['idle_days']);
                }
            }
        }

        $now = Carbon::now();

        $customers = Customer::query()
            ->where('status', 'activo')
            ->whereNull('deleted_at')
            ->get();

        $emitted = 0;

        foreach ($customers as $customer) {
            $lastCompleted = Activity::query()
                ->where('subject_type', Customer::class)
                ->where('subject_id', $customer->id)
                ->where('status', 'completed')
                ->orderByDesc('executed_at')
                ->first();

            $reference = $lastCompleted?->executed_at ?? $lastCompleted?->updated_at ?? $customer->created_at;

            if ($reference === null) {
                continue;
            }

            $sinceDays = (int) floor(Carbon::parse($reference)->diffInDays($now, true));

            if ($sinceDays < $idleDays) {
                continue;
            }

            event(new CustomerIdle($customer, $sinceDays, $lastCompleted?->executed_at));

            $emitted++;
        }

        $this->info(sprintf('Emitted CustomerIdle for %d customers (window: %d days).', $emitted, $idleDays));

        return self::SUCCESS;
    }
}