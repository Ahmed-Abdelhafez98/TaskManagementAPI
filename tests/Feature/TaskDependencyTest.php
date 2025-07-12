<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

class TaskDependencyTest extends TestCase
{
    use RefreshDatabase;

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
    public function manager_can_add_task_dependency()
    {
        Sanctum::actingAs($this->manager);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        $response = $this->postJson("/api/tasks/{$task1->id}/dependencies", [
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Task dependency added successfully'
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'task_id',
                    'depends_on_task_id',
                    'task',
                    'depends_on_task'
                ]
            ]);

        $this->assertDatabaseHas('task_dependencies', [
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);
    }

    #[Test]
    public function user_cannot_add_task_dependency()
    {
        Sanctum::actingAs($this->user);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        $response = $this->postJson("/api/tasks/{$task1->id}/dependencies", [
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Only managers can manage task dependencies.'
            ]);
    }

    #[Test]
    public function adding_dependency_requires_valid_data()
    {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/tasks/1/dependencies", [
            'task_id' => '',
            'depends_on_task_id' => ''
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['task_id', 'depends_on_task_id']);
    }

    #[Test]
    public function cannot_add_dependency_to_nonexistent_task()
    {
        Sanctum::actingAs($this->manager);

        $task = Task::factory()->create(['created_by' => $this->manager->id]);

        $response = $this->postJson("/api/tasks/{$task->id}/dependencies", [
            'task_id' => $task->id,
            'depends_on_task_id' => 99999 // Non-existent task
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['depends_on_task_id']);
    }

    #[Test]
    public function cannot_create_self_dependency()
    {
        Sanctum::actingAs($this->manager);

        $task = Task::factory()->create(['created_by' => $this->manager->id]);

        $response = $this->postJson("/api/tasks/{$task->id}/dependencies", [
            'task_id' => $task->id,
            'depends_on_task_id' => $task->id // Same task
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['depends_on_task_id']);
    }

    #[Test]
    public function cannot_create_circular_dependency()
    {
        Sanctum::actingAs($this->manager);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        // Create initial dependency: task1 depends on task2
        TaskDependency::create([
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);

        // Try to create circular dependency: task2 depends on task1
        $response = $this->postJson("/api/tasks/{$task2->id}/dependencies", [
            'task_id' => $task2->id,
            'depends_on_task_id' => $task1->id
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot add dependency. This would create a circular dependency.'
            ]);
    }

    #[Test]
    public function cannot_create_duplicate_dependency()
    {
        Sanctum::actingAs($this->manager);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        // Create initial dependency
        TaskDependency::create([
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);

        // Try to create duplicate dependency
        $response = $this->postJson("/api/tasks/{$task1->id}/dependencies", [
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'This dependency already exists.'
            ]);
    }

    #[Test]
    public function manager_can_view_task_dependencies()
    {
        Sanctum::actingAs($this->manager);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        TaskDependency::create([
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);

        $response = $this->getJson("/api/tasks/{$task1->id}/dependencies");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'task_id',
                        'depends_on_task_id',
                        'depends_on_task' => [
                            'id',
                            'title',
                            'status',
                            'assigned_user',
                            'creator'
                        ]
                    ]
                ]
            ]);
    }

    #[Test]
    public function user_can_view_dependencies_for_assigned_tasks()
    {
        $task1 = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id
        ]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        TaskDependency::create([
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/tasks/{$task1->id}/dependencies");

        $response->assertStatus(200);
    }

    #[Test]
    public function user_cannot_view_dependencies_for_other_users_tasks()
    {
        $otherUser = User::factory()->create(['role' => 'user']);
        $task = Task::factory()->create([
            'assigned_to' => $otherUser->id,
            'created_by' => $this->manager->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/tasks/{$task->id}/dependencies");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'You can only view dependencies for tasks assigned to you.'
            ]);
    }

    #[Test]
    public function manager_can_remove_task_dependency()
    {
        Sanctum::actingAs($this->manager);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        TaskDependency::create([
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);

        $response = $this->deleteJson("/api/tasks/{$task1->id}/dependencies/{$task2->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Task dependency removed successfully'
            ]);

        $this->assertDatabaseMissing('task_dependencies', [
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);
    }

    #[Test]
    public function user_cannot_remove_task_dependency()
    {
        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        TaskDependency::create([
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("/api/tasks/{$task1->id}/dependencies/{$task2->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Only managers can manage task dependencies.'
            ]);
    }

    #[Test]
    public function removing_nonexistent_dependency_returns_404()
    {
        Sanctum::actingAs($this->manager);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        $response = $this->deleteJson("/api/tasks/{$task1->id}/dependencies/{$task2->id}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Task dependency not found'
            ]);
    }

    #[Test]
    public function can_view_task_dependents()
    {
        Sanctum::actingAs($this->manager);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        TaskDependency::create([
            'task_id' => $task2->id,
            'depends_on_task_id' => $task1->id
        ]);

        $response = $this->getJson("/api/tasks/{$task1->id}/dependents");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'task_id',
                        'depends_on_task_id',
                        'task' => [
                            'id',
                            'title',
                            'status',
                            'assigned_user',
                            'creator'
                        ]
                    ]
                ]
            ]);
    }

    #[Test]
    public function can_view_dependency_graph()
    {
        Sanctum::actingAs($this->manager);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task3 = Task::factory()->create(['created_by' => $this->manager->id]);

        // Create dependencies: task1 depends on task2, task3 depends on task1
        TaskDependency::create([
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);
        TaskDependency::create([
            'task_id' => $task3->id,
            'depends_on_task_id' => $task1->id
        ]);

        $response = $this->getJson("/api/tasks/{$task1->id}/graph");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'task',
                    'dependencies',
                    'dependents',
                    'can_be_completed'
                ]
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data['dependencies']);
        $this->assertCount(1, $data['dependents']);
        $this->assertIsBool($data['can_be_completed']);
    }

    #[Test]
    public function can_clear_all_task_dependencies()
    {
        Sanctum::actingAs($this->manager);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task3 = Task::factory()->create(['created_by' => $this->manager->id]);

        // Create multiple dependencies
        TaskDependency::create([
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);
        TaskDependency::create([
            'task_id' => $task1->id,
            'depends_on_task_id' => $task3->id
        ]);

        $response = $this->deleteJson("/api/tasks/{$task1->id}/dependencies");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'dependencies_removed'
                ]
            ])
            ->assertJson([
                'success' => true,
                'message' => 'All task dependencies removed successfully'
            ]);

        $this->assertDatabaseMissing('task_dependencies', [
            'task_id' => $task1->id
        ]);

        $data = $response->json('data');
        $this->assertEquals(2, $data['dependencies_removed']);
    }

    #[Test]
    public function user_cannot_clear_task_dependencies()
    {
        $task = Task::factory()->create(['created_by' => $this->manager->id]);

        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("/api/tasks/{$task->id}/dependencies");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Only managers can manage task dependencies.'
            ]);
    }

    #[Test]
    public function dependency_operations_on_nonexistent_task_return_404()
    {
        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/tasks/99999/dependencies');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Task not found'
            ]);
    }

    #[Test]
    public function unauthenticated_requests_are_rejected()
    {
        $task = Task::factory()->create();

        $response = $this->getJson("/api/tasks/{$task->id}/dependencies");

        $response->assertStatus(401);
    }
}
