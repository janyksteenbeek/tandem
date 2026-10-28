<?php

namespace App\Http\Controllers\Automations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automations\PreviewMessageTemplateRequest;
use App\Http\Requests\Automations\SaveMessageTemplateRequest;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Services\Automations\TemplateRenderer;
use App\Services\Mail\Ingestion\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MessageTemplateController extends Controller
{
    public function index(Request $request, Organization $currentOrganization): Response
    {
        $organization = $currentOrganization;

        return Inertia::render('settings/templates/Index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'templates' => $organization->messageTemplates()->with('inbox')->get()->map(fn (MessageTemplate $template) => $this->templateSummary($template))->values(),
            'inboxes' => $organization->inboxes()->get()->map(fn (Inbox $inbox) => ['uuid' => $inbox->uuid, 'name' => $inbox->name, 'color' => $inbox->color])->values(),
            'variables' => TemplateRenderer::catalog(),
        ]);
    }

    public function store(SaveMessageTemplateRequest $request, Organization $currentOrganization, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $organization = $currentOrganization;
        $payload = $request->templatePayload();
        $payload['body_html'] = $sanitizer->sanitize($payload['body_html']) ?? '';

        $organization->messageTemplates()->create($payload + ['created_by_id' => $request->user()->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template created.')]);

        return to_route('templates.index', ['current_organization' => $organization->slug]);
    }

    public function update(SaveMessageTemplateRequest $request, Organization $currentOrganization, MessageTemplate $template, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $organization = $currentOrganization;
        $this->ensureBelongs($template, $organization);

        $payload = $request->templatePayload();
        $payload['body_html'] = $sanitizer->sanitize($payload['body_html']) ?? '';

        $template->update($payload);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template updated.')]);

        return to_route('templates.index', ['current_organization' => $organization->slug]);
    }

    public function destroy(Request $request, Organization $currentOrganization, MessageTemplate $template): RedirectResponse
    {
        $organization = $currentOrganization;
        $this->ensureBelongs($template, $organization);

        $template->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template deleted.')]);

        return to_route('templates.index', ['current_organization' => $organization->slug]);
    }

    /**
     * Render a template body/subject against a real conversation (or the most recent one) so
     * editors can see variables resolved.
     */
    public function preview(PreviewMessageTemplateRequest $request, Organization $currentOrganization, TemplateRenderer $renderer, HtmlSanitizer $sanitizer): JsonResponse
    {
        $organization = $currentOrganization;

        $query = Conversation::query()->where('organization_id', $organization->id);

        $conversation = $request->validated('conversation_uuid')
            ? $query->where('uuid', $request->validated('conversation_uuid'))->firstOrFail()
            : $query->latest('last_activity_at')->first();

        if (! $conversation) {
            return response()->json([
                'conversation' => null,
                'subject' => $request->validated('subject'),
                'body_html' => $sanitizer->sanitize($request->validated('body_html')) ?? '',
                'variables' => [],
            ]);
        }

        return response()->json([
            'conversation' => ['uuid' => $conversation->uuid, 'subject' => $conversation->subject],
            'subject' => $request->validated('subject') ? $renderer->render($request->validated('subject'), $conversation) : null,
            'body_html' => $sanitizer->sanitize($renderer->render($request->validated('body_html'), $conversation)) ?? '',
            'variables' => $renderer->variables($conversation),
        ]);
    }

    protected function ensureBelongs(MessageTemplate $template, Organization $organization): void
    {
        abort_unless($template->organization_id === $organization->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateSummary(MessageTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'category' => $template->category,
            'subject' => $template->subject,
            'body_html' => $template->body_html,
            'inbox' => $template->inbox ? ['uuid' => $template->inbox->uuid, 'name' => $template->inbox->name] : null,
            'inbox_uuid' => $template->inbox?->uuid,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
