<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FGCheckerTableService
{
    protected const SOURCE_TABLE = 'fgchecker_format';

    public function currentTableName(): string{
        return 'fgchecker' . now()->format('Y');
    }

    public function ensureTableExists(): string{
        $table = $this->currentTableName();

        if(! Schema::hasTable($table))
        {
            if(! Schema::hasTable(self::SOURCE_TABLE))
            {
                throw new \RuntimeException(
                    'Source table "' . self::SOURCE_TABLE . '" does not exist - cannot create ' . $table
                );
            }

            DB::statement("CREATE TABLE `{$table}` LIKE `" . self::SOURCE_TABLE . '`');
        }

        return $table;
    }
}
