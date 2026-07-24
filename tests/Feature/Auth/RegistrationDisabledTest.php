<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class RegistrationDisabledTest extends TestCase
{
    public function test_public_registration_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }
}
