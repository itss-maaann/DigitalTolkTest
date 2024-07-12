<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DTApi\Repository\UserRepository;
use DTApi\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected $userRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userRepository = new UserRepository(new User);
    }

    public function testCreateUser()
    {
        $request = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'John Doe',
            'company_id' => '',
            'department_id' => '',
            'email' => 'john@example.com',
            'dob_or_orgid' => '1990-01-01',
            'phone' => '1234567890',
            'mobile' => '0987654321',
            'password' => 'password123',
            'consumer_type' => 'paid',
            'customer_type' => 'regular',
            'username' => 'johndoe',
            'post_code' => '12345',
            'address' => '123 Main St',
            'city' => 'Metropolis',
            'town' => 'Metropolis',
            'country' => 'USA',
            'additional_info' => 'Some additional info',
            'status' => '1',
            'new_towns' => 'Gotham',
            'user_language' => [1, 2],
            'user_towns_projects' => [1, 2],
            'translator_ex' => [3, 4]
        ];

        $user = $this->userRepository->createOrUpdate(null, $request);
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
    }

    public function testUpdateUser()
    {
        $existingUser = User::factory()->create();
        $request = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'Jane Doe',
            'company_id' => '',
            'department_id' => '',
            'email' => 'jane@example.com',
            'dob_or_orgid' => '1990-01-01',
            'phone' => '1234567890',
            'mobile' => '0987654321',
            'consumer_type' => 'paid',
            'customer_type' => 'regular',
            'username' => 'janedoe',
            'post_code' => '12345',
            'address' => '123 Main St',
            'city' => 'Metropolis',
            'town' => 'Metropolis',
            'country' => 'USA',
            'additional_info' => 'Some additional info',
            'status' => '1',
            'new_towns' => 'Gotham',
            'user_language' => [1, 2],
            'user_towns_projects' => [1, 2],
            'translator_ex' => [3, 4]
        ];

        $user = $this->userRepository->createOrUpdate($existingUser->id, $request);
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('Jane Doe', $user->name);
        $this->assertEquals('jane@example.com', $user->email);
    }
}