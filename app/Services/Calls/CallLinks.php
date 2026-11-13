<?php

namespace App\Services\Calls;

use App\Models\Calls\Call;
use App\Models\Calls\CallLink;
use App\Models\Organization;
use App\Models\Projects\Issue;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The light connection between calls and Projects: a call can be about an issue or a project.
 * Linking never changes who may see either side.
 */
class CallLinks
{
    /**
     * Find the issue or project a request points at, if the user may see it.
     */
    public function resolve(Organization $organization, User $user, ?string $type, ?string $key): ?Model
    {
        if (blank($type) || blank($key)) {
            return null;
        }

        $model = match ($type) {
            'issue' => Issue::query()->where('organization_id', $organization->id)->where('identifier', strtoupper((string) $key))->first(),
            'project' => Project::query()->where('organization_id', $organization->id)->where('slug', $key)->first(),
            default => null,
        };

        return $model !== null && $user->can('view', $model) ? $model : null;
    }

    public function link(Call $call, Model $linkable, User $by): CallLink
    {
        return $call->links()->firstOrCreate(
            ['linkable_type' => $linkable->getMorphClass(), 'linkable_id' => $linkable->getKey()],
            ['created_by_id' => $by->id],
        );
    }

    /**
     * The links of a call the user is allowed to see, ready for the interface.
     *
     * @return list<array{id: int, type: string, key: string, title: string, url: string}>
     */
    public function describe(Call $call, User $user): array
    {
        $slug = $call->organization->slug;

        return array_values($call->links()->with('linkable')->get()
            ->filter(fn (CallLink $link) => $link->linkable !== null && $user->can('view', $link->linkable))
            ->map(fn (CallLink $link) => match (true) {
                $link->linkable instanceof Issue => [
                    'id' => $link->id,
                    'type' => 'issue',
                    'key' => $link->linkable->identifier,
                    'title' => $link->linkable->title,
                    'url' => route('projects.issues.show', ['current_organization' => $slug, 'issue' => $link->linkable->identifier]),
                ],
                $link->linkable instanceof Project => [
                    'id' => $link->id,
                    'type' => 'project',
                    'key' => $link->linkable->slug,
                    'title' => $link->linkable->name,
                    'url' => route('projects.projects.show', ['current_organization' => $slug, 'project' => $link->linkable->slug]),
                ],
                default => null,
            })
            ->filter()
            ->all());
    }

    /**
     * Calls about an issue or project that this user may open.
     *
     * @return Collection<int, Call>
     */
    public function callsFor(Model $linkable, User $user, Organization $organization): Collection
    {
        return Call::query()
            ->visibleTo($user, $organization)
            ->whereHas('links', fn ($query) => $query->where('linkable_type', $linkable->getMorphClass())->where('linkable_id', $linkable->getKey()))
            ->with(['host', 'organization'])
            ->withCount(['recordings as playable_recordings_count' => fn ($query) => $query->whereIn('status', ['ready', 'partial'])])
            ->latest('id')
            ->limit(25)
            ->get();
    }
}
