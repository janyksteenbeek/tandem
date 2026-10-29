<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organization-level provider configuration: Google OAuth client, Pub/Sub, outbound SMTP, business hours.
 */
class OrganizationIntegrationController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function edit(Request $request, Organization $organization): Response
    {
        $this->authorize('manage', $organization);

        $smtp = $organization->outbound_mail_settings ?? [];

        return Inertia::render('settings/organizations/Integrations', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'timezone' => $organization->timezone,
                'google_oauth_client_id' => $organization->google_oauth_client_id,
                'google_oauth_client_secret_set' => filled($organization->google_oauth_client_secret),
                'google_oauth_client_secret_hint' => $organization->google_oauth_client_secret ? '••••••••'.Str::substr($organization->google_oauth_client_secret, -4) : null,
                'google_pubsub_topic' => $organization->google_pubsub_topic,
                'business_hours' => $organization->business_hours,
                'outbound' => [
                    'host' => $smtp['host'] ?? null,
                    'port' => $smtp['port'] ?? 587,
                    'encryption' => $smtp['encryption'] ?? 'tls',
                    'username' => $smtp['username'] ?? null,
                    'password_set' => filled($smtp['password'] ?? null),
                    'from_name' => $smtp['from_name'] ?? null,
                ],
            ],
            'google' => [
                'redirect_uri' => route('mail.gmail.callback'),
                'push_endpoint' => route('webhooks.gmail.push', ['token' => config('tandem.gmail.pubsub_token')]),
                'scopes' => config('tandem.gmail.scopes'),
                'env_fallback' => filled(config('services.google.client_id')),
            ],
            'forwardingDomain' => config('tandem.forwarding.domain'),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('manage', $organization);

        $data = $request->validate([
            'timezone' => ['required', 'timezone:all'],
            'google_oauth_client_id' => ['nullable', 'string', 'max:255'],
            'google_oauth_client_secret' => ['nullable', 'string', 'max:255'],
            'clear_google_secret' => ['boolean'],
            'google_pubsub_topic' => ['nullable', 'string', 'max:255', 'regex:/^projects\/[^\/]+\/topics\/[^\/]+$/'],
            'business_hours' => ['nullable', 'array'],
            'business_hours.timezone' => ['nullable', 'timezone:all'],
            'business_hours.days' => ['nullable', 'array'],
            'business_hours.days.*' => ['array'],
            'business_hours.days.*.*.start' => ['required', 'date_format:H:i'],
            'business_hours.days.*.*.end' => ['required', 'date_format:H:i'],
            'business_hours.holidays' => ['nullable', 'array'],
            'business_hours.holidays.*' => ['date_format:Y-m-d'],
            'outbound' => ['nullable', 'array'],
            'outbound.host' => ['nullable', 'string', 'max:255'],
            'outbound.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'outbound.encryption' => ['nullable', 'in:tls,ssl,none'],
            'outbound.username' => ['nullable', 'string', 'max:255'],
            'outbound.password' => ['nullable', 'string', 'max:255'],
            'outbound.from_name' => ['nullable', 'string', 'max:120'],
            'clear_outbound' => ['boolean'],
        ]);

        $organization->timezone = $data['timezone'];
        $organization->google_oauth_client_id = $data['google_oauth_client_id'] ?? null;
        $organization->google_pubsub_topic = $data['google_pubsub_topic'] ?? null;
        $organization->business_hours = $data['business_hours'] ?? null;

        if ($data['clear_google_secret'] ?? false) {
            $organization->google_oauth_client_secret = null;
        } elseif (filled($data['google_oauth_client_secret'] ?? null)) {
            $organization->google_oauth_client_secret = $data['google_oauth_client_secret'];
        }

        if ($data['clear_outbound'] ?? false) {
            $organization->outbound_mail_settings = null;
        } elseif (filled($data['outbound']['host'] ?? null)) {
            $existing = $organization->outbound_mail_settings ?? [];
            $organization->outbound_mail_settings = [
                'host' => $data['outbound']['host'],
                'port' => (int) ($data['outbound']['port'] ?? 587),
                'encryption' => $data['outbound']['encryption'] ?? 'tls',
                'username' => $data['outbound']['username'] ?? null,
                'password' => filled($data['outbound']['password'] ?? null) ? $data['outbound']['password'] : ($existing['password'] ?? null),
                'from_name' => $data['outbound']['from_name'] ?? null,
            ];
        }

        $organization->save();
        $this->audit->record('organization.integrations_updated', $organization, $request->user(), $organization, [
            'google_client_configured' => $organization->hasGoogleOAuthCredentials(),
            'pubsub_topic' => $organization->google_pubsub_topic,
            'business_hours' => $organization->business_hours !== null,
            'outbound_smtp' => $organization->outbound_mail_settings !== null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Integration settings saved.')]);

        return back();
    }
}
