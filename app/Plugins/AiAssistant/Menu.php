<?php

namespace App\Plugins\AiAssistant;

use App\Plugins\Hooks\MenuEntryHook;

class Menu extends MenuEntryHook
{
    public string $view = 'resources.views.menu';

    public function authorize(\Illuminate\Contracts\Auth\Authenticatable $user, array $settings = []): bool
    {
        return $user->can('ai-assistant.chat');
    }

    public function data(array $settings = []): array
    {
        return [];
    }
}
