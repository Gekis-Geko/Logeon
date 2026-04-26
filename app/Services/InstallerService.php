<?php

declare(strict_types=1);

namespace App\Services;

class InstallerService
{
    private $allowedCharsets = [
        // Unicode completo — supporta tutte le lingue incluse asiatiche, arabe, emoji
        'utf8mb4',
        // UTF-8 parziale (3 byte) — legacy MySQL, manca emoji e alcuni caratteri CJK
        'utf8',
        // Europa occidentale
        'latin1',
        'latin2',   // Europa centrale (polacco, ceco, ungherese…)
        'latin5',   // Turco
        'latin7',   // Baltico
        // Lingue specifiche
        'greek',
        'hebrew',
        'arabic',
        'cp1250',   // Windows Europa centrale
        'cp1251',   // Windows Cirillico
        'cp1256',   // Windows Arabo
        'cp1257',   // Windows Baltico
        'utf16',
        'utf32',
    ];
    private $allowedCollations = [
        // ── utf8mb4 ───────────────────────────────────────────────────────────
        'utf8mb4_unicode_ci',       // Unicode standard, case-insensitive (raccomandato)
        'utf8mb4_unicode_520_ci',   // Unicode 5.2 — ordinamento migliorato
        'utf8mb4_0900_ai_ci',       // Unicode 9.0, accent+case insensitive (MySQL 8+)
        'utf8mb4_0900_as_cs',       // Unicode 9.0, accent+case sensitive (MySQL 8+)
        'utf8mb4_general_ci',       // Più veloce, meno accurato
        'utf8mb4_bin',              // Binario — case+accent sensitive
        // Lingue specifiche utf8mb4
        'utf8mb4_italian_ci',
        'utf8mb4_spanish_ci',
        'utf8mb4_spanish2_ci',
        'utf8mb4_german2_ci',
        'utf8mb4_french_ci',
        'utf8mb4_polish_ci',
        'utf8mb4_roman_ci',
        'utf8mb4_turkish_ci',
        'utf8mb4_czech_ci',
        'utf8mb4_danish_ci',
        'utf8mb4_hungarian_ci',
        'utf8mb4_persian_ci',
        'utf8mb4_romanian_ci',
        'utf8mb4_croatian_ci',
        'utf8mb4_slovenian_ci',
        'utf8mb4_estonian_ci',
        'utf8mb4_latvian_ci',
        'utf8mb4_lithuanian_ci',
        'utf8mb4_slovak_ci',
        'utf8mb4_swedish_ci',
        'utf8mb4_vietnamese_ci',
        // ── utf8 (legacy 3-byte) ─────────────────────────────────────────────
        'utf8_unicode_ci',
        'utf8_unicode_520_ci',
        'utf8_general_ci',
        'utf8_bin',
        'utf8_italian_ci',
        'utf8_spanish_ci',
        'utf8_german2_ci',
        'utf8_turkish_ci',
        // ── latin / codepage ─────────────────────────────────────────────────
        'latin1_swedish_ci',
        'latin1_general_ci',
        'latin1_general_cs',
        'latin1_bin',
        'latin2_general_ci',
        'latin5_turkish_ci',
        'greek_general_ci',
        'hebrew_general_ci',
        'cp1250_general_ci',
        'cp1251_general_ci',
        'cp1256_general_ci',
        'cp1257_general_ci',
    ];
    private $mysqliFactory;

    public function __construct(callable $mysqliFactory = null)
    {
        $this->mysqliFactory = $mysqliFactory;
    }

    private function createMysqli($host, $user, $pwd, $dbName = null)
    {
        if (is_callable($this->mysqliFactory)) {
            return call_user_func($this->mysqliFactory, $host, $user, $pwd, $dbName);
        }

        if ($dbName === null) {
            return new \mysqli($host, $user, $pwd);
        }

        return new \mysqli($host, $user, $pwd, $dbName);
    }

    private function beginTransaction(\mysqli $mysqli): bool
    {
        return (bool) $mysqli->begin_transaction();
    }

    private function commitTransaction(\mysqli $mysqli): bool
    {
        return (bool) $mysqli->commit();
    }

    private function rollbackTransaction(\mysqli $mysqli): bool
    {
        return (bool) $mysqli->rollback();
    }

    private function hasUsersTable(\mysqli $mysqli, string $dbName): bool
    {
        if ($dbName === '') {
            return false;
        }

        $stmt = $mysqli->prepare(
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
             LIMIT 1',
        );
        if ($stmt === false) {
            return false;
        }

        $tableName = 'users';
        $stmt->bind_param('ss', $dbName, $tableName);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $found = 0;
        $stmt->bind_result($found);
        $stmt->fetch();
        $stmt->close();

        return ((int) $found) === 1;
    }

    /** @return array<string,mixed> */
    private function dbConfig(): array
    {
        if (!defined('DB')) {
            return [];
        }
        return (array) DB;
    }

    /** @return array<string,mixed> */
    private function mysqlConfig(): array
    {
        $db = $this->dbConfig();
        $raw = $db['mysql'] ?? null;
        return is_array($raw) ? $raw : [];
    }

    public function isInstalled()
    {
        if ($this->readInstallLock()) {
            return true;
        }

        if ($this->detectLegacyInstall()) {
            $this->writeInstallLock([
                'source' => 'legacy-auto-detect',
                'installed_at' => date('c'),
            ]);
            return true;
        }

        return false;
    }

    public function isLocked(): bool
    {
        return $this->readInstallLock();
    }

    public function getInstallState()
    {
        return [
            'installed' => $this->isInstalled(),
            'lock_file' => $this->getInstallLockPath(),
        ];
    }

    public function getDefaultAppConfig()
    {
        $app = defined('APP') ? APP : [];
        return [
            'baseurl' => (string) ($app['baseurl'] ?? ''),
            'lang' => (string) ($app['lang'] ?? 'it'),
            'name' => (string) ($app['name'] ?? ''),
            'title' => (string) ($app['title'] ?? ''),
            'description' => (string) ($app['description'] ?? ''),
            'wm_name' => (string) ($app['wm_name'] ?? '-'),
            'wm_email' => (string) ($app['wm_email'] ?? '-'),
            'dba_name' => (string) ($app['dba_name'] ?? '-'),
            'dba_email' => (string) ($app['dba_email'] ?? '-'),
            'support_name' => (string) ($app['support_name'] ?? '-'),
            'support_email' => (string) ($app['support_email'] ?? '-'),
        ];
    }

    public function getDefaultDbConfig()
    {
        $db = defined('DB') ? DB : [];
        $mysql = $db['mysql'] ?? [];
        return [
            'host' => (string) ($mysql['host'] ?? 'localhost'),
            'db_name' => (string) ($mysql['db_name'] ?? ''),
            'user' => (string) ($mysql['user'] ?? ''),
            'pwd' => '',
            'charset' => (string) ($mysql['charset'] ?? 'utf8mb4'),
            'collation' => (string) ($mysql['collation'] ?? 'utf8mb4_unicode_ci'),
        ];
    }

    public function validateApp(array $input)
    {
        $baseurl = trim((string) ($input['baseurl'] ?? ''));
        $baseurl = preg_replace('#^https?://#i', '', $baseurl);
        $baseurl = rtrim($baseurl, "/ \t\n\r\0\x0B");
        if ($baseurl === '') {
            return ['ok' => false, 'error' => 'Base URL obbligatorio.'];
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'error' => 'Nome applicazione obbligatorio.'];
        }

        $title = trim((string) ($input['title'] ?? $name));
        if ($title === '') {
            $title = $name;
        }

        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '') {
            return ['ok' => false, 'error' => 'Descrizione applicazione obbligatoria.'];
        }

        $lang = strtolower(trim((string) ($input['lang'] ?? 'it')));
        if (!preg_match('/^[a-z]{2}$/', $lang)) {
            $lang = 'it';
        }

        $normalized = [
            'baseurl' => $baseurl,
            'lang' => $lang,
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'wm_name' => $this->normalizeContact($input, 'wm_name', '-'),
            'wm_email' => $this->normalizeEmail($input, 'wm_email', '-'),
            'dba_name' => $this->normalizeContact($input, 'dba_name', '-'),
            'dba_email' => $this->normalizeEmail($input, 'dba_email', '-'),
            'support_name' => $this->normalizeContact($input, 'support_name', '-'),
            'support_email' => $this->normalizeEmail($input, 'support_email', '-'),
        ];

        return ['ok' => true, 'data' => $normalized];
    }

    public function validateDb(array $input)
    {
        $host = trim((string) ($input['host'] ?? ''));
        $dbName = trim((string) ($input['db_name'] ?? ''));
        $user = trim((string) ($input['user'] ?? ''));
        $pwd = (string) ($input['pwd'] ?? '');
        $charset = strtolower(trim((string) ($input['charset'] ?? 'utf8mb4')));
        $collation = strtolower(trim((string) ($input['collation'] ?? 'utf8mb4_unicode_ci')));

        if ($host === '') {
            return ['ok' => false, 'error' => 'Host DB obbligatorio.'];
        }
        if ($dbName === '') {
            return ['ok' => false, 'error' => 'Nome database obbligatorio.'];
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
            return ['ok' => false, 'error' => 'Nome database non valido (usa solo lettere, numeri, underscore).'];
        }
        if ($user === '') {
            return ['ok' => false, 'error' => 'Utente DB obbligatorio.'];
        }

        if (!in_array($charset, $this->allowedCharsets, true)) {
            $charset = 'utf8mb4';
        }
        if (!in_array($collation, $this->allowedCollations, true)) {
            $collation = 'utf8mb4_unicode_ci';
        }

        return [
            'ok' => true,
            'data' => [
                'host' => $host,
                'db_name' => $dbName,
                'user' => $user,
                'pwd' => $pwd,
                'charset' => $charset,
                'collation' => $collation,
            ],
        ];
    }

    public function testDb(array $dbInput)
    {
        $validated = $this->validateDb($dbInput);
        if (!$validated['ok']) {
            return $validated;
        }
        $db = $validated['data'];

        $mysqli = @$this->createMysqli($db['host'], $db['user'], $db['pwd']);
        if ($mysqli->connect_errno) {
            return ['ok' => false, 'error' => 'Connessione DB fallita: controlla host/utente/password.'];
        }

        $dbName = $this->quoteIdentifier($db['db_name']);
        $charset = $this->quoteIdentifier($db['charset']);
        $collation = $this->quoteIdentifier($db['collation']);

        if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$collation}")) {
            $mysqli->close();
            return ['ok' => false, 'error' => 'Impossibile creare/verificare il database.'];
        }

        if (!$mysqli->select_db($db['db_name'])) {
            $mysqli->close();
            return ['ok' => false, 'error' => 'Impossibile selezionare il database.'];
        }

        $mysqli->set_charset($db['charset']);
        $mysqli->close();

        return ['ok' => true];
    }

    public function writeAppConfig(array $appInput)
    {
        $validated = $this->validateApp($appInput);
        if (!$validated['ok']) {
            return $validated;
        }
        $normalized = $validated['data'];

        $current = defined('APP') ? APP : [];
        $config = array_merge($current, $normalized);
        if (!isset($config['shop']) || !is_array($config['shop'])) {
            $config['shop'] = ['sell_ratio' => 0.5];
        }

        $content = "<?php\n\nconst APP = " . var_export($config, true) . ";\n";
        return $this->writeFile($this->getAppConfigPath(), $content);
    }

    public function writeDbConfig(array $dbInput)
    {
        $validated = $this->validateDb($dbInput);
        if (!$validated['ok']) {
            return $validated;
        }
        $normalized = $validated['data'];

        // Usa la chiave fornita dal form se valida (hex >= 32 char), altrimenti genera
        $cryptKey = trim((string) ($dbInput['crypt_key'] ?? ''));
        if ($cryptKey === '' || !preg_match('/^[0-9a-fA-F]{32,}$/', $cryptKey)) {
            $cryptKey = bin2hex(random_bytes(16));
        }

        $config = [
            'mysql' => $normalized,
            'crypt_key' => $cryptKey,
        ];

        $content = "<?php\n\nconst DB = " . var_export($config, true) . ";\n";
        return $this->writeFile($this->getDbConfigPath(), $content);
    }

    public function initDatabase(array $dbInput)
    {
        $validated = $this->validateDb($dbInput);
        if (!$validated['ok']) {
            return $validated;
        }
        $db = $validated['data'];

        $test = $this->testDb($db);
        if (!$test['ok']) {
            return $test;
        }

        $dumpPath = $this->getSqlDumpPath();
        if (!file_exists($dumpPath)) {
            return ['ok' => false, 'error' => 'Dump SQL non trovato: ' . $this->getSqlDumpRelativePath()];
        }

        $sql = file_get_contents($dumpPath);
        if ($sql === false || trim($sql) === '') {
            return ['ok' => false, 'error' => 'Dump SQL vuoto o non leggibile.'];
        }

        $dbName = $this->quoteIdentifier($db['db_name']);
        $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `[^`]+`/i', "CREATE DATABASE IF NOT EXISTS `{$dbName}`", $sql, 1);
        $sql = preg_replace('/USE `[^`]+`;/i', "USE `{$dbName}`;", $sql, 1);
        $sql = $this->fixGeneratedColumnInserts($sql);

        @set_time_limit(300);
        $mysqli = @$this->createMysqli($db['host'], $db['user'], $db['pwd']);
        if ($mysqli->connect_errno) {
            return ['ok' => false, 'error' => 'Connessione DB fallita durante init schema.'];
        }

        if (!$mysqli->set_charset($db['charset'])) {
            $mysqli->close();
            return ['ok' => false, 'error' => 'Charset DB non impostabile.'];
        }

        if (!$mysqli->select_db($db['db_name'])) {
            $mysqli->close();
            return ['ok' => false, 'error' => 'Impossibile selezionare il database per l\'import.'];
        }

        if (!$mysqli->multi_query($sql)) {
            $error = $mysqli->error;
            $mysqli->close();
            return ['ok' => false, 'error' => 'Errore import SQL: ' . $error];
        }

        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());

        if ($mysqli->errno) {
            $error = $mysqli->error;
            $mysqli->close();
            return ['ok' => false, 'error' => 'Errore import SQL: ' . $error];
        }

        $mysqli->close();

        return ['ok' => true];
    }

    public function createAdmin(array $input)
    {
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $passwordConfirm = (string) ($input['password_confirm'] ?? '');
        $characterName = trim((string) ($input['character_name'] ?? ''));
        $gender = (int) ($input['gender'] ?? 1);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'Email non valida.'];
        }
        if (strlen($password) < 6) {
            return ['ok' => false, 'error' => 'La password deve essere di almeno 6 caratteri.'];
        }
        if ($password !== $passwordConfirm) {
            return ['ok' => false, 'error' => 'Le password non coincidono.'];
        }
        if ($characterName === '') {
            return ['ok' => false, 'error' => 'Il nome del personaggio e obbligatorio.'];
        }
        if (strlen($characterName) > 25) {
            return ['ok' => false, 'error' => 'Il nome del personaggio non puo superare 25 caratteri.'];
        }
        if (!in_array($gender, [1, 2], true)) {
            $gender = 1;
        }

        $dbCfg = $this->mysqlConfig();
        $cryptKey = (string) ($this->dbConfig()['crypt_key'] ?? '');
        if (empty($dbCfg) || $cryptKey === '') {
            return ['ok' => false, 'error' => 'Configurazione DB non disponibile. Completa prima lo step 3.'];
        }

        $mysqli = @$this->createMysqli(
            (string) ($dbCfg['host'] ?? 'localhost'),
            (string) ($dbCfg['user'] ?? ''),
            (string) ($dbCfg['pwd'] ?? ''),
            (string) ($dbCfg['db_name'] ?? ''),
        );

        if ($mysqli->connect_errno) {
            return ['ok' => false, 'error' => 'Connessione DB fallita.'];
        }

        $mysqli->set_charset((string) ($dbCfg['charset'] ?? 'utf8mb4'));

        $checkStmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM `users` WHERE `is_superuser` = ?');
        if ($checkStmt === false) {
            $error = $mysqli->error;
            $mysqli->close();
            return ['ok' => false, 'error' => 'Verifica superuser fallita: ' . $error];
        }

        $superuserFlag = 1;
        $checkStmt->bind_param('i', $superuserFlag);
        if (!$checkStmt->execute()) {
            $error = $checkStmt->error;
            $checkStmt->close();
            $mysqli->close();
            return ['ok' => false, 'error' => 'Verifica superuser fallita: ' . $error];
        }

        $checkResult = $checkStmt->get_result();
        $checkStmt->close();

        $existingSuperusers = 0;
        if ($checkResult !== false) {
            $row = $checkResult->fetch_row();
            $existingSuperusers = is_array($row) ? (int) ($row[0] ?? 0) : 0;
            $checkResult->free();
        }

        if ($existingSuperusers > 0) {
            $mysqli->close();
            return ['ok' => false, 'error' => 'Esiste gia un account superuser. Impossibile crearne un secondo.'];
        }

        if (!$this->beginTransaction($mysqli)) {
            $error = $mysqli->error;
            $mysqli->close();
            return ['ok' => false, 'error' => 'Impossibile avviare la transazione di creazione admin: ' . $error];
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $userGender = 1;
        $isAdministrator = 1;
        $isSuperuser = 1;
        $isModerator = 1;
        $isMaster = 1;
        $sessionVersion = 1;

        $insertUserStmt = $mysqli->prepare(
            'INSERT INTO `users`
                (`email`, `password`, `gender`, `is_administrator`, `is_superuser`, `is_moderator`, `is_master`, `date_actived`, `date_created`, `session_version`)
             VALUES
                (AES_ENCRYPT(?, ?), ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)',
        );
        if ($insertUserStmt === false) {
            $error = $mysqli->error;
            $this->rollbackTransaction($mysqli);
            $mysqli->close();
            return ['ok' => false, 'error' => 'Creazione utente fallita: ' . $error];
        }

        $insertUserStmt->bind_param(
            'sssiiiiii',
            $email,
            $cryptKey,
            $passwordHash,
            $userGender,
            $isAdministrator,
            $isSuperuser,
            $isModerator,
            $isMaster,
            $sessionVersion,
        );
        if (!$insertUserStmt->execute()) {
            $error = $insertUserStmt->error;
            $insertUserStmt->close();
            $this->rollbackTransaction($mysqli);
            $mysqli->close();
            return ['ok' => false, 'error' => 'Creazione utente fallita: ' . $error];
        }
        $insertUserStmt->close();

        $userId = (int) $mysqli->insert_id;
        if ($userId <= 0) {
            $this->rollbackTransaction($mysqli);
            $mysqli->close();
            return ['ok' => false, 'error' => 'Creazione utente fallita: id non valido.'];
        }

        $socialstatusId = 1;
        $insertCharacterStmt = $mysqli->prepare(
            'INSERT INTO `characters` (`user_id`, `name`, `gender`, `socialstatus_id`)
             VALUES (?, ?, ?, ?)',
        );
        if ($insertCharacterStmt === false) {
            $error = $mysqli->error;
            $this->rollbackTransaction($mysqli);
            $mysqli->close();
            return ['ok' => false, 'error' => 'Creazione personaggio fallita: ' . $error];
        }

        $insertCharacterStmt->bind_param('isii', $userId, $characterName, $gender, $socialstatusId);
        if (!$insertCharacterStmt->execute()) {
            $error = $insertCharacterStmt->error;
            $insertCharacterStmt->close();
            $this->rollbackTransaction($mysqli);
            $mysqli->close();
            return ['ok' => false, 'error' => 'Creazione personaggio fallita: ' . $error];
        }
        $insertCharacterStmt->close();

        if (!$this->commitTransaction($mysqli)) {
            $error = $mysqli->error;
            $this->rollbackTransaction($mysqli);
            $mysqli->close();
            return ['ok' => false, 'error' => 'Commit creazione admin fallito: ' . $error];
        }

        $mysqli->close();

        return $this->finalizeInstall();
    }

    public function finalizeInstall()
    {
        $state = $this->writeInstallLock([
            'installed_at' => date('c'),
            'schema_source' => $this->getSqlDumpRelativePath(),
        ]);
        if (!$state['ok']) {
            return $state;
        }

        return ['ok' => true];
    }

    private function writeInstallLock(array $meta)
    {
        $metaExport = var_export($meta, true);
        $content = "<?php\n\nconst INSTALLED = true;\nconst INSTALL_META = {$metaExport};\n";
        return $this->writeFile($this->getInstallLockPath(), $content);
    }

    private function readInstallLock()
    {
        $path = $this->getInstallLockPath();
        if (!file_exists($path)) {
            return false;
        }

        if (!defined('INSTALLED')) {
            require_once $path;
        }

        return defined('INSTALLED') && INSTALLED === true;
    }

    private function detectLegacyInstall()
    {
        $mysql = $this->mysqlConfig();
        if (empty($mysql)) {
            return false;
        }

        $host = (string) ($mysql['host'] ?? '');
        $user = (string) ($mysql['user'] ?? '');
        $pwd = (string) ($mysql['pwd'] ?? '');
        $dbName = (string) ($mysql['db_name'] ?? '');

        if ($host === '' || $user === '' || $dbName === '') {
            return false;
        }

        $mysqli = @$this->createMysqli($host, $user, $pwd, $dbName);
        if ($mysqli->connect_errno) {
            return false;
        }

        $ok = $this->hasUsersTable($mysqli, $dbName);
        $mysqli->close();

        return $ok;
    }

    private function normalizeContact(array $input, $key, $default = '-')
    {
        $value = trim((string) ($input[$key] ?? $default));
        return $value === '' ? $default : $value;
    }

    private function normalizeEmail(array $input, $key, $default = '-')
    {
        $value = trim((string) ($input[$key] ?? $default));
        if ($value === '' || $value === '-') {
            return $default;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $default;
        }

        return $value;
    }

    private function writeFile($path, $content)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return ['ok' => false, 'error' => 'Directory config non trovata: ' . $dir];
        }
        if (!is_writable($dir)) {
            return ['ok' => false, 'error' => 'Directory non scrivibile: ' . $dir];
        }

        $written = @file_put_contents($path, $content, LOCK_EX);
        if ($written === false) {
            return ['ok' => false, 'error' => 'Scrittura file fallita: ' . $path];
        }

        return ['ok' => true];
    }

    private function fixGeneratedColumnInserts(string $sql): string
    {
        // MySQL 8.x rifiuta valori espliciti per colonne GENERATED VIRTUAL.
        // Il dump esportato senza --skip-generated include quei valori in INSERT ... VALUES.
        //
        // Tabella: users - colonna generata: superuser_unique_guard (posizione 19, ultima)
        // Fix: aggiungiamo la lista esplicita delle 18 colonne reali e rimuoviamo
        // il 19 valore (1 o NULL) dall'INSERT.

        $userCols = '`id`,`email`,`google_sub`,`google_avatar`,`password`,`gender`,'
            . '`is_administrator`,`is_superuser`,`is_moderator`,`is_master`,'
            . '`date_last_pass`,`session_version`,`date_sessions_revoked`,`date_actived`,'
            . '`date_created`,`date_last_signin`,`date_last_signout`,`date_last_seed`';

        // Passo 1: aggiunge la lista colonne (str_replace e binary-safe)
        $sql = str_replace(
            'INSERT INTO `users` VALUES',
            "INSERT INTO `users` ({$userCols}) VALUES",
            $sql,
        );

        // Passo 2: rimuove il valore della colonna generata (ultimo, sempre 1 o NULL)
        // L'anchor $ con /m corrisponde alla fine di ogni riga - sicuro anche con dati binari
        // perche mysqldump esegue l'escape dei byte speciali (null, newline) nelle stringhe.
        $sql = preg_replace(
            '/^(INSERT INTO `users` \(`id`[^\n]*),(?:NULL|[01])\);$/m',
            '$1);',
            $sql,
        );

        return $sql;
    }

    private function quoteIdentifier($value)
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', (string) $value);
    }

    private function getInstallLockPath()
    {
        return __DIR__ . '/../../configs/installed.php';
    }

    private function getAppConfigPath()
    {
        return __DIR__ . '/../../configs/app.php';
    }

    private function getDbConfigPath()
    {
        return __DIR__ . '/../../configs/db.php';
    }

    private function getSqlDumpPath()
    {
        return __DIR__ . '/../../database/logeon_db_core.sql';
    }

    private function getSqlDumpRelativePath(): string
    {
        return 'database/logeon_db_core.sql';
    }
}
