<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\V2\TimeDriven\QuotationWillExpire;
use App\Models\AutomationRule;
use App\Models\Quotation;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Scheduler-driven event emitter (B12): find quotations that expire in the
 * configured window and emit QuotationWillExpire for each.
 *
 * The lookAheadDays is read from every active automation rule whose
 * trigger_event = QuotationWillExpire (per-rule payload override) with a
 * fallback of 7 days.
 */
class EmitQuotationWillExpire extends Command
{
    protected $signature = 'automation:emit-quotation-will-expire {--look-ahead=7 : Default look-ahead days when no rule overrides it}';

    protected $description = 'Emit QuotationWillExpire events for quotations nearing expiry.';

    public function handle(): int
    {
        $defaultLookAhead = (int) $this->option('look-ahead');

        $rules = AutomationRule::query()
            ->active()
            ->forTrigger(QuotationWillExpire::class)
            ->get();

        $lookAhead = $defaultLookAhead;

        foreach ($rules as $rule) {
            $actions = $rule->actions()->get();
            foreach ($actions as $action) {
                $payload = (array) ($action->payload_json ?? []);
                if (! empty($payload['look_ahead_days'])) {
                    $lookAhead = max(1, (int) $payload['look_ahead_days']);
                }
            }
        }

        $today = Carbon::today();
        $until = $today->copy()->addDays($lookAhead);

        $quotations = Quotation::query()
            ->whereIn('status', ['draft', 'sent'])
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$today->toDateString(), $until->toDateString()])
            ->get();

        foreach ($quotations as $quotation) {
            $daysUntilExpiry = $today->diffInDays(Carbon::parse($quotation->expires_at), false);

            event(new QuotationWillExpire($quotation, $lookAhead, max(0, (int) $daysUntilExpiry)));
        }

        $this->info(sprintf(
            'Emitted QuotationWillExpire for %d quotations (look-ahead: %d days).',
            $quotations->count(),
            $lookAhead,
        ));

        return self::SUCCESS;
    }
}