<?php

namespace App\Plugins\AiAssistant;

use App\Plugins\Hooks\PageHook;

class Page extends PageHook
{
    public string $view = 'resources.views.page';

    public function authorize(\Illuminate\Contracts\Auth\Authenticatable $user): bool
    {
        return $user->can('global-read');
    }

    public function data(): array
    {
        return [
            'title' => 'AI Assistant',
        ];
    }
}
