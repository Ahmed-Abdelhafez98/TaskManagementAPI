<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TaskDependency extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'task_id',
        'depends_on_task_id',
    ];

    /**
     * The task that has the dependency
     */
    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    /**
     * The task that is depended upon
     */
    public function dependsOnTask()
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id');
    }

    /**
     * Check if adding this dependency would create a circular dependency
     */
    public static function wouldCreateCircularDependency($taskId, $dependsOnTaskId): bool
    {
        // If the task we want to depend on already depends on us (directly or indirectly),
        // it would create a circular dependency
        return self::hasPath($dependsOnTaskId, $taskId);
    }

    /**
     * Check if there's a dependency path from one task to another
     */
    private static function hasPath($fromTaskId, $toTaskId, $visited = []): bool
    {
        if ($fromTaskId == $toTaskId) {
            return true;
        }

        if (in_array($fromTaskId, $visited)) {
            return false; // Prevent infinite recursion
        }

        $visited[] = $fromTaskId;

        $dependencies = self::where('task_id', $fromTaskId)->pluck('depends_on_task_id');

        foreach ($dependencies as $dependencyId) {
            if (self::hasPath($dependencyId, $toTaskId, $visited)) {
                return true;
            }
        }

        return false;
    }
}
