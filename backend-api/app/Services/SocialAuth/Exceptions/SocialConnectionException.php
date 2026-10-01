<?php

namespace App\Services\SocialAuth\Exceptions;

use App\Models\SocialAccount;
use App\Services\SocialAuth\SocialConnectionStatus;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Phase 9 Task 2 — a feature needed a social connection that is expired or
 * revoked (known from its stored state, or just reported by the provider
 * and persisted by SocialConnectionService). The message and payload are
 * safe to show a user: no token, no raw provider response — only the
 * connection's id/type/status, the stored safe reason and where to
 * reconnect. Extends RuntimeException so any caller that does not handle
 * it specially still fails the way it did before.
 */
class SocialConnectionException extends RuntimeException
{
    public const RECONNECT_PATH = '/social/accounts';

    private const ASSET_LABELS = [
        'facebook_page' => 'Facebook Page',
        'instagram' => 'Instagram account',
        'meta_ad_account' => 'Meta Ad Account',
        'linkedin_page' => 'LinkedIn Page',
        'youtube_channel' => 'YouTube channel',
    ];

    public function __construct(
        public readonly int $socialAccountId,
        public readonly string $assetType,
        public readonly string $connectionStatus,
        public readonly ?string $reason = null,
        public readonly ?string $assetName = null,
    ) {
        $label = self::ASSET_LABELS[$assetType] ?? 'social';
        $what = $assetName ? "{$label} \"{$assetName}\"" : $label;

        parent::__construct($connectionStatus === SocialConnectionStatus::REVOKED
            ? "Access to the connected {$what} was revoked. Reconnect it in Social Accounts, then try again."
            : "The connected {$what} has expired. Reconnect it in Social Accounts, then try again.");
    }

    public static function for(SocialAccount $socialAccount, string $connectionStatus, ?string $reason = null): self
    {
        return new self((int) $socialAccount->id, (string) $socialAccount->asset_type, $connectionStatus, $reason, $socialAccount->name);
    }

    public function errorCode(): string
    {
        return $this->connectionStatus === SocialConnectionStatus::REVOKED ? 'SOCIAL_CONNECTION_REVOKED' : 'SOCIAL_CONNECTION_EXPIRED';
    }

    /** The 409 body every feature returns for this case. */
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode(),
            'connection' => [
                'social_account_id' => $this->socialAccountId,
                'asset_type' => $this->assetType,
                'connection_status' => $this->connectionStatus,
                'reason' => $this->reason,
            ],
            'reconnect_path' => self::RECONNECT_PATH,
        ], 409);
    }
}
