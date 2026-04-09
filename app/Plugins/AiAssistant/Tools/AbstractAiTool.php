<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Models\User;

abstract class AbstractAiTool implements AiToolInterface
{
    /**
     * The Eloquent model class this tool primarily reads from.
     *
     * Tools that target a single model with a matching Laravel Policy can
     * just set this property and inherit the default authorize() below,
     * which delegates to $user->can('viewAny', $authorizedModel).
     *
     * Tools that touch multiple models, or access data without a Policy
     * (e.g. syslog, eventlog, RRD files), must override authorize() directly.
     *
     * @var class-string|null
     */
    protected ?string $authorizedModel = null;

    public function authorize(User $user): bool
    {
        // Fail closed: a tool that neither sets $authorizedModel nor overrides
        // authorize() is refused. This prevents new tools from silently
        // inheriting unlimited access just because their author forgot to
        // declare an authorization target.
        if ($this->authorizedModel === null) {
            return false;
        }

        return $user->can('viewAny', $this->authorizedModel);
    }

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
