<?php

namespace Tests\Feature;

use Falak\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_the_dashboard_url_redirects_to_the_projects_home()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertRedirect('/projects');
    }
}
