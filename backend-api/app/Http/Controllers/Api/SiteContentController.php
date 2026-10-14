<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\SiteContent;
use App\Support\SiteContentDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super Admin "Website Content": edit the public website's sections and read
 * contact-form messages. Routes are behind role:super_admin.
 */
class SiteContentController extends Controller
{
    /** GET /api/admin/site-content — current content, defaults, and which sections were customised. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PublicSiteController::content(),
            'defaults' => SiteContentDefaults::all(),
            'customized' => SiteContent::query()->whereIn('key', SiteContentDefaults::KEYS)->pluck('key')->values(),
        ]);
    }

    /** PUT /api/admin/site-content/{key} — replace one section. */
    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless(in_array($key, SiteContentDefaults::KEYS, true), 404);

        $data = $request->validate(['value' => ['required', 'array']]);
        abort_if(strlen((string) json_encode($data['value'])) > 200_000, 422, 'This section is too large.');

        SiteContent::updateOrCreate(
            ['key' => $key],
            ['value' => $data['value'], 'updated_by_user_id' => $request->user()->id],
        );
        PublicSiteController::forgetCache();

        return response()->json(['message' => 'Saved.', 'data' => PublicSiteController::content()[$key]]);
    }

    /** DELETE /api/admin/site-content/{key} — go back to the default for one section. */
    public function reset(string $key): JsonResponse
    {
        abort_unless(in_array($key, SiteContentDefaults::KEYS, true), 404);

        SiteContent::where('key', $key)->delete();
        PublicSiteController::forgetCache();

        return response()->json(['message' => 'Reset to default.', 'data' => SiteContentDefaults::get($key)]);
    }

    /** GET /api/admin/contact-messages?status=open|handled|all */
    public function messages(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['open', 'handled', 'all'])]]);
        $status = $request->query('status', 'open');

        $messages = ContactMessage::query()
            ->when($status === 'open', fn ($q) => $q->whereNull('handled_at'))
            ->when($status === 'handled', fn ($q) => $q->whereNotNull('handled_at'))
            ->latest()
            ->paginate(25);

        return response()->json($messages->toArray() + [
            'open_count' => ContactMessage::whereNull('handled_at')->count(),
        ]);
    }

    /** PATCH /api/admin/contact-messages/{id} — mark handled / reopen. */
    public function updateMessage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['handled' => ['required', 'boolean']]);
        $message = ContactMessage::findOrFail($id);
        $message->update(['handled_at' => $data['handled'] ? now() : null]);

        return response()->json(['message' => 'Updated.', 'data' => $message]);
    }
}
