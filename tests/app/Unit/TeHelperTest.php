<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DTApi\Helpers\TeHelper;
use Carbon\Carbon;

class TeHelperTest extends TestCase
{
    public function testWillExpireAtWithin90Minutes()
    {
        $dueTime = Carbon::now()->addMinutes(30)->format('Y-m-d H:i:s');
        $createdAt = Carbon::now()->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($dueTime, $createdAt);
        $this->assertEquals($dueTime, $result);
    }

    public function testWillExpireAtWithin24Hours()
    {
        $dueTime = Carbon::now()->addHours(20)->format('Y-m-d H:i:s');
        $createdAt = Carbon::now()->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($dueTime, $createdAt);
        $expected = Carbon::now()->addMinutes(90)->format('Y-m-d H:i:s');
        $this->assertEquals($expected, $result);
    }

    public function testWillExpireAtWithin72Hours()
    {
        $dueTime = Carbon::now()->addHours(48)->format('Y-m-d H:i:s');
        $createdAt = Carbon::now()->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($dueTime, $createdAt);
        $expected = Carbon::now()->addHours(16)->format('Y-m-d H:i:s');
        $this->assertEquals($expected, $result);
    }

    public function testWillExpireAtMoreThan72Hours()
    {
        $dueTime = Carbon::now()->addHours(80)->format('Y-m-d H:i:s');
        $createdAt = Carbon::now()->format('Y-m-d H:i:s');
        $result = TeHelper::willExpireAt($dueTime, $createdAt);
        $expected = Carbon::now()->addHours(32)->format('Y-m-d H:i:s'); // due - 48 hours
        $this->assertEquals($expected, $result);
    }
}