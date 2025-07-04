<?php

namespace App\Events;

use App\Models\ItunesTradeAccountLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TradeLogCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ItunesTradeAccountLog $log;

    /**
     * Create a new event instance.
     */
    public function __construct(ItunesTradeAccountLog $log)
    {
        $this->log = $log;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('trade-monitor'),
        ];
    }
} 