<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\User;

interface AiToolInterface
{
    public function name(): string;

    public function description(): string;

    public function parameters(): array;

    /**
     * Decide whether this user is allowed to invoke this tool at all.
     *
     * This is the coarse, tool-level gate enforced by LlmService before the
     * tool runs. Per-record authorization (e.g. "can this user see this
     * specific device?") still happens inside execute() via Eloquent
     * hasAccess() scopes and/or policy checks on fetched models.
     *
     * Returning false causes LlmService to send a denied result back to the
     * LLM instead of invoking execute().
     */
    public function authorize(User $user): bool;

    public function execute(array $params, ?User $user = null): array;

    public function toFunctionDefinition(): array;
}
