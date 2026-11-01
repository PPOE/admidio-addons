<?php

// SPDX-License-Identifier: GPL-2.0-only
//
// Tests of liquid/members-api.php, the view liquid_members, the table
// liquid_region_plz and the entry point internal/liquid-members.php, run by
// tests/run.sh. Synthetic users only.

require __DIR__ . '/../liquid/members-api.php';
require __DIR__ . '/../pgacc/members-api.php';

const SOCKET_DSN = 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=admidio;charset=utf8mb4';
const TOKEN = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';
const PEER = '192.0.2.20';
const PGACC_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const PGACC_PEER = '192.0.2.10';

$failures = 0;

function check($name, $condition)
{
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures++;
    }
}

$tmp = sys_get_temp_dir() . '/liquid-members-test-' . getmypid();
mkdir($tmp);

// A secrets file with $secrets (an array or PHP source); returns its path.
function secrets_file($name, $secrets)
{
    global $tmp;
    $file = "$tmp/$name.php";
    file_put_contents($file, is_string($secrets) ? $secrets : '<?php return ' . var_export($secrets, true) . ';');
    return $file;
}

$valid = [
    'token' => TOKEN,
    'peers' => [PEER],
    'dsn' => SOCKET_DSN,
    'user' => 'liquid_members',
    'password' => 'test-liquid-reader-password',
];
$pgacc = [
    'token' => PGACC_TOKEN,
    'peers' => [PGACC_PEER],
    'dsn' => SOCKET_DSN,
    'user' => 'pgacc_members',
    'password' => 'test-reader-password',
];

function request($file, $server = [])
{
    $server += ['REMOTE_ADDR' => PEER, 'HTTP_AUTHORIZATION' => 'Bearer ' . TOKEN];
    if ($file !== null) {
        $server['LIQUID_MEMBERS_SECRETS'] = $file;
    }
    return liquid_members_api_response($server);
}

// The database is shared with the other tests: start from empty tables.
$root = new PDO(SOCKET_DSN, 'root', 'test-root-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['adm_users', 'adm_members', 'adm_user_data', 'adm_user_fields', 'adm_roles'] as $table) {
    $root->exec("DELETE FROM $table");
}

// Refusals: 403 with an empty body.
$validFile = secrets_file('valid', $valid);
$pgaccFile = secrets_file('pgacc', $pgacc);
$refusals = [
    'no secrets path' => request(null),
    'empty secrets path' => request(''),
    'missing secrets file' => request("$tmp/missing.php"),
    'secrets file without an array' => request(secrets_file('scalar', '<?php return 1;')),
    'secrets file with a syntax error' => request(secrets_file('broken', '<?php return [;')),
    'empty token' => request(secrets_file('notoken', ['token' => ''] + $valid)),
    'missing token' => request(secrets_file('notoken2', array_diff_key($valid, ['token' => 1]))),
    'empty peer list' => request(secrets_file('nopeers', ['peers' => []] + $valid)),
    'missing peer list' => request(secrets_file('nopeers2', array_diff_key($valid, ['peers' => 1]))),
    'address not a peer' => request($validFile, ['REMOTE_ADDR' => '192.0.2.21']),
    'no address' => request($validFile, ['REMOTE_ADDR' => null]),
    'forwarded address of a peer is ignored' => request($validFile, ['REMOTE_ADDR' => '192.0.2.21', 'HTTP_X_FORWARDED_FOR' => PEER]),
    'no Authorization header' => request($validFile, ['HTTP_AUTHORIZATION' => null]),
    'wrong token' => request($validFile, ['HTTP_AUTHORIZATION' => 'Bearer ' . strrev(TOKEN)]),
    'token without Bearer' => request($validFile, ['HTTP_AUTHORIZATION' => TOKEN]),
    'pgacc\'s secrets path is ignored' => request(null, ['PGACC_MEMBERS_SECRETS' => $validFile]),
    'pgacc\'s peer with pgacc\'s token' => request($validFile, ['REMOTE_ADDR' => PGACC_PEER, 'HTTP_AUTHORIZATION' => 'Bearer ' . PGACC_TOKEN]),
    'pgacc\'s token from the peer' => request($validFile, ['HTTP_AUTHORIZATION' => 'Bearer ' . PGACC_TOKEN]),
];
foreach ($refusals as $name => $response) {
    check("refused: $name", $response === [403, '']);
}
// The other way round: Liquid's peer and token on pgacc's /members.php.
$crossPeer = ['REMOTE_ADDR' => PEER, 'HTTP_AUTHORIZATION' => 'Bearer ' . TOKEN, 'PGACC_MEMBERS_SECRETS' => $pgaccFile];
check('refused: Liquid\'s peer with its token on /members.php', members_api_response($crossPeer) === [403, '']);
check('refused: Liquid\'s secrets path is ignored by /members.php', members_api_response(['LIQUID_MEMBERS_SECRETS' => $validFile] + array_diff_key($crossPeer, ['PGACC_MEMBERS_SECRETS' => 1])) === [403, '']);

// Database errors: 503 with an empty body, and only the class and code in
// the log.
$log = "$tmp/error.log";
ini_set('error_log', $log);
check('503: wrong database password', request(secrets_file('badpw', ['password' => 'wrong'] + $valid)) === [503, '']);
check('503: no database settings', request(secrets_file('nodb', ['token' => TOKEN, 'peers' => [PEER]])) === [503, '']);
check('503: unreachable database', request(secrets_file('nosocket', ['dsn' => 'mysql:unix_socket=/nonexistent;dbname=admidio'] + $valid)) === [503, '']);
check('503: pgacc\'s reader cannot read liquid_members', request(secrets_file('pgaccuser', ['user' => 'pgacc_members', 'password' => 'test-reader-password'] + $valid)) === [503, '']);
$logged = (string) @file_get_contents($log);
check('503: the log has the class and code only', preg_match_all('/liquid-members\.php: PDOException \S+$/m', $logged) === 4
    && !str_contains($logged, 'liquid_members') && !str_contains($logged, 'pgacc_members') && !str_contains($logged, 'wrong'));
ini_restore('error_log');

// The reader user may read the view only.
$reader = new PDO(SOCKET_DSN, 'liquid_members', 'test-liquid-reader-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['adm_users', 'adm_members', 'adm_user_data', 'adm_user_fields', 'adm_roles', 'liquid_region_plz', 'pgacc_members', 'pgacc_accsync_user_data'] as $table) {
    try {
        $reader->query("SELECT 1 FROM $table");
        check("reader cannot read $table", false);
    } catch (PDOException $e) {
        check("reader cannot read $table", true);
    }
}
check('reader can read liquid_members', $reader->query('SELECT COUNT(*) FROM liquid_members')->fetchColumn() !== false);
$reader = null;
$pgaccReader = new PDO(SOCKET_DSN, 'pgacc_members', 'test-reader-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['liquid_members', 'liquid_region_plz'] as $table) {
    try {
        $pgaccReader->query("SELECT 1 FROM $table");
        check("pgacc's reader cannot read $table", false);
    } catch (PDOException $e) {
        check("pgacc's reader cannot read $table", true);
    }
}
$pgaccReader = null;

// The region table, as applied by the deployment (and again by this test).
$regionFile = __DIR__ . '/../liquid/liquid_region_plz.sql';
function apply_sql($file)
{
    global $root;
    foreach (preg_split('/;\s*$/m', (string) file_get_contents($file)) as $statement) {
        if (trim(preg_replace('/^--.*$/m', '', $statement)) !== '') {
            $root->exec($statement);
        }
    }
}
$regionCounts = function () use ($root) {
    return $root->query('SELECT nuts, COUNT(*) FROM liquid_region_plz GROUP BY nuts ORDER BY nuts')->fetchAll(PDO::FETCH_KEY_PAIR);
};
check('regions: 23 postcodes for AT130 and 62 for AT221', $regionCounts() === ['AT130' => 23, 'AT221' => 62]);

// The view, with synthetic users. Dates relative to the database's today.
$today = new DateTimeImmutable($root->query('SELECT CURRENT_DATE')->fetchColumn());
function day($offset)
{
    global $today;
    return $today->modify("$offset day")->format('Y-m-d');
}
function user($id, $valid = 1)
{
    global $root;
    $root->prepare('INSERT INTO adm_users (usr_id, usr_uuid, usr_valid) VALUES (?, ?, ?)')
        ->execute([$id, sprintf('00000000-0000-4000-8000-%012d', $id), $valid]);
}
function field($user, $field, $value)
{
    global $root;
    $root->prepare('INSERT INTO adm_user_data (usd_usr_id, usd_usf_id, usd_value) VALUES (?, ?, ?)')->execute([$user, $field, $value]);
}
function member($user, $role, $begin, $end = '9999-12-31')
{
    global $root;
    $root->prepare('INSERT INTO adm_members (mem_rol_id, mem_usr_id, mem_uuid, mem_begin, mem_end) VALUES (?, ?, UUID(), ?, ?)')
        ->execute([$role, $user, $begin, $end]);
}
// A member (valid, role 2 since 2000) with the profile fields $fields.
function liquid_member($id, $fields = [], $lo = null)
{
    user($id);
    member($id, 2, '2000-01-01');
    if ($lo !== null) {
        member($id, $lo, '2000-01-01');
    }
    foreach ($fields as $field => $value) {
        field($id, $field, $value);
    }
}

$paid = [35 => day(-400), 26 => day(+100)];
liquid_member(1, $paid + [12 => 'anna@example.org', 4 => '1010'], 38);  // eligible, LO 38, Vienna
liquid_member(2, [12 => 'bert@example.org', 4 => '1010', 26 => day(+100)]);   // paid, not accredited
liquid_member(3, $paid + [12 => 'mitglied@piratenpartei.at', 33 => 'cora@example.org']);
liquid_member(4, $paid + [12 => 'mitglied@piratenpartei.at']);          // no backup address
liquid_member(5, $paid + [12 => 'not-an-email']);
liquid_member(6, $paid + [12 => str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 58) . '.example']);
liquid_member(7, $paid + [12 => "o'brien@example.org"]);
liquid_member(8, $paid + [12 => 'dora@example.org', 4 => '1010', 39 => '8010']);  // Vienna + Graz
liquid_member(9, $paid + [4 => '1010', 39 => '1020']);                  // the same code twice
liquid_member(10, $paid + [4 => '9999', 39 => '01010']);                // unknown, not exact
liquid_member(11, $paid + [4 => ' 8010 ']);                             // spaces
liquid_member(12, $paid);                                               // no postcode, no email
liquid_member(13, $paid + [12 => 'eve@example.org', 4 => '1010'], 40);
user(20, 0);                                                            // not valid
member(20, 2, '2000-01-01');
field(20, 12, 'ivo@example.org');
user(21);                                                               // role 2 ended yesterday
member(21, 2, '2000-01-01', day(-1));
user(22);                                                               // LO change today
member(22, 2, '2000-01-01');
member(22, 38, '2000-01-01', day(0));
member(22, 41, day(0));
user(23);                                                               // other roles only
member(23, 37, '2000-01-01');

$response = request($validFile);
check('200 for a peer with the token', $response[0] === 200);
$rows = json_decode($response[1], true);
check('the body is a JSON list', is_array($rows) && array_is_list($rows));
$byId = array_column($rows ?? [], null, 'id');
$pgaccRows = $root->query('SELECT id, lo FROM pgacc_members ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check('the members and LOs of pgacc_members, ordered by id', array_map(fn($r) => [$r['id'], $r['lo']], $rows ?? [])
    === array_map(fn($r) => [(int) $r['id'], $r['lo'] === null ? null : (int) $r['lo']], $pgaccRows));
check('the members: valid, active role 2', array_column($rows ?? [], 'id') === [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 22]);
check('member: id, LO, eligible, email and regions', ($byId[1] ?? null) === ['id' => 1, 'lo' => 38, 'eligible' => true, 'email' => 'anna@example.org', 'regions' => ['AT130']]);
check('LO change day: the LO that begins today', ($byId[22]['lo'] ?? 0) === 41);
check('no LO: null', array_key_exists('lo', $byId[12] ?? []) && $byId[12]['lo'] === null);
check('not eligible: no email, regions kept', ($byId[2] ?? null) === ['id' => 2, 'lo' => null, 'eligible' => false, 'email' => null, 'regions' => ['AT130']]);
check('email: backup field 33 for mitglied@piratenpartei.at', ($byId[3]['email'] ?? null) === 'cora@example.org');
check('email: mitglied@piratenpartei.at without a backup: null', array_key_exists('email', $byId[4] ?? []) && $byId[4]['email'] === null);
check('email: invalid: null, member still listed and eligible', ($byId[5] ?? null) === ['id' => 5, 'lo' => null, 'eligible' => true, 'email' => null, 'regions' => []]);
check('email: longer than 254 characters: null', array_key_exists('email', $byId[6] ?? []) && $byId[6]['email'] === null);
check('email: valid with an apostrophe: kept', ($byId[7]['email'] ?? null) === "o'brien@example.org");
check('email: none: null', array_key_exists('email', $byId[12] ?? []) && $byId[12]['email'] === null);
check('regions: fields 4 and 39, both codes, sorted', ($byId[8]['regions'] ?? null) === ['AT130', 'AT221']);
check('regions: the same code twice: once', ($byId[9]['regions'] ?? null) === ['AT130']);
check('regions: unknown or not exact: []', ($byId[10]['regions'] ?? null) === []);
check('regions: spaces trimmed', ($byId[11]['regions'] ?? null) === ['AT221']);
check('regions: no postcode: [] in the JSON', ($byId[12]['regions'] ?? null) === [] && str_contains($response[1], '{"id":12,"lo":null,"eligible":true,"email":null,"regions":[]}'));
check('other LO, same region', ($byId[13] ?? null) === ['id' => 13, 'lo' => 40, 'eligible' => true, 'email' => 'eve@example.org', 'regions' => ['AT130']]);

// A postcode with two codes: both count. Applying the region file again
// restores exactly its rows, also when applied twice.
$root->exec("INSERT INTO liquid_region_plz (plz, nuts) VALUES ('8010', 'AT999')");
check('regions: a postcode with two codes: both', $root->query('SELECT regions FROM liquid_members WHERE id = 11')->fetchColumn() === 'AT221,AT999');
apply_sql($regionFile);
apply_sql($regionFile);
check('regions: the region file applied twice: the same 85 rows', $regionCounts() === ['AT130' => 23, 'AT221' => 62]);
check('regions: the extra code is gone', $root->query('SELECT regions FROM liquid_members WHERE id = 11')->fetchColumn() === 'AT221');

// Eligibility (adm1.php): accredited (field 35) on or before today, and
// paid (field 26) or accredited at most 30 days ago. The database's clock is
// set for this session to $date.
$root->exec('DELETE FROM adm_user_data');
$eligibleOn = function ($date, $fields) use ($root) {
    $root->exec('DELETE FROM adm_user_data WHERE usd_usr_id = 12');
    foreach ($fields as $field => $value) {
        field(12, $field, $value);
    }
    field(12, 12, 'dora@example.org');
    $root->exec("SET timestamp = UNIX_TIMESTAMP('$date 12:00:00')");
    $row = $root->query('SELECT eligible, email FROM liquid_members WHERE id = 12')->fetch(PDO::FETCH_NUM);
    $root->exec('SET timestamp = DEFAULT');
    if ($row === false) {
        return null;
    }
    // The email only when eligible.
    return $row[1] === ($row[0] ? 'dora@example.org' : null) ? (int) $row[0] : 'email mismatch';
};
$T = '2026-06-15';
$cases = [
    'accredited tomorrow, paid: no' => [[35 => '2026-06-16', 26 => '2026-12-31'], 0],
    'accredited today, not paid: yes (trial month)' => [[35 => '2026-06-15'], 1],
    'accredited 30 days ago, not paid: yes' => [[35 => '2026-05-16'], 1],
    'accredited 31 days ago, not paid: no' => [[35 => '2026-05-15'], 0],
    'accredited, paid until 30 days ago: yes' => [[35 => '2025-01-01', 26 => '2026-05-16'], 1],
    'accredited, paid until 31 days ago: no' => [[35 => '2025-01-01', 26 => '2026-05-15'], 0],
    'accredited, paid until later: yes' => [[35 => '2025-01-01', 26 => '2026-12-31'], 1],
    'paid, not accredited: no' => [[26 => '2026-12-31'], 0],
    'nothing: no' => [[], 0],
    'accredited: not a calendar date: no' => [[35 => '2026-02-30', 26 => '2026-12-31'], 0],
    'accredited: not zero-padded: no' => [[35 => '2026-6-1', 26 => '2026-12-31'], 0],
    'accredited: trailing text: no' => [[35 => '2026-06-01x', 26 => '2026-12-31'], 0],
    'accredited: another format: no' => [[35 => '01.06.2026', 26 => '2026-12-31'], 0],
    'accredited: zero date: no' => [[35 => '0000-00-00', 26 => '2026-12-31'], 0],
    'accredited: zero year: no' => [[35 => '0000-06-01', 26 => '2026-12-31'], 0],
    'accredited: zero month: no' => [[35 => '2026-00-01', 26 => '2026-12-31'], 0],
    'accredited: zero day: no' => [[35 => '2026-06-00', 26 => '2026-12-31'], 0],
    'accredited on a leap day: yes' => [[35 => '2024-02-29', 26 => '2026-12-31'], 1],
    'paid until: not a calendar date: no' => [[35 => '2025-01-01', 26 => '2026-06-31'], 0],
    'paid until: another format: no' => [[35 => '2025-01-01', 26 => '31.12.2026'], 0],
    'paid until: trailing text: no' => [[35 => '2025-01-01', 26 => '2026-12-31x'], 0],
    'paid until: zero date: no' => [[35 => '2025-01-01', 26 => '0000-00-00'], 0],
    'paid until: zero month: no' => [[35 => '2025-01-01', 26 => '2026-00-31'], 0],
    'paid until: zero day: no' => [[35 => '2025-01-01', 26 => '2026-12-00'], 0],
    'paid until a leap day: yes' => [[35 => '2025-01-01', 26 => '2028-02-29'], 1],
];
foreach ($cases as $name => [$fields, $expected]) {
    check("eligible: $name", $eligibleOn($T, $fields) === $expected);
}
$december = [35 => '2025-01-01', 26 => '2026-12-31'];
check('eligible: paid until 31 December: on 30 January', $eligibleOn('2027-01-30', $december) === 1);
check('eligible: paid until 31 December: not on 31 January', $eligibleOn('2027-01-31', $december) === 0);
check('eligible: leap day + 30 days', $eligibleOn('2024-03-30', [35 => '2024-02-29']) === 1 && $eligibleOn('2024-03-31', [35 => '2024-02-29']) === 0);

// The entry point, through PHP's web server (REMOTE_ADDR 127.0.0.1). The
// prepended file sets the secrets path, as the web server's FastCGI
// parameter does in production.
$port = 8090;
$serverSecrets = secrets_file('server', ['peers' => ['127.0.0.1']] + $valid);
$prepend = secrets_file('prepend', '<?php $_SERVER[\'LIQUID_MEMBERS_SECRETS\'] = ' . var_export($serverSecrets, true) . ';');
$process = proc_open(
    [PHP_BINARY, '-d', "auto_prepend_file=$prepend", '-S', "127.0.0.1:$port", '-t', __DIR__ . '/../internal'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
);
$get = function ($token, $path = '/liquid-members.php') use ($port) {
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'header' => $token === null ? '' : "Authorization: Bearer $token"]]);
    for ($try = 0; $try < 50; $try++) {
        $body = @file_get_contents("http://127.0.0.1:$port$path", false, $context);
        if ($body !== false) {
            return [(int) explode(' ', http_get_last_response_headers()[0])[1], $body, http_get_last_response_headers()];
        }
        usleep(100000);
    }
    return null;
};
$expected = liquid_members_api_response(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . TOKEN, 'LIQUID_MEMBERS_SECRETS' => $serverSecrets]);
$ok = $get(TOKEN);
check('entry point: 200 and the members as JSON', $ok !== null && $ok[0] === 200 && $expected[0] === 200 && $ok[1] === $expected[1]
    && in_array('Content-Type: application/json', $ok[2], true));
check('entry point: 403, empty body, without the token', $get(null) === null ? false : array_slice($get(null), 0, 2) === [403, '']);
check('entry point: 403, empty body, with a wrong token', $get(strrev(TOKEN)) === null ? false : array_slice($get(strrev(TOKEN)), 0, 2) === [403, '']);
check('entry point: /members.php refuses Liquid\'s token', $get(TOKEN, '/members.php') === null ? false : array_slice($get(TOKEN, '/members.php'), 0, 2) === [403, '']);
proc_terminate($process);
proc_close($process);

array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
echo $failures === 0 ? "All tests passed.\n" : "$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
