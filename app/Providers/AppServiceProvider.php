<?php

namespace App\Providers;

use App\Events\Mail\ConversationAssigned;
use App\Events\Mail\ConversationMoved;
use App\Events\Mail\ConversationStatusChanged;
use App\Events\Mail\ConversationTagged;
use App\Events\Mail\MessageIngested;
use App\Events\Projects\CycleCompleted;
use App\Events\Projects\CycleStarted;
use App\Events\Projects\DependencyResolved;
use App\Events\Projects\IssueAssigned;
use App\Events\Projects\IssueCommentPosted;
use App\Events\Projects\IssueCreated;
use App\Events\Projects\IssueStatusChanged;
use App\Events\Projects\TeamAddedToIssue;
use App\Events\Projects\WorkstreamStatusChanged;
use App\Listeners\Automations\RunAutomations;
use App\Listeners\Automations\RunIssueAutomations;
use App\Models\AuditLog;
use App\Models\Automations\AutomationRule;
use App\Models\Automations\AutomationRun;
use App\Models\Mail\Attachment;
use App\Models\Mail\AutoresponderSend;
use App\Models\Mail\Comment;
use App\Models\Mail\Contact;
use App\Models\Mail\Conversation;
use App\Models\Mail\ConversationEvent;
use App\Models\Mail\ConversationShare;
use App\Models\Mail\Draft;
use App\Models\Mail\DraftAttachment;
use App\Models\Mail\Inbox;
use App\Models\Mail\MailAccount;
use App\Models\Mail\MailFolder;
use App\Models\Mail\Message;
use App\Models\Mail\MessageTemplate;
use App\Models\Mail\SavedView;
use App\Models\Mail\SenderIdentity;
use App\Models\Mail\SyncRun;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Projects\Cycle;
use App\Models\Projects\EntityLink;
use App\Models\Projects\Initiative;
use App\Models\Projects\Issue;
use App\Models\Projects\IssueAttachment;
use App\Models\Projects\IssueComment;
use App\Models\Projects\IssueLabel;
use App\Models\Projects\IssueView;
use App\Models\Projects\IssueWorkstream;
use App\Models\Projects\Milestone;
use App\Models\Projects\Module;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\Projects\Release;
use App\Models\Projects\Workflow;
use App\Models\Projects\WorkflowStatus;
use App\Models\SsoIdentity;
use App\Models\Tag;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WebhookReceipt;
use App\Services\Projects\Access\IssueAccess;
use App\Services\Sso\Sso;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(IssueAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
        $this->configureTrustedProxies();
        $this->registerAutomationListeners();
        Sso::registerDrivers();
    }

    /**
     * Polymorphic columns (audit logs, notifications, push subscriptions) store these aliases
     * instead of class names, so classes can move between namespaces without touching data.
     * Every model that can be the target of a morph relation must be listed here.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap(self::morphMap());
    }

    /**
     * @return array<string, class-string<Model>>
     */
    public static function morphMap(): array
    {
        return [
            'organization' => Organization::class,
            'organization_invitation' => OrganizationInvitation::class,
            'membership' => Membership::class,
            'team' => Team::class,
            'team_member' => TeamMember::class,
            'user' => User::class,
            'sso_identity' => SsoIdentity::class,
            'tag' => Tag::class,
            'audit_log' => AuditLog::class,
            'webhook_receipt' => WebhookReceipt::class,
            'inbox' => Inbox::class,
            'mail_account' => MailAccount::class,
            'mail_folder' => MailFolder::class,
            'sender_identity' => SenderIdentity::class,
            'sync_run' => SyncRun::class,
            'conversation' => Conversation::class,
            'conversation_event' => ConversationEvent::class,
            'conversation_share' => ConversationShare::class,
            'message' => Message::class,
            'attachment' => Attachment::class,
            'draft' => Draft::class,
            'draft_attachment' => DraftAttachment::class,
            'comment' => Comment::class,
            'contact' => Contact::class,
            'saved_view' => SavedView::class,
            'message_template' => MessageTemplate::class,
            'automation_rule' => AutomationRule::class,
            'automation_run' => AutomationRun::class,
            'autoresponder_send' => AutoresponderSend::class,
            'workflow' => Workflow::class,
            'workflow_status' => WorkflowStatus::class,
            'product' => Product::class,
            'module' => Module::class,
            'project' => Project::class,
            'initiative' => Initiative::class,
            'milestone' => Milestone::class,
            'release' => Release::class,
            'cycle' => Cycle::class,
            'issue_label' => IssueLabel::class,
            'entity_link' => EntityLink::class,
            'issue' => Issue::class,
            'issue_workstream' => IssueWorkstream::class,
            'issue_comment' => IssueComment::class,
            'issue_attachment' => IssueAttachment::class,
            'issue_view' => IssueView::class,
        ];
    }

    /**
     * Behind a load balancer or Docker ingress the X-Forwarded-* headers decide the scheme and
     * client IP (OAuth redirects, webhook signatures, rate limits). Accepts "*" or a list of IPs/CIDRs.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('tandem.trusted_proxies');

        if (! is_string($proxies) || trim($proxies) === '') {
            return;
        }

        $proxies = trim($proxies);

        TrustProxies::at(in_array($proxies, ['*', '**'], true)
            ? $proxies
            : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    }

    /**
     * Domain events that feed the automation engine. Plain event classes are not discovered, so
     * the listener is registered explicitly.
     */
    protected function registerAutomationListeners(): void
    {
        Event::listen([
            MessageIngested::class,
            ConversationAssigned::class,
            ConversationMoved::class,
            ConversationStatusChanged::class,
            ConversationTagged::class,
        ], RunAutomations::class);

        Event::listen([
            IssueCreated::class,
            IssueAssigned::class,
            TeamAddedToIssue::class,
            WorkstreamStatusChanged::class,
            IssueStatusChanged::class,
            DependencyResolved::class,
            IssueCommentPosted::class,
            CycleStarted::class,
            CycleCompleted::class,
        ], RunIssueAutomations::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
