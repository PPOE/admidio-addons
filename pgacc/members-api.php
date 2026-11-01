<?php

// SPDX-License-Identifier: GPL-2.0-only
//
// Member list for pgacc (internal/members.php): pgacc fetches the active
// members as JSON, [{"id": int, "uuid": string, "name": string,
// "lo": int|null, "paid": 0|1}, ...], from the view pgacc_members
// (pgacc_members.sql). A request must come from a peer of the configuration
// file and carry its token ("Authorization: Bearer <token>"); otherwise, and
// without a valid configuration, it gets 403 with an empty body. Any error
// after that answers 503 with an empty body.
//
// The configuration file is outside the document root; the web server passes
// its path as PGACC_MEMBERS_CONFIG. It returns an array:
// ['token' => '<hex>', 'peers' => ['<address>', ...],
//  'dsn' => 'mysql:unix_socket=...;dbname=...;charset=utf8mb4',
//  'user' => '<reader>', 'password' => '...'].

// The configuration from $file, or null unless it has a non-empty token and
// a non-empty peer list.
function members_api_config($file)
{
    if (!is_string($file) || $file === '' || !is_file($file)) {
        return null;
    }
    try {
        $config = include $file;
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($config) || !is_string($config['token'] ?? null) || $config['token'] === ''
        || !is_array($config['peers'] ?? null) || $config['peers'] === []) {
        return null;
    }
    return $config;
}

// True if the request ($_SERVER) comes from a configured peer (the socket's
// address; no forwarded headers) and carries the configured token.
function members_api_allowed($config, $server)
{
    return $config !== null
        && in_array($server['REMOTE_ADDR'] ?? null, $config['peers'], true)
        && hash_equals('Bearer ' . $config['token'], (string) ($server['HTTP_AUTHORIZATION'] ?? ''));
}

// The members, ordered by id. Throws on any database error.
function members_api_members($config)
{
    $pdo = new PDO($config['dsn'] ?? '', $config['user'] ?? null, $config['password'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $statement = $pdo->prepare('SELECT id, uuid, name, lo, paid FROM pgacc_members ORDER BY id');
    $statement->execute();
    $members = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $members[] = [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
            'lo' => $row['lo'] === null ? null : (int) $row['lo'],
            'paid' => (int) $row['paid'],
        ];
    }
    return $members;
}

// [HTTP status, body] for the request $server.
function members_api_response($server)
{
    $config = members_api_config($server['PGACC_MEMBERS_CONFIG'] ?? null);
    if (!members_api_allowed($config, $server)) {
        return [403, ''];
    }
    try {
        return [200, json_encode(members_api_members($config), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    } catch (Throwable $e) {
        // Only the class and code: the message may contain SQL or the user.
        error_log('members.php: ' . get_class($e) . ' ' . $e->getCode());
        return [503, ''];
    }
}

function members_api_main()
{
    [$status, $body] = members_api_response($_SERVER);
    http_response_code($status);
    if ($status === 200) {
        header('Content-Type: application/json');
    }
    echo $body;
}
