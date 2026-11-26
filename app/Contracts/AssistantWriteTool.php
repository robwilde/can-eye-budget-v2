<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A tool that changes the user's data. Read-only API keys and MCP tokens cannot call it, and a reply that already ran one
 * is never retried from the start.
 */
interface AssistantWriteTool extends AssistantTool {}
