<?php

namespace App\Http\Controllers\Calls;

use App\Enums\Calls\CallStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Calls\CallFileResource;
use App\Http\Resources\Calls\CallMessageResource;
use App\Http\Resources\Calls\CallRecordingResource;
use App\Http\Resources\Calls\CallResource;
use App\Models\Calls\Call;
use App\Models\Calls\CallParticipant;
use App\Models\Calls\CallRecording;
use App\Models\Calls\CallRecordingExport;
use App\Models\Calls\CallTranscriptDelivery;
use App\Models\Organization;
use App\Services\Calls\CallActions;
use App\Services\Calls\CallLinks;
use App\Services\Calls\CallRecordings;
use App\Services\Calls\CallTranscripts;
use App\Services\Calls\RecordingExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calls inside the organization: starting one, and the history of those that happened.
 */
class CallController extends Controller
{
    public function __construct(protected CallActions $actions, protected CallLinks $links) {}

    public function index(Request $request, Organization $currentOrganization): Response
    {
        $this->authorize('viewAny', [Call::class, $currentOrganization]);

        $user = $request->user();
        $tab = $request->string('tab')->toString() === 'recordings' ? 'recordings' : 'meetings';

        $base = fn () => Call::query()
            ->visibleTo($user, $currentOrganization)
            ->with(['host', 'organization'])
            ->withCount([
                'participants as attendee_count' => fn ($query) => $query->whereNotNull('joined_at'),
                'recordings as playable_recordings_count' => fn ($query) => $query->whereIn('status', ['ready', 'partial']),
            ]);

        return Inertia::render('calls/Index', [
            'tab' => $tab,
            // Running meetings, plus the ones this person created and has not started yet
            // (a link they shared for later). Other people's unstarted links are not news.
            'open' => fn () => $base()
                ->where(fn ($query) => $query
                    ->where('status', CallStatus::Live->value)
                    ->orWhere(fn ($pending) => $pending
                        ->where('status', CallStatus::Pending->value)
                        ->where('host_id', $user->id)
                        ->where('created_at', '>', now()->subDay())))
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (Call $call) => $this->summary($call)),
            'recent' => fn () => $base()
                ->where('status', CallStatus::Ended->value)
                ->latest('ended_at')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Call $call) => $this->summary($call)),
            'recordings' => fn () => $tab !== 'recordings' ? null : CallRecording::query()
                ->whereIn('call_id', Call::query()->visibleTo($user, $currentOrganization)->select('id'))
                ->whereNotIn('status', ['starting'])
                ->with(['call.organization', 'startedBy'])
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (CallRecording $recording) => [
                    ...CallRecordingResource::make($recording)->resolve(),
                    'call' => ['uuid' => $recording->call->uuid, 'title' => $recording->call->displayTitle()],
                ]),
            'settings' => [
                'defaults' => [...Call::DEFAULT_SETTINGS, ...array_intersect_key((array) ($currentOrganization->call_settings['defaults'] ?? []), Call::DEFAULT_SETTINGS)],
                'recording_retention_days' => app(CallRecordings::class)->retentionDays($currentOrganization),
            ],
            'can' => ['manage' => $user->can('manage', $currentOrganization)],
        ]);
    }

    /**
     * Start an instant meeting and go straight into it.
     */
    public function store(Request $request, Organization $currentOrganization): RedirectResponse|JsonResponse
    {
        $this->authorize('create', [Call::class, $currentOrganization]);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'password' => ['nullable', 'string', 'min:4', 'max:200'],
            'waiting_room' => ['sometimes', 'boolean'],
            'auto_email_transcript' => ['sometimes', 'boolean'],
            'link_type' => ['nullable', Rule::in(['issue', 'project'])],
            'link_key' => ['nullable', 'string', 'max:120'],
        ]);

        $linkable = $this->links->resolve($currentOrganization, $request->user(), $data['link_type'] ?? null, $data['link_key'] ?? null);

        abort_if(filled($data['link_type'] ?? null) && $linkable === null, 404);

        $title = $data['title'] ?? match (true) {
            $linkable === null => null,
            default => (string) ($linkable->getAttribute('identifier') ? $linkable->getAttribute('identifier').' · '.$linkable->getAttribute('title') : $linkable->getAttribute('name')),
        };

        $call = $this->actions->start($currentOrganization, $request->user(), $title, $data, $data['password'] ?? null, $linkable);

        if ($request->expectsJson()) {
            return response()->json(['call' => CallResource::make($call->load(['organization', 'host']))->resolve()], 201);
        }

        return to_route('meet.show', ['call' => $call->code]);
    }

    /**
     * Find a meeting by the code or link someone pasted.
     */
    public function lookup(Request $request, Organization $currentOrganization): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:300']]);

        preg_match('/([a-z]{3}-[a-z]{4}-[a-z]{3})/', strtolower($data['code']), $match);

        $call = isset($match[1]) ? Call::query()->where('code', $match[1])->first() : null;

        abort_if($call === null, 404, 'No meeting was found for that code.');

        return response()->json(['url' => route('meet.show', ['call' => $call->code])]);
    }

    public function show(Request $request, Organization $currentOrganization, Call $call, CallTranscripts $transcripts): Response
    {
        $this->authorize('view', $call);

        $user = $request->user();
        $call->load(['organization', 'host']);

        return Inertia::render('calls/Show', [
            'call' => CallResource::make($call)->resolve(),
            'participants' => $call->participants()->with('user')->whereNotNull('admitted_at')->orderBy('id')->get()->map(fn (CallParticipant $participant) => [
                'uuid' => $participant->uuid,
                'name' => $participant->name,
                'email' => $participant->email,
                'initials' => $participant->initials(),
                'avatar' => $participant->avatarUrl(),
                'role' => $participant->role->value,
                'status' => $participant->status->value,
                'attended' => $participant->joined_at !== null,
                'joined_at' => $participant->joined_at?->toISOString(),
                'left_at' => $participant->left_at?->toISOString(),
            ]),
            'messages' => CallMessageResource::collection(
                $call->messages()->withTrashed()->with(['participant.user', 'reactions.participant', 'files.call', 'parent.participant'])->orderBy('id')->limit(2000)->get()
            )->resolve(),
            'files' => CallFileResource::collection($call->files()->whereNotNull('message_id')->with('call')->latest('id')->get())->resolve(),
            'recordings' => $call->recordings()->with(['startedBy', 'exports'])->latest('id')->get()->map(fn (CallRecording $recording) => [
                ...CallRecordingResource::make($recording)->resolve(),
                'has_share_link' => $recording->isShareLinkValid(),
                'share_expires_at' => $recording->share_expires_at?->toISOString(),
                'exports' => $recording->exports->map(fn (CallRecordingExport $export) => $this->export($export))->values(),
            ]),
            'deliveries' => $call->transcriptDeliveries()->with('sentBy')->latest('id')->limit(100)->get()->map(fn (CallTranscriptDelivery $delivery) => [
                'uuid' => $delivery->uuid,
                'email' => $delivery->email,
                'name' => $delivery->name,
                'status' => $delivery->status->value,
                'error' => $delivery->error,
                'is_automatic' => $delivery->is_automatic,
                'sent_by' => $delivery->sentBy?->name,
                'sent_at' => $delivery->sent_at?->toISOString(),
                'created_at' => $delivery->created_at?->toISOString(),
            ]),
            'links' => $this->links->describe($call, $user),
            'suggestedRecipients' => $transcripts->attendeeRecipients($call),
            'exportsAvailable' => Cache::remember('calls.ffmpeg-available', 600, fn () => app(RecordingExporter::class)->isAvailable()),
            'can' => [
                'update' => $user->can('update', $call),
                'delete' => $user->can('delete', $call),
                'share' => $user->can('share', $call),
                'deleteRecordings' => $user->can('deleteRecordings', $call),
            ],
        ]);
    }

    public function update(Request $request, Organization $currentOrganization, Call $call): RedirectResponse
    {
        $this->authorize('update', $call);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'auto_email_transcript' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('title', $data)) {
            $this->actions->rename($call, $data['title']);
        }

        if (array_key_exists('auto_email_transcript', $data)) {
            $call->forceFill(['settings' => [...($call->settings ?? []), 'auto_email_transcript' => (bool) $data['auto_email_transcript']]])->save();
        }

        return back();
    }

    /**
     * Delete a call with its chat, files and recordings.
     */
    public function destroy(Request $request, Organization $currentOrganization, Call $call, CallRecordings $recordings): RedirectResponse
    {
        $this->authorize('delete', $call);

        $this->actions->end($call);

        $call->recordings()->get()->each(fn (CallRecording $recording) => $recordings->delete($recording->setRelation('call', $call), $request->user()));
        $call->files()->get()->each->delete();
        $call->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Meeting deleted.']);

        return to_route('calls.index', ['current_organization' => $currentOrganization->slug]);
    }

    /**
     * Calls linked to one issue or project, for the Projects pages.
     */
    public function linked(Request $request, Organization $currentOrganization): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['issue', 'project'])],
            'key' => ['required', 'string', 'max:120'],
        ]);

        $linkable = $this->links->resolve($currentOrganization, $request->user(), $data['type'], $data['key']);

        abort_if($linkable === null, 404);

        return response()->json([
            'calls' => $this->links->callsFor($linkable, $request->user(), $currentOrganization)->map(fn (Call $call) => $this->summary($call))->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Call $call): array
    {
        return [
            ...CallResource::make($call)->resolve(),
            'attendee_count' => (int) ($call->getAttribute('attendee_count') ?? 0),
            'recording_count' => (int) ($call->getAttribute('playable_recordings_count') ?? 0),
            'url' => route('calls.show', ['current_organization' => $call->organization->slug, 'call' => $call->uuid]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function export(CallRecordingExport $export): array
    {
        return [
            'uuid' => $export->uuid,
            'kind' => $export->kind->value,
            'label' => $export->kind->label(),
            'status' => $export->status->value,
            'size_bytes' => $export->size_bytes,
            'error' => $export->error,
            'created_at' => $export->created_at?->toISOString(),
        ];
    }
}
