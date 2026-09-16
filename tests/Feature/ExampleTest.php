<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // Without this the test runs against whatever state the database file was
    // last left in, so GET / died on `no such table: languages` — passing or
    // failing depending on what ran before it. Every other feature test uses
    // the trait; this one shipped with it commented out by the scaffold.
    use RefreshDatabase;

    /**
     * Smoke test for a fresh install: the slide board has to render on an
     * empty database, with no slides, shows, entities or languages seeded.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
