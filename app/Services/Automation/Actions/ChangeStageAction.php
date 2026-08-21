<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\AutomationExecutionStep;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Services\OpportunityService;
use InvalidArgumentException;

/**
 * Change the pipeline stage of an opportunity.
 *
 * Payload:
 *  - stage_slug (required) — PipelineStage slug, e.g. 'calificacion',
 *    'propuesta', 'ganada', 'perdida'.
 *  - note (string, optional)
 */
class ChangeStageAction implements ActionContract
{
    public function __construct(private readonly OpportunityService $opportunities)
    {
    }

    public function execute(array $payload, AutomationExecutionStep $step): void
    {
        $execution = $step->execution()->first();

        if ($execution === null) {
            return;
        }

        $subject = $execution->subject_type === Opportunity::class
            ? Opportunity::query()->find((int) $execution->subject_id)
            : null;

        if ($subject === null) {
            return;
        }

        $slug = (string) ($payload['stage_slug'] ?? '');

        if ($slug === '') {
            throw new InvalidArgumentException('ChangeStageAction: stage_slug is required.');
        }

        $stage = PipelineStage::query()->where('slug', $slug)->first();

        if ($stage === null) {
            throw new InvalidArgumentException("ChangeStageAction: stage_slug '{$slug}' not found.");
        }

        $actor = \App\Models\User::query()->first();

        if ($actor === null) {
            throw new \RuntimeException('ChangeStageAction: no user available to act as actor.');
        }

        $this->opportunities->changeStage($subject, $stage, $actor, $payload['note'] ?? null);

        $step->response_json = array_merge((array) ($step->response_json ?? []), [
            'stage_slug' => $slug,
            'stage_id' => $stage->id,
        ]);
        $step->save();
    }

    public function simulate(array $payload): array
    {
        return [
            'would_change_stage' => true,
            'payload' => $payload,
        ];
    }
}