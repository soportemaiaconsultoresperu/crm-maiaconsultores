<?php

namespace App\Services\Content;

use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldValue;

/**
 * Real ContentFieldValueUsage: reports whether any stored value references the
 * definition. T6b binds this to the ContentFieldValueUsage seam in the service
 * provider, which activates the field-type immutability that T5a defined.
 *
 * A multiple-selection value owns one `content_field_values` row too, so a
 * single existence check on that table covers every field type, including the
 * list ones. The default NullContentFieldValueUsage stays available for tests
 * that need a value-free guard.
 */
class DatabaseContentFieldValueUsage implements ContentFieldValueUsage
{
    public function hasStoredValues(ContentFieldDefinition $definition): bool
    {
        if (! $definition->exists) {
            return false;
        }

        return ContentFieldValue::query()
            ->where('content_field_definition_id', $definition->getKey())
            ->exists();
    }
}
