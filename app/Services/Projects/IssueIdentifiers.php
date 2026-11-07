<?php

namespace App\Services\Projects;

use App\Models\Organization;
use App\Models\Projects\IssueSequence;
use App\Models\Projects\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Hands out PREFIX-N identifiers. One counter row per scope (organization or product), locked
 * with SELECT ... FOR UPDATE inside the caller's transaction so two creators can never read the
 * same number and a failed insert rolls the counter back with it.
 */
class IssueIdentifiers
{
    /**
     * @return array{prefix: string, number: int, identifier: string}
     */
    public function next(Organization $organization, ?Product $product): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('IssueIdentifiers::next() must run inside the transaction that creates the issue.');
        }

        $scope = $product ? 'product:'.$product->id : 'org';
        $prefix = $product ? strtoupper($product->key) : $this->organizationKey($organization);
        $now = now();

        IssueSequence::query()->insertOrIgnore([
            'organization_id' => $organization->id,
            'scope_key' => $scope,
            'product_id' => $product?->id,
            'prefix' => $prefix,
            'next_number' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = IssueSequence::query()
            ->where('organization_id', $organization->id)
            ->where('scope_key', $scope)
            ->lockForUpdate()
            ->firstOrFail();

        $number = $sequence->next_number;
        $sequence->forceFill(['next_number' => $number + 1, 'prefix' => $prefix])->save();

        return ['prefix' => $prefix, 'number' => $number, 'identifier' => $prefix.'-'.$number];
    }

    /**
     * The organization's own prefix, used for issues outside any product.
     */
    public function organizationKey(Organization $organization): string
    {
        if (filled($organization->issue_key)) {
            return strtoupper((string) $organization->issue_key);
        }

        return static::deriveKey($organization->name);
    }

    /**
     * "Northwind Labs" → NL, "Tandem" → TAND.
     */
    public static function deriveKey(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $key = count($words) >= 2
            ? implode('', array_map(fn (string $w) => Str::substr($w, 0, 1), array_slice($words, 0, 4)))
            : Str::substr($words[0] ?? 'ORG', 0, 4);

        $key = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $key) ?? 'ORG');

        if (strlen($key) < 2) {
            $key = str_pad($key, 2, 'X');
        }

        if (! preg_match('/^[A-Z]/', $key)) {
            $key = 'X'.$key;
        }

        return Str::substr($key, 0, 10);
    }

    /**
     * @return array{prefix: string, number: int}|null
     */
    public function parse(string $identifier): ?array
    {
        if (! preg_match('/^([A-Za-z][A-Za-z0-9]{1,9})-(\d+)$/', trim($identifier), $m)) {
            return null;
        }

        return ['prefix' => strtoupper($m[1]), 'number' => (int) $m[2]];
    }

    /**
     * Product keys and the organization key share one identifier namespace.
     */
    public function isProductKeyAvailable(Organization $organization, string $key, ?Product $except = null): bool
    {
        $key = strtoupper($key);

        if ($key === $this->organizationKey($organization)) {
            return false;
        }

        return ! Product::query()->withTrashed()
            ->where('organization_id', $organization->id)
            ->where('key', $key)
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();
    }

    public function isOrganizationKeyAvailable(Organization $organization, string $key): bool
    {
        return ! Product::query()->withTrashed()
            ->where('organization_id', $organization->id)
            ->where('key', strtoupper($key))
            ->exists();
    }
}
