<?php

namespace App\Http\Requests;

/**
 * Phase 6 — CRM Task 7. PUT|PATCH /api/crm/tags/{id} — rename. A tag has
 * no other editable field, so PATCH and PUT share the same required
 * `name` rule rather than PATCH accepting an empty no-op body.
 */
class UpdateCrmTagRequest extends StoreCrmTagRequest
{
}
