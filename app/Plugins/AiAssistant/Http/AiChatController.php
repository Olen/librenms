<?php

/*
 * AiChatController.php
 *
 * HTTP controller for the AI assistant chat endpoint.
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

namespace App\Plugins\AiAssistant\Http;

use App\Http\Controllers\Controller;
use App\Plugins\AiAssistant\Services\AiServiceFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

class AiChatController extends Controller
{
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'session_id' => 'nullable|string|max:64',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        $settings = app(PluginManagerInterface::class)
            ->getSettings('AiAssistant');

        if (! AiServiceFactory::isConfigured($settings)) {
            return response()->json(['error' => 'AI Assistant is not configured. Set an API key in settings.'], 503);
        }

        $services = AiServiceFactory::fromSettings($settings);
        /** @var \App\Plugins\AiAssistant\Services\ConversationManager $manager */
        $manager = $services['conversation_manager'];

        $sessionId = $request->input('session_id', 'web-' . $user->user_id . '-' . uniqid());

        try {
            $response = $manager->handleMessage(
                $request->input('message'),
                $sessionId,
                $user,
                'web',
            );

            return response()->json([
                'response' => $response,
                'session_id' => $sessionId,
            ]);
        } catch (\Throwable $e) {
            Log::error('AI chat error: ' . $e->getMessage());

            return response()->json([
                'error' => 'An error occurred while processing your request. Please try again.',
                'session_id' => $sessionId,
            ], 500);
        }
    }
}
