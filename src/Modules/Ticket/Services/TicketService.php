<?php

namespace MultiTenantSaas\Modules\Ticket\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use MultiTenantSaas\Modules\Ticket\Models\Ticket;
use MultiTenantSaas\Modules\Ticket\Models\TicketComment;

class TicketService
{
    public function list(?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = Ticket::query()->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function find(int $ticketId): ?Ticket
    {
        return Ticket::where('ticket_id', $ticketId)->first();
    }

    public function create(array $data): Ticket
    {
        return Ticket::create([
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'category' => $data['category'] ?? null,
            'created_by' => auth()->id(),
            'status' => 'open',
        ]);
    }

    public function update(int $ticketId, array $data): Ticket
    {
        $ticket = $this->find($ticketId);
        abort_if(! $ticket, 404);

        $ticket->update($data);

        return $ticket->fresh();
    }

    public function delete(int $ticketId): bool
    {
        $ticket = $this->find($ticketId);
        if (! $ticket) {
            return false;
        }

        return (bool) $ticket->delete();
    }

    public function assign(int $ticketId, int $userId): Ticket
    {
        $ticket = $this->find($ticketId);
        abort_if(! $ticket, 404);

        $ticket->update(['assigned_to' => $userId, 'status' => 'in_progress']);

        return $ticket->fresh();
    }

    public function resolve(int $ticketId): Ticket
    {
        $ticket = $this->find($ticketId);
        abort_if(! $ticket, 404);

        $ticket->update(['status' => 'resolved']);

        return $ticket->fresh();
    }

    public function addComment(int $ticketId, string $content): TicketComment
    {
        return TicketComment::create([
            'ticket_id' => $ticketId,
            'user_id' => auth()->id(),
            'content' => $content,
        ]);
    }

    public function getComments(int $ticketId): Collection
    {
        return TicketComment::where('ticket_id', $ticketId)
            ->orderBy('created_at')
            ->get();
    }
}
