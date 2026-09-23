<?php

namespace App\Http\Requests;

/**
 * Phase 6 — CRM Task 9. POST /api/crm/leads/bulk/tags/attach and
 * /bulk/tags/detach — one tag id per request. The tag's ownership is
 * checked by the controller through forAccount() (a foreign or missing id
 * is the same generic 422).
 */
class BulkCrmLeadTagRequest extends BulkCrmLeadRequest
{
    public function rules(): array
    {
        return [
            ...$this->leadIdRules(),
            'tag_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
