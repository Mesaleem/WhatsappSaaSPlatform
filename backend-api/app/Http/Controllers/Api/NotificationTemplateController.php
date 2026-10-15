<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Services\Mail\SystemEmailCatalog;
use App\Services\Mail\SystemMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mail Template Manager — CRUD for reusable template content. Platform-wide
 * (not account-scoped), gated by manage-notifications (super_admin or
 * admin) — see NotificationTemplate's docblock for why templates aren't
 * per-tenant.
 *
 * Master (system) templates — the automatic emails, is_system = 1, found by
 * slug (SystemEmailCatalog / SystemMailer) — are Super Admin only: another
 * admin never sees them, and they are edited, switched off or reset to the
 * built-in default but never deleted. `?kind=system|custom` filters the list.
 */
class NotificationTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 15), 100);
        $superAdmin = $request->user()->isSuperAdmin();
        $kind = $request->string('kind')->toString();

        $templates = NotificationTemplate::query()
            ->with('creator:id,name')
            ->when(! $superAdmin || $kind === 'custom', fn ($q) => $q->system(false))
            ->when($superAdmin && $kind === 'system', fn ($q) => $q->system())
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('subject', 'like', '%'.$request->string('search').'%')
                ->orWhere('slug', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->orderByDesc('is_system')
            ->orderBy('category')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($templates);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->find($request, $id)->load('creator:id,name'));
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
        $template = $this->find($request, $id);
        $data = $this->validated($request);

        if ($template->is_system) {
            // A master template keeps its slug and identity; its content and on/off switch change.
            $data = array_intersect_key($data, array_flip(['name', 'category', 'subject', 'body', 'is_active']));
            $data['type'] = 'raw_html';
        } else {
            unset($data['is_active']);
        }

        $template->update($data);

        return response()->json($template->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $template = $this->find($request, $id);

        if ($template->is_system) {
            return response()->json([
                'message' => 'A system email template cannot be deleted. Switch it off or reset it to the default instead.',
            ], 422);
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    /** POST /api/notification-templates/{id}/reset — put a master template back to its built-in default. */
    public function reset(Request $request, int $id): JsonResponse
    {
        $template = $this->find($request, $id);
        $default = $template->is_system && $template->slug ? SystemEmailCatalog::get($template->slug) : null;

        if ($default === null) {
            return response()->json(['message' => 'Only system email templates have a default to reset to.'], 422);
        }

        $template->update([
            'name' => $default['name'],
            'category' => $default['category'],
            'description' => $default['description'],
            'subject' => $default['subject'],
            'body' => $default['body'],
            'variables' => SystemEmailCatalog::variablesFor($template->slug),
            'type' => 'raw_html',
            'is_active' => true,
        ]);

        return response()->json($template->fresh());
    }

    /**
     * POST /api/notification-templates/test {subject, body} — email the given
     * (unsaved) content to the caller, with sample values in the variables,
     * through the platform's Mail Settings.
     */
    public function sendTest(Request $request, SystemMailer $mailer): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'variables' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $sent = $mailer->sendTest($user->email, $data['subject'], $data['body'], $user->name);

        return $sent
            ? response()->json(['message' => "Test email sent to {$user->email}."])
            : response()->json(['message' => 'The test email could not be sent. Check Mail Settings (SMTP) and try again.'], 422);
    }

    private function find(Request $request, int $id): NotificationTemplate
    {
        $template = NotificationTemplate::findOrFail($id);

        // Master templates are invisible to anyone but the Super Admin (404, not 403: nothing to discover).
        abort_if($template->is_system && ! $request->user()->isSuperAdmin(), 404);

        return $template;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['rich_text', 'raw_html'])],
            'category' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
