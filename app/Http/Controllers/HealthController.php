<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'disk' => $this->checkDisk(),
        ];

        $databaseOk = $checks['database']['status'] === 'ok';
        $cacheOk = $checks['cache']['status'] === 'ok';
        $diskOk = $checks['disk']['status'] === 'ok';

        if (! $databaseOk || ! $cacheOk) {
            $status = 'unhealthy';
            $httpStatus = 503;
        } elseif (! $diskOk) {
            $status = 'degraded';
            $httpStatus = 200;
        } else {
            $status = 'ok';
            $httpStatus = 200;
        }

        return response()->json([
            'status' => $status,
            'version' => config('app.version', env('APP_VERSION', 'unknown')),
            'checks' => $checks,
        ], $httpStatus);
    }

    /**
     * @return array{status: string, error?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $this->shortError($e)];
        }
    }

    /**
     * @return array{status: string, error?: string}
     */
    private function checkCache(): array
    {
        try {
            $key = 'health-check:'.Str::uuid()->toString();

            Cache::put($key, 'ok', 60);

            $value = Cache::get($key);

            Cache::forget($key);

            if ($value !== 'ok') {
                return ['status' => 'fail', 'error' => 'cache read/write mismatch'];
            }

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $this->shortError($e)];
        }
    }

    /**
     * @return array{status: string, error?: string}
     */
    private function checkDisk(): array
    {
        $path = 'health-checks/'.Str::uuid()->toString().'.tmp';

        try {
            Storage::disk('local')->put($path, 'ok');

            if (Storage::disk('local')->get($path) !== 'ok') {
                return ['status' => 'fail', 'error' => 'disk read/write mismatch'];
            }

            Storage::disk('local')->delete($path);

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            try {
                Storage::disk('local')->delete($path);
            } catch (Throwable) {
                // ignore cleanup failures
            }

            return ['status' => 'fail', 'error' => $this->shortError($e)];
        }
    }

    private function shortError(Throwable $e): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');

        if ($message === '') {
            $message = $e::class;
        }

        return Str::limit($message, 120);
    }
}
