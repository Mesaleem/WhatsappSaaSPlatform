<?php

namespace App\Support\Security;

/**
 * Turns a host name into the IP addresses a connection to it could reach. A seam, so the SSRF guard can be
 * tested with a controllable resolver (including DNS-rebinding answers) and never touches the network in tests.
 */
interface HostResolver
{
    /** @return list<string> every A / AAAA address for the host (an IP literal resolves to itself); empty when it does not resolve */
    public function resolve(string $host): array;
}
