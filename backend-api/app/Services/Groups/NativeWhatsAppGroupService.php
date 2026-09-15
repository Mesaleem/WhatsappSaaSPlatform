<?php

namespace App\Services\Groups;

use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Native WhatsApp Group Re-Architecture — the qr-engine-service client for
 * REAL WhatsApp group management (create a live group, add participants
 * to one). Mirrors BaileysDriver's own Http/X-Internal-Secret pattern
 * exactly (same base URL/secret config, same 15s timeout, same
 * "trust the JSON success flag, never let a transport exception escape"
 * shape) — kept as its own class rather than added onto BaileysDriver
 * because group *management* (create/add-participants) is a different
 * concern from WhatsAppDriverInterface's job of *sending a message*; a
 * ContactGroup has no notion of "driver" the way an Account/Subscription
 * does, and this only ever applies to the 'qr' engine (see the class-level
 * note below on why the Meta Cloud API cannot support this at all).
 *
 * [Disclosed, hard external constraint, not a gap in this code]: the
 * official Meta WhatsApp Cloud API has no group-messaging capability of
 * any kind — creating or sending to a WhatsApp group is only possible
 * through the unofficial Baileys/WhatsApp-Web protocol this app's 'qr'
 * engine already uses. Callers of this service (ContactGroupController,
 * SyncNativeWhatsAppGroupJob, SyncNativeWhatsAppGroupParticipantsJob) are
 * responsible for only ever calling it for an account whose
 * currentSubscription->engine_type is 'qr' — this class itself has no
 * Account/Subscription context to enforce that a second time.
 */
class NativeWhatsAppGroupService
{
    /**
     * Converts already-normalized (PhoneNumberNormalizer::normalize())
     * bare-digit phone numbers into Baileys JIDs before they ever leave
     * this process — qr-engine-service treats every participant string
     * it receives as an opaque, already-correct JID, the exact same
     * division of responsibility BaileysDriver already uses for
     * /api/message/send's "to" field (see that class's own docblock).
     */
    private const JID_SUFFIX = '@s.whatsapp.net';

    /**
     * Creates a real WhatsApp group on accountId's connected Baileys
     * device with the given subject and initial participants (plain,
     * un-normalized phone numbers — normalized here).
     *
     * @param list<string> $participantPhones
     * @return array{success: bool, jid?: string, invite_link?: string, error?: string}
     */
    public function createGroup(int $accountId, string $subject, array $participantPhones): array
    {
        $participants = $this->toJids($participantPhones);

        if ($participants === []) {
            return ['success' => false, 'error' => 'No valid participant phone numbers were provided.'];
        }

        $result = $this->post('/api/group/create', [
            'account_id' => $accountId,
            'subject' => $subject,
            'participants' => $participants,
        ]);

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'The QR engine rejected the group-create request.'];
        }

        return [
            'success' => true,
            'jid' => $result['jid'] ?? null,
            'invite_link' => $result['invite_link'] ?? null,
        ];
    }

    /**
     * Adds participants to an already-created live group. Not a
     * requirement of the original re-architecture request's 3 numbered
     * items, but a direct, minimal-risk consequence of point 1
     * ("REAL Native WhatsApp Groups" as the whole point of this
     * re-architecture) — see ContactGroupController::addContacts()'s
     * docblock for exactly where this is called from and why leaving it
     * out would make "add contacts" silently only update this app's own
     * DB shadow copy for a native group, not the real group members see.
     *
     * @param list<string> $participantPhones
     * @return array{success: bool, error?: string}
     */
    public function addParticipants(int $accountId, string $groupJid, array $participantPhones): array
    {
        $participants = $this->toJids($participantPhones);

        if ($participants === []) {
            return ['success' => true];
        }

        $result = $this->post('/api/group/add-participants', [
            'account_id' => $accountId,
            'group_jid' => $groupJid,
            'participants' => $participants,
        ]);

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'The QR engine rejected the add-participants request.'];
        }

        return ['success' => true];
    }

    /**
     * "Select an existing group" extension — every real WhatsApp group
     * the connected account is CURRENTLY a participant of. Read-only,
     * no local ContactGroup rows are touched here; ContactGroupController::
     * availableNativeGroups() is the caller, which filters out groups
     * already imported (matched by wa_group_jid) before returning the
     * list to the frontend.
     *
     * @return array{success: bool, groups?: list<array{jid: string, subject: string, participants_count: int}>, error?: string}
     */
    public function listGroups(int $accountId): array
    {
        $result = $this->get('/api/group/list', ['account_id' => $accountId]);

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'The QR engine rejected the group-list request.'];
        }

        return ['success' => true, 'groups' => $result['groups'] ?? []];
    }

    /**
     * "Select an existing group" extension — full metadata (including
     * the current participant JIDs) for ONE group, fetched right after
     * the user picks it from listGroups()'s summary. Called by
     * ContactGroupController::importNative() to populate the new
     * ContactGroup's members from the real group instead of starting at
     * 0 — see that method for how the returned JIDs are converted back
     * into this app's normalized phone-digit form.
     *
     * @return array{success: bool, jid?: string, subject?: string, participants?: list<array{jid: string, is_admin: bool}>, error?: string}
     */
    public function groupMetadata(int $accountId, string $groupJid): array
    {
        $result = $this->get('/api/group/metadata', ['account_id' => $accountId, 'group_jid' => $groupJid]);

        if (! ($result['success'] ?? false)) {
            return ['success' => false, 'error' => $result['error'] ?? 'The QR engine rejected the group-metadata request.'];
        }

        return [
            'success' => true,
            'jid' => $result['jid'] ?? $groupJid,
            'subject' => $result['subject'] ?? '',
            'participants' => $result['participants'] ?? [],
        ];
    }

    /** @return list<string> */
    private function toJids(array $phones): array
    {
        return collect($phones)
            ->map(fn (string $p) => PhoneNumberNormalizer::normalize($p))
            ->filter(fn (string $digits) => $digits !== '')
            ->unique()
            ->map(fn (string $digits) => $digits.self::JID_SUFFIX)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload): array
    {
        $baseUrl = rtrim((string) config('services.qr_engine.url'), '/');
        $secret = config('services.qr_engine.internal_secret');

        try {
            $response = Http::withHeaders(['X-Internal-Secret' => $secret])
                ->timeout(15)
                ->post("{$baseUrl}{$path}", $payload);
        } catch (Throwable $e) {
            Log::error('NativeWhatsAppGroupService: qr-engine-service unreachable.', [
                'path' => $path,
                'account_id' => $payload['account_id'] ?? null,
                'exception' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'qr-engine-service is unreachable: '.$e->getMessage()];
        }

        $body = $response->json() ?? [];
        $succeeded = $response->successful() && ($body['success'] ?? false) === true;

        if (! $succeeded) {
            Log::warning('NativeWhatsAppGroupService: request failed.', [
                'path' => $path,
                'account_id' => $payload['account_id'] ?? null,
                'status_code' => $response->status(),
                'response' => $body,
            ]);

            return ['success' => false, 'error' => $body['error'] ?? $body['message'] ?? 'The QR engine rejected the request.'];
        }

        return $body;
    }

    /**
     * GET counterpart to post() above — same auth header, timeout, and
     * "trust the JSON success flag, never let a transport exception
     * escape" contract, used by the two read-only "select an existing
     * group" endpoints (listGroups()/groupMetadata()) added alongside
     * this method. Query params (account_id, group_jid) instead of a
     * JSON body since these are GET requests on the qr-engine-service
     * side (see server.js's /api/group/list and /api/group/metadata).
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $baseUrl = rtrim((string) config('services.qr_engine.url'), '/');
        $secret = config('services.qr_engine.internal_secret');

        try {
            $response = Http::withHeaders(['X-Internal-Secret' => $secret])
                ->timeout(15)
                ->get("{$baseUrl}{$path}", $query);
        } catch (Throwable $e) {
            Log::error('NativeWhatsAppGroupService: qr-engine-service unreachable.', [
                'path' => $path,
                'account_id' => $query['account_id'] ?? null,
                'exception' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'qr-engine-service is unreachable: '.$e->getMessage()];
        }

        $body = $response->json() ?? [];
        $succeeded = $response->successful() && ($body['success'] ?? false) === true;

        if (! $succeeded) {
            Log::warning('NativeWhatsAppGroupService: request failed.', [
                'path' => $path,
                'account_id' => $query['account_id'] ?? null,
                'status_code' => $response->status(),
                'response' => $body,
            ]);

            return ['success' => false, 'error' => $body['error'] ?? $body['message'] ?? 'The QR engine rejected the request.'];
        }

        return $body;
    }
}
