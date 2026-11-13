<?php

namespace Database\Factories\Projects;

use App\Models\Projects\Issue;
use App\Models\Projects\IssueComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueComment>
 */
class IssueCommentFactory extends Factory
{
    public function definition(): array
    {
        $text = fake()->sentence();

        return [
            'issue_id' => Issue::factory(),
            'user_id' => User::factory(),
            'body_html' => '<p>'.$text.'</p>',
            'body_text' => $text,
            'mentions' => [],
        ];
    }
}
