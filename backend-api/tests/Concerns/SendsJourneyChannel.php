<?php

namespace Tests\Concerns;

use App\Models\WhatsAppNumber;

/**
 * Journey create/update requires `whatsapp_number_ids` (WhatsAppFlowController::
 * validateFlow()). Suites written before that rule test other gates (palette,
 * entitlements, secrets, versioning), so this adds an owned number to any
 * Journey save that does not send one. The rule itself is covered by
 * JourneyChannelGateTest, which does not use this trait.
 */
trait SendsJourneyChannel
{
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)
            && preg_match('#^/?api/whatsapp/flows(/\d+)?(\?|$)#', $uri)
            && ! array_key_exists('whatsapp_number_ids', $data)
            && ($accountId = $this->journeyChannelAccountId($uri)) !== null) {
            $data['whatsapp_number_ids'] = [$this->journeyChannelFor($accountId)->id];
        }

        return parent::json($method, $uri, $data, $headers, $options);
    }

    private function journeyChannelAccountId(string $uri): ?int
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        // Same as the server: a body account_id is ignored (some suites forge
        // one on purpose); only the ?account_id= override or the user's own.
        $id = $query['account_id'] ?? $this->app['auth']->guard()->user()?->account_id;

        return $id !== null ? (int) $id : null;
    }

    private function journeyChannelFor(int $accountId): WhatsAppNumber
    {
        return WhatsAppNumber::query()->where('account_id', $accountId)->orderBy('id')->first()
            ?? WhatsAppNumber::query()->create([
                'account_id' => $accountId,
                'phone_number' => '9198'.str_pad((string) $accountId, 8, '0', STR_PAD_LEFT),
                'is_included' => true,
                'is_default' => true,
                'status' => WhatsAppNumber::STATUS_UNLINKED,
            ]);
    }
}
