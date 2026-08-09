<?php

namespace App\Http\Controllers;

use App\Models\TabletRecord;
use Exception;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function  saveTabletID(Request $request){
        $validated = $request->validate([
            'tabletID' => 'required|string'
        ]);

        $tablet = TabletRecord::firstOrCreate(
            ['tablet_id' => $validated['tabletID']],
            [
                'IP_Address' => $request->ip(),
                'Area' => 'TBA',
            ]
        );

        if ($tablet->wasRecentlyCreated) {
            return response()->json(['message' => 'Recorded.'], 201);
        }

        return response()->json(['message' => 'Already exists.'], 200);

    }
}
