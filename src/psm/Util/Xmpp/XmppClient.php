<?php

/**
 * PHP Server Monitor
 * Monitor your servers and websites.
 *
 * This file is part of PHP Server Monitor.
 * PHP Server Monitor is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PHP Server Monitor is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PHP Server Monitor.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package     phpservermon
 * @license     http://www.gnu.org/licenses/gpl.txt GNU GPL v3
 * @version     Release: @package_version@
 * @link        http://www.phpservermonitor.org/
 **/

namespace psm\Util\Xmpp;

/**
 * Minimal XMPP client that is able to log in and send chat messages.
 *
 * Replaces the abandoned JAXL library, which does not work with PHP 8.
 * Supports STARTTLS (or direct TLS on port 5223), SASL PLAIN and X-OAUTH2.
 */
class XmppClient
{
    const NS_TLS = 'urn:ietf:params:xml:ns:xmpp-tls';
    const NS_SASL = 'urn:ietf:params:xml:ns:xmpp-sasl';
    const NS_BIND = 'urn:ietf:params:xml:ns:xmpp-bind';
    const NS_SESSION = 'urn:ietf:params:xml:ns:xmpp-session';

    /**
     * @var string $host
     */
    protected $host;

    /**
     * @var int $port
     */
    protected $port;

    /**
     * @var string $domain
     */
    protected $domain;

    /**
     * @var string $user
     */
    protected $user;

    /**
     * @var string $password
     */
    protected $password;

    /**
     * @var string $auth_type PLAIN or X-OAUTH2
     */
    protected $auth_type;

    /**
     * @var bool $force_tls
     */
    protected $force_tls;

    /**
     * @var int $timeout in seconds
     */
    protected $timeout;

    /**
     * @var resource|null $socket
     */
    protected $socket;

    /**
     * Data received but not processed yet
     * @var string $buffer
     */
    protected $buffer = '';

    /**
     * @param string $host
     * @param string $username either a full JID (user@domain) or only the local part
     * @param string $password
     * @param int|null $port default 5222
     * @param string|null $domain XMPP domain, if not part of the username
     * @param bool $force_tls
     * @param string $auth_type
     * @param int $timeout
     */
    public function __construct(
        $host,
        $username,
        $password,
        $port = null,
        $domain = null,
        $force_tls = true,
        $auth_type = 'PLAIN',
        $timeout = 10
    ) {
        $parts = explode('@', (string) $username, 2);
        $this->user = $parts[0];
        if (!empty($domain)) {
            $this->domain = $domain;
        } elseif (isset($parts[1])) {
            $this->domain = $parts[1];
        } else {
            $this->domain = $host;
        }
        $this->host = !empty($host) ? $host : $this->domain;
        $this->port = !empty($port) ? (int) $port : 5222;
        $this->password = (string) $password;
        $this->force_tls = (bool) $force_tls;
        $this->auth_type = strtoupper((string) $auth_type);
        $this->timeout = (int) $timeout;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Connect, encrypt the connection, authenticate and bind a resource.
     * @throws \RuntimeException
     */
    public function connect()
    {
        // port 5223 is the legacy port for direct TLS connections
        $transport = $this->port === 5223 ? 'tls' : 'tcp';
        // the certificate has to be valid for the XMPP domain, not for the host we connect to
        $context = stream_context_create(array('ssl' => array('peer_name' => $this->domain)));
        $socket = @stream_socket_client(
            $transport . '://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new \RuntimeException(
                'Unable to connect to ' . $this->host . ':' . $this->port . ': ' . $errstr . ' (' . $errno . ')'
            );
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);

        $features = $this->openStream();

        if ($transport === 'tcp') {
            if (strpos($features, '<starttls') !== false) {
                $this->write('<starttls xmlns="' . self::NS_TLS . '"/>');
                $this->read(array('proceed', 'failure'), 'proceed');
                $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                if (@stream_socket_enable_crypto($this->socket, true, $crypto) !== true) {
                    throw new \RuntimeException('Unable to establish an encrypted connection.');
                }
                $features = $this->openStream();
            } elseif ($this->force_tls) {
                throw new \RuntimeException('The server does not support encrypted connections (STARTTLS).');
            }
        }

        $this->authenticate($features);
        $features = $this->openStream();

        if (strpos($features, self::NS_BIND) !== false) {
            $this->write(
                '<iq type="set" id="bind_1"><bind xmlns="' . self::NS_BIND . '">' .
                '<resource>phpservermon</resource></bind></iq>'
            );
            $this->readIq('bind_1');
        }
        // RFC 3921 session establishment, only required by older servers
        if (
            strpos($features, self::NS_SESSION) !== false &&
            strpos($features, '<optional') === false
        ) {
            $this->write('<iq type="set" id="session_1"><session xmlns="' . self::NS_SESSION . '"/></iq>');
            $this->readIq('session_1');
        }
    }

    /**
     * Send a chat message
     * @param string $to JID of the receiver
     * @param string $message
     */
    public function sendMessage($to, $message)
    {
        $this->write(
            '<message to="' . $this->escape($to) . '" type="chat"><body>' . $this->escape($message) .
            '</body></message>'
        );
    }

    /**
     * Close the stream and the connection
     */
    public function disconnect()
    {
        if (is_resource($this->socket)) {
            // wait for the server to close its stream as well, otherwise stanzas that have
            // just been sent might be discarded
            if (@fwrite($this->socket, '</stream:stream>')) {
                while (strpos($this->buffer, '</stream:stream>') === false) {
                    $data = @fread($this->socket, 8192);
                    if (!is_string($data) || $data === '') {
                        break;
                    }
                    $this->buffer .= $data;
                }
            }
            fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * Open a new XML stream and wait for the stream features
     * @return string stream features
     */
    protected function openStream()
    {
        $this->buffer = '';
        $this->write(
            '<?xml version="1.0"?><stream:stream to="' . $this->escape($this->domain) . '" ' .
            'xmlns="jabber:client" xmlns:stream="http://etherx.jabber.org/streams" version="1.0">'
        );
        return $this->read(array('stream:features'), 'stream:features');
    }

    /**
     * @param string $features
     * @throws \RuntimeException
     */
    protected function authenticate($features)
    {
        if (strpos($features, '>' . $this->auth_type . '</mechanism>') === false) {
            throw new \RuntimeException('Authentication type ' . $this->auth_type . ' is not supported by the server.');
        }
        switch ($this->auth_type) {
            case 'PLAIN':
                $attributes = '';
                break;
            case 'X-OAUTH2':
                $attributes = ' xmlns:auth="http://www.google.com/talk/protocol/auth" auth:service="oauth2"';
                break;
            default:
                throw new \RuntimeException('Authentication type ' . $this->auth_type . ' is not supported.');
        }
        $this->write(
            '<auth xmlns="' . self::NS_SASL . '" mechanism="' . $this->auth_type . '"' . $attributes . '>' .
            base64_encode("\0" . $this->user . "\0" . $this->password) . '</auth>'
        );
        $this->read(array('success', 'failure'), 'success');
    }

    /**
     * Wait for the result of an iq request
     * @param string $id
     * @throws \RuntimeException
     */
    protected function readIq($id)
    {
        $stanza = $this->read(array('iq'), 'iq', $id);
        if (strpos($stanza, 'type="result"') === false && strpos($stanza, "type='result'") === false) {
            throw new \RuntimeException('Request ' . $id . ' failed: ' . $stanza);
        }
    }

    /**
     * Read from the socket until one of the expected elements has been received completely.
     * @param array $elements names of the elements to wait for
     * @param string $success name of the element that indicates success
     * @param string|null $id only accept elements with this id
     * @return string the received element
     * @throws \RuntimeException
     */
    protected function read(array $elements, $success, $id = null)
    {
        $names = implode('|', array_map('preg_quote', $elements));
        $id_pattern = $id === null ? '' : '(?=[^>]*\bid=["\']' . preg_quote($id) . '["\'])';
        $pattern = '#<(' . $names . ')\b' . $id_pattern . '[^>]*?(?:/>|>.*?</\1>)#s';

        while (true) {
            if (preg_match('#<stream:error\b.*?</stream:error>#s', $this->buffer, $match)) {
                throw new \RuntimeException('Stream error: ' . strip_tags(str_replace('><', '> <', $match[0])));
            }
            if (preg_match($pattern, $this->buffer, $match, PREG_OFFSET_CAPTURE)) {
                $this->buffer = (string) substr($this->buffer, $match[0][1] + strlen($match[0][0]));
                if ($match[1][0] !== $success) {
                    throw new \RuntimeException('Unexpected response: ' . $match[0][0]);
                }
                return $match[0][0];
            }
            $data = fread($this->socket, 8192);
            if (
                $data === false ||
                ($data === '' && (feof($this->socket) || stream_get_meta_data($this->socket)['timed_out']))
            ) {
                throw new \RuntimeException('Connection closed while waiting for ' . implode(', ', $elements) . '.');
            }
            $this->buffer .= $data;
        }
    }

    /**
     * @param string $data
     * @throws \RuntimeException
     */
    protected function write($data)
    {
        for ($written = 0; $written < strlen($data); $written += $result) {
            $result = fwrite($this->socket, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new \RuntimeException('Unable to write to the connection.');
            }
        }
    }

    /**
     * @param string $value
     * @return string
     */
    protected function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
