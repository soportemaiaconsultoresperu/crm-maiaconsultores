<?php

namespace App\Http\Controllers;

use App\Services\CampaignContactSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignContactSearchController extends Controller
{
    public function __construct(private readonly CampaignContactSearchService $search) {}

    public function search(Request $request): JsonResponse
    {
        $filters = $request->only(['q', 'type', 'status_id', 'source_id', 'owner_id', 'ubigeo_code']);
        $results = $this->search->search($filters, page: (int) $request->query('page', 1), perPage: 25);
        return response()->json(['results' => $results]);
    }
}
