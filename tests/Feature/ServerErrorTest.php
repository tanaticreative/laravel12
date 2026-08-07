<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ServerErrorTest extends TestCase
{
    use RefreshDatabase;

    /** Text that stands in for anything a customer must never be shown. */
    private const SECRET = 'mysql://sail:password@mysql:3306/laravel';

    protected function setUp(): void
    {
        parent::setUp();

        // A route that fails the way an unmodelled failure does: an exception
        // nobody planned for, carrying infrastructure detail in its message.
        Route::middleware('api')->get('/__boom', function () {
            throw new RuntimeException(self::SECRET);
        });
    }

    #[Test]
    public function it_masks_an_unhandled_failure(): void
    {
        $this->getJson('/__boom')
            ->assertStatus(500)
            ->assertExactJson(['error' => 'server_error', 'message' => 'Server Error']);
    }

    /**
     * The regression that matters. APP_DEBUG decides what Laravel's handler
     * renders *before* the middleware sees it: with debug on the body gains the
     * exception message, file, line and a full stack trace. The masking has to
     * hold anyway, because a production box with a mistyped .env is exactly the
     * case where the guarantee is load-bearing.
     */
    #[Test]
    public function it_masks_the_failure_even_with_debug_on(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/__boom')->assertStatus(500);

        $response->assertExactJson(['error' => 'server_error', 'message' => 'Server Error']);
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
    }

    /**
     * Masking must not swallow the cause — it only moves it to a channel
     * customers cannot read. If this breaks, 500s become undiagnosable.
     */
    #[Test]
    public function it_still_reports_the_cause(): void
    {
        Log::spy();

        $this->getJson('/__boom')->assertStatus(500);

        // The detail the customer was denied has to exist somewhere, or 500s
        // become undiagnosable. The log is that somewhere.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, self::SECRET));
    }

    /** Modelled 4xx keep their own bodies; only 5xx are masked. */
    #[Test]
    public function it_leaves_client_errors_alone(): void
    {
        $this->postJson('/holds/999999/confirm')
            ->assertStatus(404)
            ->assertExactJson(['error' => 'not_found', 'message' => 'Not Found']);
    }
}
