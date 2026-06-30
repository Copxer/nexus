<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'email_verified_at' => now(),
        ]);

        $this->call(ProjectSeeder::class);
        $this->call(RepositorySeeder::class); // also seeds issues + PRs + workflow runs
        $this->call(HostSeeder::class);       // spec 040 — 2 hosts (1 online + 1 offline)
        $this->call(WebsiteSeeder::class);    // spec 040 — 3 websites with 20m check history
        $this->call(AlertSeeder::class);      // spec 040 — 4 alerts across the lifecycle
    }
}
