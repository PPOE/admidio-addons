<?php

// SPDX-License-Identifier: GPL-2.0-only
//
// Member list for Liquid (internal/liquid-members.php): Liquid fetches the
// active members as JSON, [{"id": int, "lo": int|null, "eligible": bool,
// "email": string|null, "regions": [string, ...]}, ...], from the view
// liquid_members (liquid_members.sql). An email outside Liquid's rule
// (LIQUID_MEMBERS_EMAIL, at most 254 characters) is sent as null, since
// Liquid would reject the whole list for it. A request
// must come from a peer of the secrets file and carry its token
// ("Authorization: Bearer <token>"); otherwise, and without a valid secrets
// file, it gets 403 with an empty body. Any error after that answers 503
// with an empty body.
//
// The secrets file (liquid-members-secrets.php) is outside the document
// root; the web server passes its path as LIQUID_MEMBERS_SECRETS. It returns
// an array: ['token' => '<hex>', 'peers' => ['<address>', ...],
//  'dsn' => 'mysql:unix_socket=...;dbname=...;charset=utf8mb4',
//  'user' => '<reader>', 'password' => '...'].

require_once dirname(__DIR__) . '/lib/internal-api.php';

// The email rule of Liquid's member_sync() (member_sync.sql), the same
// regular expression: a plain local@domain, the domain with at least one dot
// (no quoted local parts, no IP literals).
const LIQUID_MEMBERS_EMAIL = '/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]+(\.[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*@[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/D';

// The members, ordered by id. Throws on any database error.
function liquid_members_api_members($secrets)
{
    $pdo = new PDO($secrets['dsn'] ?? '', $secrets['user'] ?? null, $secrets['password'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $statement = $pdo->prepare('SELECT id, lo, eligible, email, regions FROM liquid_members ORDER BY id');
    $statement->execute();
    $members = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $email = $row['email'];
        $members[] = [
            'id' => (int) $row['id'],
            'lo' => $row['lo'] === null ? null : (int) $row['lo'],
            'eligible' => (int) $row['eligible'] === 1,
            'email' => is_string($email) && strlen($email) <= 254 && preg_match(LIQUID_MEMBERS_EMAIL, $email) === 1 ? $email : null,
            'regions' => $row['regions'] === null ? [] : explode(',', $row['regions']),
        ];
    }
    return $members;
}

// [HTTP status, body] for the request $server.
function liquid_members_api_response($server)
{
    $secrets = members_api_secrets($server['LIQUID_MEMBERS_SECRETS'] ?? null);
    if (!members_api_allowed($secrets, $server)) {
        return [403, ''];
    }
    try {
        return [200, json_encode(liquid_members_api_members($secrets), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    } catch (Throwable $e) {
        // Only the class and code: the message may contain SQL or the user.
        error_log('liquid-members.php: ' . get_class($e) . ' ' . $e->getCode());
        return [503, ''];
    }
}

function liquid_members_api_main()
{
    [$status, $body] = liquid_members_api_response($_SERVER);
    http_response_code($status);
    if ($status === 200) {
        header('Content-Type: application/json');
    }
    echo $body;
}
