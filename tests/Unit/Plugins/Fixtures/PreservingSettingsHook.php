<?php

/*
 * PreservingSettingsHook.php
 *
 * Test fixture: a SettingsHook that preserves the stored api_key when
 * the form submits it blank. Demonstrates the canonical "don't clear
 * the secret if the admin didn't touch the field" use case.
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

final class PreservingSettingsHook extends SettingsHook
{
    public function beforeSave(array $incoming, array $current): array
    {
        if (empty($incoming['api_key']) && ! empty($current['api_key'])) {
            $incoming['api_key'] = $current['api_key'];
        }

        return $incoming;
    }
}
