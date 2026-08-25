<?php

namespace App\Services\DemoData;

use App\Models\DemoDataBatch;
use App\Models\DemoDataRecord;
use App\Models\Document;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DemoDataPurger
{
    public function __construct(private readonly DemoDataGenerator $generator) {}

    public function delete(DemoDataBatch $batch): DemoDataBatch
    {
        DB::transaction(function () use ($batch): void {
            $batch->forceFill(['status' => DemoDataBatch::STATUS_DELETING])->save();
            $this->purgeRecords($batch);
            $batch->records()->delete();
            $batch->forceFill([
                'status' => DemoDataBatch::STATUS_DELETED,
                'finished_at' => now(),
            ])->save();
            $batch->delete();
        });

        return $batch->refresh();
    }

    public function reset(DemoDataBatch $batch, ?User $actor = null): DemoDataBatch
    {
        $modules = $batch->modules ?: DemoDataDependencyPreview::ALL_MODULES;

        DB::transaction(function () use ($batch): void {
            $batch->forceFill(['status' => DemoDataBatch::STATUS_RESETTING])->save();
            $this->purgeRecords($batch);
            $batch->records()->delete();
            $batch->forceFill([
                'status' => DemoDataBatch::STATUS_RESET,
                'reset_at' => now(),
                'finished_at' => now(),
            ])->save();
            $batch->delete();
        });

        return $this->generator->generate($modules, $actor);
    }

    private function purgeRecords(DemoDataBatch $batch): void
    {
        $records = $batch->records()->get()->sortByDesc(function (DemoDataRecord $record): int {
            return $this->deleteWeight($record);
        });

        foreach ($records as $record) {
            $this->deleteRecord($record);
        }
    }

    private function deleteRecord(DemoDataRecord $record): void
    {
        $class = $record->model_type;
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return;
        }

        /** @var class-string<Model> $class */
        $query = method_exists($class, 'withTrashed') ? $class::withTrashed() : $class::query();
        /** @var Model|null $model */
        $model = $query->whereKey((int) $record->record_id)->first();
        if ($model === null) {
            return;
        }

        if ($model instanceof Document) {
            $disk = Storage::disk($model->disk ?: (string) config('filesystems.docs_disk', 'docs'));
            if ($model->path !== null && $disk->exists($model->path)) {
                $disk->delete($model->path);
            }
        }

        if ($model instanceof Opportunity) {
            $model->stageHistories()->delete();
        }

        if (method_exists($model, 'forceDelete')) {
            $model->forceDelete();
            return;
        }

        $model->delete();
    }

    private function deleteWeight(DemoDataRecord $record): int
    {
        return match ($record->table_name) {
            'automation_execution_steps' => 140,
            'automation_executions' => 135,
            'automation_actions' => 132,
            'automation_conditions' => 131,
            'automation_condition_groups' => 130,
            'automation_rules' => 125,
            'support_observation_histories' => 139,
            'support_observations' => 138,
            'support_status_periods' => 137,
            'support_resolution_cycles' => 136,
            'support_incident_details' => 135,
            'support_reschedules' => 134,
            'support_session_participants' => 133,
            'support_session_details' => 132,
            'support_ticket_updates' => 131,
            'support_assignments' => 130,
            'support_tickets' => 129,
            'campaign_action_items' => 120,
            'campaign_participants' => 115,
            'campaign_steps' => 110,
            'campaign_runs' => 105,
            'campaign_templates' => 100,
            'documents' => 95,
            'quotation_items' => 90,
            'quotations' => 85,
            'activities' => 80,
            'opportunity_stage_histories' => 75,
            'opportunities' => 70,
            'contacts' => 60,
            'customers' => 50,
            'leads' => 40,
            'products' => 30,
            'users' => 10,
            default => 0,
        };
    }
}
