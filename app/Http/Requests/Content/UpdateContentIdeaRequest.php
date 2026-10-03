<?php

namespace App\Http\Requests\Content;

/**
 * Input contract for editing an idea (T6d).
 *
 * The editable fields and their rules are exactly the registration contract, so
 * this request reuses it instead of drifting. Reachability and the `update`
 * ability are inherited with it.
 */
class UpdateContentIdeaRequest extends StoreContentIdeaRequest
{
}
