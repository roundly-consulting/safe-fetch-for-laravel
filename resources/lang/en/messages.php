<?php

declare(strict_types=1);

return [
    'invalid_url' => 'The URL cannot be fetched: it is not a valid URL.',
    'scheme' => 'The URL cannot be fetched: its scheme is not allowed.',
    'credentials' => 'The URL cannot be fetched: it must not contain a user name or password.',
    'non_canonical_host' => 'The URL cannot be fetched: its host is not written in a standard form.',
    'port' => 'The URL cannot be fetched: its port is not allowed.',
    // blocked and unresolved read the same: telling them apart would map the internal network.
    'blocked' => 'The URL cannot be fetched: its destination is not allowed or cannot be reached.',
    'unresolved' => 'The URL cannot be fetched: its destination is not allowed or cannot be reached.',
    'not_allowed' => 'The URL cannot be fetched: its host is not on the list of allowed hosts.',
    'cannot_pin' => 'The request was not sent: its connection could not be secured to the checked destination.',
    'too_many_redirects' => 'The URL cannot be fetched: it redirects too many times.',
    'redirect_downgrade' => 'The URL cannot be fetched: it redirects from a secure to an insecure address.',
    'timeout' => 'The URL cannot be fetched right now: the server did not answer in time.',
    'status' => 'The URL cannot be fetched: the server answered with status :status.',
    'too_large' => 'The URL cannot be fetched: the response is too large.',
    'connection' => 'The URL cannot be fetched right now: the connection to the server failed.',
    'tls' => 'The URL cannot be fetched: the server\'s certificate could not be verified.',
    'protocol' => 'The URL cannot be fetched: the server\'s answer could not be read.',
];
