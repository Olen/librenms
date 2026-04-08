<?php

/*
 * ChatEndpointTest.php
 *
 * Feature tests for the AI assistant chat endpoint controller.
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

namespace LibreNMS\Tests\Feature\AiAssistant;

use App\Models\User;
use App\Plugins\AiAssistant\Http\AiChatController;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Tests\TestCase;
use Mockery;

class ChatEndpointTest extends TestCase
{
    /**
     * Test that the chat endpoint returns 401 when no user is authenticated.
     */
    public function testChatEndpointRequiresAuthentication(): void
    {
        $controller = new AiChatController();

        $request = Request::create('/plugin/ai/chat', 'POST', [
            'message' => 'Hello',
        ]);
        $request->headers->set('Accept', 'application/json');
        // No user set on the request

        $response = $controller->chat($request);

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Authentication required', $data['error']);
    }

    /**
     * Test that the chat endpoint validates the message field is required.
     */
    public function testChatEndpointRequiresMessageField(): void
    {
        $controller = new AiChatController();
        $user = User::factory()->make(['user_id' => 1]);

        $request = Request::create('/plugin/ai/chat', 'POST', []);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $controller->chat($request);
    }

    /**
     * Test that the chat endpoint returns 503 when not configured (no API key).
     */
    public function testChatEndpointReturnsErrorWhenNotConfigured(): void
    {
        $controller = new AiChatController();
        $user = User::factory()->make(['user_id' => 1]);

        // Mock the PluginManager to return empty settings (no API key)
        $pluginManager = Mockery::mock(PluginManagerInterface::class);
        $pluginManager->shouldReceive('getSettings')
            ->with('AiAssistant')
            ->andReturn([]);
        $this->app->instance(PluginManagerInterface::class, $pluginManager);

        $request = Request::create('/plugin/ai/chat', 'POST', [
            'message' => 'Hello',
        ]);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $user);

        $response = $controller->chat($request);

        $this->assertEquals(503, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('AI Assistant is not configured. Set an API key in settings.', $data['error']);
    }
}
