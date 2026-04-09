<?php

namespace App\Http\Controllers;

use App\Models\Plugin;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Util\Notifications;

class PluginSettingsController extends Controller
{
    public function __invoke(PluginManagerInterface $manager, Plugin $plugin): \Illuminate\Contracts\View\View
    {
        if (! $manager->pluginEnabled($plugin->plugin_name)) {
            abort(404, trans('plugins.errors.disabled', ['plugin' => $plugin->plugin_name]));
        }

        $data = array_merge([
            // fallbacks to prevent exceptions
            'title' => trans('plugins.settings_page', ['plugin' => $plugin->plugin_name]),
            'plugin_name' => $plugin->plugin_name,
            'plugin_id' => Plugin::where('plugin_name', $plugin->plugin_name)->value('plugin_id'),
            'content_view' => 'plugins.missing',
            'settings' => [],
        ],
            $manager->call(SettingsHook::class, [], $plugin->plugin_name)[0] ?? []
        );

        return view('plugins.settings', $data);
    }

    public function update(Request $request, \App\Plugins\PluginManager $manager, Plugin $plugin): \Illuminate\Http\RedirectResponse
    {
        $validated = $this->validate($request, [
            'plugin_active' => 'in:0,1',
            'settings' => 'array',
        ]);

        if (isset($validated['settings'])) {
            // Let the plugin's SettingsHook transform the submitted
            // settings (e.g. preserve secrets the form intentionally
            // omits) before the wholesale replace below.
            $validated['settings'] = $manager->applyBeforeSave(
                $plugin->plugin_name,
                $validated['settings'],
                (array) $plugin->settings,
            );
        }

        $plugin->fill($validated);

        if ($plugin->isDirty('plugin_active') && $plugin->plugin_active == 1) {
            // enabling plugin delete notifications assuming they are fixed
            Notifications::remove("Plugin $plugin->plugin_name disabled");
        }

        $plugin->save();

        return redirect()->back();
    }
}
