<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Read-only. There are intentionally no write endpoints for audit logs. */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('audit.view');
        $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'outcome' => ['nullable', 'in:success,failure'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $page = AuditLog::with('user:id,name,email')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->input('action').'%'))
            ->when($request->filled('outcome'), fn ($q) => $q->where('outcome', $request->input('outcome')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->latest('id')
            ->paginate(50);

        return response()->json($page);
    }
}
