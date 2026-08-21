<?php

namespace App\Services;

use App\Models\CampaignStep;
use App\Models\CampaignTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CampaignTemplateService
{
    public function create(array $data, User $actor): CampaignTemplate
    {
        return DB::transaction(function () use ($data, $actor) {
            $template = CampaignTemplate::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'objective' => $data['objective'] ?? 'custom',
                'status' => $data['status'] ?? CampaignTemplate::STATUS_DRAFT,
                'owner_id' => $data['owner_id'] ?? $actor->id,
                'team_id' => $data['team_id'] ?? null,
            ]);
            $this->syncSteps($template, $data['steps'] ?? []);
            return $template->fresh();
        });
    }

    public function update(CampaignTemplate $template, array $data): CampaignTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $template->update([
                'name' => $data['name'] ?? $template->name,
                'description' => $data['description'] ?? $template->description,
                'objective' => $data['objective'] ?? $template->objective,
                'status' => $data['status'] ?? $template->status,
                'owner_id' => $data['owner_id'] ?? $template->owner_id,
                'team_id' => $data['team_id'] ?? $template->team_id,
            ]);
            if (isset($data['steps'])) {
                $template->steps()->delete();
                $this->syncSteps($template, $data['steps']);
            }
            return $template->fresh();
        });
    }

    public function duplicate(CampaignTemplate $template, string $newName, User $actor): CampaignTemplate
    {
        return DB::transaction(function () use ($template, $newName, $actor) {
            $clone = CampaignTemplate::query()->create([
                'name' => $newName,
                'description' => $template->description,
                'objective' => $template->objective,
                'status' => CampaignTemplate::STATUS_DRAFT,
                'owner_id' => $actor->id,
                'team_id' => $template->team_id,
            ]);
            foreach ($template->steps as $step) {
                CampaignStep::query()->create([
                    'is_template' => true,
                    'template_id' => $clone->id,
                    'run_id' => null,
                    'source_step_id' => null,
                    'order' => $step->order,
                    'action_type_id' => $step->action_type_id,
                    'title' => $step->title,
                    'day_offset' => $step->day_offset,
                    'scheduled_time' => $step->scheduled_time,
                    'instructions' => $step->instructions,
                    'is_required' => $step->is_required,
                    'is_advertising' => $step->is_advertising,
                    'status' => $step->status,
                ]);
            }
            return $clone->fresh();
        });
    }

    public function deactivate(CampaignTemplate $template): void
    {
        $template->update(['status' => CampaignTemplate::STATUS_INACTIVE]);
    }

    public function activate(CampaignTemplate $template): void
    {
        $template->update(['status' => CampaignTemplate::STATUS_ACTIVE]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function syncSteps(CampaignTemplate $template, array $steps): void
    {
        foreach ($steps as $i => $step) {
            CampaignStep::query()->create([
                'is_template' => true,
                'template_id' => $template->id,
                'run_id' => null,
                'source_step_id' => null,
                'order' => $step['order'] ?? ($i + 1),
                'action_type_id' => $step['action_type_id'],
                'title' => $step['title'],
                'day_offset' => $step['day_offset'] ?? 0,
                'scheduled_time' => $step['scheduled_time'] ?? null,
                'instructions' => $step['instructions'] ?? null,
                'is_required' => $step['is_required'] ?? true,
                'is_advertising' => $step['is_advertising'] ?? false,
                'status' => $step['status'] ?? CampaignStep::STATUS_ACTIVE,
            ]);
        }
    }
}
