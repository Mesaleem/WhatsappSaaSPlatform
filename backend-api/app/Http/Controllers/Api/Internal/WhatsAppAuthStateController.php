<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppEngineAuthState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Storage for qr-engine-service's Baileys credentials (see the
 * whatsapp_engine_auth_states migration for why they live here).
 *
 * Every route is behind the `internal.secret` middleware: there is no user,
 * only the shared secret, and nothing here is reachable from a browser.
 *
 * Names are the same filename-shaped keys Baileys uses on disk (for example
 * "creds.json", "pre-key-1.json"). Validated by hand, not with Laravel's
 * `set.*` rule, because those names contain dots, which Laravel would read as
 * nested paths.
 */
class WhatsAppAuthStateController extends Controller
{
    /**
     * Letters, digits and . _ @ + = - only. Covers every name Baileys produces
     * (JIDs, key ids, base64 app-state ids, after its own '/' -> '__' and ':' -> '-'
     * mapping). Anything else, including a path separator, is refused.
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9._@+=\-]{1,191}$/';

    /** Upper bound per request. The Node side splits large imports below this. */
    private const MAX_ENTRIES_PER_REQUEST = 1000;

    /** Upper bound per value. Real key material is a few KB. */
    private const MAX_VALUE_BYTES = 2_000_000;

    /** GET /api/internal/whatsapp-auth/{numberId} */
    public function show(int $numberId): JsonResponse
    {
        $this->ensureSlotExists($numberId);

        $entries = WhatsAppEngineAuthState::query()
            ->where('whatsapp_number_id', $numberId)
            ->get()
            ->mapWithKeys(fn (WhatsAppEngineAuthState $row) => [$row->name => $row->value])
            ->all();

        return response()->json(['entries' => $entries]);
    }

    /**
     * PUT /api/internal/whatsapp-auth/{numberId}
     * Body: { "set": { "<name>": "<json string>", ... }, "delete": ["<name>", ...] }
     * Applied in one transaction, so a crash mid-write never stores half an update.
     */
    public function update(Request $request, int $numberId): JsonResponse
    {
        $this->ensureSlotExists($numberId);

        $data = $request->validate([
            'set' => ['present', 'array', 'max:'.self::MAX_ENTRIES_PER_REQUEST],
            'delete' => ['present', 'array', 'max:'.self::MAX_ENTRIES_PER_REQUEST],
        ]);

        $set = $data['set'];
        $delete = $data['delete'];

        foreach ($set as $name => $value) {
            $this->assertValidName((string) $name);
            if (! is_string($value) || strlen($value) > self::MAX_VALUE_BYTES) {
                throw new HttpException(422, 'Each stored value must be a string under the size limit.');
            }
        }
        foreach ($delete as $name) {
            if (! is_string($name)) {
                throw new HttpException(422, 'Delete entries must be strings.');
            }
            $this->assertValidName($name);
        }

        DB::transaction(function () use ($numberId, $set, $delete): void {
            foreach ($set as $name => $value) {
                WhatsAppEngineAuthState::query()->updateOrCreate(
                    ['whatsapp_number_id' => $numberId, 'name' => (string) $name],
                    ['value' => $value],
                );
            }

            if ($delete !== []) {
                WhatsAppEngineAuthState::query()
                    ->where('whatsapp_number_id', $numberId)
                    ->whereIn('name', $delete)
                    ->delete();
            }
        });

        return response()->json([
            'message' => 'Auth state updated.',
            'stored' => count($set),
            'deleted' => count($delete),
        ]);
    }

    /** DELETE /api/internal/whatsapp-auth/{numberId} — logout: forget every key for the number slot. */
    public function destroy(int $numberId): JsonResponse
    {
        $this->ensureSlotExists($numberId);

        WhatsAppEngineAuthState::query()->where('whatsapp_number_id', $numberId)->delete();

        return response()->json(['message' => 'Auth state removed.']);
    }

    /**
     * GET /api/internal/whatsapp-auth/paired — accounts whose stored creds
     * completed real WhatsApp pairing (creds.registered === true). Used on
     * qr-engine-service boot to resume sessions after a redeploy. A
     * mid-pairing account is never listed, so it is never resumed silently.
     */
    public function paired(): JsonResponse
    {
        $numberIds = WhatsAppEngineAuthState::query()
            ->where('name', 'creds.json')
            ->get(['whatsapp_number_id', 'value'])
            ->filter(function (WhatsAppEngineAuthState $row): bool {
                $creds = json_decode((string) $row->value, true);

                return is_array($creds) && ((($creds['registered'] ?? false) === true) || ! empty($creds['me']['id'] ?? null));
            })
            ->pluck('whatsapp_number_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return response()->json(['number_ids' => $numberIds]);
    }

    private function ensureSlotExists(int $numberId): void
    {
        // [Bug fix, disclosed]: WhatsAppController::platformDeviceSlot() now lazily
        // creates a real WhatsAppNumber row for the Super Admin's own "test
        // WhatsApp" device the first time it connects (see that method's own
        // docblock for why a plain account-id fallback here could never work --
        // whatsapp_engine_auth_states.whatsapp_number_id has a hard foreign key to
        // this table), so a plain existence check is correct again.
        if (! DB::table('whatsapp_numbers')->where('id', $numberId)->exists()) {
            throw new HttpException(404, 'WhatsApp number not found.');
        }
    }

    private function assertValidName(string $name): void
    {
        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw new HttpException(422, 'Invalid auth state name.');
        }
    }
}
