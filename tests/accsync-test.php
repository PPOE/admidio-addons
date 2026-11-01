<?php

// SPDX-License-Identifier: GPL-2.0-only
//
// Tests of pgacc/accsync.php and the grants of pgacc/pgacc_accsync.sql, run
// by tests/run.sh. accsync.php runs as a separate process against the HTTPS
// test server (tests/https-server.php) with self-signed certificates made
// here. Synthetic users only.

require __DIR__ . '/../pgacc/accsync.php';

const SOCKET_DSN = 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=admidio;charset=utf8mb4';
const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const PORT = 8443;
const OTHER_NAME_PORT = 8444;

$failures = 0;

function check($name, $condition)
{
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures++;
    }
}

$tmp = sys_get_temp_dir() . '/accsync-test-' . getmypid();
mkdir($tmp);

// A self-signed certificate (with its key) for the subject alternative name
// $san; returns [PEM file with certificate and key, certificate file].
function certificate($name, $san)
{
    global $tmp;
    $cnf = "$tmp/$name.cnf";
    file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nsubjectAltName = $san\nbasicConstraints = critical, CA:TRUE\n");
    $options = ['config' => $cnf, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + $options);
    $csr = openssl_csr_new(['commonName' => $name], $key, $options);
    $cert = openssl_csr_sign($csr, null, $key, 30, ['x509_extensions' => 'ext'] + $options, random_int(1, PHP_INT_MAX));
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem, null, $options);
    file_put_contents("$tmp/$name.crt", $certPem);
    file_put_contents("$tmp/$name.pem", $certPem . $keyPem);
    return ["$tmp/$name.pem", "$tmp/$name.crt"];
}

[$serverPem, $serverCrt] = certificate('server', 'IP:127.0.0.1');
[, $otherCrt] = certificate('other', 'IP:127.0.0.1');
[$otherNamePem] = certificate('othername', 'DNS:other.invalid');
$document = "$tmp/document.json";
file_put_contents($document, '[]');

$servers = [];
foreach ([PORT => $serverPem, OTHER_NAME_PORT => $otherNamePem] as $port => $pem) {
    $servers[] = proc_open(
        [PHP_BINARY, __DIR__ . '/https-server.php', (string) $port, $pem, TOKEN, $document],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
}
for ($try = 0; $try < 50 && @fsockopen('127.0.0.1', PORT) === false; $try++) {
    usleep(100000);
}

$secrets = [
    'url' => 'https://127.0.0.1:' . PORT . '/mbapi.php',
    'token' => TOKEN,
    'cafile' => $serverCrt,
    'dsn' => SOCKET_DSN,
    'user' => 'pgacc_accsync',
    'password' => 'test-writer-password',
];

// A secrets file with $config (an array or PHP source); returns its path.
function secrets_file($config)
{
    global $tmp;
    static $count = 0;
    $file = "$tmp/secrets-" . $count++ . '.php';
    file_put_contents($file, is_string($config) ? $config : '<?php return ' . var_export($config, true) . ';');
    return $file;
}

$outputs = '';

// Runs accsync.php with $args; [exit status, stdout, stderr]. $document is
// what the server serves; $secrets (array) is written to a secrets file
// passed with --secrets, unless $args already chooses one.
function accsync($payments, $override = [], $args = [], $env = [])
{
    global $secrets, $document, $outputs;
    file_put_contents($document, is_string($payments) ? $payments : json_encode($payments));
    if (!array_filter($args, fn ($arg) => str_starts_with($arg, '--secrets')) && !isset($env['ACCSYNC_SECRETS'])) {
        $args[] = '--secrets=' . secrets_file($override + $secrets);
    }
    $process = proc_open(
        array_merge([PHP_BINARY, __DIR__ . '/../pgacc/accsync.php'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env + ['PATH' => getenv('PATH')],
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $status = proc_close($process);
    $outputs .= $stdout . $stderr;
    return [$status, $stdout, $stderr];
}

// The lines of $output without the summary line.
function changes($output)
{
    return array_values(array_filter(explode("\n", $output), fn ($line) => $line !== '' && !str_contains($line, ' payments, ')));
}

// Synthetic data. Dates relative to the database's today.
$root = new PDO(SOCKET_DSN, 'root', 'test-root-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$today = new DateTimeImmutable($root->query('SELECT CURRENT_DATE')->fetchColumn());
function day($offset)
{
    global $today;
    return $today->modify("$offset day")->format('Y-m-d');
}
function sql($query, $params = [])
{
    global $root;
    $root->prepare($query)->execute($params);
}
foreach ([1 => ['TEXT', 'LAST_NAME'], 2 => ['TEXT', 'FIRST_NAME'], 26 => ['DATE', 'PAID_UNTIL'], 27 => ['DECIMAL', 'AMOUNT']] as $id => [$type, $name]) {
    sql("INSERT INTO adm_user_fields (usf_id, usf_cat_id, usf_uuid, usf_type, usf_name_intern, usf_name, usf_sequence)
        VALUES (?, 1, UUID(), ?, ?, ?, ?)", [$id, $type, $name, $name, $id]);
}
foreach (array_merge([2], range(37, 45)) as $id) {
    sql('INSERT INTO adm_roles (rol_id, rol_cat_id, rol_uuid, rol_name) VALUES (?, 1, UUID(), ?)', [$id, "Role $id"]);
}
function user($id, $valid = 1, $begin = -400, $end = '9999-12-31')
{
    sql('INSERT INTO adm_users (usr_id, usr_uuid, usr_valid) VALUES (?, UUID(), ?)', [$id, $valid]);
    sql('INSERT INTO adm_members (mem_rol_id, mem_usr_id, mem_uuid, mem_begin, mem_end) VALUES (2, ?, UUID(), ?, ?)',
        [$id, day($begin), $end]);
}
function field($user, $field, $value)
{
    sql('INSERT INTO adm_user_data (usd_usr_id, usd_usf_id, usd_value) VALUES (?, ?, ?)', [$user, $field, $value]);
}
// All user data as "user/field" => value.
function data()
{
    global $root;
    $data = [];
    foreach ($root->query('SELECT usd_usr_id, usd_usf_id, usd_value FROM adm_user_data ORDER BY 1, 2') as $row) {
        $data["$row[0]/$row[1]"] = $row[2];
    }
    return $data;
}

user(1);                                  // no values yet; names
field(1, 1, 'Secret-Lastname');
field(1, 2, 'Secret-Firstname');
user(2);                                  // earlier date, higher amount
field(2, 26, '2025-12-31');
field(2, 27, '50.00');
user(3);                                  // later date entered by hand
field(3, 26, '2027-12-31');
field(3, 27, '20.00');
user(4);                                  // empty values
field(4, 26, '');
field(4, 27, '');
user(5);                                  // NULL values
field(5, 26, null);
field(5, 27, null);
user(6, 0);                               // not valid
user(7, 1, -400, day(-1));                // role 2 ended yesterday
user(8, 1, +1);                           // role 2 begins tomorrow
user(10);                                 // not in the document
field(10, 26, '2025-12-31');
field(10, 27, '30.00');
user(11);                                 // unchanged
field(11, 26, '2026-12-31');
field(11, 27, '20.00');
user(12, 1, 0, day(0));                   // role 2 begins and ends today
user(13);                                 // role 2 of another user only: none
sql('DELETE FROM adm_members WHERE mem_usr_id = 13');
sql('INSERT INTO adm_members (mem_rol_id, mem_usr_id, mem_uuid, mem_begin) VALUES (38, 13, UUID(), ?)', [day(-400)]);

$payments = [
    ['id' => 1, 'amount_cents' => 2000, 'paid_until' => '2026-12-31'],
    ['id' => 2, 'amount_cents' => 2505, 'paid_until' => '2026-12-31'],
    ['id' => 3, 'amount_cents' => 7, 'paid_until' => '2026-12-31'],
    ['id' => 4, 'amount_cents' => 0, 'paid_until' => '2026-12-31'],
    ['id' => 5, 'amount_cents' => 123456789012, 'paid_until' => '2026-12-31'],
    ['id' => 6, 'amount_cents' => 2000, 'paid_until' => '2026-12-31'],
    ['id' => 7, 'amount_cents' => 2000, 'paid_until' => '2026-12-31'],
    ['id' => 8, 'amount_cents' => 2000, 'paid_until' => '2026-12-31'],
    ['id' => 9, 'amount_cents' => 2000, 'paid_until' => '2026-12-31'],
    ['id' => 11, 'amount_cents' => 2000, 'paid_until' => '2026-12-31'],
    ['id' => 12, 'amount_cents' => 2000, 'paid_until' => '2026-12-31'],
    ['id' => 13, 'amount_cents' => 2000, 'paid_until' => '2026-12-31'],
];
$initial = data();

// Validation of the document (no database).
$valid = '[{"id":1,"amount_cents":2000,"paid_until":"2026-12-31"},{"id":2,"amount_cents":0,"paid_until":"2028-02-29"}]';
check('valid document', accsync_validate($valid) === [
    ['id' => 1, 'amount_cents' => 2000, 'paid_until' => '2026-12-31'],
    ['id' => 2, 'amount_cents' => 0, 'paid_until' => '2028-02-29'],
]);
check('empty list: valid, no payments', accsync_validate('[]') === []);
check('empty list with spaces: valid', accsync_validate(" [ ]\n") === []);
$row = '{"id":1,"amount_cents":2000,"paid_until":"2026-12-31"}';
$invalid = [
    'empty body' => '',
    'not JSON' => 'error',
    'truncated' => '[' . $row,
    'trailing garbage' => "[$row]x",
    'null' => 'null',
    'a number' => '1',
    'an object' => '{}',
    'an object of rows' => '{"0":' . $row . '}',
    'row not an object' => '[[1,2000,"2026-12-31"]]',
    'row is null' => '[null]',
    'missing id' => '[{"amount_cents":2000,"paid_until":"2026-12-31"}]',
    'missing amount' => '[{"id":1,"paid_until":"2026-12-31"}]',
    'missing date' => '[{"id":1,"amount_cents":2000}]',
    'extra key' => '[{"id":1,"amount_cents":2000,"paid_until":"2026-12-31","name":"x"}]',
    'id as string' => '[{"id":"1","amount_cents":2000,"paid_until":"2026-12-31"}]',
    'id as float' => '[{"id":1.0,"amount_cents":2000,"paid_until":"2026-12-31"}]',
    'id zero' => '[{"id":0,"amount_cents":2000,"paid_until":"2026-12-31"}]',
    'id negative' => '[{"id":-1,"amount_cents":2000,"paid_until":"2026-12-31"}]',
    'id too large for an integer' => '[{"id":99999999999999999999,"amount_cents":2000,"paid_until":"2026-12-31"}]',
    'id true' => '[{"id":true,"amount_cents":2000,"paid_until":"2026-12-31"}]',
    'duplicate id' => "[$row,$row]",
    'duplicate id, other values' => "[$row," . '{"id":1,"amount_cents":3000,"paid_until":"2027-12-31"}]',
    'negative amount' => '[{"id":1,"amount_cents":-1,"paid_until":"2026-12-31"}]',
    'amount as float' => '[{"id":1,"amount_cents":20.5,"paid_until":"2026-12-31"}]',
    'amount as string' => '[{"id":1,"amount_cents":"2000","paid_until":"2026-12-31"}]',
    'amount null' => '[{"id":1,"amount_cents":null,"paid_until":"2026-12-31"}]',
    'amount too large for an integer' => '[{"id":1,"amount_cents":1e30,"paid_until":"2026-12-31"}]',
    'date not real' => '[{"id":1,"amount_cents":2000,"paid_until":"2026-02-29"}]',
    'date month 13' => '[{"id":1,"amount_cents":2000,"paid_until":"2026-13-01"}]',
    'date year 0' => '[{"id":1,"amount_cents":2000,"paid_until":"0000-12-31"}]',
    'date without leading zeros' => '[{"id":1,"amount_cents":2000,"paid_until":"2026-1-5"}]',
    'date German' => '[{"id":1,"amount_cents":2000,"paid_until":"31.12.2026"}]',
    'date with time' => '[{"id":1,"amount_cents":2000,"paid_until":"2026-12-31 00:00"}]',
    'date with newline' => '[{"id":1,"amount_cents":2000,"paid_until":"2026-12-31\n"}]',
    'date as number' => '[{"id":1,"amount_cents":2000,"paid_until":20261231}]',
    'date null' => '[{"id":1,"amount_cents":2000,"paid_until":null}]',
];
foreach ($invalid as $name => $body) {
    check("invalid document: $name", accsync_validate($body) === null);
}
check('decimal euros from cents', array_map('accsync_euros', [0, 7, 70, 2000, 2505, 123456789012, PHP_INT_MAX])
    === ['0.00', '0.07', '0.70', '20.00', '25.05', '1234567890.12', '92233720368547758.07']);

// Refusals before any write: non-zero exit, data unchanged.
$refusals = [
    'no secrets file given' => accsync($payments, [], ['--secrets=']),
    'secrets file missing' => accsync($payments, [], ["--secrets=$tmp/missing.php"]),
    'secrets file not an array' => accsync($payments, [], ['--secrets=' . secrets_file('<?php return 1;')]),
    'secrets file with a syntax error' => accsync($payments, [], ['--secrets=' . secrets_file('<?php return [;')]),
    'unknown option' => accsync($payments, [], ['--dryrun']),
    'empty token' => accsync($payments, ['token' => '']),
    'token not a string' => accsync($payments, ['token' => 1]),
    'URL not https' => accsync($payments, ['url' => 'http://127.0.0.1:' . PORT . '/mbapi.php']),
    'URL missing' => accsync($payments, ['url' => '']),
    'cafile missing' => accsync($payments, ['cafile' => "$tmp/missing.crt"]),
    'cafile empty' => accsync($payments, ['cafile' => '']),
    'wrong database password' => accsync($payments, ['password' => 'wrong']),
    'wrong token' => accsync($payments, ['token' => strrev(TOKEN)]),
    'HTTP 500' => accsync($payments, ['url' => 'https://127.0.0.1:' . PORT . '/error']),
    'HTTP 404' => accsync($payments, ['url' => 'https://127.0.0.1:' . PORT . '/missing']),
    'redirect not followed' => accsync($payments, ['url' => 'https://127.0.0.1:' . PORT . '/redirect']),
    'certificate not the cafile' => accsync($payments, ['cafile' => $otherCrt]),
    'certificate for another name' => accsync($payments, ['url' => 'https://127.0.0.1:' . OTHER_NAME_PORT . '/mbapi.php']),
    'nothing listening' => accsync($payments, ['url' => 'https://127.0.0.1:9/mbapi.php']),
    'timeout' => accsync($payments, ['url' => 'https://127.0.0.1:' . PORT . '/slow', 'timeout' => 1]),
    'invalid document' => accsync(array_merge($payments, [['id' => 1, 'amount_cents' => 1, 'paid_until' => '2026-12-31']])),
    'not JSON' => accsync('<html>'),
];
foreach ($refusals as $name => [$status, $stdout, $stderr]) {
    check("refused: $name (exit status, message, no writes)", $status !== 0 && $stdout === '' && $stderr !== '' && data() === $initial);
}

// Deployment check: each broken expectation stops the run before the fetch.
$broken = [
    'field 26 not a date' => ["UPDATE adm_user_fields SET usf_type = 'TEXT' WHERE usf_id = 26", "UPDATE adm_user_fields SET usf_type = 'DATE' WHERE usf_id = 26"],
    'field 27 not a decimal' => ["UPDATE adm_user_fields SET usf_type = 'NUMBER' WHERE usf_id = 27", "UPDATE adm_user_fields SET usf_type = 'DECIMAL' WHERE usf_id = 27"],
    'field 1 not the last name' => ["UPDATE adm_user_fields SET usf_name_intern = 'CITY' WHERE usf_id = 1", "UPDATE adm_user_fields SET usf_name_intern = 'LAST_NAME' WHERE usf_id = 1"],
    'field 2 missing' => ['UPDATE adm_user_fields SET usf_id = 102 WHERE usf_id = 2', 'UPDATE adm_user_fields SET usf_id = 2 WHERE usf_id = 102'],
    'role 2 missing' => ['UPDATE adm_roles SET rol_id = 102 WHERE rol_id = 2', 'UPDATE adm_roles SET rol_id = 2 WHERE rol_id = 102'],
    'role 45 missing' => ['UPDATE adm_roles SET rol_id = 145 WHERE rol_id = 45', 'UPDATE adm_roles SET rol_id = 45 WHERE rol_id = 145'],
    'unique key missing' => ['DROP INDEX adm_idx_usd_usr_usf_id ON adm_user_data', 'CREATE UNIQUE INDEX adm_idx_usd_usr_usf_id ON adm_user_data (usd_usr_id, usd_usf_id)'],
    'key not unique' => ['DROP INDEX adm_idx_usd_usr_usf_id ON adm_user_data; CREATE INDEX adm_idx_usd_usr_usf_id ON adm_user_data (usd_usr_id, usd_usf_id)',
        'DROP INDEX adm_idx_usd_usr_usf_id ON adm_user_data; CREATE UNIQUE INDEX adm_idx_usd_usr_usf_id ON adm_user_data (usd_usr_id, usd_usf_id)'],
    'unique key on other columns' => ['DROP INDEX adm_idx_usd_usr_usf_id ON adm_user_data; CREATE UNIQUE INDEX adm_idx_usd_usr_usf_id ON adm_user_data (usd_usr_id, usd_usf_id, usd_id)',
        'DROP INDEX adm_idx_usd_usr_usf_id ON adm_user_data; CREATE UNIQUE INDEX adm_idx_usd_usr_usf_id ON adm_user_data (usd_usr_id, usd_usf_id)'],
];
foreach ($broken as $name => [$break, $repair]) {
    $root->exec($break);
    [$status, $stdout, $stderr] = accsync($payments);
    $root->exec($repair);
    check("deployment check: $name", $status !== 0 && $stdout === '' && str_contains($stderr, 'deployment check') && data() === $initial);
}

// The empty list: no writes.
[$status, $stdout] = accsync([]);
check('empty list: exit 0, no writes', $status === 0 && changes($stdout) === [] && data() === $initial);

// Dry run: the changes are printed, nothing is written.
[$status, $dryStdout] = accsync($payments, [], ['--dry-run']);
check('dry run: exit 0, nothing written', $status === 0 && data() === $initial);
check('dry run: the changes are printed as such', count(changes($dryStdout)) === 16
    && array_filter(changes($dryStdout), fn ($line) => !str_starts_with($line, 'dry run: ')) === []);

// Rollback: an SQL error on a later member undoes the earlier writes.
user(20);
$root->exec("CREATE TRIGGER fail_20 BEFORE INSERT ON adm_user_data FOR EACH ROW
    IF NEW.usd_usr_id = 20 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test'; END IF");
[$status, $stdout, $stderr] = accsync(array_merge($payments, [['id' => 20, 'amount_cents' => 2000, 'paid_until' => '2026-12-31']]));
$root->exec('DROP TRIGGER fail_20');
check('SQL error: non-zero exit, rolled back', $status !== 0 && $stderr !== '' && data() === $initial);

// The run.
[$status, $stdout, $stderr] = accsync($payments);
$data = data();
check('run: exit 0', $status === 0);
check('new member: date and amount inserted', ($data['1/26'] ?? null) === '2026-12-31' && ($data['1/27'] ?? null) === '20.00');
check('later date from pgacc: date moves forward', $data['2/26'] === '2026-12-31');
check('amount may go down', $data['2/27'] === '25.05');
check('earlier date from pgacc: the later date stays', $data['3/26'] === '2027-12-31');
check('amount below one euro', $data['3/27'] === '0.07');
check('empty values: set', $data['4/26'] === '2026-12-31' && $data['4/27'] === '0.00');
check('NULL values: set', $data['5/26'] === '2026-12-31' && $data['5/27'] === '1234567890.12');
check('ineligible and unknown ids: nothing written', !array_filter(array_keys($data), fn ($key) => in_array((int) $key, [6, 7, 8, 9, 13], true)));
check('role 2 only today: written', ($data['12/26'] ?? null) === '2026-12-31');
check('ids not in the document: unchanged', $data['10/26'] === '2025-12-31' && $data['10/27'] === '30.00');
check('equal values: unchanged', $data['11/26'] === '2026-12-31' && $data['11/27'] === '20.00');
check('names and other fields: unchanged', $data['1/1'] === 'Secret-Lastname' && $data['1/2'] === 'Secret-Firstname');
check('one line per change and skip, ids and values', changes($stdout) === [
    'user 1 field 26: (none) -> 2026-12-31',
    'user 1 field 27: (none) -> 20.00',
    'user 2 field 26: 2025-12-31 -> 2026-12-31',
    'user 2 field 27: 50.00 -> 25.05',
    'user 3 field 27: 20.00 -> 0.07',
    'user 4 field 26: "" -> 2026-12-31',
    'user 4 field 27: "" -> 0.00',
    'user 5 field 26: (none) -> 2026-12-31',
    'user 5 field 27: (none) -> 1234567890.12',
    'user 6 skipped: not an eligible member',
    'user 7 skipped: not an eligible member',
    'user 8 skipped: not an eligible member',
    'user 9 skipped: not an eligible member',
    'user 12 field 26: (none) -> 2026-12-31',
    'user 12 field 27: (none) -> 20.00',
    'user 13 skipped: not an eligible member',
]);
check('summary line', str_contains($stdout, '12 payments, 11 changes, 5 skipped'));
check('dry run printed the same changes', changes($dryStdout) === array_map(fn ($line) => "dry run: $line", changes($stdout)));
check('nothing on stderr', $stderr === '');

[$status, $stdout] = accsync($payments);
check('second run: no changes', $status === 0 && array_filter(changes($stdout), fn ($line) => !str_contains($line, 'skipped')) === [] && data() === $data);

// The environment variable instead of the option.
[$status] = accsync([], [], [], ['ACCSYNC_SECRETS' => secrets_file($secrets)]);
check('secrets file from ACCSYNC_SECRETS', $status === 0);

// A later date entered by hand while the run waits for the row: it stays.
// The open transaction holds the row lock until accsync.php is waiting.
$manual = new PDO(SOCKET_DSN, 'root', 'test-root-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$manual->beginTransaction();
$manual->exec("UPDATE adm_user_data SET usd_value = '2029-12-31' WHERE usd_usr_id = 2 AND usd_usf_id = 26");
file_put_contents($document, json_encode([['id' => 2, 'amount_cents' => 2505, 'paid_until' => '2028-12-31']]));
$process = proc_open(
    [PHP_BINARY, __DIR__ . '/../pgacc/accsync.php', '--secrets=' . secrets_file($secrets)],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
$waited = false;
for ($try = 0; $try < 100 && !$waited; $try++) {
    usleep(100000);
    $waited = (int) $root->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'")->fetchColumn() > 0;
}
$manual->commit();
$stdout = stream_get_contents($pipes[1]);
$status = proc_close($process);
check('concurrent later date: the run waited for the lock', $waited);
check('concurrent later date: it stays, exit 0, no change', $status === 0 && data()['2/26'] === '2029-12-31' && changes($stdout) === []);

check('no secrets and no names in any output', !str_contains($outputs, TOKEN) && !str_contains($outputs, 'test-writer-password')
    && !str_contains($outputs, 'Secret-'));

// The writer user may not touch anything else.
$writer = new PDO(SOCKET_DSN, 'pgacc_accsync', 'test-writer-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'read user names' => 'SELECT usr_login_name FROM adm_users',
    'change users' => 'UPDATE adm_users SET usr_valid = 0',
    'change memberships' => 'UPDATE adm_members SET mem_end = CURRENT_DATE',
    'delete user data' => 'DELETE FROM adm_user_data',
    'change profile fields' => "UPDATE adm_user_fields SET usf_type = 'TEXT'",
] as $name => $query) {
    try {
        $writer->exec($query);
        check("writer cannot $name", false);
    } catch (PDOException $e) {
        check("writer cannot $name", true);
    }
}

foreach ($servers as $server) {
    proc_terminate($server);
    proc_close($server);
}
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
echo $failures === 0 ? "All tests passed.\n" : "$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
