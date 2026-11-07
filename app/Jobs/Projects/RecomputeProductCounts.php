<?php

namespace App\Jobs\Projects;

use App\Models\Projects\Product;
use App\Services\Projects\ProjectProgress;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Debounces bursts of issue changes into one counter recompute per product, so the navigation
 * and the products index show live open-issue totals.
 */
class RecomputeProductCounts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 30;

    public function __construct(public int $productId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(ProjectProgress $progress): void
    {
        $product = Product::query()->find($this->productId);

        if ($product) {
            $progress->recomputeProduct($product);
        }
    }
}
