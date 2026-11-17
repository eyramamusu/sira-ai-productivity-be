<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the scheduler (`schedule:run`) for hosts without cron, such as Render's free tier.
 * An outside service (e.g. cron-job.org) calls it every few minutes with the shared secret,
 * which also keeps a sleeping free instance awake. Disabled (404) while SIRA_CRON_SECRET is empty.
 */
class CronController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        $secret = (string) config('sira.cron_secret');
        abort_if($secret === '', 404);
        abort_unless(hash_equals($secret, (string) $request->header('X-Cron-Secret')), 403);

        $exitCode = Artisan::call('schedule:run');

        return response()->json(['ran' => $exitCode === 0, 'output' => trim(Artisan::output())], $exitCode === 0 ? 200 : 500);
    }
}
