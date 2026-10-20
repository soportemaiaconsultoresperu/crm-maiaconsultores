<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\UpdateContentStandardFieldsRequest;
use App\Models\Content\ContentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Per-project choice of the optional standard fields (S2).
 *
 * Hiding a field only changes the project setting: the stored content data is
 * never touched, so a field shown again brings its values back. The `configure`
 * ability is required (administrative, refused on an archived project).
 */
class ContentStandardFieldsController extends Controller
{
    public function update(UpdateContentStandardFieldsRequest $request, ContentProject $contentProject): RedirectResponse
    {
        Gate::authorize('configure', $contentProject);

        $contentProject->forceFill(['hidden_standard_fields' => $request->hiddenFields()])->save();

        return redirect()
            ->route('content.fields.index', $contentProject)
            ->with('status', 'Campos estándar actualizados. Los datos ya registrados se conservan aunque el campo esté oculto.');
    }
}
