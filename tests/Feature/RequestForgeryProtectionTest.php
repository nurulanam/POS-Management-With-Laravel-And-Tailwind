<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RequestForgeryProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Laravel bypasses CSRF checks in the testing environment. Exercise the
        // real middleware while retaining the isolated test database and stores.
        $this->app['env'] = 'local';

        Route::middleware('web')->post('/_test/request-forgery', fn () => response()->noContent());
    }

    public function test_cross_site_requests_without_a_token_are_rejected(): void
    {
        $this->withHeader('Sec-Fetch-Site', 'cross-site')
            ->post('/_test/request-forgery')
            ->assertStatus(419);
    }

    public function test_requests_without_origin_metadata_still_require_a_token(): void
    {
        $this->post('/_test/request-forgery')->assertStatus(419);
    }

    public function test_requests_with_a_matching_session_token_are_accepted(): void
    {
        $this->withSession(['_token' => 'upgrade-test-token'])
            ->post('/_test/request-forgery', ['_token' => 'upgrade-test-token'])
            ->assertNoContent();
    }

    public function test_same_origin_requests_are_accepted(): void
    {
        $this->withHeader('Sec-Fetch-Site', 'same-origin')
            ->post('/_test/request-forgery')
            ->assertNoContent();
    }
}
