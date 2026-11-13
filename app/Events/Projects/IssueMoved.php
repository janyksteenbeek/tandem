<?php

namespace App\Events\Projects;

use App\Models\Projects\Issue;
use App\Models\Projects\Product;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IssueMoved
{
    use Dispatchable, SerializesModels;

    public function __construct(public Issue $issue, public ?Product $fromProduct, public ?Product $toProduct, public ?Project $fromProject, public ?Project $toProject, public ?User $actor = null) {}
}
