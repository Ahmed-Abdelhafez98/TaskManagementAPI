<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $manager;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->user = User::factory()->create(['role' => 'user']);
    }

    #[Test]
    public function manager_can_create_task()
    {
        Sanctum::actingAs($this->manager);

        $taskData = [
            'title' => 'Test Task',
            'description' => 'Test Description',
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'assigned_to' => $this->user->id,
        ];

        $response = $this->postJson('/api/tasks', $taskData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'title',
                    'description',
                    'status',
                    'due_date',
                    'assigned_to',
                    'created_by',
                    'assigned_user',
                    'creator'
                ]
            ]);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Test Task',
            'created_by' => $this->manager->id,
            'assigned_to' => $this->user->id,
        ]);
    }

    #[Test]
    public function user_cannot_create_task()
    {
        Sanctum::actingAs($this->user);

        $taskData = [
            'title' => 'Test Task',
            'description' => 'Test Description',
        ];

        $response = $this->postJson('/api/tasks', $taskData);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Only managers can create tasks.'
            ]);
    }

    #[Test]
    public function creating_task_with_invalid_assigned_user_fails()
    {
        Sanctum::actingAs($this->manager);

        $taskData = [
            'title' => 'Test Task',
            'description' => 'Test Description',
            'assigned_to' => 99999, // Non-existent user
        ];

        $response = $this->postJson('/api/tasks', $taskData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_to']);
    }

    #[Test]
    public function manager_can_view_all_tasks()
    {
        Sanctum::actingAs($this->manager);

        // Create multiple tasks
        Task::factory()->count(5)->create(['created_by' => $this->manager->id]);

        $response = $this->getJson('/api/tasks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'current_page',
                    'data' => [
                        '*' => [
                            'id',
                            'title',
                            'description',
                            'status',
                            'due_date',
                            'assigned_to',
                            'created_by',
                            'assigned_user',
                            'creator',
                            'dependencies'
                        ]
                    ],
                    'per_page',
                    'total'
                ]
            ]);
    }

    #[Test]
    public function user_can_only_view_assigned_tasks()
    {
        Sanctum::actingAs($this->user);

        // Create tasks - some assigned to user, some not
        $assignedTask = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id
        ]);

        Task::factory()->create([
            'assigned_to' => null,
            'created_by' => $this->manager->id
        ]);

        $response = $this->getJson('/api/tasks');

        $response->assertStatus(200);

        $tasks = $response->json('data.data');
        $this->assertCount(1, $tasks);
        $this->assertEquals($assignedTask->id, $tasks[0]['id']);
    }

    #[Test]
    public function can_filter_tasks_by_status()
    {
        Sanctum::actingAs($this->manager);

        Task::factory()->create(['status' => 'pending', 'created_by' => $this->manager->id]);
        Task::factory()->create(['status' => 'completed', 'created_by' => $this->manager->id]);

        $response = $this->getJson('/api/tasks?status=pending');

        $response->assertStatus(200);
        $tasks = $response->json('data.data');

        foreach ($tasks as $task) {
            $this->assertEquals('pending', $task['status']);
        }
    }

    #[Test]
    public function can_filter_tasks_by_assigned_user()
    {
        Sanctum::actingAs($this->manager);

        Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id
        ]);
        Task::factory()->create([
            'assigned_to' => null,
            'created_by' => $this->manager->id
        ]);

        $response = $this->getJson("/api/tasks?assigned_user={$this->user->id}");

        $response->assertStatus(200);
        $tasks = $response->json('data.data');

        foreach ($tasks as $task) {
            $this->assertEquals($this->user->id, $task['assigned_to']);
        }
    }

    #[Test]
    public function can_filter_tasks_by_due_date_range()
    {
        Sanctum::actingAs($this->manager);

        $startDate = now()->addDays(1)->format('Y-m-d');
        $endDate = now()->addDays(7)->format('Y-m-d');

        Task::factory()->create([
            'due_date' => now()->addDays(3),
            'created_by' => $this->manager->id
        ]);
        Task::factory()->create([
            'due_date' => now()->addDays(10),
            'created_by' => $this->manager->id
        ]);

        $response = $this->getJson("/api/tasks?due_date_from={$startDate}&due_date_to={$endDate}");

        $response->assertStatus(200);
        $tasks = $response->json('data.data');

        $this->assertCount(1, $tasks);
    }

    #[Test]
    public function can_search_tasks_by_title_and_description()
    {
        Sanctum::actingAs($this->manager);

        Task::factory()->create([
            'title' => 'Important Task',
            'description' => 'This is urgent',
            'created_by' => $this->manager->id
        ]);
        Task::factory()->create([
            'title' => 'Regular Task',
            'description' => 'Normal priority',
            'created_by' => $this->manager->id
        ]);

        $response = $this->getJson('/api/tasks?search=Important');

        $response->assertStatus(200);
        $tasks = $response->json('data.data');
        $this->assertCount(1, $tasks);
        $this->assertStringContainsString('Important', $tasks[0]['title']);
    }

    #[Test]
    public function pagination_works_with_per_page_limit()
    {
        Sanctum::actingAs($this->manager);

        Task::factory()->count(15)->create(['created_by' => $this->manager->id]);

        $response = $this->getJson('/api/tasks?per_page=5');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(5, $data['per_page']);
        $this->assertCount(5, $data['data']);
    }

    #[Test]
    public function per_page_limit_cannot_exceed_maximum()
    {
        Sanctum::actingAs($this->manager);

        Task::factory()->count(10)->create(['created_by' => $this->manager->id]);

        $response = $this->getJson('/api/tasks?per_page=200'); // Exceeds max of 100

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(100, $data['per_page']); // Should be capped at 100
    }

    #[Test]
    public function manager_can_update_all_task_fields()
    {
        Sanctum::actingAs($this->manager);

        $task = Task::factory()->create(['created_by' => $this->manager->id]);

        $updateData = [
            'title' => 'Updated Title',
            'description' => 'Updated Description',
            'status' => 'in_progress',
            'assigned_to' => $this->user->id,
        ];

        $response = $this->putJson("/api/tasks/{$task->id}", $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Task updated successfully'
            ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Updated Title',
            'status' => 'in_progress',
            'assigned_to' => $this->user->id,
        ]);
    }

    #[Test]
    public function updating_task_with_invalid_assigned_user_fails()
    {
        Sanctum::actingAs($this->manager);

        $task = Task::factory()->create(['created_by' => $this->manager->id]);

        $response = $this->putJson("/api/tasks/{$task->id}", [
            'assigned_to' => 99999 // Non-existent user
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['assigned_to']);
    }

    #[Test]
    public function user_can_only_update_status_of_assigned_tasks()
    {
        $task = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id,
            'status' => 'pending'
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->putJson("/api/tasks/{$task->id}", ['status' => 'completed']);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'completed'
        ]);
    }

    #[Test]
    public function user_cannot_make_invalid_status_transitions()
    {
        $task = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id,
            'status' => 'pending'
        ]);

        Sanctum::actingAs($this->user);

        // Try invalid transition (assuming we implement status validation)
        $response = $this->putJson("/api/tasks/{$task->id}", ['status' => 'invalid_status']);

        $response->assertStatus(422);
    }

    #[Test]
    public function user_cannot_update_other_users_tasks()
    {
        $otherUser = User::factory()->create(['role' => 'user']);
        $task = Task::factory()->create([
            'assigned_to' => $otherUser->id,
            'created_by' => $this->manager->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->putJson("/api/tasks/{$task->id}", ['status' => 'completed']);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'You can only update tasks assigned to you.'
            ]);
    }

    #[Test]
    public function manager_can_delete_task()
    {
        Sanctum::actingAs($this->manager);

        $task = Task::factory()->create(['created_by' => $this->manager->id]);

        $response = $this->deleteJson("/api/tasks/{$task->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Task deleted successfully'
            ]);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    #[Test]
    public function user_cannot_delete_task()
    {
        $task = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("/api/tasks/{$task->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Only managers can delete tasks.'
            ]);
    }

    #[Test]
    public function cannot_delete_task_with_dependents()
    {
        Sanctum::actingAs($this->manager);

        $parentTask = Task::factory()->create(['created_by' => $this->manager->id]);
        $childTask = Task::factory()->create(['created_by' => $this->manager->id]);

        // Create dependency
        TaskDependency::create([
            'task_id' => $childTask->id,
            'depends_on_task_id' => $parentTask->id
        ]);

        $response = $this->deleteJson("/api/tasks/{$parentTask->id}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete task that has dependent tasks.'
            ]);
    }

    #[Test]
    public function task_show_includes_dependencies_and_dependents()
    {
        Sanctum::actingAs($this->manager);

        $task = Task::factory()->create(['created_by' => $this->manager->id]);

        $response = $this->getJson("/api/tasks/{$task->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'title',
                    'dependencies',
                    'dependents'
                ]
            ]);
    }

    #[Test]
    public function user_can_only_view_assigned_task_details()
    {
        $task = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/tasks/{$task->id}");

        $response->assertStatus(200);
    }

    #[Test]
    public function user_cannot_view_other_users_task_details()
    {
        $otherUser = User::factory()->create(['role' => 'user']);
        $task = Task::factory()->create([
            'assigned_to' => $otherUser->id,
            'created_by' => $this->manager->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/tasks/{$task->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'You can only view tasks assigned to you.'
            ]);
    }

    #[Test]
    public function task_not_found_returns_404()
    {
        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/tasks/99999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Task not found'
            ]);
    }

    #[Test]
    public function unauthenticated_requests_are_rejected()
    {
        $response = $this->getJson('/api/tasks');

        $response->assertStatus(401);
    }
}
