<?php

namespace App\Services\Content;

use App\Models\Content\ContentFieldDefinition;

/**
 * Default ContentFieldValueUsage: reports no stored values.
 *
 * This is the honest state for T5a, which has no value storage yet. It lets a
 * definition type change while no values exist, and it is replaced by T6's real
 * implementation through the container binding documented on the interface.
 */
class NullContentFieldValueUsage implements ContentFieldValueUsage
{
    public function hasStoredValues(ContentFieldDefinition $definition): bool
    {
        return false;
    }
}
