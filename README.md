# clue/reactphp-ssh-proxy [![Build Status](https://travis-ci.org/clue/reactphp-ssh-proxy.svg?branch=master)](https://travis-ci.org/clue/reactphp-ssh-proxy)

Async SSH proxy connector and forwarder, tunnel any TCP/IP-based protocol through an SSH server,
built on top of [ReactPHP](https://reactphp.org).

[Secure Shell (SSH)](https://en.wikipedia.org/wiki/Secure_Shell) is a secure
network protocol that is most commonly used to access a login shell on a remote
server. Its architecture allows it to use multiple secure channels over a single
connection. Among others, this can also be used to create an "SSH tunnel", which
is commonly used to tunnel HTTP(S) traffic through an intermediary ("proxy"), to
conceal the origin address (anonymity) or to circumvent address blocking
(geoblocking). This can be used to tunnel any TCP/IP-based protocol (HTTP, SMTP,
IMAP etc.) and as such also allows you to access local services that are otherwise
not accessible from the outside (database behind firewall).
This library is implemented as a lightweight process wrapper around the `ssh` client
binary and provides a simple API to create these tunneled connections for you.
Because it implements ReactPHP's standard
[`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface),
it can simply be used in place of a normal connector.
This makes it fairly simple to add SSH proxy support to pretty much any
existing higher-level protocol implementation.

* **Async execution of connections** -
  Send any number of SSH proxy requests in parallel and process their
  responses as soon as results come in.
  The Promise-based design provides a *sane* interface to working with out of
  bound responses and possible connection errors.
* **Standard interfaces** -
  Allows easy integration with existing higher-level components by implementing
  ReactPHP's standard
  [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface).
* **Lightweight, SOLID design** -
  Provides a thin abstraction that is [*just good enough*](https://en.wikipedia.org/wiki/Principle_of_good_enough)
  and does not get in your way.
  Builds on top of well-tested components and well-established concepts instead of reinventing the wheel.
* **Good test coverage** -
  Comes with an automated tests suite and is regularly tested against actual SSH servers in the wild.

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [SshProcessConnector](#sshprocessconnector)
    * [Plain TCP connections](#plain-tcp-connections)
    * [HTTP requests](#http-requests)
    * [Connection timeout](#connection-timeout)
    * [DNS resolution](#dns-resolution)
    * [Password authentication](#password-authentication)
* [Install](#install)
* [Tests](#tests)
* [License](#license)
* [More](#more)

## Quickstart example

The following example code demonstrates how this library can be used to send a
plaintext HTTP request to google.com through a remote SSH server:

```php
$loop = React\EventLoop\Factory::create();

$proxy = new Clue\React\SshProxy\SshProcessConnector('user@example.com', $loop);
$connector = new React\Socket\Connector($loop, array(
    'tcp' => $proxy,
    'dns' => false
));

$connector->connect('tcp://google.com:80')->then(function (React\Socket\ConnectionInterface $connection) {
    $connection->write("GET / HTTP/1.1\r\nHost: google.com\r\nConnection: close\r\n\r\n");
    $connection->on('data', function ($chunk) {
        echo $chunk;
    });
    $connection->on('close', function () {
        echo '[DONE]';
    });
}, 'printf');

$loop->run();
```

See also the [examples](examples).

## Usage

### SshProcessConnector

The `SshProcessConnector` is responsible for creating plain TCP/IP connections to
any destination by using an intermediary SSH server as a proxy server.

```
[you] -> [proxy] -> [destination]
```

This class is implemented as a lightweight process wrapper around the `ssh`
client binary, so you'll have to make sure that you have a suitable SSH client
installed. On Debian/Ubuntu-based systems, you may simply install it like this:

```bash
$ sudo apt install openssh-client
```

Its constructor simply accepts an SSH proxy server URL and a loop to bind to:

```php
$loop = React\EventLoop\Factory::create();
$proxy = new Clue\React\SshProxy\SshProcessConnector('user@example.com', $loop);
```

The proxy URL may or may not contain a scheme and port definition. The default
port will be `22` for SSH, but you may have to use a custom port depending on
your SSH server setup.

This is the main class in this package.
Because it implements ReactPHP's standard
[`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface),
it can simply be used in place of a normal connector.
Accordingly, it provides only a single public method, the
[`connect()`](https://github.com/reactphp/socket#connect) method.
The `connect(string $uri): PromiseInterface<ConnectionInterface, Exception>`
method can be used to establish a streaming connection.
It returns a [Promise](https://github.com/reactphp/promise) which either
fulfills with a [ConnectionInterface](https://github.com/reactphp/socket#connectioninterface)
on success or rejects with an `Exception` on error.

This makes it fairly simple to add SSH proxy support to pretty much any
higher-level component:

```diff
- $client = new SomeClient($connector);
+ $proxy = new SshProcessConnector('user@example.com', $loop);
+ $client = new SomeClient($proxy);
```

#### Plain TCP connections

SSH proxy servers are commonly used to issue HTTPS requests to your destination.
However, this is actually performed on a higher protocol layer and this
connector is actually inherently a general-purpose plain TCP/IP connector.
As documented above, you can simply invoke its `connect()` method to establish
a streaming plain TCP/IP connection and use any higher level protocol like so:

```php
$proxy = new SshProcessConnector('user@example.com', $connector);

$proxy->connect('tcp://smtp.googlemail.com:587')->then(function (ConnectionInterface $stream) {
    $stream->write("EHLO local\r\n");
    $stream->on('data', function ($chunk) use ($stream) {
        echo $chunk;
    });
});
```

You can either use the `SshProcessConnector` directly or you may want to wrap this connector
in ReactPHP's [`Connector`](https://github.com/reactphp/socket#connector):

```php
$connector = new Connector($loop, array(
    'tcp' => $proxy,
    'dns' => false
));

$connector->connect('tcp://smtp.googlemail.com:587')->then(function (ConnectionInterface $stream) {
    $stream->write("EHLO local\r\n");
    $stream->on('data', function ($chunk) use ($stream) {
        echo $chunk;
    });
});
```

Keep in mind that this class is implemented as a lightweight process wrapper
around the `ssh` client binary, so it will spawn one `ssh` process for each
connection. Each process will keep running until the connection is closed, so
you're recommended to limit the total number of concurrent connections.

#### HTTP requests

HTTP operates on a higher layer than this low-level SSH proxy implementation.
If you want to issue HTTP requests, you can add a dependency for
[clue/reactphp-buzz](https://github.com/clue/reactphp-buzz).
It can interact with this library by issuing all HTTP requests through your SSH
proxy server, similar to how it can issue
[HTTP requests through an HTTP CONNECT proxy server](https://github.com/clue/reactphp-buzz#http-proxy).
At the moment, this only works for plaintext HTTP requests.

#### Connection timeout

By default, the `SshProcessConnector` does not implement any timeouts for establishing remote
connections.
Your underlying operating system may impose limits on pending and/or idle TCP/IP
connections, anywhere in a range of a few minutes to several hours.

Many use cases require more control over the timeout and likely values much
smaller, usually in the range of a few seconds only.

You can use ReactPHP's [`Connector`](https://github.com/reactphp/socket#connector)
or the low-level
[`TimeoutConnector`](https://github.com/reactphp/socket#timeoutconnector)
to decorate any given `ConnectorInterface` instance.
It provides the same `connect()` method, but will automatically reject the
underlying connection attempt if it takes too long:

```php
$connector = new Connector($loop, array(
    'tcp' => $proxy,
    'dns' => false,
    'timeout' => 3.0
));

$connector->connect('tcp://google.com:80')->then(function ($stream) {
    // connection succeeded within 3.0 seconds
});
```

See also any of the [examples](examples).

> Note how the connection timeout is in fact entirely handled outside of this
  SSH proxy client implementation.

#### DNS resolution

By default, the `SshProcessConnector` does not perform any DNS resolution at all and simply
forwards any hostname you're trying to connect to the remote proxy server.
The remote proxy server is thus responsible for looking up any hostnames via DNS
(this default mode is thus called *remote DNS resolution*).

As an alternative, you can also send the destination IP to the remote proxy
server.
In this mode you either have to stick to using IPs only (which is ofen unfeasable)
or perform any DNS lookups locally and only transmit the resolved destination IPs
(this mode is thus called *local DNS resolution*).

The default *remote DNS resolution* is useful if your local `SshProcessConnector` either can
not resolve target hostnames because it has no direct access to the internet or
if it should not resolve target hostnames because its outgoing DNS traffic might
be intercepted.

As noted above, the `SshProcessConnector` defaults to using remote DNS resolution.
However, wrapping the `SshProcessConnector` in ReactPHP's
[`Connector`](https://github.com/reactphp/socket#connector) actually
performs local DNS resolution unless explicitly defined otherwise.
Given that remote DNS resolution is assumed to be the preferred mode, all
other examples explicitly disable DNS resolution like this:

```php
$connector = new Connector($loop, array(
    'tcp' => $proxy,
    'dns' => false
));
```

If you want to explicitly use *local DNS resolution*, you can use the following code:

```php
// set up Connector which uses Google's public DNS (8.8.8.8)
$connector = new Connector($loop, array(
    'tcp' => $proxy,
    'dns' => '8.8.8.8'
));
```

> Note how local DNS resolution is in fact entirely handled outside of this
  SSH proxy client implementation.

#### Password authentication

Note that this class is implemented as a lightweight process wrapper around the
`ssh` client binary. It works under the assumption that you have verified you
can access your SSH proxy server on the command line like this:

```bash
# test SSH access
$ ssh user@example.com echo hello
```

Because this class is designed to be used to create any number of connections,
it does not provide a way to interactively ask for your password. Similarly,
the `ssh` client binary does not provide a way to "pass" in the password on the
command line for security reasons. This means that you are highly recommended to
set up pubkey-based authentication without a password for this to work best.

Additionally, this library provides a way to pass in a password in a somewhat
less secure way if your use case absolutely requires this. Before proceeding,
please consult your SSH documentation to find out why this may be a bad idea and
why pubkey-based authentication is usually the better alternative.
If your SSH proxy server requires password authentication, you may pass the
username and password as part of the SSH proxy server URL like this:

```php
$proxy = new SshProcessConnector('user:pass@example.com', $connector);
```

For this to work, you will have to have the `sshpass` binary installed. On
Debian/Ubuntu-based systems, you may simply install it like this:

```bash
$ sudo apt install sshpass
```

Note that both the username and password must be percent-encoded if they contain
special characters:

```php
$user = 'he:llo';
$pass = 'p@ss';

$proxy = new SshProcessConnector(
    rawurlencode($user) . ':' . rawurlencode($pass) . '@example.com:2222',
    $connector
);
```

## Install

The recommended way to install this library is [through Composer](https://getcomposer.org).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require clue/reactphp-ssh-proxy:dev-master
```

This project aims to run on any platform and thus does not require any PHP
extensions and supports running on legacy PHP 5.3 through current PHP 7+ and
HHVM.
It's *highly recommended to use PHP 7+* for this project.

This project is implemented as a lightweight process wrapper around the `ssh`
client binary, so you'll have to make sure that you have a suitable SSH client
installed. On Debian/Ubuntu-based systems, you may simply install it like this:

```bash
$ sudo apt install openssh-client
```

Additionally, if you use [password authentication](#password-authentication)
(not recommended), then you will have to have the `sshpass` binary installed. On
Debian/Ubuntu-based systems, you may simply install it like this:

```bash
$ sudo apt install sshpass
```

*Running on [Windows is currently not supported](https://github.com/reactphp/child-process/issues/9)*

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org):

```bash
$ composer install
```

To run the test suite, go to the project root and run:

```bash
$ php vendor/bin/phpunit
```

The test suite contains a number of tests that require an actual SSH proxy server.
These tests will be skipped unless you configure your SSH login credentials to
be able to create some actual test connections. You can assign the `SSH_PROXY`
environment and prefix this with a space to make sure your login credentials are
not stored in your bash history like this:

```bash
$  export SSH_PROXY=user:secret@example.com
$ php vendor/bin/phpunit --exclude-group internet
```

## License

This project is released under the permissive [MIT license](LICENSE).

> Did you know that I offer custom development services and issuing invoices for
  sponsorships of releases and for contributions? Contact me (@clue) for details.

## More

* If you want to learn more about how the
  [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface)
  and its usual implementations look like, refer to the documentation of the underlying
  [react/socket](https://github.com/reactphp/socket) component.
* If you want to learn more about processing streams of data, refer to the
  documentation of the underlying
  [react/stream](https://github.com/reactphp/stream) component.
* As an alternative to an SSH proxy server, you may also want to look into
  using a SOCKS5 or SOCKS4(a) proxy instead.
  You may want to use [clue/reactphp-socks](https://github.com/clue/reactphp-socks)
  which also provides an implementation of the same
  [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface)
  so that supporting either proxy protocol should be fairly trivial.
* As another alternative to an SSH proxy server, you may also want to look into
  using an HTTP CONNECT proxy instead.
  You may want to use [clue/reactphp-http-proxy](https://github.com/clue/reactphp-http-proxy)
  which also provides an implementation of the same
  [`ConnectorInterface`](https://github.com/reactphp/socket#connectorinterface)
