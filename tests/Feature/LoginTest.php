<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LoginTest extends TestCase
{
    /**
     * The production SPA is served from a bare IP. That origin must be treated
     * as stateful so Sanctum injects StartSession; otherwise $request->session()
     * throws "Session store not set on request." and the handler returns 500
     * (which is exactly what /api/login was doing).
     *
     * This drives a throwaway route through the real `api` middleware group so
     * the assertion does not depend on the database.
     */
    public function test_api_requests_from_production_origin_have_a_session_store(): void
    {
        Route::middleware('api')->get('/_test/session-store', function (Request $request) {
            // Would throw "Session store not set on request." before the fix.
            return response()->json(['session_id' => $request->session()->getId()]);
        });

        $response = $this->withHeaders([
            'Origin' => 'http://34.150.126.247',
        ])->getJson('/_test/session-store');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('session_id'));
    }

    /**
     * Lock in the shared-origins fix: the production hosts allowed by CORS must
     * also be Sanctum-stateful, so the two configs cannot drift apart and
     * reintroduce the missing-session error.
     */
    public function test_production_origin_is_stateful_and_cors_allowed(): void
    {
        $this->assertContains('34.150.126.247', config('sanctum.stateful'));
        $this->assertContains('34.150.126.247:8080', config('sanctum.stateful'));

        $this->assertContains('http://34.150.126.247', config('cors.allowed_origins'));
        $this->assertContains('http://34.150.126.247:8080', config('cors.allowed_origins'));
    }
}
