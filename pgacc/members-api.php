<?php

// SPDX-License-Identifier: GPL-2.0-only
//
// Member list for pgacc (internal/members.php): pgacc fetches the active
// members as JSON, [{"id": int, "uuid": string, "name": string,
// "lo": int|null, "paid": 0|1}, ...], from the view pgacc_members
// (pgacc_members.sql). A request must come from a peer of the secrets file
// and carry its token ("Authorization: Bearer <token>"); otherwise, and
// without a valid secrets file, it gets 403 with an empty body. Any error
// after that answers 503 with an empty body.
//
// The secrets file (members-secrets.php) is outside the document root; the
// web server passes its path as PGACC_MEMBERS_SECRETS. It returns an array:
// ['token' => '<hex>', 'peers' => ['<address>', ...],
//  'dsn' => 'mysql:unix_socket=...;dbname=...;charset=utf8mb4',
//  'user' => '<reader>', 'password' => '...'].

// The secrets from $file, or null unless they have a non-empty token and a
// non-empty peer list.
function members_api_secrets($file)
{
    if (!is_string($file) || $file === '' || !is_file($file)) {
        return null;
    }
    try {
        $secrets = include $file;
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($secrets) || !is_string($secrets['token'] ?? null) || $secrets['token'] === ''
        || !is_array($secrets['peers'] ?? null) || $secrets['peers'] === []) {
        return null;
    }
    return $secrets;
}

// True if the request ($_SERVER) comes from a peer of the secrets (the
// socket's address; no forwarded headers) and carries their token.
function members_api_allowed($secrets, $server)
{
    return $secrets !== null
        && in_array($server['REMOTE_ADDR'] ?? null, $secrets['peers'], true)
        && hash_equals('Bearer ' . $secrets['token'], (string) ($server['HTTP_AUTHORIZATION'] ?? ''));
}

// The members, ordered by id. Throws on any database error.
function members_api_members($secrets)
{
    $pdo = new PDO($secrets['dsn'] ?? '', $secrets['user'] ?? null, $secrets['password'] ?? null, [
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
    $secrets = members_api_secrets($server['PGACC_MEMBERS_SECRETS'] ?? null);
    if (!members_api_allowed($secrets, $server)) {
        return [403, ''];
    }
    try {
        return [200, json_encode(members_api_members($secrets), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
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
