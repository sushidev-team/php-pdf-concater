<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_hashes_passwords_and_omits_sensitive_values_from_serialization(): void
    {
        $user = User::factory()->make([
            'email_verified_at' => '2026-10-31 12:00:00',
            'password' => 'example-password',
        ]);

        $this->assertTrue(Hash::check('example-password', $user->password));
        $this->assertInstanceOf(CarbonInterface::class, $user->email_verified_at);
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }
}
