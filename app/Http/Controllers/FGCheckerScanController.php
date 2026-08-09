<?php

namespace App\Http\Controllers;

use App\Services\FGCheckerTableService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class FGCheckerScanController extends Controller
{
    public function __construct(protected FGCheckerTableService $tables)
    {
    }

    public function processes(): JsonResponse
    {
        $processes = DB::table('fgchecker_monitoring_process')
            ->select('Process', 'Type')
            ->orderBy('No')
            ->get();

        return response()->json(['data' => $processes]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_name'    => ['required', 'string', 'max:100'],
            'lot_no'        => ['required', 'string', 'max:100'],
            'bcs_quantity'  => ['required', 'numeric'],
            'encoder'       => ['required', 'string', 'max:100'],
            'result'        => [
                'required',
                'string',
                'exists:fgchecker_monitoring_process,Process',
            ],
        ]);

        $table = $this->tables->ensureTableExists();
        $ip = $request->ip();
        $area = $this->resolveArea($ip);
        $now = now();

        DB::table($table)->insert([
            'Shift_Date_Time' => $now,
            'Shift_Date'      => $this->shiftDate(Carbon::now()),
            'Area'            => $area,
            'Result'          => $validated['result'],
            'Model_Name'      => $validated['model_name'],
            'Lot_No'          => $validated['lot_no'],
            'BCS_Quantity'    => $validated['bcs_quantity'],
            'Output_Quantity' => 1,
            'Output_Factor'   => 1,
            'Final_Quantity'  => 1,
            'Encoder'         => $validated['encoder'],
            'IP_Address'      => $ip,
        ]);

        return response()->json(['message' => 'Recorded.'], 201);
    }

    protected function shiftDate(Carbon $now): string
    {
        return $now->hour < 6
            ? $now->copy()->subDay()->toDateString()
            : $now->toDateString();
    }

    protected function resolveArea(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        return DB::table('ipaddress')
            ->where('IP_Address', $ip)
            ->value('Area');
    }
}
