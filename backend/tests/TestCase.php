<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests that simulate Sanctum's stateful SPA requests must send
        // the session's CSRF token, just as the browser client does.
        $csrfToken = 'testing-csrf-token';
        $this->withSession(['_token' => $csrfToken]);
        $this->withHeader('X-CSRF-TOKEN', $csrfToken);
    }
}
