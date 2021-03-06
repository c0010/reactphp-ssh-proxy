<?php

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use Clue\React\SshProxy\SshProcessConnector;

class FunctionalSshProcessConnectorTest extends TestCase
{
    const TIMEOUT = 10.0;

    private $loop;
    private $connector;

    public function setUp()
    {
        $url = getenv('SSH_PROXY');
        if ($url === false) {
            $this->markTestSkipped('No SSH_PROXY env set');
        }

        $this->loop = Factory::create();
        $this->connector = new SshProcessConnector($url, $this->loop);
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Connection to example.com:80 failed because SSH client died
     */
    public function testConnectInvalidProxyUriWillReturnRejectedPromise()
    {
        $this->connector = new SshProcessConnector(getenv('SSH_PROXY') . '.invalid', $this->loop);
        $promise = $this->connector->connect('example.com:80');

        \Clue\React\Block\await($promise, $this->loop, self::TIMEOUT);
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Connection to example.invalid:80 rejected:
     */
    public function testConnectInvalidTargetWillReturnRejectedPromise()
    {
        $promise = $this->connector->connect('example.invalid:80');

        \Clue\React\Block\await($promise, $this->loop, self::TIMEOUT);
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Connection to example.com:80 cancelled while waiting for SSH client
     */
    public function testCancelConnectWillReturnRejectedPromise()
    {
        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();

        \Clue\React\Block\await($promise, $this->loop, 0);
    }

    public function testConnectValidTargetWillReturnPromiseWhichResolvesToConnection()
    {
        $promise = $this->connector->connect('example.com:80');

        $connection = \Clue\React\Block\await($promise, $this->loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Socket\ConnectionInterface', $connection);
        $this->assertTrue($connection->isReadable());
        $this->assertTrue($connection->isWritable());
        $connection->close();
    }

    public function testConnectValidTargetWillNotInheritActiveFileDescriptors()
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);

        // ensure that we can not listen on the same address twice
        $copy = @stream_socket_server('tcp://' . $address);
        if ($copy !== false) {
            fclose($server);
            fclose($copy);

            $this->markTestSkipped('Platform does not prevent binding to same address (Windows?)');
        }

        // wait for successful connection
        $promise = $this->connector->connect('example.com:80');
        $connection = \Clue\React\Block\await($promise, $this->loop, self::TIMEOUT);

        // close server and ensure we can start a new server on the previous address
        // the open SSH connection process should not inherit the existing server socket
        fclose($server);
        $server = stream_socket_server('tcp://' . $address);
        $this->assertTrue(is_resource($server));

        fclose($server);
        $connection->close();
    }
}
