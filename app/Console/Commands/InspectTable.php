<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InspectTable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inspect:table {table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect a database table structure';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $table = $this->argument('table');
        
        if (!Schema::hasTable($table)) {
            $this->error("Table {$table} does not exist!");
            return 1;
        }
        
        $columns = Schema::getColumnListing($table);
        
        $this->info("Table: {$table}");
        $this->info("Columns:");
        
        foreach ($columns as $column) {
            $type = DB::getSchemaBuilder()->getColumnType($table, $column);
            $this->line("- {$column} ({$type})");
        }
        
        return 0;
    }
}
