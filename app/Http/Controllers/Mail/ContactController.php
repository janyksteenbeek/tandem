<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Recipient autocomplete over the user's personal contacts and the current organization's contacts.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);

        $user = $request->user();
        $organization = $user->currentOrganization;
        $term = strtolower(trim($data['q']));

        $contacts = Contact::query()
            ->where(function ($q) use ($user, $organization) {
                $q->where(fn ($p) => $p->whereNull('organization_id')->where('user_id', $user->id));

                if ($organization) {
                    $q->orWhere('organization_id', $organization->id);
                }
            })
            ->where(fn ($q) => $q->where('email', 'like', $term.'%')->orWhere('email', 'like', '%.'.$term.'%')->orWhere('name', 'like', '%'.$term.'%'))
            ->orderByDesc('message_count')
            ->limit(8)
            ->get()
            ->unique('email')
            ->map(fn (Contact $contact) => ['name' => $contact->name, 'email' => $contact->email])
            ->values();

        return response()->json(['contacts' => $contacts]);
    }
}
