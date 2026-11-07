<?php

namespace App\Services\Projects;

use App\Enums\Projects\ProductMemberRole;
use App\Enums\Projects\ProductStatus;
use App\Models\Organization;
use App\Models\Projects\Module;
use App\Models\Projects\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductActions
{
    public function __construct(protected AuditLogger $audit, protected IssueIdentifiers $identifiers) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Organization $organization, array $attributes, User $actor): Product
    {
        if (! $this->identifiers->isProductKeyAvailable($organization, $attributes['key'])) {
            throw ValidationException::withMessages(['key' => 'That key is already used in this organization.']);
        }

        $product = DB::transaction(function () use ($organization, $attributes, $actor) {
            $product = Product::query()->create([
                'organization_id' => $organization->id,
                'name' => $attributes['name'],
                'key' => strtoupper($attributes['key']),
                'description' => $attributes['description'] ?? null,
                'icon' => $attributes['icon'] ?? null,
                'color' => $attributes['color'] ?? 'violet',
                'owner_id' => $attributes['owner_id'] ?? $actor->id,
                'status' => ProductStatus::Active,
            ]);

            $product->teams()->sync($this->teamIds($organization, $attributes['team_ids'] ?? []));

            foreach ($this->memberIds($organization, $attributes['member_ids'] ?? []) as $userId) {
                $product->members()->attach($userId, ['role' => ProductMemberRole::Member->value]);
            }

            return $product;
        });

        $this->audit->record('product.created', $organization, $actor, $product, ['key' => $product->key]);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes, User $actor): Product
    {
        $organization = $product->organization;

        if (isset($attributes['key']) && strtoupper($attributes['key']) !== $product->key) {
            if (! $this->identifiers->isProductKeyAvailable($organization, $attributes['key'], $product)) {
                throw ValidationException::withMessages(['key' => 'That key is already used in this organization.']);
            }

            $this->audit->record('product.key_changed', $organization, $actor, $product, ['from' => $product->key, 'to' => strtoupper($attributes['key'])]);
        }

        DB::transaction(function () use ($product, $attributes, $organization) {
            $product->fill(array_intersect_key($attributes, array_flip(['name', 'key', 'description', 'icon', 'color', 'owner_id', 'status'])));

            if (isset($attributes['key'])) {
                $product->key = strtoupper($attributes['key']);
            }

            $product->save();

            if (array_key_exists('team_ids', $attributes) && $attributes['team_ids'] !== null) {
                $product->teams()->sync($this->teamIds($organization, $attributes['team_ids']));
            }
        });

        $this->audit->record('product.updated', $organization, $actor, $product);

        return $product->refresh();
    }

    /**
     * @param  array<int, array{user_id: int, role?: string}>  $members
     */
    public function syncMembers(Product $product, array $members, User $actor): Product
    {
        $sync = [];

        foreach ($members as $member) {
            if (in_array((int) $member['user_id'], $this->memberIds($product->organization, [(int) $member['user_id']]), true)) {
                $sync[(int) $member['user_id']] = ['role' => (ProductMemberRole::tryFrom($member['role'] ?? 'member') ?? ProductMemberRole::Member)->value];
            }
        }

        $product->members()->sync($sync);
        $this->audit->record('product.members_updated', $product->organization, $actor, $product, ['count' => count($sync)]);

        return $product->refresh();
    }

    public function archive(Product $product, User $actor): Product
    {
        $product->forceFill(['status' => ProductStatus::Archived])->save();
        $this->audit->record('product.archived', $product->organization, $actor, $product);

        return $product;
    }

    public function delete(Product $product, User $actor): void
    {
        $product->delete();
        $this->audit->record('product.deleted', $product->organization, $actor, $product, ['key' => $product->key]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createModule(Product $product, array $attributes, User $actor): Module
    {
        $parent = isset($attributes['parent_id']) ? $product->modules()->whereKey($attributes['parent_id'])->first() : null;

        $module = DB::transaction(function () use ($product, $attributes, $parent) {
            $module = $product->modules()->create([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'icon' => $attributes['icon'] ?? null,
                'color' => $attributes['color'] ?? null,
                'parent_id' => $parent?->id,
                'owner_id' => $attributes['owner_id'] ?? null,
                'position' => (int) $product->modules()->where('parent_id', $parent?->id)->max('position') + 1,
            ]);

            $module->teams()->sync($this->teamIds($product->organization, $attributes['team_ids'] ?? []));

            return $module;
        });

        $this->audit->record('module.created', $product->organization, $actor, $module, ['product_id' => $product->id]);

        return $module;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateModule(Module $module, array $attributes, User $actor): Module
    {
        if (array_key_exists('parent_id', $attributes) && $attributes['parent_id'] !== null) {
            $parent = $module->product->modules()->whereKey($attributes['parent_id'])->first();

            if (! $parent || $parent->id === $module->id || $this->isDescendant($module, $parent)) {
                throw ValidationException::withMessages(['parent_id' => 'A module cannot be nested under itself.']);
            }
        }

        DB::transaction(function () use ($module, $attributes) {
            $module->fill(array_intersect_key($attributes, array_flip(['name', 'description', 'icon', 'color', 'parent_id', 'owner_id'])))->save();

            if (array_key_exists('team_ids', $attributes) && $attributes['team_ids'] !== null) {
                $module->teams()->sync($this->teamIds($module->product->organization, $attributes['team_ids']));
            }
        });

        $this->audit->record('module.updated', $module->product->organization, $actor, $module);

        return $module->refresh();
    }

    public function deleteModule(Module $module, User $actor): void
    {
        DB::transaction(function () use ($module) {
            Module::query()->where('parent_id', $module->id)->update(['parent_id' => $module->parent_id]);
            $module->delete();
        });

        $this->audit->record('module.deleted', $module->product->organization, $actor, $module);
    }

    /**
     * @param  array<int, int>  $orderedIds
     */
    public function reorderModules(Product $product, array $orderedIds, User $actor): void
    {
        DB::transaction(function () use ($product, $orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                $product->modules()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    protected function isDescendant(Module $module, Module $candidate): bool
    {
        $cursor = $candidate;

        while ($cursor->parent_id) {
            if ($cursor->parent_id === $module->id) {
                return true;
            }

            $cursor = $cursor->parent;
        }

        return false;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    protected function teamIds(Organization $organization, array $ids): array
    {
        return $organization->teams()->whereIn('teams.id', $ids)->pluck('teams.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    protected function memberIds(Organization $organization, array $ids): array
    {
        return $organization->members()->whereIn('users.id', $ids)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }
}
