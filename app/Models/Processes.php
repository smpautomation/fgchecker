<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Processes extends Model
{
    protected $table = "fgchecker_monitoring_process";
    public $timestamps = false;
    protected $guarded = [];
}
