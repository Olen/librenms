<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\User;

interface AiToolInterface
{
    public function name(): string;

    public function description(): string;

    public function parameters(): array;

    public function execute(array $params, ?User $user = null): array;

    public function toFunctionDefinition(): array;
}
