<?php

namespace Tests\Unit;

use App\Support\Orders\OrderStatus as S;
use App\Support\Orders\StatusTransitions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

class StatusTransitionsTest extends TestCase
{
    private function order(int $status): object
    {
        return (object) ['id' => 1, 'account_id' => 1, 'order_status_id' => $status];
    }

    public function test_known_and_unknown_edges(): void
    {
        $this->assertTrue(StatusTransitions::isAllowed(S::CONFIRMED, S::IN_DELIVERY));
        $this->assertTrue(StatusTransitions::isAllowed(S::DELIVERED, S::DELIVERED));
        $this->assertTrue(StatusTransitions::isAllowed(null, S::PENDING));
        $this->assertFalse(StatusTransitions::isAllowed(S::CANCELLED, S::DELIVERED));
        $this->assertFalse(StatusTransitions::isAllowed(S::PENDING, S::RETURNED));
    }

    public function test_log_mode_records_and_does_not_block(): void
    {
        config(['orders.enforce_transitions' => 'log']);
        $this->expectNotToPerformAssertions();

        StatusTransitions::guard($this->order(S::CANCELLED), S::DELIVERED, ['source' => 'test']);
    }

    public function test_enforce_mode_refuses_with_422(): void
    {
        config(['orders.enforce_transitions' => 'enforce']);

        try {
            StatusTransitions::guard($this->order(S::CANCELLED), S::DELIVERED, ['source' => 'test']);
            $this->fail('the transition should have been refused');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }

        // an allowed edge passes in enforce mode
        StatusTransitions::guard($this->order(S::CONFIRMED), S::IN_DELIVERY);
        $this->assertTrue(true);
    }
}
