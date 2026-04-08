<?php

/*
 * ContextBuilderTest.php
 *
 * Unit tests for the AI assistant ContextBuilder service.
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

namespace LibreNMS\Tests\Unit\AiAssistant;

use App\Plugins\AiAssistant\Services\ContextBuilder;
use LibreNMS\Tests\TestCase;

class ContextBuilderTest extends TestCase
{
    public function testBuildContextSnapshotReturnsString(): void
    {
        $builder = new ContextBuilder();
        $snapshot = $builder->buildContextSnapshot();

        $this->assertIsString($snapshot);
    }

    public function testBuildContextSnapshotContainsDevices(): void
    {
        $builder = new ContextBuilder();
        $snapshot = $builder->buildContextSnapshot();

        $this->assertStringContainsString('devices', $snapshot);
    }

    public function testBuildContextSnapshotContainsAlerts(): void
    {
        $builder = new ContextBuilder();
        $snapshot = $builder->buildContextSnapshot();

        $this->assertStringContainsString('Active Alerts', $snapshot);
    }

    public function testBuildContextSnapshotContainsPorts(): void
    {
        $builder = new ContextBuilder();
        $snapshot = $builder->buildContextSnapshot();

        $this->assertStringContainsString('Ports', $snapshot);
    }

    public function testBuildContextSnapshotContainsCurrentTime(): void
    {
        $builder = new ContextBuilder();
        $snapshot = $builder->buildContextSnapshot();

        $this->assertStringContainsString('Current time', $snapshot);
    }

    public function testBuildContextSnapshotContainsEvents(): void
    {
        $builder = new ContextBuilder();
        $snapshot = $builder->buildContextSnapshot();

        $this->assertStringContainsString('events', $snapshot);
    }
}
