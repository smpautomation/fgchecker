<?php

namespace App\Http\Controllers;

use App\Models\TabletRecord;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FGCheckerPrintController extends Controller
{
    public function models(): JsonResponse
    {
        $models = DB::table('model_list')
            ->distinct()
            ->orderBy('Model_Name')
            ->pluck('Model_Name');

        return response()->json(['data' => $models]);
    }

    /** GET /validations — powers the Validation Type dropdown */
    public function validationTypes(): JsonResponse
    {
        $types = DB::table('validation_lists')
            ->orderBy('ID')
            ->pluck('Validation_Name');

        return response()->json(['data' => $types]);
    }

    /**
     * POST /print/validation-sticker
     * Prints a single validation sticker: QR content "VALIDATION;MODEL;TYPE;1;VALIDATION",
     * plus human-readable Sample Type / Model Name / Result text, sized by result.
     * Ports the SBPL sequence from the legacy print_validation_sticker_qr.php exactly.
     */
    public function printValidationSticker(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_name'      => ['required', 'string', 'max:100'],
            'validation_name' => ['required', 'string', 'max:100'],
            'tablet_id'       => ['required', 'string', 'max:100'],
        ]);

        $tablet = $this->resolveTablet($validated['tablet_id']);
        if (! $tablet) {
            return response()->json([
                'message' => 'This tablet is not registered to a printer. Please contact PIC.',
            ], 422);
        }

        $model = strtoupper(trim($validated['model_name']));
        $type  = strtoupper(trim($validated['validation_name']));

        $xQRCode = "VALIDATION;{$model};{$type};1;VALIDATION";
        $breakQR = explode(';', $xQRCode);

        $offsetH = (int) $tablet->Horizontal_Offset;
        $offsetV = (int) $tablet->Vertical_Offset;
        $esc = chr(27);

        $data = $esc . 'A';
        $data .= $esc . 'A3H1374V0001';

        $data .= $esc . 'H' . sprintf('%04d', $offsetH) . $esc . 'V' . sprintf('%04d', $offsetV) . $esc . 'P2' . $esc . 'L0102' . $esc . 'SSample Type:';
        $data .= $esc . 'H' . sprintf('%04d', $offsetH) . $esc . 'V' . sprintf('%04d', $offsetV + 40) . $esc . 'P2' . $esc . 'L0203' . $esc . 'S' . $breakQR[0];
        $data .= $esc . 'H' . sprintf('%04d', $offsetH) . $esc . 'V' . sprintf('%04d', $offsetV + 90) . $esc . 'P2' . $esc . 'L0102' . $esc . 'SModel Name:';
        $data .= $esc . 'H' . sprintf('%04d', $offsetH) . $esc . 'V' . sprintf('%04d', $offsetV + 130) . $esc . 'P2' . $esc . 'L0203' . $esc . 'S' . $breakQR[1];
        $data .= $esc . 'H' . sprintf('%04d', $offsetH) . $esc . 'V' . sprintf('%04d', $offsetV + 180) . $esc . 'P2' . $esc . 'L0102' . $esc . 'SValidation Sample Result:';

        // Label size varies by result — GOOD gets the largest font, WRONG ORIENTATION a
        // medium one, anything else the default. Mirrors the original branching exactly.
        if ($breakQR[2] === 'GOOD') {
            $data .= $esc . 'H' . sprintf('%04d', $offsetH) . $esc . 'V' . sprintf('%04d', $offsetV + 220) . $esc . 'P2' . $esc . 'L0405' . $esc . 'S' . $breakQR[2];
        } elseif ($breakQR[2] === 'WRONG ORIENTATION') {
            $data .= $esc . 'H' . sprintf('%04d', $offsetH) . $esc . 'V' . sprintf('%04d', $offsetV + 220) . $esc . 'P2' . $esc . 'L0205' . $esc . 'S' . $breakQR[2];
        } else {
            $data .= $esc . 'H' . sprintf('%04d', $offsetH) . $esc . 'V' . sprintf('%04d', $offsetV + 220) . $esc . 'P2' . $esc . 'L0305' . $esc . 'S' . $breakQR[2];
        }

        $data .= $esc . 'H' . sprintf('%04d', $offsetH + 300) . $esc . 'V' . sprintf('%04d', $offsetV + 10) . $esc . '2D30,L,03,0,0';
        $data .= $esc . 'DN' . sprintf('%04d', strlen($xQRCode)) . ',' . $xQRCode;

        $data .= $esc . 'Q1';
        $data .= $esc . 'Z' . $esc;

        try {
            $this->sendToPrinter($tablet->SATO_IP, $data);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => "Couldn't reach the printer at {$tablet->SATO_IP}. Please contact PIC.",
            ], 502);
        }

        return response()->json(['message' => "Sticker sent to printer: {$xQRCode}"]);
    }

    /**
     * POST /print/rtv (multipart)
     * Ports the legacy print_rtv_qr.php: reads the first worksheet of the uploaded
     * file, requires cell A1 to be non-empty, then prints one sticker per data row
     * starting at row 2. Each row's column A holds a pre-formatted
     * "BoxId;Model;Field2;Qty;..." string — the whole string (uppercased) becomes the
     * QR content; BoxId/Model/Qty are also printed as separate readable text.
     */
    public function printRtv(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file'      => ['required', 'file', 'mimes:xls,xlsx'],
            'tablet_id' => ['required', 'string', 'max:100'],
        ]);

        $tablet = $this->resolveTablet($validated['tablet_id']);
        if (! $tablet) {
            return response()->json([
                'message' => 'This tablet is not registered to a printer. Please contact PIC.',
            ], 422);
        }

        $spreadsheet = IOFactory::load($validated['file']->getRealPath());
        $worksheet = $spreadsheet->getSheetByIndex(0); // only the first sheet, like the legacy script
        $rows = $worksheet->toArray();

        if (! isset($rows[0][0]) || trim((string) $rows[0][0]) === '') {
            return response()->json([
                'message' => 'No data available on row A1. Please check the file and try again.',
            ], 422);
        }

        $offsetH = '0380'; // fixed layout constant from the legacy RTV sticker, not tablet-specific
        $esc = chr(27);
        $printed = 0;

        try {
            $socket = $this->openPrinterSocket($tablet->SATO_IP);

            // Data rows start at index 1 (Excel row 2) — row 1 is a header row.
            for ($d = 1; $d < count($rows); $d++) {
                $cell = (string) ($rows[$d][0] ?? '');
                if (trim($cell) === '') {
                    continue;
                }

                $xProcess = trim(strtoupper($cell), " \t.");
                $fields = explode(';', $cell);

                $data = $esc . 'A';
                $data .= $esc . 'A3H1374V0001';

                $data .= $esc . 'H' . $offsetH . $esc . 'V0022' . $esc . 'L0102' . $esc . 'MModel:';
                $data .= $esc . 'H' . $offsetH . $esc . 'V0100' . $esc . 'L0102' . $esc . 'MFG Box:';
                $data .= $esc . 'H' . $offsetH . $esc . 'V0170' . $esc . 'L0102' . $esc . 'MQty:';
                $data .= $esc . 'H' . sprintf('%04d', $offsetH + 120) . $esc . 'V0220' . $esc . 'L0102' . $esc . 'Mpcs/box';

                $data .= $esc . 'H' . sprintf('%04d', $offsetH + 120) . $esc . 'V0018' . $esc . 'P2L0101' . $esc . 'XM' . ($fields[1] ?? '');
                $data .= $esc . 'H' . sprintf('%04d', $offsetH + 120) . $esc . 'V0078' . $esc . 'P2L0101' . $esc . 'XB1' . ($fields[0] ?? '');
                $data .= $esc . 'H' . sprintf('%04d', $offsetH + 120) . $esc . 'V0168' . $esc . 'P2L0101' . $esc . 'XM' . number_format((float) ($fields[3] ?? 0), 0);

                $data .= $esc . 'H' . sprintf('%04d', $offsetH + 320) . $esc . 'V0170' . $esc . '2D30,L,04,0,0';
                $data .= $esc . 'DN' . sprintf('%04d', strlen($xProcess)) . ',' . $xProcess;

                $data .= $esc . 'H' . $offsetH . $esc . 'V0280' . $esc . 'L0102' . $esc . 'SQR Code For FG Checker Use Only';

                $data .= $esc . 'Q1';
                $data .= $esc . 'Z' . $esc;

                fputs($socket, $data);
                $printed++;
            }

            fclose($socket);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => "Couldn't reach the printer at {$tablet->SATO_IP}. Please contact PIC.",
            ], 502);
        }

        if ($printed === 0) {
            return response()->json([
                'message' => 'No data available on row A2. Please check the file and try again.',
            ], 422);
        }

        return response()->json(['message' => "{$printed} sticker(s) sent to printer."]);
    }

    protected function resolveTablet(string $tabletId): ?TabletRecord
    {
        return TabletRecord::where('tablet_id', $tabletId)->first();
    }

    /** @throws \RuntimeException */
    protected function openPrinterSocket(string $ip)
    {
        $socket = @pfsockopen($ip, 9100, $errno, $errstr, 5);
        if (! $socket) {
            throw new \RuntimeException("Could not open socket to {$ip}: {$errstr}");
        }
        return $socket;
    }

    /** @throws \RuntimeException */
    protected function sendToPrinter(string $ip, string $data): void
    {
        $socket = $this->openPrinterSocket($ip);
        fputs($socket, $data);
        fclose($socket);
    }
}
