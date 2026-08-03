<?php

namespace App\Http\Controllers;

use App\Services\FgCheckerTableService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class FgCheckerScanController extends Controller
{
    public function __construct(protected FgCheckerTableService $tables)
    {
    }

    /**
     * GET /fgchecker/processes
     * Powers the result buttons — never hardcode this list client-side,
     * it must always reflect fgchecker_monitoring_process.
     */
    public function processes(): JsonResponse
    {
        $processes = DB::table('fgchecker_monitoring_process')
            ->select('Process', 'Type')
            ->orderBy('No')
            ->get();

        return response()->json(['data' => $processes]);
    }

    /**
     * POST /fgchecker/scan
     * Records one unit's result against the current-year table.
     */
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

    /**
     * Shift boundary runs 6:00 AM to 6:00 AM the next day, so anything
     * before 6 AM belongs to the *previous* calendar day's shift.
     */
    protected function shiftDate(\Illuminate\Support\Carbon $now): string
    {
        return $now->hour < 6
            ? $now->copy()->subDay()->toDateString()
            : $now->toDateString();
    }

    /**
     * Looks up which Area this tablet belongs to via its IP, from the
     * ip_address table (IP_Address primary key -> Area).
     */
    protected function resolveArea(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        return DB::table('ip_address')
            ->where('IP_Address', $ip)
            ->value('Area');
    }
}
