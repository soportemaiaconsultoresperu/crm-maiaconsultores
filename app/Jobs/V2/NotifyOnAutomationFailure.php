<?php

declare(strict_types=1);

namespace App\Jobs\V2;

use App\Models\AutomationExecution;
use App\Models\User;
use App\Notifications\Automation\AutomationFailedPermanently;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

/**
 * Sends the "automation permanently failed" notification to admins.
 *
 * Triggered by RunAutomationAction::failed() or by
 * ReconcileFailedAutomationSteps when an execution reaches its retry
 * budget. The Job is independent from the queue so the original Job can
 * return cleanly.
 */
class NotifyOnAutomationFailure implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $executionId)
    {
    }

    public function handle(): void
    {
        $execution = AutomationExecution::query()->with('rule')->find($this->executionId);

        if ($execution === null) {
            Log::warning('NotifyOnAutomationFailure: execution not found', [
                'execution_id' => $this->executionId,
            ]);

            return;
        }

        $adminRole = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();

        if ($adminRole === null) {
            Log::warning('NotifyOnAutomationFailure: admin role missing');

            return;
        }

        $admins = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new AutomationFailedPermanently(
                executionId: $execution->id,
                ruleName: $execution->rule?->name ?? (string) $execution->rule_id,
                triggerEvent: $execution->trigger_event,
                subjectType: $execution->subject_type,
                subjectId: (int) $execution->subject_id,
                errorClass: $execution->error_class,
                errorMessage: $execution->error_message,
            ));
        }
    }
}