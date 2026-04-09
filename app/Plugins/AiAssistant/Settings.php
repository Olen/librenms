<?php

/*
 * Settings.php
 *
 * Settings hook for the AI Assistant plugin.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 LibreNMS
 * @author     LibreNMS Contributors
 */

namespace App\Plugins\AiAssistant;

use App\Models\User;
use App\Plugins\Hooks\SettingsHook;

class Settings extends SettingsHook
{
    public string $view = 'resources.views.settings';

    /**
     * Only administrators may view or modify the AI Assistant settings.
     * The form contains the LLM provider API key, so access must be
     * restricted — the base SettingsHook::authorize() returns true for
     * every authenticated user, which would leak the key.
     */
    public function authorize(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function data(array $settings = []): array
    {
        // Never round-trip the API key back into the form. The view shows a
        // placeholder so admins can tell whether a key is already stored,
        // and submitting a blank value leaves the stored key untouched
        // (handled in the controller that persists settings).
        $settings['api_key_is_set'] = ! empty($settings['api_key']);
        unset($settings['api_key']);

        return ['settings' => $settings];
    }
}
