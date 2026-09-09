<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mail Template Manager — CRUD for reusable template content. Platform-wide
 * (not account-scoped), gated by manage-notifications (super_admin or
 * admin) — see NotificationTemplate's docblock for why templates aren't
 * per-tenant.
 */
class NotificationTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 15), 100);

        $templates = NotificationTemplate::query()
            ->with('creator:id,name')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('subject', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($templates);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(NotificationTemplate::with('creator:id,name')->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        $template = NotificationTemplate::create($data);

        return response()->json($template, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $template = NotificationTemplate::findOrFail($id);
        $template->update($this->validated($request));

        return response()->json($template->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        NotificationTemplate::findOrFail($id)->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['rich_text', 'raw_html'])],
            'category' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);
    }
}
