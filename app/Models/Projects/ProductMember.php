<?php

namespace App\Models\Projects;

use App\Enums\Projects\ProductMemberRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $product_id
 * @property int $user_id
 * @property ProductMemberRole $role
 */
#[Fillable(['product_id', 'user_id', 'role'])]
class ProductMember extends Pivot
{
    protected $table = 'product_members';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['role' => ProductMemberRole::class];
    }
}
