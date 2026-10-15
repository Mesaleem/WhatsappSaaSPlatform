<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomPlanRequest;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\Mail\SystemMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Contact sales" for a custom plan (owner, 2026-10-14). Plans on sale are monthly; a
 * custom plan is arranged by the sales team:
 *
 *   store()   POST /api/billing/custom-plan-requests — the requirement form. Emails the
 *             customer ("our team will contact you within 24–48 hours") and every active
 *             Super Admin, and puts an in-app notification in each Super Admin's inbox.
 *             Mail is best effort: the request is saved even when mail is not set up.
 *   index()   GET  /api/admin/custom-plan-requests?status= — Super Admin.
 *   update()  PATCH /api/admin/custom-plan-requests/{id} {status, admin_note} — Super Admin.
 */
class CustomPlanRequestController extends Controller
{
    public function store(Request $request, SystemMailer $mailer): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            // The sales team calls back, so a phone number is required: 10–15 digits, optional +, spaces or dashes.
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9][0-9\s\-]{8,20}$/'],
            'company' => ['nullable', 'string', 'max:191'],
            'expected_messages' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'whatsapp_numbers' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'team_users' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'requirement' => ['required', 'string', 'min:10', 'max:5000'],
        ], [
            'phone.required' => 'Enter your phone number so our team can call you.',
            'phone.regex' => 'Enter a valid phone number, for example +91 98765 43210.',
        ]);
        if (strlen((string) preg_replace('/\D/', '', $data['phone'])) < 10) {
            return response()->json(['message' => 'Enter a valid phone number, for example +91 98765 43210.', 'errors' => ['phone' => ['Enter a valid phone number, for example +91 98765 43210.']]], 422);
        }

        // Optional fields may be absent from the request.
        $data += ['company' => null, 'expected_messages' => null, 'whatsapp_numbers' => null, 'team_users' => null];

        $req = CustomPlanRequest::create($data + ['account_id' => $user->account_id, 'user_id' => $user->id, 'status' => 'new']);

        $requirement = nl2br(e($data['requirement']));
        $mailer->send('sales.custom_plan_received', $data['email'], ['name' => $data['name']], ['requirement' => $requirement], $user->account_id, $data['name']);

        $admins = $mailer->superAdmins();
        $title = $data['company'] ?: $data['name'];
        foreach ($admins as $admin) {
            if ($admin->email) {
                $mailer->send('sales.custom_plan_admin', $admin->email, [
                    'title' => $title,
                    'name' => $data['name'],
                    'company' => $data['company'] ?: '-',
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'expected_messages' => $data['expected_messages'] ?? '-',
                    'whatsapp_numbers' => $data['whatsapp_numbers'] ?? '-',
                    'team_users' => $data['team_users'] ?? '-',
                    'admin_url' => $mailer->url('/'),
                ], ['requirement' => $requirement], null, $admin->name);
            }
        }
        $mailer->notifyUsers($admins, 'Custom plan request from '.$title, mb_substr($data['requirement'], 0, 250), 'billing', '/');

        return response()->json([
            'message' => 'Thank you! Our team will contact you within 24–48 hours. A confirmation has been sent to '.$data['email'].'.',
            'data' => ['id' => $req->id],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(CustomPlanRequest::STATUSES)]]);

        $page = CustomPlanRequest::query()->with(['account:id,company_name', 'handledBy:id,name'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE WHEN status = 'new' THEN 0 ELSE 1 END")->orderByDesc('id')
            ->paginate(20);

        return response()->json($page->toArray() + ['new_count' => CustomPlanRequest::query()->where('status', 'new')->count()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(CustomPlanRequest::STATUSES)], 'admin_note' => ['nullable', 'string', 'max:2000']]);
        $req = CustomPlanRequest::query()->findOrFail($id);
        $req->fill($data + ['handled_by_user_id' => $request->user()->id]);
        if ($data['status'] !== 'new' && $req->contacted_at === null) {
            $req->contacted_at = now();
        }
        $req->save();

        return response()->json(['message' => 'Request updated.', 'data' => $req->fresh(['account:id,company_name', 'handledBy:id,name'])]);
    }
}
