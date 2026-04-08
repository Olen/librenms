<?php

namespace App\Plugins\AiAssistant\Tools;

abstract class AbstractAiTool implements AiToolInterface
{
    public function toFunctionDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => $this->parameters(),
            ],
        ];
    }
}
