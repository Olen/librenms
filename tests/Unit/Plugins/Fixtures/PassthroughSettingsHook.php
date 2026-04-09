<?php

/*
 * PassthroughSettingsHook.php
 *
 * Test fixture: a SettingsHook that relies entirely on the abstract
 * class's default beforeSave() pass-through behavior.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @link       https://www.librenms.org
 */

namespace LibreNMS\Tests\Unit\Plugins\Fixtures;

use App\Plugins\Hooks\SettingsHook;

final class PassthroughSettingsHook extends SettingsHook
{
}
