<?php

namespace Database\Seeders;

use App\Enums\AutomationTrigger;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\InboxRole;
use App\Enums\InboxType;
use App\Enums\MailAccountStatus;
use App\Enums\MailProvider;
use App\Enums\OrganizationRole;
use App\Enums\TeamRole;
use App\Events\Broadcast\ActivityAdded;
use App\Events\Broadcast\CommentAdded;
use App\Events\Broadcast\ConversationUpdated;
use App\Events\Broadcast\MessageAdded;
use App\Events\Broadcast\MessageDeliveryUpdated;
use App\Events\Broadcast\UserNotified;
use App\Events\Conversations\CommentPosted;
use App\Events\Conversations\ConversationAssigned;
use App\Events\Conversations\ConversationMoved;
use App\Events\Conversations\ConversationShared;
use App\Events\Conversations\ConversationStatusChanged;
use App\Events\Conversations\ConversationTagged;
use App\Events\Mail\MessageIngested;
use App\Events\Mail\MessageSent;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\Conversations\CommentService;
use App\Services\Conversations\ConversationActions;
use App\Services\Mail\Data\MailboxState;
use App\Services\Mail\Ingestion\MessageIngestor;
use App\Services\Mail\Ingestion\MimeMessageParser;
use App\Services\Mail\Providers\Forwarding\ForwardingAddressGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * A believable workspace so the app can be explored without connecting a provider.
 * Login: demo@tandem.test / password
 */
class DemoSeeder extends Seeder
{
    public function __construct(
        protected MimeMessageParser $parser,
        protected MessageIngestor $ingestor,
        protected ConversationActions $actions,
        protected CommentService $comments,
        protected ForwardingAddressGenerator $forwarding,
    ) {}

    public function run(): void
    {
        Event::fake([
            MessageIngested::class,
            MessageSent::class,
            ConversationAssigned::class,
            ConversationStatusChanged::class,
            ConversationTagged::class,
            ConversationShared::class,
            ConversationMoved::class,
            CommentPosted::class,
            ConversationUpdated::class,
            ActivityAdded::class,
            CommentAdded::class,
            MessageAdded::class,
            UserNotified::class,
            MessageDeliveryUpdated::class,
        ]);
        Notification::fake();
        Queue::fake();

        $owner = User::factory()->create(['name' => 'Jamie Okafor', 'email' => 'demo@tandem.test', 'password' => 'password', 'email_verified_at' => now()]);
        $sam = User::factory()->create(['name' => 'Sam Rivera', 'email' => 'sam@northwind.test', 'password' => 'password', 'email_verified_at' => now()]);
        $priya = User::factory()->create(['name' => 'Priya Natarajan', 'email' => 'priya@northwind.test', 'password' => 'password', 'email_verified_at' => now()]);
        $lars = User::factory()->create(['name' => 'Lars Vermeulen', 'email' => 'lars@northwind.test', 'password' => 'password', 'email_verified_at' => now()]);

        $organization = Organization::create(['name' => 'Northwind Labs', 'timezone' => 'Europe/Amsterdam', 'color' => 'plum']);
        $organization->memberships()->create(['user_id' => $owner->id, 'role' => OrganizationRole::Owner]);
        $organization->memberships()->create(['user_id' => $sam->id, 'role' => OrganizationRole::Admin]);
        $organization->memberships()->create(['user_id' => $priya->id, 'role' => OrganizationRole::Member]);
        $organization->memberships()->create(['user_id' => $lars->id, 'role' => OrganizationRole::Member]);

        foreach ([$owner, $sam, $priya, $lars] as $user) {
            $personal = Organization::create(['name' => $user->name."'s Organization", 'is_personal' => true]);
            $personal->memberships()->create(['user_id' => $user->id, 'role' => OrganizationRole::Owner]);
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        }

        $organization->business_hours = [
            'timezone' => 'Europe/Amsterdam',
            'days' => [
                'mon' => [['start' => '09:00', 'end' => '17:30']],
                'tue' => [['start' => '09:00', 'end' => '17:30']],
                'wed' => [['start' => '09:00', 'end' => '17:30']],
                'thu' => [['start' => '09:00', 'end' => '17:30']],
                'fri' => [['start' => '09:00', 'end' => '16:00']],
                'sat' => [],
                'sun' => [],
            ],
            'holidays' => [],
        ];
        $organization->save();

        $support = Team::create(['organization_id' => $organization->id, 'name' => 'Support', 'color' => 'teal', 'description' => 'Keeps customers happy.']);
        $sales = Team::create(['organization_id' => $organization->id, 'name' => 'Sales', 'color' => 'amber', 'description' => 'Finds the next ones.']);
        $finance = Team::create(['organization_id' => $organization->id, 'name' => 'Finance', 'color' => 'emerald', 'description' => 'Counts the beans.']);

        $support->members()->attach([$owner->id => ['role' => TeamRole::Lead->value], $priya->id => ['role' => TeamRole::Member->value], $sam->id => ['role' => TeamRole::Member->value]]);
        $sales->members()->attach([$sam->id => ['role' => TeamRole::Lead->value], $lars->id => ['role' => TeamRole::Member->value]]);
        $finance->members()->attach([$lars->id => ['role' => TeamRole::Lead->value], $owner->id => ['role' => TeamRole::Member->value]]);

        $supportInbox = $this->sharedInbox($organization, 'Support', 'support@northwind.test', 'teal', [$support], $owner);
        $salesInbox = $this->sharedInbox($organization, 'Sales', 'sales@northwind.test', 'amber', [$sales], $owner);
        $billingInbox = $this->sharedInbox($organization, 'Billing', 'billing@northwind.test', 'emerald', [$finance], $owner);
        $billingInbox->users()->attach($priya->id, ['role' => InboxRole::Viewer->value]);

        $tags = collect([
            ['name' => 'Bug', 'color' => 'rose'],
            ['name' => 'Feature request', 'color' => 'violet'],
            ['name' => 'VIP', 'color' => 'amber'],
            ['name' => 'Invoice', 'color' => 'emerald'],
            ['name' => 'Renewal', 'color' => 'sky'],
            ['name' => 'Onboarding', 'color' => 'teal'],
        ])->mapWithKeys(fn (array $tag) => [$tag['name'] => Tag::create([...$tag, 'organization_id' => $organization->id])]);

        $personalInbox = Inbox::create([
            'user_id' => $owner->id,
            'type' => InboxType::Personal,
            'name' => 'jamie.okafor@gmail.com',
            'email_address' => 'jamie.okafor@gmail.com',
            'color' => 'plum',
            'icon' => 'mail',
        ]);

        $personalAccount = MailAccount::create([
            'user_id' => $owner->id,
            'inbox_id' => $personalInbox->id,
            'provider' => MailProvider::Gmail,
            'email_address' => 'jamie.okafor@gmail.com',
            'display_name' => 'Jamie Okafor',
            'credentials' => ['access_token' => 'demo', 'refresh_token' => 'demo', 'expires_at' => now()->addHour()->timestamp, 'oauth_organization_id' => $organization->id],
            'settings' => ['demo' => true],
            'sync_state' => ['history_id' => '424242'],
            'status' => MailAccountStatus::Connected,
            'last_synced_at' => now()->subMinutes(3),
            'initial_sync_completed_at' => now()->subDay(),
            'watch_expires_at' => now()->addDays(6),
        ]);
        $personalAccount->identities()->create(['name' => 'Jamie Okafor', 'email' => 'jamie.okafor@gmail.com', 'is_default' => true, 'signature_html' => '<p>— Jamie<br><span style="color:#888">Northwind Labs · Head of Customer Experience</span></p>']);

        Tag::create(['user_id' => $owner->id, 'name' => 'Travel', 'color' => 'sky']);
        Tag::create(['user_id' => $owner->id, 'name' => 'Receipts', 'color' => 'lime']);

        $this->seedSupportConversations($supportInbox, $owner, $sam, $priya, $tags);
        $this->seedSalesConversations($salesInbox, $sam, $lars, $tags);
        $this->seedBillingConversations($billingInbox, $lars, $tags);
        $this->seedPersonalConversations($personalAccount, $owner);

        $this->seedAutomations($organization, $supportInbox, $billingInbox, $finance, $tags, $owner);
    }

    /**
     * @param  array<int, Team>  $teams
     */
    protected function sharedInbox(Organization $organization, string $name, string $address, string $color, array $teams, User $creator): Inbox
    {
        $inbox = Inbox::create([
            'organization_id' => $organization->id,
            'type' => InboxType::Shared,
            'name' => $name,
            'email_address' => $address,
            'color' => $color,
            'description' => "Shared inbox for {$address}",
        ]);

        foreach ($teams as $team) {
            $inbox->teams()->attach($team->id, ['role' => InboxRole::Agent->value]);
        }

        $inbox->users()->attach($creator->id, ['role' => InboxRole::Admin->value]);

        $this->forwarding->generate($inbox, $creator, "{$name} forwarding");

        return $inbox;
    }

    /**
     * @param  Collection<string, Tag>  $tags
     */
    protected function seedSupportConversations(Inbox $inbox, User $owner, User $sam, User $priya, $tags): void
    {
        $account = $inbox->mailAccounts()->first();

        $c1 = $this->thread($account, [
            ['from' => ['Ada Lovelace', 'ada@analytical.engines'], 'subject' => 'Export to CSV silently drops the last row', 'minutes' => 55, 'body' => "Hi team,\n\nWhenever I export a report with more than 1,000 rows the final row goes missing. Reproduced three times on Chrome and Safari.\n\nSteps:\n1. Reports → Monthly usage\n2. Export → CSV\n3. Open in Numbers\n\nThe row with the totals is gone. Pretty sure this started after the Tuesday release.\n\nThanks,\nAda"],
        ]);
        $this->actions->addTag($c1, $tags['Bug'], $sam);
        $this->actions->setPriority($c1, ConversationPriority::High, $sam);
        $this->actions->assign($c1, $priya, $sam);
        $this->comments->post($c1, $sam, '<p>I can reproduce this. Looks like an off-by-one in the paginator — <strong>Priya</strong>, can you take a look before the standup?</p>', [$priya->id]);
        $this->comments->post($c1, $priya, '<p>On it. Fix is in review, will reply to Ada once it ships.</p>');

        $c2 = $this->thread($account, [
            ['from' => ['Grace Hopper', 'grace@cobol.navy'], 'subject' => 'Can we add SSO for our whole fleet?', 'minutes' => 60 * 5, 'body' => "Hello,\n\nWe are rolling Tandem out to 340 people and would love SAML SSO through Okta. Is this on the roadmap? Happy to be a design partner.\n\nGrace"],
            ['from' => ['Support', 'support@northwind.test'], 'subject' => 'Re: Can we add SSO for our whole fleet?', 'minutes' => 60 * 4, 'body' => "Hi Grace,\n\nThanks for the kind words! SSO is planned for Q1. I have added you to the design-partner list and will reach out as soon as we have a build to try.\n\nBest,\nSam", 'outbound' => true],
            ['from' => ['Grace Hopper', 'grace@cobol.navy'], 'subject' => 'Re: Can we add SSO for our whole fleet?', 'minutes' => 60 * 2, 'body' => "Perfect, thank you Sam. Looking forward to it.\n\nGrace"],
        ]);
        $this->actions->addTag($c2, $tags['Feature request'], $sam);
        $this->actions->addTag($c2, $tags['VIP'], $sam);
        $this->actions->assign($c2, $sam, $owner);
        $this->actions->setStatus($c2, ConversationStatus::Pending, $sam);

        $c3 = $this->thread($account, [
            ['from' => ['Linus Torvalds', 'linus@kernel.test'], 'subject' => 'Password reset email never arrives', 'minutes' => 60 * 26, 'body' => "I've requested a password reset four times. Nothing in spam either. Domain is kernel.test.\n\nLinus"],
            ['from' => ['Support', 'support@northwind.test'], 'subject' => 'Re: Password reset email never arrives', 'minutes' => 60 * 25, 'body' => "Hi Linus,\n\nSorry about that. Your domain had a bounce from last month that put the address on hold. I have cleared it and sent a fresh reset link. Can you check?\n\nPriya", 'outbound' => true],
            ['from' => ['Linus Torvalds', 'linus@kernel.test'], 'subject' => 'Re: Password reset email never arrives', 'minutes' => 60 * 24, 'body' => 'Got it, thanks. Works now.'],
        ]);
        $this->actions->assign($c3, $priya, $priya);
        $this->actions->setStatus($c3, ConversationStatus::Closed, $priya);

        $c4 = $this->thread($account, [
            ['from' => ['Margaret Hamilton', 'margaret@apollo.test'], 'subject' => 'Onboarding call recap + next steps', 'minutes' => 60 * 30, 'body' => "Thanks for the walkthrough yesterday. Attached are our team's inbox requirements. Two questions:\n\n- Can rules move a conversation between inboxes?\n- How do internal notes work with external replies?\n\nMargaret", 'attachment' => ['requirements.txt', "1. Shared inbox for ops@\n2. Rules for VIP routing\n3. Weekly digest"]],
        ]);
        $this->actions->addTag($c4, $tags['Onboarding'], $owner);
        $this->actions->snooze($c4, CarbonImmutable::now()->addDay()->setTime(9, 0), $owner);

        $c5 = $this->thread($account, [
            ['from' => ['Dennis Ritchie', 'dennis@bell.test'], 'subject' => 'Love the new dark mode', 'minutes' => 60 * 50, 'body' => "Just wanted to say the dark mode is excellent. No action needed — carry on.\n\nDennis"],
        ]);
        $this->actions->setStatus($c5, ConversationStatus::Closed, $owner);

        $this->thread($account, [
            ['from' => ['Katherine Johnson', 'katherine@nasa.test'], 'subject' => 'Wrong timezone on scheduled sends', 'minutes' => 12, 'body' => "Scheduled sends go out an hour early for our London team since the clocks changed. Could you check the DST handling?\n\nKatherine"],
        ]);

        $this->thread($account, [
            ['from' => ['Tim Berners-Lee', 'tim@w3.test'], 'subject' => 'Invoice attached twice?', 'minutes' => 60 * 8, 'body' => 'The last invoice email had the PDF attached twice. Not a big deal, just flagging it.'],
        ]);
    }

    /**
     * @param  Collection<string, Tag>  $tags
     */
    protected function seedSalesConversations(Inbox $inbox, User $sam, User $lars, $tags): void
    {
        $account = $inbox->mailAccounts()->first();

        $c1 = $this->thread($account, [
            ['from' => ['Radia Perlman', 'radia@spanning.tree'], 'subject' => 'Pricing for 120 seats', 'minutes' => 60 * 3, 'body' => "Hi,\n\nWe are comparing Tandem with two other tools for our 120-person support org. Could you send pricing for annual billing, and do you offer a pilot?\n\nRadia"],
            ['from' => ['Sales', 'sales@northwind.test'], 'subject' => 'Re: Pricing for 120 seats', 'minutes' => 60 * 2, 'body' => "Hi Radia,\n\nAbsolutely. I have attached our team pricing and we would be glad to run a 30-day pilot with your team. When would be a good time for a 20-minute call?\n\nSam", 'outbound' => true],
        ]);
        $this->actions->assign($c1, $sam, $sam);
        $this->actions->addTag($c1, $tags['VIP'], $sam);
        $this->actions->setPriority($c1, ConversationPriority::Urgent, $sam);
        $this->comments->post($c1, $lars, '<p>Heads-up: they are also talking to our Finance team about invoicing terms. I will keep you posted.</p>');

        $c2 = $this->thread($account, [
            ['from' => ['Alan Turing', 'alan@bletchley.test'], 'subject' => 'Renewal coming up in November', 'minutes' => 60 * 20, 'body' => "Our annual plan renews next month. Can we talk about moving to the Teams tier? We would need the Cloudflare forwarding feature for three distribution lists.\n\nAlan"],
        ]);
        $this->actions->addTag($c2, $tags['Renewal'], $sam);

        $this->thread($account, [
            ['from' => ['Barbara Liskov', 'barbara@mit.test'], 'subject' => 'Demo request', 'minutes' => 35, 'body' => 'Could someone show us how shared drafts work? Tuesday or Wednesday afternoon works for us.'],
        ]);
    }

    /**
     * @param  Collection<string, Tag>  $tags
     */
    protected function seedBillingConversations(Inbox $inbox, User $lars, $tags): void
    {
        $account = $inbox->mailAccounts()->first();

        $c1 = $this->thread($account, [
            ['from' => ['Accounts Payable', 'ap@globex.test'], 'subject' => 'Invoice NW-2026-0912 — PO number missing', 'minutes' => 60 * 6, 'body' => "Hello,\n\nWe cannot process invoice NW-2026-0912 because it does not reference our PO number (PO-55012). Please reissue.\n\nRegards,\nGlobex AP"],
        ]);
        $this->actions->addTag($c1, $tags['Invoice'], $lars);
        $this->actions->assign($c1, $lars, $lars);

        $this->thread($account, [
            ['from' => ['Stripe', 'receipts@stripe.test'], 'subject' => 'Your receipt from Northwind Labs #2043-1188', 'minutes' => 60 * 11, 'body' => 'Receipt for EUR 1,240.00 paid on 27 Oct 2026. Thank you for your business.', 'html' => '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;border:1px solid #eee;border-radius:12px;overflow:hidden"><div style="background:#5B4EFF;color:#fff;padding:24px;font-size:20px;font-weight:bold">Payment received</div><div style="padding:24px;color:#333"><p style="margin:0 0 12px">Receipt for <strong>EUR 1,240.00</strong> paid on 27 Oct 2026.</p><table style="width:100%;border-collapse:collapse;font-size:14px"><tr><td style="padding:8px 0;border-bottom:1px solid #eee">Tandem Teams · 40 seats</td><td style="text-align:right;padding:8px 0;border-bottom:1px solid #eee">EUR 1,240.00</td></tr><tr><td style="padding:8px 0;font-weight:bold">Total</td><td style="text-align:right;padding:8px 0;font-weight:bold">EUR 1,240.00</td></tr></table><p style="margin:16px 0 0;color:#888;font-size:12px">Thank you for your business.</p></div></div>'],
        ]);
    }

    protected function seedPersonalConversations(MailAccount $account, User $owner): void
    {
        $this->thread($account, [
            ['from' => ['Mum', 'mum@okafor.family'], 'subject' => 'Sunday lunch?', 'minutes' => 25, 'body' => "Are you coming on Sunday? Your dad is making the jollof. Bring the good olive oil.\n\nxx"],
        ], state: MailboxState::inbox());

        $this->thread($account, [
            ['from' => ['KLM', 'noreply@klm.test'], 'subject' => 'Your boarding pass for AMS → LIS', 'minutes' => 60 * 3, 'body' => 'Your boarding pass is ready. Flight KL1693 departs 07:05 from gate D71. Seat 14A.', 'attachment' => ['boarding-pass.txt', 'KL1693 AMS-LIS 07:05 Seat 14A']],
        ], state: MailboxState::inbox());

        $c3 = $this->thread($account, [
            ['from' => ['Ravi (Dentist)', 'frontdesk@brightsmile.test'], 'subject' => 'Appointment reminder — Thursday 14:30', 'minutes' => 60 * 9, 'body' => 'Friendly reminder of your cleaning on Thursday at 14:30. Reply YES to confirm.'],
            ['from' => ['Jamie Okafor', 'jamie.okafor@gmail.com'], 'subject' => 'Re: Appointment reminder — Thursday 14:30', 'minutes' => 60 * 8, 'body' => 'YES', 'outbound' => true],
        ], state: new MailboxState(inInbox: true, isRead: true));

        $this->thread($account, [
            ['from' => ['Hacker News Digest', 'digest@hn.test'], 'subject' => 'Top stories this week', 'minutes' => 60 * 30, 'body' => "1. Show HN: a calmer email client\n2. Why your inbox is a to-do list you did not ask for\n3. The case for internal notes"],
        ], state: new MailboxState(inInbox: false, isArchived: true, isRead: true));

        $c5 = $this->thread($account, [
            ['from' => ['Sofia from Bikeshop', 'sofia@bikeshop.test'], 'subject' => 'Your bike is ready for pickup', 'minutes' => 60 * 48, 'body' => 'Hi Jamie, the brakes are done and the chain is new. We are open until 18:00.'],
        ], state: new MailboxState(inInbox: true, isRead: true, isStarred: true));
        $this->actions->setStarred($c5, true, $owner);

        $this->thread($account, [
            ['from' => ['Totally Legit Prince', 'prince@definitely-real.test'], 'subject' => 'URGENT: inheritance of 4,000,000 USD', 'minutes' => 60 * 70, 'body' => 'Dear friend, I am contacting you regarding a confidential matter...'],
        ], state: new MailboxState(inInbox: false, isSpam: true, isRead: true));
    }

    /**
     * @param  Collection<string, Tag>  $tags
     */
    protected function seedAutomations(Organization $organization, Inbox $support, Inbox $billing, Team $finance, $tags, User $owner): void
    {
        $template = $organization->messageTemplates()->create([
            'inbox_id' => $support->id,
            'created_by_id' => $owner->id,
            'name' => 'Out of office',
            'category' => 'autoresponder',
            'subject' => 'Re: {{ conversation.subject }}',
            'body_html' => '<p>Hi {{ contact.first_name }},</p><p>Thanks for reaching out to {{ organization.name }}. We are away right now and will get back to you on {{ next_business_day }}.</p><p>— The {{ inbox.name }} team</p>',
        ]);

        $organization->automationRules()->create([
            'inbox_id' => $support->id,
            'created_by_id' => $owner->id,
            'name' => 'After-hours auto-reply',
            'description' => 'Let customers know when we will be back.',
            'trigger' => AutomationTrigger::ConversationReceived,
            'match' => 'all',
            'conditions' => [['field' => 'business_hours', 'operator' => 'is_false', 'value' => null]],
            'actions' => [['type' => 'reply_with_template', 'params' => ['template_id' => $template->id, 'cooldown_minutes' => 1440]]],
            'is_active' => true,
            'position' => 1,
            'cooldown_minutes' => 1440,
        ]);

        $organization->automationRules()->create([
            'inbox_id' => $support->id,
            'created_by_id' => $owner->id,
            'name' => 'Route invoices to Billing',
            'description' => 'Anything that smells like an invoice goes to Finance.',
            'trigger' => AutomationTrigger::ConversationReceived,
            'match' => 'any',
            'conditions' => [
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice'],
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'receipt'],
            ],
            'actions' => [
                ['type' => 'move_to_inbox', 'params' => ['inbox_uuid' => $billing->uuid]],
                ['type' => 'add_tag', 'params' => ['tag_id' => $tags['Invoice']->id]],
                ['type' => 'assign_team', 'params' => ['team_id' => $finance->id, 'strategy' => 'round_robin']],
            ],
            'is_active' => true,
            'position' => 2,
        ]);

        $organization->automationRules()->create([
            'created_by_id' => $owner->id,
            'name' => 'VIP domains get priority',
            'trigger' => AutomationTrigger::ConversationReceived,
            'match' => 'any',
            'conditions' => [
                ['field' => 'sender', 'operator' => 'ends_with', 'value' => '@cobol.navy'],
                ['field' => 'sender', 'operator' => 'ends_with', 'value' => '@spanning.tree'],
            ],
            'actions' => [
                ['type' => 'add_tag', 'params' => ['tag_id' => $tags['VIP']->id]],
                ['type' => 'set_priority', 'params' => ['priority' => 'high']],
            ],
            'is_active' => true,
            'position' => 3,
        ]);
    }

    /**
     * Build a thread by pushing hand-written MIME through the real ingestion pipeline.
     *
     * @param  array<int, array{from: array{0: string, 1: string}, subject: string, minutes: int, body: string, outbound?: bool, html?: string, attachment?: array{0: string, 1: string}}>  $messages
     */
    protected function thread(MailAccount $account, array $messages, ?MailboxState $state = null): Conversation
    {
        $conversation = null;
        $previousId = null;
        $references = [];

        foreach ($messages as $index => $message) {
            $id = Str::uuid().'@demo.tandem';
            $outbound = $message['outbound'] ?? false;
            $to = $outbound ? ($messages[0]['from'][1]) : $account->email_address;
            $sentAt = CarbonImmutable::now()->subMinutes($message['minutes']);
            $boundary = 'b-'.Str::random(12);

            $headers = implode("\r\n", array_filter([
                "From: \"{$message['from'][0]}\" <{$message['from'][1]}>",
                "To: {$to}",
                "Subject: {$message['subject']}",
                'Date: '.$sentAt->toRfc2822String(),
                "Message-ID: <{$id}>",
                $previousId ? "In-Reply-To: <{$previousId}>" : null,
                $references ? 'References: '.implode(' ', array_map(fn ($r) => "<{$r}>", $references)) : null,
                'MIME-Version: 1.0',
            ]));

            $html = $message['html'] ?? '<div>'.nl2br(e($message['body'])).'</div>';

            $parts = "--{$boundary}\r\nContent-Type: multipart/alternative; boundary=\"alt-{$boundary}\"\r\n\r\n"
                ."--alt-{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$message['body']}\r\n"
                ."--alt-{$boundary}\r\nContent-Type: text/html; charset=utf-8\r\n\r\n{$html}\r\n"
                ."--alt-{$boundary}--\r\n";

            if (isset($message['attachment'])) {
                [$filename, $content] = $message['attachment'];
                $parts .= "--{$boundary}\r\nContent-Type: text/plain; name=\"{$filename}\"\r\nContent-Disposition: attachment; filename=\"{$filename}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($content))."\r\n";
            }

            $raw = "{$headers}\r\nContent-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n{$parts}--{$boundary}--\r\n";

            $messageState = $state ?? MailboxState::inbox(read: $outbound || $index < count($messages) - 1);

            $incoming = $this->parser->parse($raw, [
                'providerMessageId' => 'demo-'.Str::random(14),
                'providerThreadId' => $conversation->provider_thread_id ?? 'thread-'.Str::random(10),
                'state' => $outbound ? new MailboxState(inInbox: false, isSent: true, isRead: true) : $messageState,
                'labels' => $outbound ? ['SENT'] : ['INBOX'],
                'keepRaw' => false,
            ]);

            $result = $this->ingestor->ingest($account, $incoming);
            $conversation = $result->conversation;
            $references[] = $id;
            $previousId = $id;
        }

        return $conversation->fresh();
    }
}
