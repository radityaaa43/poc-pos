<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\CreditApprovalRequest;
use App\Services\CreditApprovalService;
use Illuminate\Http\Request;

/** The approver's answer to a credit exception; the queue itself is on the discount approval page. Web only. */
class CreditApprovalController extends Controller
{
    public function __construct(private readonly CreditApprovalService $creditApprovals) {}

    public function approve(Request $request, CreditApprovalRequest $creditApprovalRequest)
    {
        $this->creditApprovals->approve($creditApprovalRequest, $this->reason($request), $request->user());

        return back()->with('success', __('messages.credit.approved'));
    }

    public function deny(Request $request, CreditApprovalRequest $creditApprovalRequest)
    {
        $this->creditApprovals->deny($creditApprovalRequest, $this->reason($request), $request->user());

        return back()->with('success', __('messages.credit.denied'));
    }

    private function reason(Request $request): string
    {
        // Own messages: the app ships no Indonesian validation lines.
        $required = __('messages.credit.reason_required');

        return $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:500']],
            ['reason.required' => $required, 'reason.min' => $required],
        )['reason'];
    }
}
