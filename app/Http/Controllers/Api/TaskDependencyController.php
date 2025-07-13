<?php

namespace App\Http\Controllers\Api;

use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TaskDependencyController extends BaseApiController
{
    /**
     * Add a dependency to a task
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            if (!$user->isManager()) {
                return $this->forbiddenResponse('Only managers can manage task dependencies.');
            }

            // Validate request data first
            try {
                $validatedData = $this->validateDependencyRequest($request);
            } catch (ValidationException $e) {
                throw $e; // Re-throw validation exceptions to be handled properly
            }

            $taskId = $validatedData['task_id'];
            $dependsOnTaskId = $validatedData['depends_on_task_id'];

            // Validate dependency constraints
            $validationResult = $this->validateDependencyConstraints($taskId, $dependsOnTaskId);
            if ($validationResult instanceof JsonResponse) {
                return $validationResult;
            }

            $dependency = DB::transaction(function () use ($taskId, $dependsOnTaskId) {
                $dependency = TaskDependency::create([
                    'task_id' => $taskId,
                    'depends_on_task_id' => $dependsOnTaskId,
                ]);

                $dependency->load(['task', 'dependsOnTask']);
                return $dependency;
            });

            Log::info('Task dependency added successfully', [
                'task_id' => $taskId,
                'depends_on_task_id' => $dependsOnTaskId,
                'created_by' => $user->id
            ]);

            return $this->createdResponse($dependency, 'Task dependency added successfully');
        } catch (ValidationException $e) {
            // Let Laravel handle validation exceptions properly
            throw $e;
        } catch (Throwable $e) {
            Log::error('Failed to add task dependency', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
                'request_data' => $request->all()
            ]);
            return $this->serverErrorResponse('Failed to add task dependency.');
        }
    }

    /**
     * Get all dependencies for a task
     *
     * @param string $taskId
     * @return JsonResponse
     */
    public function index(string $taskId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            $task = Task::find($taskId);
            if (!$task) {
                return $this->notFoundResponse('Task not found');
            }

            if (!$this->canUserAccessTask($user, $task)) {
                return $this->forbiddenResponse('You can only view dependencies for tasks assigned to you.');
            }

            $dependencies = TaskDependency::where('task_id', $taskId)
                ->with(['dependsOnTask.assignedUser', 'dependsOnTask.creator'])
                ->get();

            return $this->successResponse($dependencies);
        } catch (Throwable $e) {
            Log::error('Failed to retrieve task dependencies', [
                'error' => $e->getMessage(),
                'task_id' => $taskId,
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Failed to retrieve task dependencies.');
        }
    }

    /**
     * Get all tasks that depend on this task (dependents)
     *
     * @param string $taskId
     * @return JsonResponse
     */
    public function dependents(string $taskId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            $task = Task::find($taskId);
            if (!$task) {
                return $this->notFoundResponse('Task not found');
            }

            if (!$this->canUserAccessTask($user, $task)) {
                return $this->forbiddenResponse('You can only view dependents for tasks assigned to you.');
            }

            $dependents = TaskDependency::where('depends_on_task_id', $taskId)
                ->with(['task.assignedUser', 'task.creator'])
                ->get();

            return $this->successResponse($dependents);
        } catch (Throwable $e) {
            Log::error('Failed to retrieve task dependents', [
                'error' => $e->getMessage(),
                'task_id' => $taskId,
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Failed to retrieve task dependents.');
        }
    }

    /**
     * Get dependency graph for a specific task
     *
     * @param string $taskId
     * @return JsonResponse
     */
    public function graph(string $taskId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            $task = Task::with(['assignedUser', 'creator', 'dependencies', 'dependents'])->find($taskId);
            if (!$task) {
                return $this->notFoundResponse('Task not found');
            }

            if (!$this->canUserAccessTask($user, $task)) {
                return $this->forbiddenResponse('You can only view dependency graph for tasks assigned to you.');
            }

            return $this->successResponse([
                'task' => $task,
                'dependencies' => $task->dependencies,
                'dependents' => $task->dependents,
                'can_be_completed' => $task->canBeCompleted()
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to retrieve task dependency graph', [
                'error' => $e->getMessage(),
                'task_id' => $taskId,
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Failed to retrieve dependency graph.');
        }
    }

    /**
     * Remove a specific task dependency
     *
     * @param string $taskId
     * @param string $dependencyId
     * @return JsonResponse
     */
    public function destroy(string $taskId, string $dependencyId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            if (!$user->isManager()) {
                return $this->forbiddenResponse('Only managers can manage task dependencies.');
            }

            // Verify the task exists
            $task = Task::find($taskId);
            if (!$task) {
                return $this->notFoundResponse('Task not found');
            }

            // Find the dependency that belongs to this task
            $dependency = TaskDependency::where('task_id', $taskId)
                                       ->where('id', $dependencyId)
                                       ->first();

            if (!$dependency) {
                return $this->notFoundResponse('Task dependency not found');
            }

            DB::transaction(function () use ($dependency) {
                $dependency->delete();
            });

            Log::info('Task dependency removed successfully', [
                'dependency_id' => $dependencyId,
                'task_id' => $dependency->task_id,
                'depends_on_task_id' => $dependency->depends_on_task_id,
                'removed_by' => $user->id
            ]);

            return $this->successResponse(null, 'Task dependency removed successfully');

        } catch (Exception $e) {
            Log::error('Error removing task dependency', [
                'error' => $e->getMessage(),
                'task_id' => $taskId,
                'dependency_id' => $dependencyId
            ]);
            return $this->errorResponse('Failed to remove task dependency');
        }
    }

    /**
     * Clear all dependencies for a task
     *
     * @param string $taskId
     * @return JsonResponse
     */
    public function clear(string $taskId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            if (!$user->isManager()) {
                return $this->forbiddenResponse('Only managers can manage task dependencies.');
            }

            $task = Task::find($taskId);
            if (!$task) {
                return $this->notFoundResponse('Task not found');
            }

            $dependenciesCount = TaskDependency::where('task_id', $taskId)->count();

            DB::transaction(function () use ($taskId) {
                TaskDependency::where('task_id', $taskId)->delete();
            });

            Log::info('All task dependencies cleared', [
                'task_id' => $taskId,
                'dependencies_removed' => $dependenciesCount,
                'cleared_by' => $user->id
            ]);

            return $this->successResponse([
                'dependencies_removed' => $dependenciesCount
            ], 'All task dependencies removed successfully');
        } catch (Throwable $e) {
            Log::error('Failed to clear task dependencies', [
                'error' => $e->getMessage(),
                'task_id' => $taskId,
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Failed to clear task dependencies.');
        }
    }

    /**
     * Get the authenticated user
     *
     * @return User|null
     */
    private function getAuthenticatedUser()
    {
        return Auth::user();
    }

    /**
     * Check if the user can access the task
     *
     * @param $user
     * @param Task $task
     * @return bool
     */
    private function canUserAccessTask($user, Task $task): bool
    {
        return !($user->isUser() && $task->assigned_to !== $user->id);
    }

    /**
     * Validate dependency request data
     *
     * @param Request $request
     * @return array
     */
    private function validateDependencyRequest(Request $request): array
    {
        return $request->validate([
            'task_id' => 'required|integer|exists:tasks,id',
            'depends_on_task_id' => [
                'required',
                'integer',
                'exists:tasks,id',
                'different:task_id'
            ],
        ]);
    }

    /**
     * Validate dependency constraints
     *
     * @param int $taskId
     * @param int $dependsOnTaskId
     * @return JsonResponse|null
     */
    private function validateDependencyConstraints(int $taskId, int $dependsOnTaskId): ?JsonResponse
    {
        // Check if dependency already exists
        $existingDependency = TaskDependency::where('task_id', $taskId)
            ->where('depends_on_task_id', $dependsOnTaskId)
            ->first();

        if ($existingDependency) {
            return $this->validationErrorResponse('This dependency already exists.');
        }

        // Check for circular dependency
        if (TaskDependency::wouldCreateCircularDependency($taskId, $dependsOnTaskId)) {
            return $this->validationErrorResponse('Cannot add dependency. This would create a circular dependency.');
        }

        // Validate both tasks exist and are accessible
        $task = Task::find($taskId);
        $dependsOnTask = Task::find($dependsOnTaskId);

        if (!$task || !$dependsOnTask) {
            return $this->notFoundResponse('One or both tasks not found');
        }

        return null;
    }

    /**
     * Build graph nodes for visualization
     *
     * @param $dependencies
     * @return array
     */
    private function buildGraphNodes($dependencies): array
    {
        $nodes = [];
        $taskIds = [];

        foreach ($dependencies as $dependency) {
            if (!in_array($dependency->task_id, $taskIds)) {
                $nodes[] = [
                    'id' => $dependency->task_id,
                    'label' => $dependency->task->title,
                    'status' => $dependency->task->status
                ];
                $taskIds[] = $dependency->task_id;
            }

            if (!in_array($dependency->depends_on_task_id, $taskIds)) {
                $nodes[] = [
                    'id' => $dependency->depends_on_task_id,
                    'label' => $dependency->dependsOnTask->title,
                    'status' => $dependency->dependsOnTask->status
                ];
                $taskIds[] = $dependency->depends_on_task_id;
            }
        }

        return $nodes;
    }

    /**
     * Build graph edges for visualization
     *
     * @param $dependencies
     * @return array
     */
    private function buildGraphEdges($dependencies): array
    {
        $edges = [];

        foreach ($dependencies as $dependency) {
            $edges[] = [
                'from' => $dependency->depends_on_task_id,
                'to' => $dependency->task_id,
                'id' => $dependency->id
            ];
        }

        return $edges;
    }
}
