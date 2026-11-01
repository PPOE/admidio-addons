<?php

// SPDX-License-Identifier: GPL-2.0-only
//
// Tests of pgacc/members-api.php, the view pgacc_members and the entry point
// internal/members.php, run by tests/run.sh. Synthetic users only.

require __DIR__ . '/../pgacc/members-api.php';

const SOCKET_DSN = 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=admidio;charset=utf8mb4';
const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const PEER = '192.0.2.10';

$failures = 0;

function check($name, $condition)
{
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures++;
    }
}

$tmp = sys_get_temp_dir() . '/members-test-' . getmypid();
mkdir($tmp);

// A configuration file with $config; returns its path.
function config_file($name, $config)
{
    global $tmp;
    $file = "$tmp/$name.php";
    file_put_contents($file, is_string($config) ? $config : '<?php return ' . var_export($config, true) . ';');
    return $file;
}

$valid = [
    'token' => TOKEN,
    'peers' => [PEER],
    'dsn' => SOCKET_DSN,
    'user' => 'pgacc_members',
    'password' => 'test-reader-password',
];

function request($file, $server = [])
{
    $server += ['REMOTE_ADDR' => PEER, 'HTTP_AUTHORIZATION' => 'Bearer ' . TOKEN];
    if ($file !== null) {
        $server['PGACC_MEMBERS_CONFIG'] = $file;
    }
    return members_api_response($server);
}

// Refusals: 403 with an empty body.
$validFile = config_file('valid', $valid);
$refusals = [
    'no configuration path' => request(null),
    'empty configuration path' => request(''),
    'missing configuration file' => request("$tmp/missing.php"),
    'configuration file without an array' => request(config_file('scalar', '<?php return 1;')),
    'configuration file with a syntax error' => request(config_file('broken', '<?php return [;')),
    'empty token' => request(config_file('notoken', ['token' => ''] + $valid)),
    'missing token' => request(config_file('notoken2', array_diff_key($valid, ['token' => 1]))),
    'token not a string' => request(config_file('inttoken', ['token' => 1] + $valid)),
    'empty peer list' => request(config_file('nopeers', ['peers' => []] + $valid)),
    'missing peer list' => request(config_file('nopeers2', array_diff_key($valid, ['peers' => 1]))),
    'peer list not an array' => request(config_file('strpeers', ['peers' => PEER] + $valid)),
    'address not a peer' => request($validFile, ['REMOTE_ADDR' => '192.0.2.11']),
    'no address' => request($validFile, ['REMOTE_ADDR' => null]),
    'forwarded address of a peer is ignored' => request($validFile, ['REMOTE_ADDR' => '192.0.2.11', 'HTTP_X_FORWARDED_FOR' => PEER]),
    'no Authorization header' => request($validFile, ['HTTP_AUTHORIZATION' => null]),
    'wrong token' => request($validFile, ['HTTP_AUTHORIZATION' => 'Bearer ' . strrev(TOKEN)]),
    'token without Bearer' => request($validFile, ['HTTP_AUTHORIZATION' => TOKEN]),
    'token prefix only' => request($validFile, ['HTTP_AUTHORIZATION' => 'Bearer ' . substr(TOKEN, 0, 32)]),
    'empty token sent to an empty configured token' => request(config_file('notoken3', ['token' => ''] + $valid), ['HTTP_AUTHORIZATION' => 'Bearer ']),
];
foreach ($refusals as $name => $response) {
    check("refused: $name", $response === [403, '']);
}

// Database errors: 503 with an empty body.
check('503: wrong database password', request(config_file('badpw', ['password' => 'wrong'] + $valid)) === [503, '']);
check('503: no database settings', request(config_file('nodb', ['token' => TOKEN, 'peers' => [PEER]])) === [503, '']);
check('503: unreachable database', request(config_file('nosocket', ['dsn' => 'mysql:unix_socket=/nonexistent;dbname=admidio'] + $valid)) === [503, '']);

// The reader user may read the view only.
$reader = new PDO(SOCKET_DSN, 'pgacc_members', 'test-reader-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['adm_users', 'adm_members', 'adm_user_data'] as $table) {
    try {
        $reader->query("SELECT 1 FROM $table");
        check("reader cannot read $table", false);
    } catch (PDOException $e) {
        check("reader cannot read $table", true);
    }
}
$reader = null;

// The view, with synthetic users. Dates relative to the database's today.
$root = new PDO(SOCKET_DSN, 'root', 'test-root-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$today = new DateTimeImmutable($root->query('SELECT CURRENT_DATE')->fetchColumn());
function day($offset)
{
    global $today;
    return $today->modify("$offset day")->format('Y-m-d');
}
function uuid($id)
{
    return sprintf('00000000-0000-4000-8000-%012d', $id);
}
function user($id, $first, $last, $valid = 1)
{
    global $root;
    $root->prepare('INSERT INTO adm_users (usr_id, usr_uuid, usr_valid) VALUES (?, ?, ?)')->execute([$id, uuid($id), $valid]);
    foreach ([2 => $first, 1 => $last] as $field => $value) {
        if ($value !== null) {
            field($id, $field, $value);
        }
    }
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

user(1, 'Anna', 'Active');                        // member, LO 38, paid
member(1, 2, day(-400));
member(1, 38, day(-400));
field(1, 26, day(+1));
user(2, 'Ivo', 'Invalid', 0);                     // not valid
member(2, 2, day(-400));
user(3, 'Eva', 'Expired');                        // role 2 ended yesterday
member(3, 2, day(-400), day(-1));
user(4, 'Nina', 'NotYet');                        // role 2 begins tomorrow
member(4, 2, day(+1));
user(5, 'Lea', 'LastDay');                        // role 2 ends today; LO change today
member(5, 2, day(-400), day(0));
member(5, 38, day(-400), day(0));
member(5, 40, day(0));
user(6, 'Fred', 'FirstDay');                      // role 2 begins today; no LO
member(6, 2, day(0));
user(7, 'Max', 'Multi');                          // two role-2 and two LO memberships, same begin
member(7, 2, day(-400));
member(7, 2, day(-10));
member(7, 44, day(-10));
member(7, 39, day(-10));
member(7, 41, day(-400));
user(8, 'Olga', 'OldLo');                         // LO ended, next not begun; not an LO role; paid today
member(8, 2, day(-400));
member(8, 37, day(-400), day(-1));
member(8, 45, day(+1));
member(8, 46, day(-400));
member(8, 36, day(-400));
field(8, 26, day(0));
user(9, 'Paul', null);                            // no last name; paid in the past
member(9, 2, day(-400));
field(9, 26, day(-1));
user(10, null, 'Ümlaut-Ößer');                    // no first name; not a date
member(10, 2, day(-400));
field(10, 26, 'unknown');
user(11, 'Rolf', 'NoRole');                       // other roles only
member(11, 37, day(-400));

$response = request($validFile);
check('200 for a peer with the token', $response[0] === 200);
$rows = json_decode($response[1], true);
check('the body is a JSON list', is_array($rows) && array_is_list($rows));
$byId = [];
foreach ($rows ?? [] as $row) {
    $byId[$row['id']][] = $row;
}
$row = function ($id) use ($byId) {
    return count($byId[$id] ?? []) === 1 ? $byId[$id][0] : null;
};

check('only the eligible members, ordered by id', array_column($rows ?? [], 'id') === [1, 5, 6, 7, 8, 9, 10]);
check('member: id, uuid, name "first last", LO and paid', $row(1) === ['id' => 1, 'uuid' => uuid(1), 'name' => 'Anna Active', 'lo' => 38, 'paid' => 1]);
check('not valid: left out', !isset($byId[2]));
check('role 2 ended: left out', !isset($byId[3]));
check('role 2 not yet begun: left out', !isset($byId[4]));
check('role 2 ends today: included', $row(5) !== null);
check('role 2 begins today: included', $row(6) !== null);
check('LO change day: the LO that begins today', ($row(5)['lo'] ?? 0) === 40);
check('no LO: null', ($row(6) ?? ['lo' => 0])['lo'] === null);
check('several memberships: one row', count($byId[7] ?? []) === 1);
check('several LOs: the latest begin, then the lowest role id', ($row(7)['lo'] ?? 0) === 39);
check('LO ended or not begun, other roles: null', ($row(8) ?? ['lo' => 0])['lo'] === null);
check('paid until today: not paid', ($row(8)['paid'] ?? null) === 0);
check('paid until yesterday: not paid', ($row(9)['paid'] ?? null) === 0);
check('paid until: not a date: not paid', ($row(10)['paid'] ?? null) === 0);
check('no paid until: not paid', ($row(6)['paid'] ?? null) === 0);
check('missing last name', ($row(9)['name'] ?? null) === 'Paul ');
check('missing first name, UTF-8', ($row(10)['name'] ?? null) === ' Ümlaut-Ößer');
check('other roles only: left out', !isset($byId[11]));

// The entry point, through PHP's web server (REMOTE_ADDR 127.0.0.1). The
// prepended file sets the configuration path, as the web server's FastCGI
// parameter does in production.
$port = 8089;
$serverConfig = config_file('server', ['peers' => ['127.0.0.1']] + $valid);
$prepend = config_file('prepend', '<?php $_SERVER[\'PGACC_MEMBERS_CONFIG\'] = ' . var_export($serverConfig, true) . ';');
$process = proc_open(
    [PHP_BINARY, '-d', "auto_prepend_file=$prepend", '-S', "127.0.0.1:$port", '-t', __DIR__ . '/../internal'],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
);
$get = function ($token) use ($port) {
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'header' => $token === null ? '' : "Authorization: Bearer $token"]]);
    for ($try = 0; $try < 50; $try++) {
        $body = @file_get_contents("http://127.0.0.1:$port/members.php", false, $context);
        if ($body !== false) {
            return [(int) explode(' ', http_get_last_response_headers()[0])[1], $body, http_get_last_response_headers()];
        }
        usleep(100000);
    }
    return null;
};
$ok = $get(TOKEN);
check('entry point: 200 and the members as JSON', $ok !== null && $ok[0] === 200 && json_decode($ok[1], true) === $rows
    && in_array('Content-Type: application/json', $ok[2], true));
check('entry point: 403, empty body, without the token', $get(null) === null ? false : array_slice($get(null), 0, 2) === [403, '']);
check('entry point: 403, empty body, with a wrong token', $get(strrev(TOKEN)) === null ? false : array_slice($get(strrev(TOKEN)), 0, 2) === [403, '']);
proc_terminate($process);
proc_close($process);

array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
echo $failures === 0 ? "All tests passed.\n" : "$failures test(s) failed.\n";
exit($failures === 0 ? 0 : 1);
