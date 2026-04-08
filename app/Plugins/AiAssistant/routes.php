<?php

/*
 * routes.php
 *
 * Route definitions for the AI Assistant plugin.
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

use App\Plugins\AiAssistant\Http\AiChatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('plugin/ai')->group(function (): void {
    Route::post('/chat', [AiChatController::class, 'chat'])->name('plugin.ai.chat');
});
