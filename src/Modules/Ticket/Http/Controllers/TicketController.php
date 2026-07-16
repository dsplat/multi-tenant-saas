<?php

namespace MultiTenantSaas\Modules\Ticket\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ticket\Services\TicketService;

class TicketController extends Controller
{
    use ApiResponse, AuthorizesTenantAccess;

    public function __construct(
        protected TicketService $ticketService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $tickets = $this->ticketService->list(
            $request->query('status'),
            (int) $request->query('per_page', 15),
        );

        return $this->paginatedResponse($tickets);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'priority' => 'sometimes|string|in:low,medium,high,urgent',
            'category' => 'nullable|string|max:50',
        ]);

        $ticket = $this->ticketService->create($validated);

        return $this->createdResponse($ticket);
    }

    public function show(Request $request, int $ticketId): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $ticket = $this->ticketService->find($ticketId);
        if (! $ticket) {
            return $this->notFoundResponse('Ticket not found');
        }

        return $this->successResponse($ticket);
    }

    public function update(Request $request, int $ticketId): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $validated = $request->validate([
            'subject' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:5000',
            'priority' => 'sometimes|string|in:low,medium,high,urgent',
            'status' => 'sometimes|string|in:open,in_progress,resolved,closed',
            'category' => 'nullable|string|max:50',
        ]);

        $ticket = $this->ticketService->update($ticketId, $validated);

        return $this->successResponse($ticket);
    }

    public function destroy(Request $request, int $ticketId): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $this->ticketService->delete($ticketId);

        return $this->deletedResponse();
    }

    public function assign(Request $request, int $ticketId): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $validated = $request->validate([
            'user_id' => 'required|integer',
        ]);

        $ticket = $this->ticketService->assign($ticketId, $validated['user_id']);

        return $this->successResponse($ticket);
    }

    public function resolve(Request $request, int $ticketId): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $ticket = $this->ticketService->resolve($ticketId);

        return $this->successResponse($ticket);
    }

    public function comments(Request $request, int $ticketId): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $comments = $this->ticketService->getComments($ticketId);

        return $this->successResponse($comments);
    }

    public function addComment(Request $request, int $ticketId): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
        ]);

        $comment = $this->ticketService->addComment($ticketId, $validated['content']);

        return $this->createdResponse($comment);
    }
}
