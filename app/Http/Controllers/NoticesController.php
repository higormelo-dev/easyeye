<?php

namespace App\Http\Controllers;

use App\Models\Notice;
use Illuminate\Http\{JsonResponse, Request};

class NoticesController extends Controller
{
    /**
     * Returns active notices for the current entity.
     * Also attaches read status for the current entity user.
     */
    public function index(): JsonResponse
    {
        $entityId     = session('selected_entity_id');
        $entityUserId = session('selected_entity_user_id');

        $notices = Notice::where('entity_id', $entityId)
            ->active()
            ->with(['author.user', 'readers' => fn ($q) => $q->where('entity_user_id', $entityUserId)])
            ->orderByDesc('pinned')
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return response()->json([
            'data' => $notices->map(fn ($n) => [
                'id'         => $n->id,
                'content'    => $n->content,
                'priority'   => $n->priority,
                'pinned'     => $n->pinned,
                'is_urgent'  => $n->isUrgent(),
                'is_read'    => $n->readers->isNotEmpty(),
                'author'     => $n->author?->user?->name ?? 'Sistema',
                'expires_at' => $n->expires_at?->format('d/m/Y'),
                'created_at' => $n->created_at->format('d/m/Y H:i'),
            ]),
            'unread_count' => $notices->filter(fn ($n) => $n->readers->isEmpty())->count(),
        ]);
    }

    /**
     * Creates a new notice.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'content'    => ['required', 'string', 'max:1000'],
            'priority'   => ['nullable', 'integer', 'in:0,1'],
            'pinned'     => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $entityId     = session('selected_entity_id');
        $entityUserId = session('selected_entity_user_id');

        $notice = Notice::create([
            'entity_id'  => $entityId,
            'author_id'  => $entityUserId,
            'content'    => $request->input('content'),
            'priority'   => $request->integer('priority', 0),
            'pinned'     => $request->boolean('pinned', false),
            'expires_at' => $request->input('expires_at'),
        ]);

        return response()->json([
            'message' => 'Recado publicado.',
            'data'    => ['id' => $notice->id],
        ], 201);
    }

    /**
     * Marks a notice as read by the current entity user.
     */
    public function markRead(Notice $notice): JsonResponse
    {
        abort_if($notice->entity_id !== session('selected_entity_id'), 403);

        $entityUserId = session('selected_entity_user_id');

        $notice->readers()->syncWithoutDetaching([
            $entityUserId => ['read_at' => now()],
        ]);

        return response()->json(['message' => 'Marcado como lido.']);
    }

    /**
     * Deletes a notice (only author or admin).
     */
    public function destroy(Notice $notice): JsonResponse
    {
        abort_if($notice->entity_id !== session('selected_entity_id'), 403);

        $notice->delete();

        return response()->json(['message' => 'Recado removido.']);
    }
}
