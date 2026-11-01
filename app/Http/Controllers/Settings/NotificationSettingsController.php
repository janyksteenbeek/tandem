<?php

namespace App\Http\Controllers\Settings;

use App\Enums\PushTopic;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Notifications', [
            'publicKey' => config('webpush.vapid.public_key'),
            'topics' => $user->pushPreferences(),
            'deviceCount' => $user->pushSubscriptions()->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $topics = array_map(fn (PushTopic $topic) => $topic->value, PushTopic::cases());

        $data = $request->validate([
            'topics' => ['required', 'array'],
            'topics.*' => ['boolean'],
        ]);

        $user = $request->user();
        $current = $user->pushPreferences();

        foreach ($topics as $topic) {
            if (array_key_exists($topic, $data['topics'])) {
                $current[$topic] = (bool) $data['topics'][$topic];
            }
        }

        $user->forceFill([
            'preferences' => array_merge($user->preferences ?? [], ['push' => $current]),
        ])->save();

        return back()->with('toast', ['type' => 'success', 'message' => 'Notification settings saved.']);
    }
}
