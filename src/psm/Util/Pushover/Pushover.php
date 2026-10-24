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

namespace psm\Util\Pushover;

/**
 * Minimal client for the Pushover message API.
 *
 * Replaces the abandoned php-pushover library, which is not compatible with PHP 8.5.
 * @see https://pushover.net/api
 */
class Pushover
{
    const API_URL = 'https://api.pushover.net/1/messages.json';

    /**
     * Request parameters, empty values are not sent
     * @var array $params
     */
    protected $params = array();

    /**
     * @param string $token application API token
     */
    public function setToken($token)
    {
        $this->params['token'] = $token;
    }

    /**
     * @param string $user user or group key of the receiver
     */
    public function setUser($user)
    {
        $this->params['user'] = $user;
    }

    /**
     * @param string|null $device device name, empty to send to all devices of the user
     */
    public function setDevice($device)
    {
        $this->params['device'] = $device;
    }

    /**
     * @param string $title
     */
    public function setTitle($title)
    {
        $this->params['title'] = $title;
    }

    /**
     * @param string $message
     */
    public function setMessage($message)
    {
        $this->params['message'] = $message;
    }

    /**
     * @param int $html 1 to allow HTML formatting in the message
     */
    public function setHtml($html)
    {
        $this->params['html'] = (int) $html;
    }

    /**
     * @param int $priority -2 to 2, 2 requires retry and expire
     */
    public function setPriority($priority)
    {
        $this->params['priority'] = (int) $priority;
    }

    /**
     * @param int $retry seconds between retries for priority 2
     */
    public function setRetry($retry)
    {
        $this->params['retry'] = (int) $retry;
    }

    /**
     * @param int $expire seconds after which retries stop for priority 2
     */
    public function setExpire($expire)
    {
        $this->params['expire'] = (int) $expire;
    }

    /**
     * @param string $url supplementary URL
     */
    public function setUrl($url)
    {
        $this->params['url'] = $url;
    }

    /**
     * @param string $url_title title of the supplementary URL
     */
    public function setUrlTitle($url_title)
    {
        $this->params['url_title'] = $url_title;
    }

    /**
     * Send the message
     * @return object|null decoded API response (with status 1 on success and an errors array otherwise),
     *  null if the API could not be reached
     */
    public function send()
    {
        $params = array_filter($this->params, function ($value) {
            return $value !== null && $value !== '';
        });

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ));
        $response = curl_exec($ch);
        if (!is_string($response)) {
            return null;
        }
        $result = json_decode($response);
        return is_object($result) ? $result : null;
    }
}
