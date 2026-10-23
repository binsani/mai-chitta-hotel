<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactInquiry;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ContactInquiryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('website.view');
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['new', 'in_progress', 'resolved'])],
            'search' => ['nullable', 'string', 'max:180'],
        ]);

        $items = ContactInquiry::query()->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'status', 'created_at'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%")))
            ->latest('id')->paginate(25);

        return response()->json($items);
    }

    public function update(Request $request, ContactInquiry $contactInquiry, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('website.edit');
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'in_progress', 'resolved'])]]);
        $contactInquiry->update($data);
        $audit->log('cms.inquiry.updated', $contactInquiry, $data);

        return response()->json(['message' => 'Inquiry status updated.']);
    }
}
