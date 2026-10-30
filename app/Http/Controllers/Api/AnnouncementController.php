<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admission dates and deadlines (task 2.2). The Admission Head writes and
 * publishes them; applicants see only published ones.
 * Body: { title, body, event_date?: "YYYY-MM-DD", published?: bool }
 */
class AnnouncementController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** Office: every announcement, drafts included. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Announcement::latest('id')->get()->map->toApi()->all()]);
    }

    /** Applicant portal: published only, nearest date first. */
    public function published(): JsonResponse
    {
        $items = Announcement::whereNotNull('published_at')->orderByRaw('event_date is null')->orderBy('event_date')->latest('published_at')->get();

        return response()->json(['data' => $items->map->toApi()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $announcement = Announcement::create($data + ['created_by' => $request->user()->id]);
        $this->audit->record('announcement.created', "Created the announcement \"{$announcement->title}\"".($announcement->published_at ? ' (published)' : ''), $announcement);

        return response()->json(['data' => $announcement->toApi()], 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $announcement->update($this->validated($request, false, $announcement));
        $this->audit->record('announcement.updated', "Updated the announcement \"{$announcement->title}\"".($announcement->published_at ? ' (published)' : ' (draft)'), $announcement);

        return response()->json(['data' => $announcement->toApi()]);
    }

    public function destroy(Announcement $announcement): Response
    {
        $this->audit->record('announcement.deleted', "Deleted the announcement \"{$announcement->title}\"", $announcement);
        $announcement->delete();

        return response()->noContent();
    }

    private function validated(Request $request, bool $creating, ?Announcement $current = null): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'title' => [$required, 'string', 'max:150'],
            'body' => [$required, 'string', 'max:5000'],
            'event_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'published' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('published', $data)) {
            // Keep the first publish time when an already published one is saved again
            $data['published_at'] = $data['published'] ? ($current?->published_at ?? now()) : null;
            unset($data['published']);
        }

        return $data;
    }
}
