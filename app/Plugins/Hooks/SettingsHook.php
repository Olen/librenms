<?php

/*
 * SettingsHook.php
 *
 * -Description-
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS
 * @link       http://librenms.org
 * @copyright  2021 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Plugins\Hooks;

use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;

abstract class SettingsHook implements \LibreNMS\Interfaces\Plugins\Hooks\SettingsHook
{
    public string $view = 'resources.views.settings';

    public function authorize(User $user): bool
    {
        return true;
    }

    public function data(array $settings): array
    {
        return [
            'settings' => $settings,
        ];
    }

    /**
     * Transform submitted settings before they are persisted.
     *
     * Called by PluginSettingsController with the settings submitted from
     * the form and the settings currently stored for the plugin. The
     * returned array is what actually gets written to the database.
     *
     * Useful for preserving fields the form intentionally omits — most
     * commonly secrets (API keys, passwords, OAuth tokens) that should
     * not round-trip through the rendered HTML but also should not be
     * cleared when the admin saves unrelated changes without re-entering
     * them.
     *
     * The default implementation is a pass-through so existing plugins
     * keep their historical "wholesale replace" save behavior.
     *
     * @param  array  $incoming  settings submitted via the form
     * @param  array  $current  settings currently stored for this plugin
     * @return array settings to persist
     */
    public function beforeSave(array $incoming, array $current): array
    {
        return $incoming;
    }

    final public function handle(string $pluginName, array $settings, Application $app): array
    {
        return array_merge([
            'content_view' => Str::start($this->view, "$pluginName::"),
        ], $this->data($app->call($this->data(...), [
            'settings' => $settings,
        ])));
    }
}
