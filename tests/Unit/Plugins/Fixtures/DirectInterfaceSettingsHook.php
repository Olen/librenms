<?php

/*
 * DirectInterfaceSettingsHook.php
 *
 * Test fixture: a plugin that implements the SettingsHook interface
 * directly (i.e. without extending the abstract class). Used to
 * verify that PluginManager::applyBeforeSave() skips hooks that have
 * no beforeSave() method rather than throwing a method-not-found
 * error.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @link       https://www.librenms.org
 */

namespace LibreNMS\Tests\Unit\Plugins\Fixtures;

use App\Models\User;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

final class DirectInterfaceSettingsHook implements SettingsHook
{
    public function authorize(User $user): bool
    {
        return true;
    }
}
