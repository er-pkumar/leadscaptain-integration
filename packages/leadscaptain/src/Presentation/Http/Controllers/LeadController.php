<?php

declare(strict_types=1);

namespace Leadscaptain\Presentation\Http\Controllers;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Leadscaptain\Application\UseCase\ListStoredLeads;
use Leadscaptain\Domain\Lead\Lead;
use Leadscaptain\Presentation\Http\Resources\LeadResource;

/**
 * GET {prefix}/leads?page=&per_page= : stored leads, oldest first.
 */
final class LeadController
{
    private const int DEFAULT_PER_PAGE = 20;

    public function index(Request $request, ListStoredLeads $listLeads, ValidationFactory $validation): JsonResponse
    {
        $validator = $validation->make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListStoredLeads::MAX_PER_PAGE],
        ]);

        // Always JSON: this is an API route, even when opened in a browser.
        if ($validator->fails()) {
            return new JsonResponse(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $perPage = $request->integer('per_page', self::DEFAULT_PER_PAGE);
        $page = $listLeads->execute($request->integer('page', 1), $perPage);

        $url = fn (int $number): string => $request->fullUrlWithQuery(['page' => $number]);

        return new JsonResponse([
            'data' => array_map(static fn (Lead $lead): array => (new LeadResource($lead))->toArray($request), $page->leads),
            'meta' => [
                'page' => $page->page,
                'per_page' => $page->perPage,
                'total' => $page->total,
                'last_page' => $page->lastPage(),
            ],
            'links' => [
                'self' => $url($page->page),
                'next' => $page->hasMorePages() ? $url($page->page + 1) : null,
                'prev' => $page->page > 1 ? $url($page->page - 1) : null,
            ],
        ]);
    }
}
