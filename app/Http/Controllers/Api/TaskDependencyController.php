<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskDependencyController extends Controller
{
    /**
     * Add a dependency to a task
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Only managers can manage task dependencies
        if (!$user->isManager()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only managers can manage task dependencies.'
            ], 403);
        }

        $request->validate([
            'task_id' => 'required|exists:tasks,id',
            'depends_on_task_id' => 'required|exists:tasks,id|different:task_id',
        ]);

        // Check if dependency already exists
        $existingDependency = TaskDependency::where('task_id', $request->task_id)
            ->where('depends_on_task_id', $request->depends_on_task_id)
            ->first();

        if ($existingDependency) {
            return response()->json([
                'success' => false,
                'message' => 'This dependency already exists.'
            ], 422);
        }

        // Check for circular dependency
        if (TaskDependency::wouldCreateCircularDependency($request->task_id, $request->depends_on_task_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot add dependency. This would create a circular dependency.'
            ], 422);
        }

        $dependency = TaskDependency::create([
            'task_id' => $request->task_id,
            'depends_on_task_id' => $request->depends_on_task_id,
        ]);

        $dependency->load(['task', 'dependsOnTask']);

        return response()->json([
            'success' => true,
            'message' => 'Task dependency added successfully',
            'data' => $dependency
        ], 201);
    }

    /**
     * Get all dependencies for a task
     */
    public function index($taskId)
    {
        $user = Auth::user();
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found'
            ], 404);
        }

        // Role-based access: Users can only see dependencies for tasks assigned to them
        if ($user->isUser() && $task->assigned_to !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only view dependencies for tasks assigned to you.'
            ], 403);
        }

        $dependencies = TaskDependency::where('task_id', $taskId)
            ->with(['dependsOnTask'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $dependencies
        ]);
    }

    /**
     * Remove a dependency from a task
     */
    public function destroy($taskId, $dependencyId)
    {
        $user = Auth::user();

        // Only managers can manage task dependencies
        if (!$user->isManager()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only managers can manage task dependencies.'
            ], 403);
        }

        $dependency = TaskDependency::where('task_id', $taskId)
            ->where('depends_on_task_id', $dependencyId)
            ->first();

        if (!$dependency) {
            return response()->json([
                'success' => false,
                'message' => 'Dependency not found'
            ], 404);
        }

        $dependency->delete();

        return response()->json([
            'success' => true,
            'message' => 'Task dependency removed successfully'
        ]);
    }

    /**
     * Get all tasks that depend on a specific task
     */
    public function dependents($taskId)
    {
        $user = Auth::user();
        $task = Task::find($taskId);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found'
            ], 404);
        }

        // Role-based access: Users can only see dependents for tasks assigned to them
        if ($user->isUser() && $task->assigned_to !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only view dependents for tasks assigned to you.'
            ], 403);
        }

        $dependents = TaskDependency::where('depends_on_task_id', $taskId)
            ->with(['task'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $dependents
        ]);
    }
}
