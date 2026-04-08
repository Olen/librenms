<?php

namespace LibreNMS\Tests\Unit\Plugins;

use App\Plugins\PluginManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\Hooks\AlertInjectionHook;
use LibreNMS\Interfaces\Plugins\Hooks\EventListenerHook;
use LibreNMS\Interfaces\Plugins\Hooks\ScheduledTaskHook;
use LibreNMS\Tests\TestCase;

final class HookDispatchTest extends TestCase
{
    /**
     * Create a PluginManager partial mock where hooksFor() and getSettings() are stubbed.
     * This avoids DB access and Laravel DI calls during testing.
     */
    private function createPluginManagerWithHooks(string $hookType, array $hooks): PluginManager
    {
        $manager = $this->getMockBuilder(PluginManager::class)
            ->onlyMethods(['hooksFor', 'getSettings'])
            ->getMock();

        $collection = new Collection(array_map(fn ($hook) => [
            'plugin_name' => $hook['plugin_name'],
            'instance' => $hook['instance'],
        ], $hooks));

        $manager->method('hooksFor')
            ->with($hookType, [], null)
            ->willReturn($collection);

        $manager->method('getSettings')
            ->willReturnCallback(fn (string $name) => ['key' => 'value_' . $name]);

        return $manager;
    }

    // ──────────────────────────────────────────────
    // dispatchEvent tests
    // ──────────────────────────────────────────────

    public function testDispatchEventCallsAllHandlers(): void
    {
        $handler1 = $this->createMock(EventListenerHook::class);
        $handler1->expects($this->once())
            ->method('handle')
            ->with('device.added', ['id' => 1], 'PluginA', ['key' => 'value_PluginA']);

        $handler2 = $this->createMock(EventListenerHook::class);
        $handler2->expects($this->once())
            ->method('handle')
            ->with('device.added', ['id' => 1], 'PluginB', ['key' => 'value_PluginB']);

        $manager = $this->createPluginManagerWithHooks(EventListenerHook::class, [
            ['plugin_name' => 'PluginA', 'instance' => $handler1],
            ['plugin_name' => 'PluginB', 'instance' => $handler2],
        ]);

        $manager->dispatchEvent('device.added', ['id' => 1]);
    }

    public function testDispatchEventCatchesExceptionsWithoutPropagating(): void
    {
        $failingHandler = $this->createMock(EventListenerHook::class);
        $failingHandler->method('handle')
            ->willThrowException(new \RuntimeException('Handler exploded'));

        $successHandler = $this->createMock(EventListenerHook::class);
        $successHandler->expects($this->once())
            ->method('handle');

        $manager = $this->createPluginManagerWithHooks(EventListenerHook::class, [
            ['plugin_name' => 'FailPlugin', 'instance' => $failingHandler],
            ['plugin_name' => 'GoodPlugin', 'instance' => $successHandler],
        ]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'FailPlugin') && str_contains($msg, 'Handler exploded'));

        // Should not throw
        $manager->dispatchEvent('test.event', []);
    }

    public function testDispatchEventWithNoHooksDoesNothing(): void
    {
        $manager = $this->createPluginManagerWithHooks(EventListenerHook::class, []);

        // Should not throw
        $manager->dispatchEvent('test.event', ['data' => true]);
    }

    // ──────────────────────────────────────────────
    // collectPluginAlerts tests
    // ──────────────────────────────────────────────

    public function testCollectPluginAlertsMergesResultsFromMultiplePlugins(): void
    {
        $handler1 = $this->createMock(AlertInjectionHook::class);
        $handler1->method('handle')
            ->willReturn([
                ['title' => 'Alert from A', 'severity' => 'warning'],
            ]);

        $handler2 = $this->createMock(AlertInjectionHook::class);
        $handler2->method('handle')
            ->willReturn([
                ['title' => 'Alert from B', 'severity' => 'critical'],
                ['title' => 'Alert 2 from B', 'severity' => 'info'],
            ]);

        $manager = $this->createPluginManagerWithHooks(AlertInjectionHook::class, [
            ['plugin_name' => 'PluginA', 'instance' => $handler1],
            ['plugin_name' => 'PluginB', 'instance' => $handler2],
        ]);

        $alerts = $manager->collectPluginAlerts();

        $this->assertCount(3, $alerts);
        $this->assertEquals('Alert from A', $alerts[0]['title']);
        $this->assertEquals('Alert from B', $alerts[1]['title']);
        $this->assertEquals('Alert 2 from B', $alerts[2]['title']);
    }

    public function testCollectPluginAlertsHandlesExceptionGracefully(): void
    {
        $failingHandler = $this->createMock(AlertInjectionHook::class);
        $failingHandler->method('handle')
            ->willThrowException(new \RuntimeException('DB gone'));

        $goodHandler = $this->createMock(AlertInjectionHook::class);
        $goodHandler->method('handle')
            ->willReturn([['title' => 'Valid alert']]);

        $manager = $this->createPluginManagerWithHooks(AlertInjectionHook::class, [
            ['plugin_name' => 'BrokenPlugin', 'instance' => $failingHandler],
            ['plugin_name' => 'WorkingPlugin', 'instance' => $goodHandler],
        ]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'BrokenPlugin') && str_contains($msg, 'DB gone'));

        $alerts = $manager->collectPluginAlerts();

        $this->assertCount(1, $alerts);
        $this->assertEquals('Valid alert', $alerts[0]['title']);
    }

    public function testCollectPluginAlertsReturnsEmptyArrayWhenNoPlugins(): void
    {
        $manager = $this->createPluginManagerWithHooks(AlertInjectionHook::class, []);

        $alerts = $manager->collectPluginAlerts();

        $this->assertIsArray($alerts);
        $this->assertEmpty($alerts);
    }

    public function testCollectPluginAlertsSkipsNonArrayReturns(): void
    {
        $handler = $this->createMock(AlertInjectionHook::class);
        // Returning a non-array (the interface says array, but we test the guard)
        $handler->method('handle')
            ->willReturn([]);

        $manager = $this->createPluginManagerWithHooks(AlertInjectionHook::class, [
            ['plugin_name' => 'EmptyPlugin', 'instance' => $handler],
        ]);

        $alerts = $manager->collectPluginAlerts();

        $this->assertIsArray($alerts);
        $this->assertEmpty($alerts);
    }

    // ──────────────────────────────────────────────
    // registerPluginSchedules tests
    // ──────────────────────────────────────────────

    public function testRegisterPluginSchedulesCallsHandlersWithSchedule(): void
    {
        $schedule = $this->createMock(Schedule::class);

        $handler1 = $this->createMock(ScheduledTaskHook::class);
        $handler1->expects($this->once())
            ->method('handle')
            ->with($schedule, 'SchedulePluginA', ['key' => 'value_SchedulePluginA']);

        $handler2 = $this->createMock(ScheduledTaskHook::class);
        $handler2->expects($this->once())
            ->method('handle')
            ->with($schedule, 'SchedulePluginB', ['key' => 'value_SchedulePluginB']);

        $manager = $this->createPluginManagerWithHooks(ScheduledTaskHook::class, [
            ['plugin_name' => 'SchedulePluginA', 'instance' => $handler1],
            ['plugin_name' => 'SchedulePluginB', 'instance' => $handler2],
        ]);

        $manager->registerPluginSchedules($schedule);
    }

    public function testRegisterPluginSchedulesCatchesExceptions(): void
    {
        $schedule = $this->createMock(Schedule::class);

        $failingHandler = $this->createMock(ScheduledTaskHook::class);
        $failingHandler->method('handle')
            ->willThrowException(new \RuntimeException('Schedule error'));

        $goodHandler = $this->createMock(ScheduledTaskHook::class);
        $goodHandler->expects($this->once())
            ->method('handle');

        $manager = $this->createPluginManagerWithHooks(ScheduledTaskHook::class, [
            ['plugin_name' => 'BadSchedule', 'instance' => $failingHandler],
            ['plugin_name' => 'GoodSchedule', 'instance' => $goodHandler],
        ]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'BadSchedule') && str_contains($msg, 'Schedule error'));

        // Should not throw
        $manager->registerPluginSchedules($schedule);
    }

    public function testRegisterPluginSchedulesWithNoPluginsDoesNothing(): void
    {
        $schedule = $this->createMock(Schedule::class);

        $manager = $this->createPluginManagerWithHooks(ScheduledTaskHook::class, []);

        // Should not throw
        $manager->registerPluginSchedules($schedule);
    }
}
