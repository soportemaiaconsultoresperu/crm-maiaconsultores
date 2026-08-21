<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\V2\ActivityCompleted;
use App\Events\V2\ContactDeactivated;
use App\Events\V2\ContactPrimaryChanged;
use App\Events\V2\CustomerDeactivated;
use App\Events\V2\LeadAssigned;
use App\Events\V2\LeadCreated;
use App\Events\V2\LeadDeactivated;
use App\Events\V2\LeadStatusChanged;
use App\Events\V2\OpportunityCreated;
use App\Events\V2\OpportunityLost;
use App\Events\V2\OpportunityStageChanged;
use App\Events\V2\OpportunityWon;
use App\Events\V2\QuotationAccepted;
use App\Events\V2\QuotationCreated;
use App\Events\V2\QuotationSent;
use App\Events\V2\LeadConverted;
use App\Events\V2\TimeDriven\ActivityOverdue;
use App\Events\V2\TimeDriven\CustomerIdle;
use App\Events\V2\TimeDriven\QuotationWillExpire;
use App\Listeners\V2\DispatchAutomationRule;
use App\Services\Automation\ActionRegistry;
use App\Services\Automation\Actions\AddNoteAction;
use App\Services\Automation\Actions\AddTagAction;
use App\Services\Automation\Actions\AssignOwnerAction;
use App\Services\Automation\Actions\ChangeStageAction;
use App\Services\Automation\Actions\ChangeStatusAction;
use App\Services\Automation\Actions\CreateActivityAction;
use App\Services\Automation\Actions\CreateFollowUpActivityAction;
use App\Services\Automation\Actions\SendEmailAction;
use App\Services\Automation\Actions\SendNotificationAction;
use App\Services\Automation\Actions\SendWhatsAppTemplateAction;
use App\Services\Automation\Actions\WebhookAction;
use App\Services\Automation\ConditionEvaluator;
use App\Services\Automation\CycleDetector;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * B12 — Automation engine wiring.
 *
 *  - register(): bind the engine services and register all 11 concrete
 *    actions in the ActionRegistry.
 *  - boot(): explicit Event::listen() for every trigger class and the
 *    scheduler entries for the 5 automation commands.
 */
class AutomationServiceProvider extends ServiceProvider
{
    /**
     * Map of trigger FQCN => action type slug. Used to keep the listener
     * registration list and the action registration list compact.
     */
    public const ACTION_TYPES = [
        'create_activity' => CreateActivityAction::class,
        'assign_owner' => AssignOwnerAction::class,
        'change_status' => ChangeStatusAction::class,
        'change_stage' => ChangeStageAction::class,
        'add_tag' => AddTagAction::class,
        'send_notification' => SendNotificationAction::class,
        'send_email' => SendEmailAction::class,
        'send_whatsapp_template' => SendWhatsAppTemplateAction::class,
        'create_follow_up_activity' => CreateFollowUpActivityAction::class,
        'add_note' => AddNoteAction::class,
        'webhook' => WebhookAction::class,
    ];

    /**
     * Trigger events the listener subscribes to. One explicit binding per
     * class so the relationship is grep-able.
     */
    public const TRIGGER_EVENTS = [
        LeadCreated::class,
        LeadAssigned::class,
        LeadStatusChanged::class,
        LeadDeactivated::class,
        OpportunityCreated::class,
        OpportunityStageChanged::class,
        OpportunityWon::class,
        OpportunityLost::class,
        QuotationCreated::class,
        QuotationSent::class,
        QuotationAccepted::class,
        ActivityCompleted::class,
        ContactPrimaryChanged::class,
        ContactDeactivated::class,
        CustomerDeactivated::class,
        LeadConverted::class,
        QuotationWillExpire::class,
        ActivityOverdue::class,
        CustomerIdle::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ConditionEvaluator::class);
        $this->app->singleton(CycleDetector::class, fn () => new CycleDetector());

        $this->app->singleton(ActionRegistry::class, function (Container $container): ActionRegistry {
            $registry = new ActionRegistry($container);

            foreach (self::ACTION_TYPES as $type => $class) {
                $registry->register($type, $class);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->registerAutomationPermissions();

        foreach (self::TRIGGER_EVENTS as $eventClass) {
            Event::listen($eventClass, [DispatchAutomationRule::class, 'handle']);
        }

        $this->registerScheduler();
    }

    /**
     * B12 — ensure the 5 automation permissions exist and are granted to the
     * admin and supervisor roles. Idempotent and registered at boot time so
     * V1 seeder permission counts (84) stay unchanged.
     */
    public function registerAutomationPermissions(): void
    {
        // Guard for early boot contexts (e.g. test bootstrap before the
        // Spatie permission tables exist, or the configured DB is unreachable).
        // If the schema isn't ready or the DB connection fails, the permission
        // registrations will be performed by the dedicated Artisan command in
        // production deployments — never break the boot path with a fatal here.
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('permissions')) {
                return;
            }
        } catch (\Throwable $e) {
            // DB unreachable (driver down, credentials wrong, schema not yet
            // migrated). Exit silently; the dedicated command will catch up.
            if (function_exists('logger')) {
                logger()->debug('AutomationServiceProvider: permissions guard skipped', [
                    'reason' => $e::class,
                ]);
            }
            return;
        }

        $permissions = [
            'automations.view',
            'automations.manage',
            'automations.test',
            'automations.webhook.execute',
            'automations.audit',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $admin = Role::query()
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->first();

        if ($admin !== null) {
            $existing = $admin->permissions->pluck('name')->all();
            $merged = array_values(array_unique(array_merge($existing, $permissions)));
            $admin->syncPermissions($merged);
        }

        $supervisor = Role::query()
            ->where('name', 'supervisor')
            ->where('guard_name', 'web')
            ->first();

        if ($supervisor !== null) {
            $existing = $supervisor->permissions->pluck('name')->all();
            $merged = array_values(array_unique(array_merge($existing, ['automations.view'])));
            $supervisor->syncPermissions($merged);
        }
    }

    private function registerScheduler(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        Schedule::command('automation:dispatch-due-steps')
            ->everyMinute()
            ->timezone('America/Lima');

        Schedule::command('automation:reconcile-failed-steps')
            ->everyFiveMinutes()
            ->timezone('America/Lima');

        Schedule::command('automation:emit-activity-overdue')
            ->dailyAt('02:00')
            ->timezone('America/Lima');

        Schedule::command('automation:emit-quotation-will-expire')
            ->dailyAt('02:30')
            ->timezone('America/Lima');

        Schedule::command('automation:emit-customer-idle')
            ->everyThirtyMinutes()
            ->timezone('America/Lima');
    }
}