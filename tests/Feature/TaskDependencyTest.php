<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class TaskDependencyTest extends TestCase
{
    use RefreshDatabase;

    protected $manager;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'manager']);
        $this->user = User::factory()->create(['role' => 'user']);
    }

    /** @test */
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
            ]);

        $this->assertDatabaseHas('task_dependencies', [
            'task_id' => $task1->id,
            'depends_on_task_id' => $task2->id
        ]);
    }

    /** @test */
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
                'message' => 'Unauthorized. Only managers can manage task dependencies.'
            ]);
    }

    /** @test */
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

    /** @test */
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

        // Try to create the same dependency again
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

    /** @test */
    public function can_get_task_dependencies()
    {
        $task = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id
        ]);
        $dependencyTask = Task::factory()->create(['created_by' => $this->manager->id]);

        TaskDependency::create([
            'task_id' => $task->id,
            'depends_on_task_id' => $dependencyTask->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/tasks/{$task->id}/dependencies");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'task_id',
                        'depends_on_task_id',
                        'depends_on_task' => [
                            'id',
                            'title',
                            'status'
                        ]
                    ]
                ]
            ]);
    }

    /** @test */
    public function user_cannot_view_dependencies_of_unassigned_tasks()
    {
        Sanctum::actingAs($this->user);

        $task = Task::factory()->create([
            'assigned_to' => null,
            'created_by' => $this->manager->id
        ]);

        $response = $this->getJson("/api/tasks/{$task->id}/dependencies");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized. You can only view dependencies for tasks assigned to you.'
            ]);
    }

    /** @test */
    public function manager_can_remove_task_dependency()
    {
        Sanctum::actingAs($this->manager);

        $task1 = Task::factory()->create(['created_by' => $this->manager->id]);
        $task2 = Task::factory()->create(['created_by' => $this->manager->id]);

        $dependency = TaskDependency::create([
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

    /** @test */
    public function can_get_task_dependents()
    {
        $task = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id
        ]);
        $dependentTask = Task::factory()->create(['created_by' => $this->manager->id]);

        TaskDependency::create([
            'task_id' => $dependentTask->id,
            'depends_on_task_id' => $task->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson("/api/tasks/{$task->id}/dependents");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'task_id',
                        'depends_on_task_id',
                        'task' => [
                            'id',
                            'title',
                            'status'
                        ]
                    ]
                ]
            ]);
    }

    /** @test */
    public function task_cannot_be_completed_with_incomplete_dependencies()
    {
        $task = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id,
            'status' => 'in_progress'
        ]);

        $dependencyTask = Task::factory()->create([
            'created_by' => $this->manager->id,
            'status' => 'pending' // Not completed
        ]);

        TaskDependency::create([
            'task_id' => $task->id,
            'depends_on_task_id' => $dependencyTask->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->putJson("/api/tasks/{$task->id}", [
            'status' => 'completed'
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot complete task. Some dependencies are not yet completed.'
            ]);
    }

    /** @test */
    public function task_can_be_completed_when_all_dependencies_are_completed()
    {
        $task = Task::factory()->create([
            'assigned_to' => $this->user->id,
            'created_by' => $this->manager->id,
            'status' => 'in_progress'
        ]);

        $dependencyTask = Task::factory()->create([
            'created_by' => $this->manager->id,
            'status' => 'completed' // Completed
        ]);

        TaskDependency::create([
            'task_id' => $task->id,
            'depends_on_task_id' => $dependencyTask->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->putJson("/api/tasks/{$task->id}", [
            'status' => 'completed'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Task updated successfully'
            ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'completed'
        ]);
    }

    /** @test */
    public function dependency_validation_requires_valid_task_ids()
    {
        Sanctum::actingAs($this->manager);

        $task = Task::factory()->create(['created_by' => $this->manager->id]);

        $response = $this->postJson("/api/tasks/{$task->id}/dependencies", [
            'task_id' => $task->id,
            'depends_on_task_id' => 999999 // Non-existent task
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['depends_on_task_id']);
    }

    /** @test */
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
}
