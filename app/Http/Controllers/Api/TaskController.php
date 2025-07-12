<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskController extends BaseApiController
{
    /**
     * Maximum items per page for pagination
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Default items per page
     */
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Valid task statuses
     */
    private const VALID_STATUSES = ['pending', 'in_progress', 'completed'];

    /**
     * Valid status transitions
     */
    private const STATUS_TRANSITIONS = [
        'pending' => ['in_progress', 'completed'],
        'in_progress' => ['completed', 'pending'],
        'completed' => ['pending', 'in_progress']
    ];

    /**
     * Display a listing of tasks with filtering and pagination
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            $query = $this->buildTaskQuery($user);
            $this->applyTaskFilters($query, $request);

            $perPage = $this->getValidatedPerPage($request);
            $tasks = $query->paginate($perPage);

            Log::info('Tasks retrieved successfully', [
                'user_id' => $user->id,
                'role' => $user->role,
                'total' => $tasks->total()
            ]);

            return $this->successResponse($tasks);
        } catch (Throwable $e) {
            Log::error('Failed to retrieve tasks', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Failed to retrieve tasks.');
        }
    }

    /**
     * Store a newly created task
     *
     * @param StoreTaskRequest $request
     * @return JsonResponse
     */
    public function store(StoreTaskRequest $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            if (!$user->isManager()) {
                return $this->forbiddenResponse('Only managers can create tasks.');
            }

            // Validate assigned user if provided
            if ($request->assigned_to && !$this->validateAssignedUser($request->assigned_to)) {
                return $this->validationErrorResponse('Assigned user not found.');
            }

            $task = DB::transaction(function () use ($request, $user) {
                $task = Task::create([
                    'title' => $request->title,
                    'description' => $request->description,
                    'due_date' => $request->due_date,
                    'assigned_to' => $request->assigned_to,
                    'created_by' => $user->id,
                    'status' => 'pending',
                ]);

                $task->load(['assignedUser', 'creator']);
                return $task;
            });

            Log::info('Task created successfully', [
                'task_id' => $task->id,
                'created_by' => $user->id,
                'assigned_to' => $request->assigned_to
            ]);

            return $this->createdResponse($task, 'Task created successfully');
        } catch (Throwable $e) {
            Log::error('Failed to create task', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
                'request_data' => $request->all()
            ]);
            return $this->serverErrorResponse('Failed to create task.');
        }
    }

    /**
     * Display the specified task with dependencies
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            $task = $this->findTaskWithRelations($id);
            if (!$task) {
                return $this->notFoundResponse('Task not found');
            }

            if (!$this->canUserAccessTask($user, $task)) {
                return $this->forbiddenResponse('You can only view tasks assigned to you.');
            }

            return $this->successResponse($task);
        } catch (Throwable $e) {
            Log::error('Failed to retrieve task', [
                'error' => $e->getMessage(),
                'task_id' => $id,
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Failed to retrieve task.');
        }
    }

    /**
     * Update the specified task
     *
     * @param UpdateTaskRequest $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(UpdateTaskRequest $request, string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            $task = Task::find($id);
            if (!$task) {
                return $this->notFoundResponse('Task not found');
            }

            $updateResult = $this->processTaskUpdate($user, $task, $request);
            if ($updateResult instanceof JsonResponse) {
                return $updateResult; // Return error response
            }

            DB::transaction(function () use ($task) {
                $task->save();
                $task->load(['assignedUser', 'creator']);
            });

            Log::info('Task updated successfully', [
                'task_id' => $task->id,
                'updated_by' => $user->id,
                'status' => $task->status
            ]);

            return $this->successResponse($task, 'Task updated successfully');
        } catch (Throwable $e) {
            Log::error('Failed to update task', [
                'error' => $e->getMessage(),
                'task_id' => $id,
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Failed to update task.');
        }
    }

    /**
     * Remove the specified task
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser();
            if (!$user) {
                return $this->unauthorizedResponse('User not authenticated');
            }

            if (!$user->isManager()) {
                return $this->forbiddenResponse('Only managers can delete tasks.');
            }

            $task = Task::find($id);
            if (!$task) {
                return $this->notFoundResponse('Task not found');
            }

            if ($task->dependents()->exists()) {
                return $this->validationErrorResponse('Cannot delete task that has dependent tasks.');
            }

            DB::transaction(function () use ($task) {
                $task->delete();
            });

            Log::info('Task deleted successfully', [
                'task_id' => $id,
                'deleted_by' => $user->id
            ]);

            return $this->successResponse(null, 'Task deleted successfully');
        } catch (Throwable $e) {
            Log::error('Failed to delete task', [
                'error' => $e->getMessage(),
                'task_id' => $id,
                'user_id' => $user->id ?? null
            ]);
            return $this->serverErrorResponse('Failed to delete task.');
        }
    }

    /**
     * Build the task query based on user role and permissions
     *
     * @param User $user
     * @return Builder
     */
    private function buildTaskQuery(User $user): Builder
    {
        $query = Task::with(['assignedUser', 'creator', 'dependencies']);

        // Role-based access: Users can only see tasks assigned to them
        if ($user->isUser()) {
            $query->where('assigned_to', $user->id);
        }

        return $query;
    }

    /**
     * Apply filters to task query
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param Request $request
     * @return void
     */
    private function applyTaskFilters($query, Request $request): void
    {
        if ($request->has('status') && !empty($request->status)) {
            if (in_array($request->status, self::VALID_STATUSES)) {
                $query->byStatus($request->status);
            }
        }

        if ($request->has('assigned_user') && !empty($request->assigned_user)) {
            $query->assignedTo($request->assigned_user);
        }

        if ($request->has('due_date_from') && $request->has('due_date_to')) {
            $query->byDueDateRange($request->due_date_from, $request->due_date_to);
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
    }

    /**
     * Check if status transition is valid
     *
     * @param string $currentStatus
     * @param string $newStatus
     * @return bool
     */
    private function isValidStatusTransition(string $currentStatus, string $newStatus): bool
    {
        return in_array($newStatus, self::STATUS_TRANSITIONS[$currentStatus] ?? []);
    }

    /**
     * Get the authenticated user
     *
     * @return User|null
     */
    private function getAuthenticatedUser(): ?User
    {
        return Auth::user();
    }

    /**
     * Validate assigned user exists and is active
     *
     * @param int $userId
     * @return bool
     */
    private function validateAssignedUser(int $userId): bool
    {
        $assignedUser = User::find($userId);
        return $assignedUser !== null;
    }

    /**
     * Find task with related models
     *
     * @param string $taskId
     * @return Task|null
     */
    private function findTaskWithRelations(string $taskId): ?Task
    {
        return Task::with(['assignedUser', 'creator', 'dependencies', 'dependents'])->find($taskId);
    }

    /**
     * Check if the user can access the task
     *
     * @param User $user
     * @param Task $task
     * @return bool
     */
    private function canUserAccessTask(User $user, Task $task): bool
    {
        return !($user->isUser() && $task->assigned_to !== $user->id);
    }

    /**
     * Process the task update logic
     *
     * @param User $user
     * @param Task $task
     * @param UpdateTaskRequest $request
     * @return JsonResponse|void
     */
    private function processTaskUpdate(User $user, Task $task, UpdateTaskRequest $request)
    {
        if ($user->isUser()) {
            // Users can only update status of tasks assigned to them
            if ($task->assigned_to !== $user->id) {
                return $this->forbiddenResponse('You can only update tasks assigned to you.');
            }

            // Validate status transition
            if ($request->has('status') && !$this->isValidStatusTransition($task->status, $request->status)) {
                return $this->validationErrorResponse('Invalid status transition.');
            }

            // Check if task can be completed (all dependencies must be completed)
            if ($request->status === 'completed' && !$task->canBeCompleted()) {
                return $this->validationErrorResponse('Cannot complete task. Some dependencies are not yet completed.');
            }

            $task->status = $request->status;
        } else {
            // Managers can update all fields
            if ($request->has('status') && $request->status === 'completed' && !$task->canBeCompleted()) {
                return $this->validationErrorResponse('Cannot complete task. Some dependencies are not yet completed.');
            }

            // Validate assigned user if provided
            if ($request->has('assigned_to') && $request->assigned_to) {
                $assignedUser = User::find($request->assigned_to);
                if (!$assignedUser) {
                    return $this->validationErrorResponse('Assigned user not found.');
                }
            }

            $task->fill($request->only(['title', 'description', 'status', 'due_date', 'assigned_to']));
        }
    }

    /**
     * Get validated items per page for pagination
     *
     * @param Request $request
     * @return int
     */
    private function getValidatedPerPage(Request $request): int
    {
        $perPage = (int) $request->get('per_page', self::DEFAULT_PER_PAGE);
        return min($perPage, self::MAX_PER_PAGE);
    }
}
