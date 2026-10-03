<?php

declare(strict_types=1);

namespace App\Enums\Content;

/**
 * Single source of truth for the custom-field types a content project may
 * define. Nothing else in the module enumerates the supported types.
 *
 * The stored value is a plain VARCHAR (`type`), matching the repository
 * convention for vocabulary enums such as AutomationMode; the cast lives on
 * the `ContentFieldDefinition` model.
 *
 * `acceptsOptions()` gates list options: only the two selection types may own
 * rows in `content_field_options`. `valueRules()` is the base validation for a
 * stored value; `optionValueRules()` validates a single option reference and is
 * empty for non-list types.
 */
enum ContentFieldType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Date = 'date';
    case Number = 'number';
    case SingleSelection = 'single_selection';
    case MultipleSelection = 'multiple_selection';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texto',
            self::LongText => 'Texto largo',
            self::Date => 'Fecha',
            self::Number => 'Número',
            self::SingleSelection => 'Selección única',
            self::MultipleSelection => 'Selección múltiple',
        };
    }

    /**
     * Only selection types are backed by a list of options.
     */
    public function acceptsOptions(): bool
    {
        return in_array($this, [self::SingleSelection, self::MultipleSelection], true);
    }

    /**
     * Validation rules applied to a stored value of this type. Selection values
     * store the option id, so they are validated against the option table.
     *
     * @return list<mixed>
     */
    public function valueRules(): array
    {
        return match ($this) {
            self::Text => ['string', 'max:255'],
            self::LongText => ['string'],
            self::Date => ['date'],
            self::Number => ['numeric'],
            self::SingleSelection => $this->optionValueRules(),
            self::MultipleSelection => ['array'],
        };
    }

    /**
     * Validation rules for one option reference, or an empty list when the type
     * carries no options. Used directly for single selection and per element
     * for multiple selection.
     *
     * @return list<string>
     */
    public function optionValueRules(): array
    {
        return $this->acceptsOptions()
            ? ['integer', 'exists:content_field_options,id']
            : [];
    }
}
