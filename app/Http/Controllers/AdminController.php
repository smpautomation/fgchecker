<?php

namespace App\Http\Controllers;

use App\Models\ModelList;
use App\Models\Processes;
use App\Models\TabletRecord;
use App\Models\ValidationList;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * GET /admin/access?tablet_id=...
     * The Admin page is gated by the scanning tablet's own Role column
     * (on TabletRecord) rather than a user login.
     */
    public function checkAccess(Request $request): JsonResponse
    {
        $tabletId = $request->query('tablet_id');
        $tablet = $tabletId ? TabletRecord::where('tablet_id', $tabletId)->first() : null;

        return response()->json([
            'isAdmin' => $tablet && strtolower((string) $tablet->role) === 'admin',
        ]);
    }

    /* ===================== Models (model_list) ===================== */

    public function models(): JsonResponse
    {
        return response()->json(['data' => ModelList::orderBy('No')->get()]);
    }

    public function storeModel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'Model_Name' => ['required', 'string', 'max:100', 'unique:model_list,Model_Name'],
        ]);
        $id = ModelList::insertGetId($validated, 'No');
        return response()->json(['data' => ModelList::where('No', $id)->first()], 201);
    }

    public function updateModel(Request $request, int $no): JsonResponse
    {
        $validated = $request->validate([
            'Model_Name' => ['required', 'string', 'max:100', 'unique:model_list,Model_Name,' . $no . ',No'],
        ]);
        ModelList::where('No', $no)->update($validated);
        return response()->json(['data' => ModelList::where('No', $no)->first()]);
    }

    public function destroyModel(int $no): JsonResponse
    {
        ModelList::where('No', $no)->delete();
        return response()->json(['message' => 'Model deleted.']);
    }

    /* ===================== Validation Types (validation_list) ===================== */

    public function validations(): JsonResponse
    {
        return response()->json(['data' => ValidationList::orderBy('ID')->get()]);
    }

    public function storeValidation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'Validation_Name' => ['required', 'string', 'max:100', 'unique:validation_list,Validation_Name'],
        ]);
        $id = DB::table('validation_list')->insertGetId($validated, 'ID');
        return response()->json(['data' => ValidationList::where('ID', $id)->first()], 201);
    }

    public function updateValidation(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'Validation_Name' => ['required', 'string', 'max:100', 'unique:validation_list,Validation_Name,' . $id . ',ID'],
        ]);
        ValidationList::where('ID', $id)->update($validated);
        return response()->json(['data' => DB::table('validation_list')->where('ID', $id)->first()]);
    }

    public function destroyValidation(int $id): JsonResponse
    {
        ValidationList::where('ID', $id)->delete();
        return response()->json(['message' => 'Validation type deleted.']);
    }

    /* ===================== Result Options (fgchecker_monitoring_process) ===================== */

    public function processes(): JsonResponse
    {
        return response()->json(['data' => Processes::orderBy('No')->get()]);
    }

    public function storeProcess(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'Process' => ['required', 'string', 'max:100', 'unique:fgchecker_monitoring_process,Process'],
            'Type'    => ['required', 'string', 'in:GOOD,NOT GOOD,RELOAD'],
        ]);
        $id = Processes::insertGetId($validated, 'No');
        return response()->json(['data' => Processes::where('No', $id)->first()], 201);
    }

    public function updateProcess(Request $request, int $no): JsonResponse
    {
        $validated = $request->validate([
            'Process' => ['required', 'string', 'max:100', 'unique:fgchecker_monitoring_process,Process,' . $no . ',No'],
            'Type'    => ['required', 'string', 'in:GOOD,NOT GOOD,RELOAD'],
        ]);
        Processes::where('No', $no)->update($validated);
        return response()->json(['data' => Processes::where('No', $no)->first()]);
    }

    public function destroyProcess(int $no): JsonResponse
    {
        Processes::where('No', $no)->delete();
        return response()->json(['message' => 'Result option deleted.']);
    }

    /* ===================== Tablets (TabletRecord) ===================== */

    public function tablets(): JsonResponse
    {
        return response()->json(['data' => TabletRecord::orderBy('Area')->get()]);
    }

    public function storeTablet(Request $request): JsonResponse
    {
        $validated = $this->validateTablet($request);
        $tablet = TabletRecord::create($validated);
        return response()->json(['data' => $tablet], 201);
    }

    public function updateTablet(Request $request, int $id): JsonResponse
    {
        $tablet = TabletRecord::findOrFail($id);
        $validated = $this->validateTablet($request, $tablet->id);
        $tablet->update($validated);
        return response()->json(['data' => $tablet->fresh()]);
    }

    public function destroyTablet(int $id): JsonResponse
    {
        TabletRecord::whereKey($id)->delete();
        return response()->json(['message' => 'Tablet record deleted.']);
    }

    protected function validateTablet(Request $request, ?int $ignoreId = null): array
    {
        $uniqueRule = 'unique:tablet_records,tablet_id' . ($ignoreId ? ",{$ignoreId},id" : '');

        return $request->validate([
            'tablet_id'         => ['required', 'string', 'max:100', $uniqueRule],
            'IP_Address'        => ['nullable', 'string', 'max:45'],
            'Area'              => ['nullable', 'string', 'max:100'],
            'SATO_IP'           => ['nullable', 'string', 'max:45'],
            'Horizontal_Offset' => ['nullable', 'integer'],
            'Vertical_Offset'   => ['nullable', 'integer'],
            'r4_name'           => ['nullable', 'string', 'max:100'],
            'role'              => ['nullable', 'string', 'in:admin,operator'],
        ]);
    }
}
