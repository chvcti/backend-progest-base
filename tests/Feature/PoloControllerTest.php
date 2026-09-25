<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use App\Models\Polo;

class PoloControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = \App\Models\User::where('email', 'adminti@gmail.com')->first() 
            ?? \App\Models\User::factory()->create(['email' => 'adminti@gmail.com']);
        \Laravel\Sanctum\Sanctum::actingAs($admin);
    }

    public function test_create_polo_successfully()
    {
        $uniqueName = 'Polo Teste ' . \Illuminate\Support\Str::random(5);
        $uniqueSigla = strtoupper(\Illuminate\Support\Str::random(3));
        $payload = [
            'nome' => $uniqueName,
            'sigla' => $uniqueSigla,
            'status' => 'A'
        ];

        $response = $this->postJson('/api/polo/add', $payload);

        $response->assertStatus(201)
                 ->assertJson(['status' => true]);

        $this->assertDatabaseHas('polos', [
            'nome' => strtoupper($uniqueName),
            'sigla' => $uniqueSigla,
            'status' => 'A'
        ]);
    }

    public function test_update_polo_successfully()
    {
        $polo = Polo::factory()->create([
            'nome' => 'POLO ANTIGO',
            'sigla' => 'ANT',
            'status' => 'A'
        ]);

        $payload = [
            'id' => $polo->id,
            'nome' => 'Polo Atualizado',
            'sigla' => 'ATU',
            'status' => 'A'
        ];

        $response = $this->postJson('/api/polo/update', $payload);

        $response->assertStatus(200)
                 ->assertJson(['status' => true]);

        $this->assertDatabaseHas('polos', [
            'id' => $polo->id,
            'nome' => 'POLO ATUALIZADO',
            'sigla' => 'ATU'
        ]);
    }

    public function test_list_all_polos()
    {
        Polo::factory()->count(3)->create();

        $response = $this->postJson('/api/polo/list');

        $response->assertStatus(200)
                 ->assertJsonStructure(['status', 'data' => ['data']]);
    }

    public function test_toggle_status_polo()
    {
        $polo = Polo::factory()->create(['status' => 'A']);

        $response = $this->postJson('/api/polo/toggleStatus', ['id' => $polo->id]);

        $response->assertStatus(200)
                 ->assertJson(['status' => true]);

        $this->assertDatabaseHas('polos', [
            'id' => $polo->id,
            'status' => 'I'
        ]);
    }

    public function test_delete_polo_without_setores()
    {
        $polo = Polo::factory()->create();

        $response = $this->postJson('/api/polo/delete/' . $polo->id);

        $response->assertStatus(200)
                 ->assertJson(['status' => true]);

        $this->assertDatabaseMissing('polos', ['id' => $polo->id]);
    }
}
