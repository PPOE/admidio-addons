<?php

// SPDX-License-Identifier: GPL-2.0-only
//
// The authentication of the internal listener's endpoints (members.php for
// pgacc, liquid-members.php for Liquid): each has its own secrets file, with
// a token and a peer list.

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
