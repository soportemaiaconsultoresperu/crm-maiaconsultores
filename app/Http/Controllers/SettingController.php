<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsUpdateRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin settings UI (B08 / RF-CFG-004, RF-CFG-005).
 *
 * The view lists every row in the `settings` table grouped by `group` so
 * the admin can update parameters like "prices_include_tax" or
 * "pagination_size" from a single form. Every write goes through
 * SettingsService which encodes the value according to its declared
 * `type` and writes a `setting-updated` activity row with old/new
 * values (ADR-008).
 */
class SettingController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Render the form. The view shows a section per `group`, with one
     * input per setting whose type maps to the matching HTML control.
     */
    public function index(Request $request): View
    {
        if (! $request->user()->can('settings.view') && ! $request->user()->can('settings.manage')) {
            abort(403);
        }

        $rows = Setting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get();

        $groups = $rows->groupBy('group')->map(function ($group): array {
            return $group->map(function (Setting $row): array {
                return [
                    'key' => $row->key,
                    'value' => $row->value,
                    'type' => $row->type,
                    'group' => $row->group,
                    'casted' => $this->settings->get($row->key),
                ];
            })->values()->all();
        })->all();

        return view('admin.settings.index', [
            'groups' => $groups,
        ]);
    }

    public function update(SettingsUpdateRequest $request): RedirectResponse
    {
        if (! $request->user()->can('settings.manage')) {
            abort(403);
        }

        $payload = $request->validated()['settings'] ?? [];

        foreach ($payload as $entry) {
            $key = (string) ($entry['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $type = (string) ($entry['type'] ?? 'string');
            $group = (string) ($entry['group'] ?? 'general');
            $value = $entry['value'] ?? null;

            // Coerce booleans that came in as "1"/"0" so the service stores
            // the canonical encoding.
            if ($type === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($type === 'integer') {
                $value = (int) $value;
            } elseif ($type === 'decimal') {
                $value = (float) $value;
            } elseif ($type === 'json') {
                $value = is_string($value) && $value !== ''
                    ? json_decode($value, true)
                    : $value;
            }

                $this->settings->set($key, $value, $type, $group, $request->user());
            }

            return redirect()
                ->route('admin.settings.index')
                ->with('status', 'Parámetros guardados correctamente.');
        }

        /**
         * Company logo upload. Validates that the upload is an image (jpg, png
         * or webp), enforces a 2 MB cap, writes the file to the PRIVATE local
         * disk under storage/app/private/company/, deletes the previous file
         * if any, updates the `company.logo_path` setting and audits the
         * change via SettingsService.
         */
        public function uploadLogo(Request $request): RedirectResponse
        {
            if (! $request->user()->can('settings.manage')) {
                abort(403);
            }

            $request->validate([
                'logo' => [
                    'required',
                    'file',
                    'max:2048', // 2 MB (in KB)
                    'mimes:jpg,jpeg,png,webp',
                    'mimetypes:image/jpeg,image/png,image/webp',
                ],
            ], [
                'logo.required' => 'Seleccioná un archivo de imagen.',
                'logo.file'     => 'El archivo enviado no es válido.',
                'logo.max'      => 'El logo no puede superar los 2 MB.',
                'logo.mimes'    => 'El logo debe ser JPG, PNG o WEBP.',
                'logo.mimetypes'=> 'El contenido del archivo no coincide con una imagen.',
            ]);

            /** @var UploadedFile $file */
            $file = $request->file('logo');
            $extension = strtolower($file->getClientOriginalExtension());
            $disk = 'local'; // private disk (storage/app/private), no symlink.
            $filename = 'logo-' . now()->format('Ymd-His') . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.' . $extension;
            $relativePath = 'company/' . $filename;

            // Remove the previous logo file (if any) so the private disk does
            // not accumulate orphaned assets.
            $previous = (string) $this->settings->get('company.logo_path', '');
            if ($previous !== '' && Storage::disk($disk)->exists($previous)) {
                Storage::disk($disk)->delete($previous);
            }

            Storage::disk($disk)->putFileAs(
                'company',
                $file,
                $filename,
            );

            $this->settings->set(
                key: 'company.logo_path',
                value: $relativePath,
                type: 'string',
                group: 'company',
                actor: $request->user(),
            );

            return redirect()
                ->route('admin.settings.index')
                ->with('status', 'Logo actualizado correctamente.');
        }

        /**
         * Remove the company logo: deletes the file from the private disk and
         * clears the `company.logo_path` setting.
         */
        public function removeLogo(Request $request): RedirectResponse
        {
            if (! $request->user()->can('settings.manage')) {
                abort(403);
            }

            $disk = 'local';
            $previous = (string) $this->settings->get('company.logo_path', '');
            if ($previous !== '' && Storage::disk($disk)->exists($previous)) {
                Storage::disk($disk)->delete($previous);
            }

            $this->settings->set(
                key: 'company.logo_path',
                value: '',
                type: 'string',
                group: 'company',
                actor: $request->user(),
            );

            return redirect()
                ->route('admin.settings.index')
                ->with('status', 'Logo eliminado.');
        }

        /**
         * Stream the current company logo inline so the <img> preview can
         * render it without exposing a public URL. Authorised users only.
         */
        public function previewLogo(Request $request): Response
        {
            if (! $request->user()->can('settings.view') && ! $request->user()->can('settings.manage')) {
                abort(403);
            }

            $disk = 'local';
            $path = (string) $this->settings->get('company.logo_path', '');
            if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                abort(404);
            }

            $absolute = Storage::disk($disk)->path($path);
            // MIME inferred from the extension — the upload route already
            // restricts the whitelist to jpg/jpeg/png/webp, so we don't need
            // a separate filesystem probe here.
            $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png'         => 'image/png',
                'webp'        => 'image/webp',
                default       => 'application/octet-stream',
            };

            return new BinaryFileResponse($absolute, 200, [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, max-age=300',
            ]);
        }
    }