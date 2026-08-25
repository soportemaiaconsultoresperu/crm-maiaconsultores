<?php

namespace App\Services\DemoData;

use App\Models\Activity;
use App\Models\AutomationExecution;
use App\Models\AutomationExecutionStep;
use App\Models\DemoDataRecord;
use App\Models\Email\EmailMessage;
use App\Models\Notification\OutboundDelivery;
use App\Models\Quotation;
use App\Models\WhatsApp\WhatsAppMessage;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class DemoDataGuard
{
    public function isDemo(Model|string|null $modelOrType, ?int $id = null): bool
    {
        if ($modelOrType instanceof Model) {
            $type = $modelOrType::class;
            $id = (int) $modelOrType->getKey();
        } else {
            $type = $modelOrType;
        }

        if ($type === null || $id === null || $id <= 0) {
            return false;
        }

        return DemoDataRecord::query()
            ->where('model_type', $type)
            ->where('record_id', $id)
            ->exists();
    }

    public function assertNotDemo(Model|string|null $modelOrType, ?int $id = null, string $operation = 'external action'): void
    {
        if ($this->isDemo($modelOrType, $id)) {
            throw new RuntimeException("Demo data blocked for {$operation}.");
        }
    }

    public function isOutboundDeliveryDemo(OutboundDelivery $delivery): bool
    {
        return $this->isDemo($delivery)
            || ($delivery->related_entity_type !== null && $delivery->related_entity_id !== null
                && $this->isDemo($delivery->related_entity_type, (int) $delivery->related_entity_id));
    }

    public function isEmailMessageDemo(EmailMessage $message): bool
    {
        return $this->isDemo($message)
            || ($message->related_lead_id !== null && $this->isDemo(\App\Models\Lead::class, (int) $message->related_lead_id))
            || ($message->related_customer_id !== null && $this->isDemo(\App\Models\Customer::class, (int) $message->related_customer_id))
            || ($message->related_opportunity_id !== null && $this->isDemo(\App\Models\Opportunity::class, (int) $message->related_opportunity_id))
            || ($message->related_quotation_id !== null && $this->isDemo(Quotation::class, (int) $message->related_quotation_id))
            || ($message->related_contact_id !== null && $this->isDemo(\App\Models\Contact::class, (int) $message->related_contact_id));
    }

    public function isWhatsAppMessageDemo(WhatsAppMessage $message): bool
    {
        $conversation = $message->conversation;

        return $this->isDemo($message)
            || ($conversation !== null && $this->isDemo($conversation))
            || ($conversation?->lead_id !== null && $this->isDemo(\App\Models\Lead::class, (int) $conversation->lead_id))
            || ($conversation?->customer_id !== null && $this->isDemo(\App\Models\Customer::class, (int) $conversation->customer_id))
            || ($conversation?->contact_id !== null && $this->isDemo(\App\Models\Contact::class, (int) $conversation->contact_id));
    }

    public function isAutomationStepDemo(AutomationExecutionStep $step): bool
    {
        if ($this->isDemo($step)) {
            return true;
        }

        /** @var AutomationExecution|null $execution */
        $execution = $step->execution()->first();
        if ($execution === null) {
            return false;
        }

        return $this->isDemo($execution)
            || ($execution->rule_id !== null && $this->isDemo(\App\Models\AutomationRule::class, (int) $execution->rule_id))
            || ($execution->subject_type !== null && $execution->subject_id !== null
                && $this->isDemo((string) $execution->subject_type, (int) $execution->subject_id));
    }

    public function isActivityDemo(Activity $activity): bool
    {
        return $this->isDemo($activity)
            || ($activity->subject_type !== null && $activity->subject_id !== null
                && $this->isDemo((string) $activity->subject_type, (int) $activity->subject_id));
    }
}
