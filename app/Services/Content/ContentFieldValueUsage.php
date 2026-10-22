<?php

namespace App\Services\Content;

use App\Models\Content\ContentFieldDefinition;

/**
 * Seam for "does this definition already have stored values?".
 *
 * T5a defines the schema but does not store values: typed value storage arrives
 * with T6. The only consumer is ContentFieldSchemaService, which uses it to keep
 * a definition's `type` immutable once values depend on it.
 *
 * The service resolves this interface from the container with a null default
 * (NullContentFieldValueUsage), so nothing is faked here. T6 supplies the real
 * implementation by binding this interface, for example:
 *
 *     $this->app->bind(ContentFieldValueUsage::class, DatabaseContentFieldValueUsage::class);
 *
 * or by passing it explicitly: new ContentFieldSchemaService($realUsage).
 */
interface ContentFieldValueUsage
{
    /**
     * Whether at least one stored value references this definition's key.
     */
    public function hasStoredValues(ContentFieldDefinition $definition): bool;
}
