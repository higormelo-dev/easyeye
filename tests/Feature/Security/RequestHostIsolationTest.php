<?php

use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;

it('enforces a restrictive request host policy within the current test', function () {
    Request::setTrustedHosts(['^approved\.synthetic\.example$']);
    expect(fn () => Request::create('http://localhost')->getHost())->toThrow(SuspiciousOperationException::class);
    expect(Request::create('https://approved.synthetic.example')->getHost())->toBe('approved.synthetic.example');
});

it('does not inherit the previous test request host policy', function () {
    expect(Request::getTrustedHosts())->toBe([]);
    expect(Request::create('http://localhost')->getHost())->toBe('localhost');
});
