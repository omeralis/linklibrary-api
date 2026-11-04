<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Private, read-only schema gate. Never runs migrations or prints database data.
try {
    $api = realpath($argv[1] ?? '');
    if ($api === false || ! preg_match('#^/srv/linklibrary/releases/[0-9a-z-]+/api$#D', $api)) {
        exit(2);
    }
    require $api.'/vendor/autoload.php';
    $app = require $api.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection();
    $db->beginTransaction();
    $db->statement('SET TRANSACTION READ ONLY');
    $identity = $db->selectOne('select current_database() db,current_user usr');
    if ($identity->db !== 'linklibrary_prod' || $identity->usr !== 'linklibrary_app') {
        throw new RuntimeException('identity');
    }
    $migrator = $app->make('migrator');
    $ran = $migrator->getRepository()->getRan();
    $files = $migrator->getMigrationFiles($api.'/database/migrations');
    if (array_diff(array_keys($files), $ran) !== []) {
        throw new RuntimeException('pending migrations');
    }
    $columns = $db->select("select table_name,column_name,data_type,is_nullable,column_default from information_schema.columns where table_schema='public' order by table_name,ordinal_position");
    $migrations = [];
    foreach ($files as $name => $file) {
        $migrations[$name] = hash_file('sha256', $file);
    }
    $db->rollBack();
    echo json_encode(['ready' => true, 'schema' => hash('sha256', json_encode([$columns, $migrations], JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    echo '{"ready":false}'.PHP_EOL;
    exit(1);
}
