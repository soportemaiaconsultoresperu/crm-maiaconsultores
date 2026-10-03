<?php

namespace App\Services\Content;

use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use Illuminate\Database\Eloquent\Builder;

class ContentCatalogAdministrationService
{
    public const GROUPS = [
        'pillars' => ['model' => ContentPillar::class, 'label' => 'Pilares'],
        'topics' => ['model' => ContentTopic::class, 'label' => 'Temas'],
        'objectives' => ['model' => ContentObjective::class, 'label' => 'Objetivos'],
        'formats' => ['model' => ContentFormat::class, 'label' => 'Formatos'],
        'channels' => ['model' => ContentChannel::class, 'label' => 'Canales'],
    ];

    public function model(string $group): string
    {
        abort_unless(isset(self::GROUPS[$group]), 404);

        return self::GROUPS[$group]['model'];
    }

    public function query(ContentProject $project, string $group): Builder
    {
        $model = $this->model($group);
        $query = $model::query();

        return $group === 'topics'
            ? $query->whereHas('pillar', fn (Builder $pillars) => $pillars->where('content_project_id', $project->id))
            : $query->where('content_project_id', $project->id);
    }
}
