<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

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

    /** @test */
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

    /** @test */
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
                'message' => 'Unauthorized. Only managers can create tasks.'
            ]);
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
    public function user_can_only_update_status_of_assigned_tasks()
    {
        $task = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->putJson("/api/tasks/{$task->id}", ['status' => 'completed']);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'completed'
        ]);
    }

    /** @test */
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
                'message' => 'Unauthorized. You can only update tasks assigned to you.'
            ]);
    }

    /** @test */
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

    /** @test */
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
                'message' => 'Unauthorized. Only managers can delete tasks.'
            ]);
    }

    /** @test */
    public function can_get_task_details_with_dependencies()
    {
        Sanctum::actingAs($this->manager);

        $task = Task::factory()->create(['created_by' => $this->manager->id]);
        $dependencyTask = Task::factory()->create(['created_by' => $this->manager->id]);

        // Create dependency
        TaskDependency::create([
            'task_id' => $task->id,
            'depends_on_task_id' => $dependencyTask->id
        ]);

        $response = $this->getJson("/api/tasks/{$task->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'title',
                    'dependencies' => [
                        '*' => ['id', 'title', 'status']
                    ],
                    'dependents'
                ]
            ]);
    }

    /** @test */
    public function task_validation_fails_with_invalid_data()
    {
        Sanctum::actingAs($this->manager);

        $invalidData = [
            'title' => '', // Required field
            'due_date' => 'invalid-date',
            'assigned_to' => 999999, // Non-existent user
        ];

        $response = $this->postJson('/api/tasks', $invalidData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'due_date', 'assigned_to']);
    }

    /** @test */
    public function task_due_date_must_be_in_future()
    {
        Sanctum::actingAs($this->manager);

        $taskData = [
            'title' => 'Test Task',
            'due_date' => now()->subDay()->format('Y-m-d'), // Past date
        ];

        $response = $this->postJson('/api/tasks', $taskData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['due_date']);
    }

    /** @test */
    public function returns_404_for_non_existent_task()
    {
        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/tasks/999999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Task not found'
            ]);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_tasks()
    {
        $response = $this->getJson('/api/tasks');

        $response->assertStatus(401);
    }

    /** @test */
    public function can_paginate_tasks()
    {
        Sanctum::actingAs($this->manager);

        Task::factory()->count(20)->create(['created_by' => $this->manager->id]);

        $response = $this->getJson('/api/tasks?page=1');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'current_page',
                    'data',
                    'first_page_url',
                    'last_page',
                    'last_page_url',
                    'next_page_url',
                    'per_page',
                    'prev_page_url',
                    'total'
                ]
            ]);

        $this->assertEquals(1, $response->json('data.current_page'));
        $this->assertLessThanOrEqual(15, count($response->json('data.data')));
    }
}
