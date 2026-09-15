<?php

namespace App\Support;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- translates ONE engine-agnostic media descriptor
 * (media_type/url/caption/filename) into the driver-specific $metaData
 * shape each WhatsAppDriverInterface implementation actually expects,
 * so DirectMessageDispatcher and ProcessGroupDirectMessageJob (both
 * individual- and group-recipient paths) share one translation instead
 * of two copies.
 *
 * The Meta ('meta' engine) shape reuses, verbatim, the SAME
 * {type, <type>: {link, caption, filename}} convention
 * ChatbotEngineService::buildMediaReply() already sends to
 * MetaCloudApiDriver for chatbot media replies -- not a new contract,
 * the existing one.
 *
 * The QR ('qr'/Baileys engine) shape is NEW with this feature --
 * BaileysDriver::sendMessage() already spreads every $metaData key
 * verbatim into its POST body to qr-engine-service (see that class), so
 * this emits media_type/media_url/caption/filename keys that
 * qr-engine-service's /api/message/send handler and
 * sessionManager.sendMessage() (also extended by this feature -- see
 * their own docblocks) now read to build the matching Baileys content
 * object.
 *
 * [Disclosed, important limitation]: the Baileys/QR media path has NOT
 * been exercised against a live WhatsApp session in this environment
 * (no running qr-engine-service instance with an authenticated device
 * was available to test against) -- the content-object shapes built in
 * sessionManager.js follow Baileys' own documented sendMessage() content
 * API (image/video/document/audio by {url}, `fileName` for document),
 * but this is [Hypothesis], not independently verified end-to-end the
 * way the Meta path is (which reuses an already-shipped, presumably
 * already-tested code path). Flag this before relying on QR-engine media
 * sends in production.
 */
class WhatsAppMediaPayloadBuilder
{
    public const MEDIA_TYPES = ['image', 'document', 'video', 'audio'];

    /** Recognized image extensions for inferMediaType() below -- anything else falls back to 'document', which WhatsApp accepts for essentially any file type (PDF, DOCX, XLSX, ...). */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * Media Templates (send-time media_url override) -- when a caller
     * supplies only a bare media_url (no separate media_type field, by
     * design: TemplateMessageDispatcher's payload deliberately has just
     * one media_url key, not a second type field to keep alongside it),
     * this infers 'image' vs 'document' from the URL's file extension so
     * a query string or fragment after the extension doesn't confuse it
     * (parse_url() strips both before this ever sees the path).
     */
    public static function inferMediaType(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::IMAGE_EXTENSIONS, true) ? 'image' : 'document';
    }

    /**
     * @param array{media_type: string, url: string, caption?: string|null, filename?: string|null} $media
     * @return array{0: string, 1: array<string, mixed>} [messageParamForDriver, metaData]
     */
    public static function build(?string $engineType, array $media): array
    {
        $mediaType = $media['media_type'];
        $url = $media['url'];
        $caption = $media['caption'] ?? null;
        $filename = $media['filename'] ?? null;

        if ($engineType === 'qr') {
            $metaData = array_filter([
                'media_type' => $mediaType,
                'media_url' => $url,
                'caption' => $caption,
                'filename' => $filename,
            ], fn ($v) => $v !== null && $v !== '');

            return [(string) ($caption ?? ''), $metaData];
        }

        // 'meta' (or anything else -- WhatsAppEngineFactory::make() will
        // already have thrown before this is ever reached for an
        // unconfigured/unknown engine).
        $mediaObject = array_filter([
            'link' => $url,
            'caption' => $caption,
            'filename' => $filename,
        ], fn ($v) => $v !== null && $v !== '');

        return [(string) ($caption ?? ''), ['type' => $mediaType, $mediaType => $mediaObject]];
    }
}
