<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Connection;

enum ConnectionStatus: string
{
    case Disconnected = 'disconnected';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case ReauthRequired = 'reauth_required';
    case Failed = 'failed';
    case Disabled = 'disabled';

    /** Whether provider requests may be made with this connection. */
    public function isUsable(): bool
    {
        return $this === self::Connected;
    }
}
