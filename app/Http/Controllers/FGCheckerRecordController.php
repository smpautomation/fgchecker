<?php

namespace App\Http\Controllers;

use App\Services\FGCheckerTableService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FGCheckerRecordController extends Controller
{
    protected const COLUMNS = [
        'Shift_Date_Time',
        'Area',
        'Result',
        'Model_Name',
        'Lot_No',
        'BCS_Quantity',
        'Output_Quantity',
        'Output_Factor',
        'Final_Quantity',
        'Encoder',
        'IP_Address',
        'FG_Checker',
    ];

    public function __construct(protected FGCheckerTableService $tables)
    {

    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'model_name' => ['nullable', 'string', 'max:100'],
            'lot_no'     => ['nullable', 'string', 'max:100'],
        ]);

        $table = $this->tables->ensureTableExists();

        $rows = $this->queryRecords(
            $table,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['model_name'] ?? null,
            $validated['lot_no'] ?? null,
        )->get();

        return response()->json(['data' => $rows]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'in:xlsx,csv'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'model_name' => ['nullable', 'string', 'max:100'],
            'lot_no'     => ['nullable', 'string', 'max:100'],
        ]);

        $table = $this->tables->ensureTableExists();
        $rows = $this->queryRecords(
            $table,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['model_name'] ?? null,
            $validated['lot_no'] ?? null,
        )->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray(self::COLUMNS, null, 'A1');
        $sheet->fromArray(
            $rows->map(fn ($row) => collect(self::COLUMNS)->map(fn ($col) => $row->{$col} ?? '')->all())->all(),
            null,
            'A2'
        );

        foreach(range('A', 'L') as $col)
        {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = $table . '_' . now()->format('Ymd_His') . '.' . $validated['format'];

        $writer = $validated['format'] === 'xlsx'
            ? new Xlsx($spreadsheet)
            : new Csv($spreadsheet);

        $contentType = $validated['format'] === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, ['Content-Type' => $contentType]);
    }

    protected function queryRecords(string $table, ?string $from, ?string $to, ?string $modelName = null, ?string $lotNo = null)
    {
        $columns = collect(self::COLUMNS)
            ->map(fn ($col) => "{$table}.{$col}")
            ->push('fgchecker_monitoring_process.Type as Type')
            ->all();

        $query = DB::table($table)
            ->leftJoin(
                'fgchecker_monitoring_process',
                "{$table}.Result",
                '=',
                'fgchecker_monitoring_process.Process'
            )
            ->select($columns);

        if ($from)
        {
            $query->whereDate('Shift_Date', '>=', $from);
        }
        if ($to)
        {
            $query->whereDate('Shift_Date', '<=', $to);
        }
        if ($modelName)
        {
            $query->where("{$table}.Model_Name", 'like', "%{$modelName}%");
        }
        if ($lotNo)
        {
            $query->where("{$table}.Lot_No", 'like', "%{$lotNo}%");
        }


        return $query->orderByDesc('Shift_Date_Time');
    }
}
