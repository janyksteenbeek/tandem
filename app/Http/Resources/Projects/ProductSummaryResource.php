<?php

namespace App\Http\Resources\Projects;

use App\Models\Projects\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'key' => $this->key,
            'color' => $this->color,
            'icon' => $this->icon,
            'status' => $this->status->value,
            'issues_count' => $this->issues_count,
            'open_issues_count' => $this->open_issues_count,
        ];
    }
}
