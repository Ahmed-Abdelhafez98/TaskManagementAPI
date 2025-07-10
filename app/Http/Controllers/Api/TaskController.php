<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /**
     * Display a listing of tasks with filtering
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Task::with(['assignedUser', 'creator', 'dependencies']);

        // Role-based access: Users can only see tasks assigned to them
        if ($user->isUser()) {
            $query->where('assigned_to', $user->id);
        }

        // Apply filters
        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        if ($request->has('assigned_user')) {
            $query->assignedTo($request->assigned_user);
        }

        if ($request->has('due_date_from') && $request->has('due_date_to')) {
            $query->byDueDateRange($request->due_date_from, $request->due_date_to);
        }

        $tasks = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $tasks
        ]);
    }

    /**
     * Store a newly created task
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Only managers can create tasks
        if (!$user->isManager()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only managers can create tasks.'
            ], 403);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date|after:today',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $task = Task::create([
            'title' => $request->title,
            'description' => $request->description,
            'due_date' => $request->due_date,
            'assigned_to' => $request->assigned_to,
            'created_by' => $user->id,
            'status' => 'pending',
        ]);

        $task->load(['assignedUser', 'creator']);

        return response()->json([
            'success' => true,
            'message' => 'Task created successfully',
            'data' => $task
        ], 201);
    }

    /**
     * Display the specified task with dependencies
     */
    public function show($id)
    {
        $user = Auth::user();
        $task = Task::with(['assignedUser', 'creator', 'dependencies', 'dependents'])->find($id);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found'
            ], 404);
        }

        // Role-based access: Users can only see tasks assigned to them
        if ($user->isUser() && $task->assigned_to !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only view tasks assigned to you.'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $task
        ]);
    }

    /**
     * Update the specified task
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found'
            ], 404);
        }

        // Role-based access control
        if ($user->isUser()) {
            // Users can only update status of tasks assigned to them
            if ($task->assigned_to !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only update tasks assigned to you.'
                ], 403);
            }

            // Users can only update status
            $request->validate([
                'status' => ['required', Rule::in(['pending', 'in_progress', 'completed', 'canceled'])]
            ]);

            // Check if task can be completed (all dependencies must be completed)
            if ($request->status === 'completed' && !$task->canBeCompleted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot complete task. Some dependencies are not yet completed.'
                ], 422);
            }

            $task->status = $request->status;
        } else {
            // Managers can update all fields
            $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'canceled'])],
                'due_date' => 'nullable|date',
                'assigned_to' => 'nullable|exists:users,id',
            ]);

            if ($request->has('status') && $request->status === 'completed' && !$task->canBeCompleted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot complete task. Some dependencies are not yet completed.'
                ], 422);
            }

            $task->fill($request->only(['title', 'description', 'status', 'due_date', 'assigned_to']));
        }

        $task->save();
        $task->load(['assignedUser', 'creator']);

        return response()->json([
            'success' => true,
            'message' => 'Task updated successfully',
            'data' => $task
        ]);
    }

    /**
     * Remove the specified task
     */
    public function destroy($id)
    {
        $user = Auth::user();

        // Only managers can delete tasks
        if (!$user->isManager()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only managers can delete tasks.'
            ], 403);
        }

        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found'
            ], 404);
        }

        $task->delete();

        return response()->json([
            'success' => true,
            'message' => 'Task deleted successfully'
        ]);
    }
}
