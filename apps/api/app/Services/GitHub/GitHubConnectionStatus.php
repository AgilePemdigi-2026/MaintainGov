<?php

namespace App\Services\GitHub;

enum GitHubConnectionStatus: string
{
    case Connected = 'connected';
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case RateLimited = 'rate_limited';
    case NotFound = 'not_found';
    case Unavailable = 'unavailable';
}
