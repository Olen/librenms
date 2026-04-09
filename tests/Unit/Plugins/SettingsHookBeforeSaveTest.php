<?php

/*
 * SettingsHookBeforeSaveTest.php
 *
 * Unit tests for SettingsHook::beforeSave() and
 * PluginManager::applyBeforeSave().
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
 */

namespace LibreNMS\Tests\Unit\Plugins;

use App\Plugins\PluginManager;
use Illuminate\Support\Collection;
use LibreNMS\Tests\TestCase;

final class SettingsHookBeforeSaveTest extends TestCase
{
    /**
     * Build a PluginManager with its hooksFor() method stubbed so tests
     * can inject hook instances directly without touching the database
     * or the real plugin registration path.
     */
    private function managerWithHooks(array $hooks): PluginManager
    {
        $manager = $this->getMockBuilder(PluginManager::class)
            ->onlyMethods(['hooksFor'])
            ->getMock();

        $manager->method('hooksFor')
            ->willReturn(new Collection($hooks));

        return $manager;
    }

    // ──────────────────────────────────────────────
    // Abstract class default
    // ──────────────────────────────────────────────

    public function testAbstractDefaultReturnsIncomingUnchanged(): void
    {
        $hook = new Fixtures\PassthroughSettingsHook();

        $incoming = ['api_url' => 'https://example.com', 'api_key' => ''];
        $current = ['api_url' => 'https://example.com', 'api_key' => 'sk-secret'];

        $this->assertSame($incoming, $hook->beforeSave($incoming, $current));
    }

    public function testPluginCanPreserveSecretWhenFormFieldIsBlank(): void
    {
        $hook = new Fixtures\PreservingSettingsHook();

        $incoming = ['api_url' => 'https://example.com', 'api_key' => ''];
        $current = ['api_url' => 'https://other.com', 'api_key' => 'sk-secret'];

        $result = $hook->beforeSave($incoming, $current);

        $this->assertSame('https://example.com', $result['api_url'], 'non-secret fields follow the incoming form');
        $this->assertSame('sk-secret', $result['api_key'], 'blank secret is replaced with the stored value');
    }

    public function testPluginCanClearSecretByNotOverridingDefault(): void
    {
        // Default pass-through means a blank field DOES clear the stored
        // value — this is the historical behavior and should not regress
        // for plugins that don't opt in to beforeSave().
        $hook = new Fixtures\PassthroughSettingsHook();

        $incoming = ['api_key' => ''];
        $current = ['api_key' => 'sk-secret'];

        $result = $hook->beforeSave($incoming, $current);

        $this->assertSame('', $result['api_key']);
    }

    // ──────────────────────────────────────────────
    // PluginManager::applyBeforeSave dispatch
    // ──────────────────────────────────────────────

    public function testApplyBeforeSaveReturnsIncomingWhenNoHookRegistered(): void
    {
        $manager = $this->managerWithHooks([]);

        $incoming = ['key' => 'new'];
        $current = ['key' => 'old'];

        $this->assertSame($incoming, $manager->applyBeforeSave('SomePlugin', $incoming, $current));
    }

    public function testApplyBeforeSaveDispatchesToAbstractHook(): void
    {
        $hook = new Fixtures\MergingSettingsHook();

        $manager = $this->managerWithHooks([
            ['plugin_name' => 'TestPlugin', 'instance' => $hook],
        ]);

        $result = $manager->applyBeforeSave('TestPlugin', ['key' => 'new'], ['key' => 'old']);

        $this->assertSame([
            'merged' => true,
            'from_incoming' => 'new',
            'from_current' => 'old',
        ], $result);
    }

    public function testApplyBeforeSaveSkipsHooksThatDoNotExtendAbstractClass(): void
    {
        // A plugin that implements the interface directly (without
        // extending the abstract class) has no beforeSave default, so
        // the dispatcher should skip it and return $incoming unchanged.
        $hook = new Fixtures\DirectInterfaceSettingsHook();

        $manager = $this->managerWithHooks([
            ['plugin_name' => 'DirectPlugin', 'instance' => $hook],
        ]);

        $incoming = ['key' => 'new'];
        $current = ['key' => 'old'];

        $this->assertSame($incoming, $manager->applyBeforeSave('DirectPlugin', $incoming, $current));
    }
}
