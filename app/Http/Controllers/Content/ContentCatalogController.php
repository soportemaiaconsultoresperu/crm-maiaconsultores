<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ManageContentCatalogRequest;
use App\Models\Content\ContentProject;
use App\Services\Content\ContentCatalogAdministrationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ContentCatalogController extends Controller
{
    public function __construct(private readonly ContentCatalogAdministrationService $catalogs)
    {
    }

    public function index(ContentProject $contentProject): View
    {
        Gate::authorize('configure', $contentProject);
        $groups = [];
        foreach (ContentCatalogAdministrationService::GROUPS as $key => $definition) {
            $groups[$key] = [
                'label' => $definition['label'],
                'options' => $this->catalogs->query($contentProject, $key)->orderBy('sort')->orderBy('id')->get(),
            ];
        }

        return view('content.catalogs.index', ['project' => $contentProject, 'groups' => $groups]);
    }

    public function store(ManageContentCatalogRequest $request, ContentProject $contentProject, string $catalogGroup): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $data = $request->safe()->only(['name', 'slug', 'sort', 'content_pillar_id']);
        if ($catalogGroup !== 'topics') {
            $data['content_project_id'] = $contentProject->id;
        }
        $model = $this->catalogs->model($catalogGroup);
        $model::query()->create($data + ['is_active' => true]);

        return $this->backToCatalog($contentProject, 'Opción creada correctamente.');
    }

    public function update(ManageContentCatalogRequest $request, ContentProject $contentProject, string $catalogGroup, string $catalogOption): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $this->catalogs->query($contentProject, $catalogGroup)->findOrFail($catalogOption)
            ->update($request->safe()->only(['name']));

        return $this->backToCatalog($contentProject, 'Opción actualizada correctamente.');
    }

    public function state(ManageContentCatalogRequest $request, ContentProject $contentProject, string $catalogGroup, string $catalogOption): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $option = $this->catalogs->query($contentProject, $catalogGroup)->findOrFail($catalogOption);
        $request->boolean('is_active') ? $option->reactivate() : $option->deactivate();

        return $this->backToCatalog($contentProject, $request->boolean('is_active')
            ? 'Opción reactivada correctamente.' : 'Opción retirada. Sus referencias históricas se conservan.');
    }

    public function reorder(ManageContentCatalogRequest $request, ContentProject $contentProject, string $catalogGroup, string $catalogOption): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);
        $this->catalogs->query($contentProject, $catalogGroup)->findOrFail($catalogOption)
            ->update($request->safe()->only(['sort']));

        return $this->backToCatalog($contentProject, 'Orden actualizado correctamente.');
    }

    private function backToCatalog(ContentProject $project, string $message): RedirectResponse
    {
        return redirect()->route('content.catalogs.index', $project)->with('status', $message);
    }
}
