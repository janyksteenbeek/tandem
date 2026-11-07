<?php

namespace App\Events\Projects;

use App\Models\Projects\Cycle;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CycleStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Cycle $cycle) {}
}
