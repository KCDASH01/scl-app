<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\Controller;
use App\Http\Requests\Note\NoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    private const MORPH_MAP = [
        'lead' => \App\Models\Lead::class,
        'deal' => \App\Models\Deal::class,
    ];

    // ── GET /api/notes?notable_type=lead&notable_id=5 ─────────────────────────

    public function index(Request $request): JsonResponse
    {
        $notableType = $request->input('notable_type');
        $notableId   = $request->input('notable_id');

        if (! $notableType || ! $notableId) {
            return response()->json(['data' => []]);
        }

        $morphClass = self::MORPH_MAP[$notableType] ?? null;
        if (! $morphClass) {
            return response()->json(['data' => []]);
        }

        $parent = $morphClass::query()->find($notableId);
        if (! $parent) {
            return response()->json(['data' => []]);
        }

        if (isset($parent->branch_id)) {
            $this->branchScope()->assertRecordInScope(
                $request->user(),
                $parent->branch_id,
                $parent->assigned_to ?? null
            );
        }

        $notes = Note::query()
            ->with('createdBy')
            ->where('notable_type', $morphClass)
            ->where('notable_id', $notableId)
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => NoteResource::collection($notes)]);
    }

    // ── POST /api/notes ───────────────────────────────────────────────────────

    public function store(NoteRequest $request): JsonResponse
    {
        $validated  = $request->validated();
        $morphClass = self::MORPH_MAP[$validated['notable_type']];
        $parent = $morphClass::query()->find($validated['notable_id']);
        if (! $parent) {
            return response()->json(['message' => 'Related record not found.'], 422);
        }
        if (isset($parent->branch_id)) {
            $this->branchScope()->assertRecordInScope(
                $request->user(),
                $parent->branch_id,
                $parent->assigned_to ?? null
            );
        }

        $note = Note::create([
            'created_by'      => $request->user()->id,
            'notable_type'    => $morphClass,
            'notable_id'      => $validated['notable_id'],
            'content'         => $validated['content'],
            'is_pinned'       => $validated['is_pinned'] ?? false,
        ]);

        return response()->json([
            'data'    => new NoteResource($note->load('createdBy')),
            'message' => 'Note created successfully.',
        ], 201);
    }

    // ── PUT /api/notes/{note} ─────────────────────────────────────────────────

    public function update(Request $request, Note $note): JsonResponse
    {
        $this->assertNoteParent($request, $note);

        $data = $request->validate([
            'content'   => ['sometimes', 'string'],
            'is_pinned' => ['boolean'],
        ]);

        $note->update($data);

        return response()->json([
            'data'    => new NoteResource($note->fresh('createdBy')),
            'message' => 'Note updated successfully.',
        ]);
    }

    // ── DELETE /api/notes/{note} ──────────────────────────────────────────────

    public function destroy(Request $request, Note $note): JsonResponse
    {
        $this->assertNoteParent($request, $note);

        $note->delete();

        return response()->json(['message' => 'Note deleted successfully.']);
    }

    private function assertNoteParent(Request $request, Note $note): void
    {
        $parent = $note->notable;
        if ($parent && isset($parent->branch_id)) {
            $this->branchScope()->assertRecordInScope(
                $request->user(),
                $parent->branch_id,
                $parent->assigned_to ?? null
            );
        }
    }
}
