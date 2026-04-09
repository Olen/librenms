<?php

/*
 * MergingSettingsHook.php
 *
 * Test fixture: a SettingsHook whose beforeSave() returns a marker
 * payload built from the incoming and current arrays. Used to verify
 * PluginManager::applyBeforeSave() actually dispatches to the right
 * instance and passes both arguments through.
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

final class MergingSettingsHook extends SettingsHook
{
    public function beforeSave(array $incoming, array $current): array
    {
        return [
            'merged' => true,
            'from_incoming' => $incoming['key'],
            'from_current' => $current['key'],
        ];
    }
}
