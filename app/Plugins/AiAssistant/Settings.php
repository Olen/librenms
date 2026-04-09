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
        // Never round-trip the API key back into the form — the blade
        // only sees an "api_key_is_set" boolean and renders a status
        // badge. The password input starts empty on every render.
        //
        // IMPORTANT: LibreNMS's core PluginSettingsController::update
        // does a wholesale replace of the settings array, so an empty
        // api_key field on submit currently CLEARS the stored key.
        // Admins must re-enter the key every time they save. The blade
        // template warns about this explicitly. Proper "leave blank to
        // keep current" support requires an upstream core change (a
        // merge-save path or a beforeSave hook on SettingsHook).
        $settings['api_key_is_set'] = ! empty($settings['api_key']);
        unset($settings['api_key']);

        return ['settings' => $settings];
    }
}
