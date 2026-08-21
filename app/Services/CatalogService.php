<?php

namespace App\Services;

use App\Exceptions\InvalidOperationException;
use App\Models\ActivityType;
use App\Models\Currency;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LossReason;
use App\Models\PipelineStage;
use App\Models\ProductCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

/**
 * Generic catalog administration (B08 / RF-CFG-001, RF-CFG-002).
 *
 * Every system catalog (lead sources, lead statuses, loss reasons,
 * activity types, pipeline stages, product categories, currencies and
 * taxes) flows through this service so the rules are consistent:
 *
 * - Catalogs are NEVER physically deleted (RNF-DAT-001, RF-CFG-002). The
 *   deactivate() method flips `is_active = false` and audits the reason.
 * - Slug/code uniqueness is enforced per model. Currencies use their
 *   ISO 4217 `code` (3-char primary key) as the natural unique key; the
 *   rest of the catalogs use `slug`.
 * - The list() projection always sorts by `name` so admin dropdowns read
 *   alphabetically, with active rows first so deactivated entries are
 *   easy to spot at the bottom.
 */
class CatalogService
{
    /**
     * Model class => natural unique key column for the catalog.
     * Used by the validator to enforce uniqueness on the right column
     * (Currencies key on `code`, the rest key on `slug`).
     *
     * @var array<class-string, string>
     */
    private array $uniqueKeys = [
        LeadSource::class => 'slug',
        LeadStatus::class => 'slug',
        LossReason::class => 'slug',
        ActivityType::class => 'slug',
        PipelineStage::class => 'slug',
        ProductCategory::class => 'slug',
        Currency::class => 'code',
        Tax::class => 'slug',
    ];

    /**
     * Whitelist of catalog model classes accepted by this service. A class
     * outside this list triggers an InvalidArgumentException at the service
     * boundary, so the controller cannot accidentally widen scope.
     *
     * @var list<class-string>
     */
    public const ALLOWED_MODELS = [
        LeadSource::class,
        LeadStatus::class,
        LossReason::class,
        ActivityType::class,
        PipelineStage::class,
        ProductCategory::class,
        Currency::class,
        Tax::class,
    ];

    /**
     * Validate that the supplied model class is on the whitelist. The check
     * runs in every public method so a buggy caller cannot bypass it.
     */
    private function assertAllowed(string $modelClass): void
    {
        if (! in_array($modelClass, self::ALLOWED_MODELS, true)) {
            throw new \InvalidArgumentException(
                "El catálogo \"{$modelClass}\" no es administrable por CatalogService."
            );
        }
    }

    /**
     * Create a new catalog row. Slugs default to a lowercased + dashed
     * version of the name when not supplied, matching the existing seed
     * convention (docs/BASE_DATOS.md §3).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(string $modelClass, array $data, User $actor): Model
    {
        $this->assertAllowed($modelClass);

        $uniqueKey = $this->uniqueKeys[$modelClass];
        $this->assertUnique($modelClass, $uniqueKey, $data[$uniqueKey] ?? null, null);

        return DB::transaction(function () use ($modelClass, $data, $actor, $uniqueKey): Model {
            $row = new $modelClass();

            $row->name = (string) ($data['name'] ?? '');
            $row->$uniqueKey = (string) ($data[$uniqueKey] ?? $this->deriveSlug($row->name, $uniqueKey));
            $row->is_active = (bool) ($data['is_active'] ?? true);

            if ($modelClass === LeadStatus::class && array_key_exists('is_final', $data)) {
                $row->is_final = (bool) $data['is_final'];
            }

            if ($modelClass === PipelineStage::class) {
                $row->stage_type = (string) ($data['stage_type'] ?? 'open');
                $row->default_probability = (float) ($data['default_probability'] ?? 0);
            }

            if ($modelClass === Currency::class) {
                $row->symbol = (string) ($data['symbol'] ?? $data['code']);
                $row->decimals = (int) ($data['decimals'] ?? 2);
            }

            if ($modelClass === Tax::class && array_key_exists('rate', $data)) {
                $row->rate = (float) $data['rate'];
            }

            if (array_key_exists('sort', $data)) {
                $row->sort = (int) $data['sort'];
            }

            $row->save();

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => $modelClass,
                // Currency uses a string PK (e.g. "PEN") that does not fit
                // the activity_log.subject_id BIGINT column. When the PK
                // is non-numeric we keep the key in properties and leave
                // subject_id as null so the polymorphic link stays sound.
                'subject_id' => ctype_digit((string) $row->getKey()) ? (int) $row->getKey() : null,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'catalog-created',
                'description' => "Catálogo {$modelClass} \"{$row->name}\" creado",
                'properties' => [
                    'key' => (string) $row->getKey(),
                    'name' => $row->name,
                    'unique_key' => $uniqueKey,
                    'unique_value' => (string) $row->$uniqueKey,
                    'is_active' => (bool) $row->is_active,
                ],
            ]);

            return $row;
        });
    }

    /**
     * Update a catalog row. The unique key (slug or code) is treated like
     * any other field: callers can rename it but uniqueness is enforced
     * against the rest of the table.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $modelClass, Model $row, array $data, User $actor): Model
    {
        $this->assertAllowed($modelClass);

        $uniqueKey = $this->uniqueKeys[$modelClass];

        if (array_key_exists($uniqueKey, $data) && $data[$uniqueKey] !== $row->{$uniqueKey}) {
            $this->assertUnique($modelClass, $uniqueKey, $data[$uniqueKey], $row->getKey());
        }

        return DB::transaction(function () use ($modelClass, $row, $data, $actor): Model {
            $oldName = $row->name;
            $oldIsActive = (bool) $row->is_active;

            if (array_key_exists('name', $data)) {
                $row->name = (string) $data['name'];
            }

            $uniqueKey = $this->uniqueKeys[$modelClass];
            if (array_key_exists($uniqueKey, $data)) {
                $row->$uniqueKey = (string) $data[$uniqueKey];
            }

            if (array_key_exists('is_active', $data)) {
                $row->is_active = (bool) $data['is_active'];
            }

            if ($modelClass === LeadStatus::class && array_key_exists('is_final', $data)) {
                $row->is_final = (bool) $data['is_final'];
            }

            if ($modelClass === PipelineStage::class) {
                if (array_key_exists('stage_type', $data)) {
                    $row->stage_type = (string) $data['stage_type'];
                }
                if (array_key_exists('default_probability', $data)) {
                    $row->default_probability = (float) $data['default_probability'];
                }
            }

            if ($modelClass === Currency::class) {
                if (array_key_exists('symbol', $data)) {
                    $row->symbol = (string) $data['symbol'];
                }
                if (array_key_exists('decimals', $data)) {
                    $row->decimals = (int) $data['decimals'];
                }
            }

            if ($modelClass === Tax::class && array_key_exists('rate', $data)) {
                $row->rate = (float) $data['rate'];
            }

            if (array_key_exists('sort', $data)) {
                $row->sort = (int) $data['sort'];
            }

            $row->save();

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => $modelClass,
                'subject_id' => ctype_digit((string) $row->getKey()) ? (int) $row->getKey() : null,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'catalog-updated',
                'description' => "Catálogo {$modelClass} #{$row->getKey()} actualizado",
                'properties' => [
                    'key' => (string) $row->getKey(),
                    'old_name' => $oldName,
                    'new_name' => $row->name,
                    'old_is_active' => $oldIsActive,
                    'new_is_active' => (bool) $row->is_active,
                ],
            ]);

            return $row->refresh();
        });
    }

    /**
     * Deactivate (never delete) a catalog row with a mandatory reason.
     *
     * @throws InvalidOperationException When the row is already inactive.
     */
    public function deactivate(string $modelClass, Model $row, User $actor, string $reason): void
    {
        $this->assertAllowed($modelClass);

        if (! $row->is_active) {
            throw new InvalidOperationException(
                "El catálogo #{$row->getKey()} ya estaba desactivado."
            );
        }

        DB::transaction(function () use ($modelClass, $row, $actor, $reason): void {
            $row->is_active = false;
            $row->save();

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => $modelClass,
                'subject_id' => ctype_digit((string) $row->getKey()) ? (int) $row->getKey() : null,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'catalog-deactivated',
                'description' => "Catálogo {$modelClass} \"{$row->name}\" desactivado",
                'properties' => [
                    'key' => (string) $row->getKey(),
                    'name' => $row->name,
                    'reason' => $reason,
                ],
            ]);
        });
    }

    /**
     * Reactivate a previously deactivated row.
     *
     * @throws InvalidOperationException When the row is already active.
     */
    public function activate(string $modelClass, Model $row, User $actor): void
    {
        $this->assertAllowed($modelClass);

        if ($row->is_active) {
            throw new InvalidOperationException(
                "El catálogo #{$row->getKey()} ya estaba activo."
            );
        }

        DB::transaction(function () use ($modelClass, $row, $actor): void {
            $row->is_active = true;
            $row->save();

            Activity::query()->create([
                'log_name' => 'default',
                'subject_type' => $modelClass,
                'subject_id' => ctype_digit((string) $row->getKey()) ? (int) $row->getKey() : null,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'event' => 'catalog-activated',
                'description' => "Catálogo {$modelClass} \"{$row->name}\" activado",
                'properties' => [
                    'key' => (string) $row->getKey(),
                    'name' => $row->name,
                ],
            ]);
        });
    }

/**
     * List catalog rows ordered by name. Active rows come first so the
     * admin sees the active set up top and the inactive ones below.
     *
     * @return Collection<int, Model>
     */
    public function list(string $modelClass, bool $includeInactive = true): Collection
    {
        $this->assertAllowed($modelClass);

        $instance = new $modelClass();
        $tieBreaker = $instance->getKeyName();

        $query = $modelClass::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy($tieBreaker);

        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Reject duplicate slug/code values. Centralised so both create()
     * and update() enforce the same rule.
     */
    private function assertUnique(string $modelClass, string $column, mixed $value, mixed $ignoreId): void
    {
        if ($value === null || $value === '') {
            throw new \InvalidArgumentException(
                "El campo {$column} es obligatorio para el catálogo."
            );
        }

        $validator = Validator::make(
            [$column => $value],
            [$column => [
                'required',
                Rule::unique($this->tableFor($modelClass), $column)->ignore($ignoreId),
            ]],
        );

        if ($validator->fails()) {
            throw new \InvalidArgumentException(
                "Ya existe un registro con {$column}=\"{$value}\" en este catálogo."
            );
        }
    }

    private function tableFor(string $modelClass): string
    {
        return (new $modelClass())->getTable();
    }

    /**
     * Lowercased + dashed slug for name-driven catalogs (the rest of the
     * seed convention). Currency codes pass through untouched.
     */
    private function deriveSlug(string $name, string $column): string
    {
        if ($column === 'code') {
            return mb_strtoupper(trim($name));
        }

        $slug = mb_strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug);
        $slug = trim((string) $slug, '-');

        return $slug;
    }
}