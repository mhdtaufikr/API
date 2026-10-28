<?php

declare (strict_types=1);
final class LegacyConfigMigration
{
    public static function active(): bool
    {
        return in_array(env('INTEGRATION_STORAGE'), ['legacy_config', 'legacy_app'], true);
    }
    public static function table(): string
    {
        $schema=Database::driver()==='mysql'?env('DB_DATABASE'):env('LEGACY_SCHEMA','public');
        return Database::quote($schema).'.'.Database::quote('tbl_config');
    }
    public static function run(): array
    {
        if(Database::driver()==='pgsql')return self::postgres();
        $pdo = Database::open();
        $table = self::table();
        $stmt = $pdo->prepare('SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table');
        $stmt->execute(['db' => env('DB_DATABASE'), 'table' => 'tbl_config']);
        $comment = $stmt->fetchColumn();
        if ($comment !== false) {
            if ($comment !== 'mkm-talenta-config-v1') {
                compat_throw(new RuntimeException('tbl_config already exists and is not owned by this application. Nothing changed.'));
            }
            $columns = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
            $expected = ['provider', 'client_id_encrypted', 'client_secret_encrypted', 'import_token_encrypted', 'revision', 'audit_history', 'created_at', 'updated_at'];
            if (array_column($columns, 'Field') !== $expected || $columns[0]['Key'] !== 'PRI') {
                compat_throw(new RuntimeException('Existing tbl_config structure differs. Nothing changed.'));
            }
            return ['tbl_config already installed. No tables or records changed.'];
        }
        // A concurrent creator causes an error; never adopt, alter or drop an existing table.
        $pdo->exec('CREATE TABLE ' . $table . " (\n            provider VARCHAR(30) PRIMARY KEY,\n            client_id_encrypted TEXT NULL,\n            client_secret_encrypted TEXT NULL,\n            import_token_encrypted TEXT NULL,\n            revision BIGINT NOT NULL DEFAULT 1,\n            audit_history JSON NOT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n        ) ENGINE=InnoDB COMMENT='mkm-talenta-config-v1'");
        return ['Created tbl_config only. Existing attendance tables unchanged. Run seed next.'];
    }
    private static function postgres(): array
    {
        $pdo=Database::open();$schema=env('LEGACY_SCHEMA','public');$table=self::table();$pdo->beginTransaction();
        try{
            $pdo->exec('SELECT pg_advisory_xact_lock(18421029)');
            $stmt=$pdo->prepare("SELECT c.relkind,obj_description(c.oid,'pg_class') AS ownership FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=:schema AND c.relname=:table");
            $stmt->execute(['schema'=>$schema,'table'=>'tbl_config']);$existing=$stmt->fetch(PDO::FETCH_ASSOC);
            if($existing){
                if($existing['relkind']!=='r'||$existing['ownership']!=='mkm-talenta-config-v1')throw new RuntimeException('Existing tbl_config is not owned by this application. Nothing changed.');
                $stmt=$pdo->prepare('SELECT column_name,data_type FROM information_schema.columns WHERE table_schema=:schema AND table_name=:table ORDER BY ordinal_position');$stmt->execute(['schema'=>$schema,'table'=>'tbl_config']);$columns=$stmt->fetchAll(PDO::FETCH_ASSOC);
                if(array_column($columns,'column_name')!==['provider','client_id_encrypted','client_secret_encrypted','import_token_encrypted','revision','audit_history','created_at','updated_at']||$columns[5]['data_type']!=='jsonb')throw new RuntimeException('Existing tbl_config differs. Nothing changed.');
                $pdo->commit();return ['tbl_config already installed. No data changed.'];
            }
            $pdo->exec('CREATE TABLE '.$table.' (provider VARCHAR(30) PRIMARY KEY, client_id_encrypted TEXT NULL, client_secret_encrypted TEXT NULL, import_token_encrypted TEXT NULL, revision BIGINT NOT NULL DEFAULT 1, audit_history JSONB NOT NULL, created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            $pdo->exec("COMMENT ON TABLE ".$table." IS 'mkm-talenta-config-v1'");$pdo->commit();
            return ['Created tbl_config only in existing schema. Legacy tables unchanged. Run config:seed next.'];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
