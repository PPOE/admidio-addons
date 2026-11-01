<?php

// SPDX-License-Identifier: GPL-2.0-only
//
// Payment status from pgacc (CLI, run by a systemd timer):
//   php accsync.php [--dry-run] [--secrets=<file>]
// Fetches pgacc's internal endpoint (JSON [{"id": int, "amount_cents": int,
// "paid_until": "YYYY-MM-DD"}, ...]) and writes "paid until" (profile field
// 26; a stored value stays only if it is a later ISO date) and the amount in
// euros (field 27; exactly, also down) of the eligible members into the
// Admidio database, in one
// transaction. Ids that are not eligible members are skipped; members not in
// the document are left unchanged. Before that, a deployment check, and the
// whole document must be valid; otherwise nothing is written. Prints a
// summary (counts only: the journal gets no payment data); exit status 0 on
// success, 1 (and a message on stderr) otherwise. --dry-run (run by hand)
// does the same, also prints one line per change or skip, and rolls back.
//
// The secrets file (outside the document root; --secrets or the environment
// variable ACCSYNC_SECRETS) returns an array:
// ['url' => 'https://<pgacc internal address>:<port>/mbapi.php',
//  'token' => '<hex>', 'cafile' => '<pgacc certificate>',
//  'dsn' => 'mysql:unix_socket=...;dbname=...;charset=utf8mb4',
//  'user' => 'pgacc_accsync', 'password' => '...'].

const ACCSYNC_MAX_BODY = 1048576;
const ACCSYNC_TIMEOUT = 20;

class AccsyncError extends Exception
{
}

// The secrets from $file; throws unless the URL is https, the token is a
// non-empty string and the cafile exists.
function accsync_secrets($file)
{
    if (!is_string($file) || $file === '' || !is_file($file)) {
        throw new AccsyncError('no secrets file (--secrets or ACCSYNC_SECRETS)');
    }
    try {
        $secrets = include $file;
    } catch (Throwable $e) {
        throw new AccsyncError('cannot read the secrets file');
    }
    if (!is_array($secrets)) {
        throw new AccsyncError('the secrets file does not return an array');
    }
    if (!is_string($secrets['url'] ?? null) || !str_starts_with($secrets['url'], 'https://')) {
        throw new AccsyncError('the URL must start with https://');
    }
    if (!is_string($secrets['token'] ?? null) || $secrets['token'] === '') {
        throw new AccsyncError('no token');
    }
    if (!is_string($secrets['cafile'] ?? null) || !is_file($secrets['cafile'])) {
        throw new AccsyncError('no cafile');
    }
    return $secrets;
}

// Throws unless profile fields 1, 2, 26 and 27 and roles 2 and 37-45 exist as
// expected and adm_user_data has a unique key on exactly (usd_usr_id,
// usd_usf_id), which the upserts rely on.
function accsync_check_deployment($pdo)
{
    $fields = $pdo->query('SELECT usf_id, usf_type, usf_name_intern FROM adm_user_fields WHERE usf_id IN (1, 2, 26, 27) ORDER BY usf_id')
        ->fetchAll(PDO::FETCH_NUM);
    $types = array_map(fn ($row) => [(int) $row[0], $row[1], in_array((int) $row[0], [1, 2], true) ? $row[2] : null], $fields);
    if ($types !== [[1, 'TEXT', 'LAST_NAME'], [2, 'TEXT', 'FIRST_NAME'], [26, 'DATE', null], [27, 'DECIMAL', null]]) {
        throw new AccsyncError('deployment check: profile fields 1, 2, 26, 27 not as expected');
    }
    $roles = $pdo->query('SELECT rol_id FROM adm_roles WHERE rol_id = 2 OR rol_id BETWEEN 37 AND 45 ORDER BY rol_id')
        ->fetchAll(PDO::FETCH_COLUMN);
    if (array_map('intval', $roles) !== array_merge([2], range(37, 45))) {
        throw new AccsyncError('deployment check: roles 2, 37-45 missing');
    }
    $keys = $pdo->query("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'adm_user_data' AND NON_UNIQUE = 0 GROUP BY INDEX_NAME")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!in_array('usd_usr_id,usd_usf_id', $keys, true)) {
        throw new AccsyncError('deployment check: no unique key on adm_user_data (usd_usr_id, usd_usf_id)');
    }
}

// The body of the endpoint: https only, the certificate verified against the
// cafile alone, no redirects, a timeout for connection, headers and body
// together ($timeout seconds; 20 except in the tests; PHP's own stream
// timeout applies to each read only), at most 1 MiB, HTTP 200 required.
// Throws otherwise.
function accsync_fetch($secrets, $timeout = ACCSYNC_TIMEOUT)
{
    $deadline = microtime(true) + $timeout;
    $url = parse_url($secrets['url']);
    if (($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user']) || isset($url['pass'])) {
        throw new AccsyncError('fetch failed: not an https URL');
    }
    $port = $url['port'] ?? 443;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => trim($url['host'], '[]'),
            'allow_self_signed' => false,
            'cafile' => $secrets['cafile'],
        ],
    ]);
    $stream = @stream_socket_client("ssl://{$url['host']}:$port", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
    if ($stream === false) {
        throw new AccsyncError('fetch failed: ' . ($error !== '' ? $error : (error_get_last()['message'] ?? 'unknown error')));
    }
    // HTTP/1.0: no chunked body; the connection closes after the response.
    $request = 'GET ' . ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '') . " HTTP/1.0\r\n"
        . 'Host: ' . $url['host'] . (isset($url['port']) ? ':' . $port : '') . "\r\n"
        . 'Authorization: Bearer ' . $secrets['token'] . "\r\nConnection: close\r\n\r\n";
    $response = '';
    $timedOut = false;
    $limit = 65536 + ACCSYNC_MAX_BODY + 1;
    $written = @fwrite($stream, $request) === strlen($request);
    while ($written && !feof($stream) && strlen($response) < $limit) {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            $timedOut = true;
            break;
        }
        stream_set_timeout($stream, (int) $left, (int) (($left - (int) $left) * 1000000));
        $chunk = @fread($stream, min(65536, $limit - strlen($response)));
        if ($chunk === false || stream_get_meta_data($stream)['timed_out']) {
            $timedOut = true;
            break;
        }
        $response .= $chunk;
    }
    $complete = feof($stream);
    fclose($stream);
    if (!$written || $timedOut) {
        throw new AccsyncError('fetch failed: ' . ($written ? 'timeout' : 'cannot send the request'));
    }
    $end = strpos($response, "\r\n\r\n");
    $status = $end === false ? '' : strstr($response, "\r\n", true);
    if (!preg_match('#^HTTP/\S+ 200( |$)#', $status)) {
        throw new AccsyncError('fetch failed: ' . ($status === '' ? 'no status' : $status));
    }
    $body = substr($response, $end + 4);
    if (!$complete || strlen($body) > ACCSYNC_MAX_BODY) {
        throw new AccsyncError('fetch failed: incomplete or too large body');
    }
    return $body;
}

// The payments of $body as a list of ['id', 'amount_cents', 'paid_until'],
// or null unless the whole document is valid: a JSON list of objects with
// exactly these keys, ids positive integers and unique, amounts integers
// >= 0, dates real dates as YYYY-MM-DD and not later than 31 December of
// next year (pgacc sends <year>-12-31; field 26 only moves forward, so a
// date far in the future would stay).
function accsync_validate($body)
{
    $latest = ((int) date('Y') + 1) . '-12-31';
    try {
        $rows = json_decode($body, false, 3, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    } catch (JsonException $e) {
        return null;
    }
    if (!is_array($rows)) {
        return null;
    }
    $payments = [];
    foreach ($rows as $row) {
        if (!$row instanceof stdClass) {
            return null;
        }
        $row = get_object_vars($row);
        $keys = array_keys($row);
        sort($keys);
        if ($keys !== ['amount_cents', 'id', 'paid_until']
            || !is_int($row['id']) || $row['id'] <= 0 || isset($payments[$row['id']])
            || !is_int($row['amount_cents']) || $row['amount_cents'] < 0
            || !is_string($row['paid_until']) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $row['paid_until'], $date)
            || !checkdate((int) $date[2], (int) $date[3], (int) $date[1]) || $row['paid_until'] > $latest) {
            return null;
        }
        $payments[$row['id']] = ['id' => $row['id'], 'amount_cents' => $row['amount_cents'], 'paid_until' => $row['paid_until']];
    }
    return array_values($payments);
}

// $cents (int >= 0) as exact decimal euros, "12.34".
function accsync_euros($cents)
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

// A stored value for the log.
function accsync_shown($value)
{
    return $value === null ? '(none)' : ($value === '' ? '""' : $value);
}

// Writes $payments in one transaction (rolled back for a dry run) and
// prints the summary after it, for a dry run also the changes and skips;
// throws (after the rollback, printing nothing) on any database error.
function accsync_apply($pdo, $payments, $dryRun)
{
    $eligible = $pdo->prepare('SELECT COUNT(*) FROM adm_users u WHERE u.usr_id = ? AND u.usr_valid = 1
        AND EXISTS (SELECT 1 FROM adm_members m WHERE m.mem_usr_id = u.usr_id AND m.mem_rol_id = 2
            AND m.mem_begin <= CURRENT_DATE AND m.mem_end >= CURRENT_DATE)');
    $current = $pdo->prepare('SELECT usd_value FROM pgacc_accsync_user_data WHERE usd_usr_id = ? AND usd_usf_id = ? FOR UPDATE');
    $upsert = [
        26 => $pdo->prepare("INSERT INTO pgacc_accsync_user_data (usd_usr_id, usd_usf_id, usd_value) VALUES (?, 26, ?)
            ON DUPLICATE KEY UPDATE usd_value = IF(usd_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}\$' AND usd_value > VALUES(usd_value),
                usd_value, VALUES(usd_value))"),
        27 => $pdo->prepare('INSERT INTO pgacc_accsync_user_data (usd_usr_id, usd_usf_id, usd_value) VALUES (?, 27, ?)
            ON DUPLICATE KEY UPDATE usd_value = VALUES(usd_value)'),
    ];
    $value = function ($id, $field) use ($current) {
        $current->execute([$id, $field]);
        $value = $current->fetchColumn();
        $current->closeCursor();
        return $value === false ? null : $value;
    };
    $prefix = $dryRun ? 'dry run: ' : '';
    $lines = [];
    $changes = 0;
    $skipped = 0;
    // READ COMMITTED: with MariaDB's snapshot isolation (on by default since
    // 11.8), a value changed by hand after the transaction's first read would
    // make the locking read fail under REPEATABLE READ.
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        foreach ($payments as $payment) {
            $id = $payment['id'];
            $eligible->execute([$id]);
            if ((int) $eligible->fetchColumn() === 0) {
                $lines[] = "user $id skipped: not an eligible member";
                $skipped++;
                continue;
            }
            foreach ([26 => $payment['paid_until'], 27 => accsync_euros($payment['amount_cents'])] as $field => $new) {
                $old = $value($id, $field);
                $upsert[$field]->execute([$id, $new]);
                $stored = $value($id, $field);
                if ($stored !== $old) {
                    $lines[] = "user $id field $field: " . accsync_shown($old) . ' -> ' . accsync_shown($stored);
                    $changes++;
                }
            }
        }
        if ($dryRun) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if (!$dryRun) {
        $lines = [];
    }
    $lines[] = count($payments) . " payments, $changes changes, $skipped skipped";
    foreach ($lines as $line) {
        echo $prefix . $line . "\n";
    }
}

// The run for the command line $argv and the environment $env; returns the
// exit status.
function accsync_main($argv, $env)
{
    $dryRun = false;
    $file = $env['ACCSYNC_SECRETS'] ?? null;
    try {
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
            } elseif (str_starts_with($arg, '--secrets=')) {
                $file = substr($arg, strlen('--secrets='));
            } else {
                throw new AccsyncError('usage: accsync.php [--dry-run] [--secrets=<file>]');
            }
        }
        $secrets = accsync_secrets($file);
        try {
            $pdo = new PDO($secrets['dsn'] ?? '', $secrets['user'] ?? null, $secrets['password'] ?? null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            accsync_check_deployment($pdo);
        } catch (PDOException $e) {
            throw new AccsyncError('database: ' . $e->getCode());
        }
        $payments = accsync_validate(accsync_fetch($secrets));
        if ($payments === null) {
            throw new AccsyncError('invalid document; nothing written');
        }
        try {
            accsync_apply($pdo, $payments, $dryRun);
        } catch (PDOException $e) {
            throw new AccsyncError('database: ' . $e->getCode() . '; rolled back, nothing written');
        }
    } catch (AccsyncError $e) {
        fwrite(STDERR, 'accsync: ' . $e->getMessage() . "\n");
        return 1;
    }
    return 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    exit(accsync_main($_SERVER['argv'], getenv()));
}
