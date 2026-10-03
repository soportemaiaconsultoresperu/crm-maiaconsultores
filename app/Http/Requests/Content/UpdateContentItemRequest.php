<?php

namespace App\Http\Requests\Content;

/**
 * Input contract for editing a planned content (T6c).
 *
 * The editable fields and their rules are exactly the creation contract, so
 * this request reuses it instead of drifting. Reachability and the `update`
 * ability are inherited with it.
 */
class UpdateContentItemRequest extends StoreContentItemRequest
{
}
