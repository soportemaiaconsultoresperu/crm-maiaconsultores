<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * System settings (B08 / RF-CFG-004, RF-CFG-005).
 *
 * Settings are stored in the `settings` table keyed by `key` (string PK).
 * Each value is cast through its declared `type` (string / integer /
 * decimal / boolean / json) so consumers always receive a typed value.
 *
 * Every write is audited with `setting-updated` and carries the
 * previous/new values in `properties` so the audit viewer shows the
 * exact change. The `old_value` / `new_value` payloads are coerced to
 * strings (json_encoded when applicable) so JSON settings still fit in
 * the activity_log.properties column.
 */
class SettingsService
{
    /**
     * Allowed value types for a setting. Mirrors the enum in
     * `settings` table column `type`.
     */
    public const ALLOWED_TYPES = ['string', 'integer', 'decimal', 'boolean', 'json'];

    /**
     * Read a setting, falling back to the supplied default when the key
     * is not present. Returns the value already cast to its declared type
     * (integer / boolean / decimal / json_decode'd array, or string).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = Setting::query()->whereKey($key)->first();

        if ($row === null) {
            return $default;
        }

        return $this->castValue($row->value, $row->type);
    }

    /**
     * Upsert a setting (RF-CFG-004, RF-CFG-005). The value is encoded
     * according to its type before storage, and an audit row is written
     * with the previous/new values.
     *
     * @param  mixed  $value
     */
    public function set(string $key, mixed $value, string $type = 'string', string $group = 'general', ?User $actor = null): Setting
    {
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Tipo de setting no soportado: \"{$type}\". Use uno de: ".
                implode(', ', self::ALLOWED_TYPES).'.'
            );
        }

        $encoded = $this->encodeValue($value, $type);

        return DB::transaction(function () use ($key, $encoded, $type, $group, $value, $actor): Setting {
            /** @var Setting|null $existing */
            $existing = Setting::query()->whereKey($key)->first();
            $previous = $existing?->value;
            $previousType = $existing?->type;
            $previousGroup = $existing?->group;

            $row = Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $encoded, 'type' => $type, 'group' => $group],
            );

            if ($actor !== null) {
                // Setting uses a string PK ("currency_default", etc.) which
                // does not fit the activity_log.subject_id BIGINT column.
                // Keep the key in properties and leave subject_id as null so
                // the polymorphic link stays consistent across the codebase.
                Activity::query()->create([
                    'log_name' => 'default',
                    'subject_type' => Setting::class,
                    'subject_id' => null,
                    'causer_type' => User::class,
                    'causer_id' => $actor->id,
                    'event' => 'setting-updated',
                    'description' => "Setting {$key} actualizado",
                    'properties' => [
                        'key' => $key,
                        'old_value' => $this->stringifyForLog($previous, $previousType),
                        'new_value' => $this->stringifyForLog($encoded, $type),
                        'old_type' => $previousType,
                        'new_type' => $type,
                        'old_group' => $previousGroup,
                        'new_group' => $group,
                    ],
                ]);
            }

            // Return the row with the typed value applied for convenience.
            $row->value = $encoded;
            $row->type = $type;
            $row->group = $group;

            return $row;
        });
    }

    /**
     * Return all settings keyed by their primary key with values already
     * cast through their declared types. Useful for populating the
     * "Configuración" admin view (RF-CFG-005).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Setting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->mapWithKeys(fn (Setting $row): array => [
                $row->key => $this->castValue($row->value, $row->type),
            ])
            ->all();
    }

    /**
     * Encode a value according to the setting type. Integers/booleans/
     * decimals become strings (settings.value is TEXT); JSON is
     * json_encoded; strings pass through.
     */
    private function encodeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'integer' => (string) (int) $value,
            'decimal' => number_format((float) $value, 2, '.', ''),
            'boolean' => $this->encodeBool($value),
            'json' => json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
            default => (string) $value,
        };
    }

    private function encodeBool(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
        }

        return ((int) $value) > 0 ? '1' : '0';
    }

    /**
     * Cast a stored string back into its declared type.
     */
    private function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => in_array($value, ['1', 'true', 'yes', 'on'], true),
            'json' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    private function stringifyForLog(?string $value, ?string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($type === 'json') {
            return $value;
        }

        return $value;
    }
}