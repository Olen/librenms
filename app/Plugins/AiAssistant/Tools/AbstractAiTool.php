<?php

namespace App\Plugins\AiAssistant\Tools;

use App\Facades\Permissions;
use App\Models\User;

abstract class AbstractAiTool implements AiToolInterface
{
    /**
     * The Eloquent model class this tool primarily reads from.
     *
     * Tools that target a single model with a matching Laravel Policy can
     * just set this property and inherit the default authorize() below.
     *
     * Tools that touch multiple models, or access data without a Policy
     * (e.g. syslog, eventlog, RRD files), must override authorize() directly.
     *
     * @var class-string|null
     */
    protected ?string $authorizedModel = null;

    /**
     * Default authorization: pass if the user can view any records of the
     * tool's target model, OR if they have explicit per-record assignments.
     *
     * The second leg matters for LibreNMS's "user" role: those users have
     * no global `*.viewAny` permission and rely entirely on device / port
     * assignments via `Manage Access`. Each tool's internal `hasAccess()`
     * scope already restricts returned rows to those assignments, so
     * letting them through the coarse gate is safe — they only see what
     * they were explicitly granted.
     *
     * Fail-closed semantics are preserved for tools that neither declare
     * $authorizedModel nor override authorize(): those return false and
     * prevent new tools from silently inheriting open access if their
     * author forgot to think about permissions.
     */
    public function authorize(User $user): bool
    {
        if ($this->authorizedModel === null) {
            return false;
        }

        if ($user->can('viewAny', $this->authorizedModel)) {
            return true;
        }

        // Fallback: users with explicit resource assignments (but no
        // viewAny) are still allowed to invoke the tool — the tool's
        // hasAccess() scope will filter the query down to just those
        // resources. A user with zero assignments is still blocked,
        // which saves LLM budget on guaranteed-empty tool calls.
        return $this->hasAnyResourceAccess($user);
    }

    /**
     * Whether the user has any explicitly assigned device, device-group,
     * or port — sufficient to justify running a scoped tool.
     */
    protected function hasAnyResourceAccess(User $user): bool
    {
        return Permissions::devicesForUser($user)->isNotEmpty()
            || Permissions::portsForUser($user)->isNotEmpty();
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
