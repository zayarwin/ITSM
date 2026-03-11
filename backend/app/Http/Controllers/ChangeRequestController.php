<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ChangeRequest;
use Illuminate\Support\Facades\Storage;

class ChangeRequestController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $changeRequests = ChangeRequest::with(['requester', 'implementer', 'reviewer', 'approver', 'devices'])->orderBy('created_at', 'desc')->get();
        return response()->json($changeRequests);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'purpose' => 'required|string|max:255',
            'task' => 'required|string',
            'implementer_id' => 'required|exists:users,id',
            'reviewer_id' => 'required|exists:users,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after_or_equal:start_time',
            'device_ids' => 'required|array',
            'device_ids.*' => 'exists:devices,id',
            'attachment' => 'nullable|file|mimes:xlsx,doc,docx,txt,pdf,csv,xml|max:10240', // max 10MB
        ]);

        $latestCRQ = ChangeRequest::where('crq_number', 'like', 'CRQ%')->orderBy('id', 'desc')->first();
        $nextNumber = 1;

        if ($latestCRQ && preg_match('/^CRQ(\d{8})$/', $latestCRQ->crq_number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        } else {
            // Fallback if the regex fails (e.g. if there were old format CRQs)
            $maxId = \Illuminate\Support\Facades\DB::table('change_requests')->max('id') ?? 0;
            $nextNumber = $maxId + 1;
        }

        $crqNumber = 'CRQ' . str_pad($nextNumber, 8, '0', STR_PAD_LEFT);

        $changeRequest = new ChangeRequest([
            'crq_number' => $crqNumber,
            'purpose' => $validated['purpose'],
            'task' => $validated['task'],
            'requester_id' => $request->user()->id,
            'implementer_id' => $validated['implementer_id'],
            'reviewer_id' => $validated['reviewer_id'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'status' => 'pending'
        ]);

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store('attachments', 'public');
            $changeRequest->attachment_path = $path;
            $changeRequest->attachment_name = $file->getClientOriginalName();
        }

        $changeRequest->save();

        if (isset($validated['device_ids']) && count($validated['device_ids']) > 0) {
            $changeRequest->devices()->attach($validated['device_ids']);
        }

        $changeRequest->load(['requester', 'implementer', 'reviewer', 'devices']);

        return response()->json($changeRequest, 201);
    }

    /**
     * Update the approval status of the specified resource.
     */
    public function updateStatus(Request $request, string $id)
    {
        $changeRequest = ChangeRequest::findOrFail($id);
        
        // Ensure user is an admin or manager
        if (!in_array($request->user()->role, ['admin', 'manager'])) {
            return response()->json(['message' => 'Unauthorized. Only admins or managers can approve or reject.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
            'approval_comments' => 'nullable|string|max:1000',
        ]);

        $changeRequest->status = $validated['status'];
        $changeRequest->approval_comments = $validated['approval_comments'] ?? null;
        $changeRequest->approver_id = $request->user()->id;
        $changeRequest->save();

        $changeRequest->load(['approver']);

        return response()->json($changeRequest);
    }

    /**
     * Download the attachment for the specified change request.
     */
    public function downloadAttachment(string $id)
    {
        $changeRequest = ChangeRequest::findOrFail($id);

        if (!$changeRequest->attachment_path || !Storage::disk('public')->exists($changeRequest->attachment_path)) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        return Storage::disk('public')->download($changeRequest->attachment_path, $changeRequest->attachment_name);
    }
}
