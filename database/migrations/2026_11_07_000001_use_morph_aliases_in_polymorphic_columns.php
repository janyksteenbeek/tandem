<?php

use App\Providers\AppServiceProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Polymorphic columns used to store class names. Classes have since moved into domain
 * namespaces, and the application now stores short morph aliases instead, so existing rows are
 * rewritten from both the old and the current class names to their alias.
 */
return new class extends Migration
{
    /** @var array<string, string> column => table */
    private array $columns = [
        'notifications' => 'notifiable_type',
        'audit_logs' => 'auditable_type',
        'push_subscriptions' => 'subscribable_type',
    ];

    public function up(): void
    {
        foreach ($this->columns as $table => $column) {
            foreach ($this->aliases() as $class => $alias) {
                DB::table($table)->where($column, $class)->update([$column => $alias]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $table => $column) {
            foreach (AppServiceProvider::morphMap() as $alias => $class) {
                DB::table($table)->where($column, $alias)->update([$column => $class]);
            }
        }
    }

    /**
     * Current and pre-move class names mapped to their alias.
     *
     * @return array<string, string>
     */
    private function aliases(): array
    {
        $map = [];

        foreach (AppServiceProvider::morphMap() as $alias => $class) {
            $map[$class] = $alias;
            $map['App\\Models\\'.class_basename($class)] = $alias;
        }

        return $map;
    }
};
