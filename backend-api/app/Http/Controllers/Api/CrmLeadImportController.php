<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Services\Batches\RecipientFileParser;
use App\Services\Crm\CrmLeadService;
use App\Support\CrmVisibility;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * QA fixes (2026-10-14) — POST /api/crm/leads/import: leads from a CSV / Excel file.
 *
 * The CRM plan's only lead source used to be typing leads in one by one. The
 * file's first row names the columns (name, phone, email, company, requirement
 * — phone is required; common variants such as "mobile" or "full name" are
 * understood). A number that already has a CRM lead is skipped, so importing
 * the same file twice adds nothing. At most RecipientFileParser::MAX_ROWS rows.
 */
class CrmLeadImportController extends Controller
{
    use ResolvesTenantAccount;

    private const COLUMNS = [
        'phone' => ['phone', 'phone_number', 'mobile', 'mobile_number', 'number', 'whatsapp', 'contact', 'contact_number'],
        'name' => ['name', 'full_name', 'customer', 'customer_name', 'lead_name', 'contact_name'],
        'email' => ['email', 'email_address', 'e_mail'],
        'company_name' => ['company', 'company_name', 'business', 'organisation', 'organization'],
        'requirement' => ['requirement', 'notes', 'note', 'interest', 'message', 'details'],
    ];

    public function __invoke(Request $request, RecipientFileParser $parser, CrmLeadService $leads): JsonResponse
    {
        $account = $this->requireAccount($request);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120']]);

        $file = $request->file('file');
        $rows = $parser->rows($file->getRealPath(), (string) $file->getClientOriginalExtension());
        if (count($rows) < 2) {
            return response()->json(['message' => 'The file has no leads. The first row must name the columns (name, phone, email, ...).', 'error_code' => 'EMPTY_FILE'], 422);
        }
        if (count($rows) > RecipientFileParser::MAX_ROWS + 1) {
            return response()->json(['message' => 'At most '.RecipientFileParser::MAX_ROWS.' leads per file.', 'error_code' => 'TOO_MANY_ROWS'], 422);
        }

        $header = array_map(fn ($h) => trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $h)), '_'), $rows[0]);
        $index = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($header as $i => $h) {
                if (in_array($h, $names, true)) {
                    $index[$field] = $i;
                    break;
                }
            }
        }
        if (! isset($index['phone'])) {
            return response()->json(['message' => 'No phone column found. Name one column "phone" (or "mobile").', 'error_code' => 'NO_PHONE_COLUMN'], 422);
        }

        $assignee = CrmVisibility::seesAll($request->user()) ? null : $request->user()->id;
        $created = 0;
        $skipped = 0;
        $invalid = 0;
        foreach (array_slice($rows, 1) as $row) {
            $value = fn (string $field) => isset($index[$field]) ? trim((string) ($row[$index[$field]] ?? '')) : '';
            $phone = PhoneNumberNormalizer::normalize($value('phone'));
            if (! preg_match('/^[0-9]{10,15}$/', $phone)) {
                $invalid++;

                continue;
            }
            $contactId = Contact::query()->where('account_id', $account->id)->where('phone_number', $phone)->value('id');
            if ($contactId !== null && CrmLead::query()->where('account_id', $account->id)->where('contact_id', $contactId)->exists()) {
                $skipped++;

                continue;
            }
            $email = $value('email');
            try {
                $leads->create($account, array_filter([
                    'phone_number' => $phone,
                    'name' => $value('name') ?: null,
                    'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                    'company_name' => $value('company_name') ?: null,
                    'requirement' => $value('requirement') ? mb_substr($value('requirement'), 0, 2000) : null,
                    'source' => CrmLead::SOURCE_MANUAL,
                    'assigned_user_id' => $assignee,
                ], fn ($v) => $v !== null));
                $created++;
            } catch (Throwable) {
                $invalid++;
            }
        }

        return response()->json([
            'message' => "{$created} lead(s) imported".($skipped ? ", {$skipped} already in the CRM" : '').($invalid ? ", {$invalid} row(s) without a valid phone number" : '').'.',
            'data' => ['created' => $created, 'skipped' => $skipped, 'invalid' => $invalid],
        ], 201);
    }
}
