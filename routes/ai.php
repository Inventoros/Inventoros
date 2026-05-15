<?php

declare(strict_types=1);

use App\Mcp\Servers\InventorosServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Routes
|--------------------------------------------------------------------------
|
| The Inventoros MCP server is exposed over HTTP at /mcp. Every request must
| present a Sanctum bearer token (Authorization: Bearer ...). The token's
| user provides the organization scope and permissions used by every tool.
|
| Rate limit reuses the existing 'api' throttle (60/min/user) so MCP calls
| share quota with the REST API instead of bypassing it.
|
*/

Mcp::web('/mcp', InventorosServer::class)
    ->middleware(['auth:sanctum', 'throttle:api']);
