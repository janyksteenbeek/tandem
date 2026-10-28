<?php

namespace App\Enums;

enum TeamRole: string
{
    case Lead = 'lead';
    case Member = 'member';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
