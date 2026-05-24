<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Loans\Models\DocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $types = DocumentType::query()
            ->when($request->boolean('active_only', true), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description', 'is_active']);

        return response()->json([
            'data' => $types,
        ]);
    }
}
