<?php
/**
 * This file is part of the Shieldon package.
 *
 * (c) Terry L. <contact@terryl.in>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 * php version 7.1.0
 * 
 * @category  Web-security
 * @package   Shieldon
 * @author    Terry Lin <contact@terryl.in>
 * @copyright 2019 terrylinooo
 * @license   https://github.com/terrylinooo/shieldon/blob/2.x/LICENSE MIT
 * @link      https://github.com/terrylinooo/shieldon
 * @see       https://shieldon.io
 */

declare(strict_types=1);

namespace Shieldon\Firewall;

use Shieldon\Firewall\Driver\DirverProvider;
use Shieldon\Firewall\Utils\Container;
use RuntimeException;
use function Shieldon\Firewall\get_request;
use function Shieldon\Firewall\get_response;
use function Shieldon\Firewall\set_response;
use function Shieldon\Firewall\get_microtimesamp;
use function Shieldon\Firewall\create_session_id;
use function Shieldon\Firewall\get_ip;
use function time;
use function rand;
use function intval;
use function setcookie;

/*
 * Session for the use of Shieldon.
 */
class Session
{
    /**
     * The session data.
     *
     * @var array
     */
    protected $data = [];

    /**
     * The session data will be removed after expiring.
     * Time unit: second.
     *
     * @var int
     */
    protected $expire = 600;

    /**
     * The Shieldon kernel.
     *
     * @var Kernel|null
     */
    protected $kernel;

    /**
     * The data driver.
     *
     * @var DirverProvider|null
     */
    protected $driver;

    /**
     * Make sure the init() run first.
     *
     * @var bool
     */
    protected static $status = false;

    /**
     * A session Id.
     *
     * @var string
     */
    protected static $id = '_php_cli_';

    /**
     * Constructor.
     * 
     * @param string $id Session ID
     */
    public function __construct(string $sessionId = '')
    {
        $this->setId($sessionId);

        /**
         * Store the session data back into the database table when the 
         * Shieldon Kernel workflow is reaching the end of the process.
         */
        add_listener('kernel_end', [$this, 'save'], 10);

        /**
         * Store the session data back into the database table when the 
         * user is logged successfully.
         */
        add_listener('user_login', [$this, 'save'], 10);
    }

    /**
     * Get session ID.
     *
     * @return string
     */
    public function getId(): string
    {
        return self::$id;
    }

    /**
     * Set session ID.
     *
     * @param string $id Session Id.
     *
     * @return void
     */
    public function setId(string $id): void
    {
        self::$id = $id;

        // We store this session ID into the container for the use of other functions.
        Container::set('session_id', $id);
    }

    /**
     * Initialize.
     *
     * @param object $driver        The data driver.
     * @param int    $gcExpires     The time of expiring.
     * @param int    $gcProbability GC setting,
     * @param int    $gcDivisor     GC setting,
     * @param bool   $psr7          Reset the cookie the PSR-7 way?
     *
     * @return void
     */
    public function init(
             $driver, 
        int  $gcExpires     = 300, 
        int  $gcProbability = 1, 
        int  $gcDivisor     = 100, 
        bool $psr7          = false
    ): void {
        $this->driver = $driver;

        $cookie = get_request()->getCookieParams();

        $this->gc($gcExpires, $gcProbability, $gcDivisor);
 
        // New visitor? Create a new session.
        if (php_sapi_name() !== 'cli' && empty($cookie['_shieldon'])) {
            self::resetCookie($psr7);
            $this->create();
            self::$status = true;
            return;
        }

        $this->data = $this->driver->get(self::$id, 'session');

        if (empty($this->data)) {
            self::resetCookie($psr7);
            $this->create();
        }

        $this->parsedData();

        self::$status = true;
    }

    /**
     * Check the initialization status.
     *
     * @return bool
     */
    public function IsInitialized(): bool
    {
        return $this->status;
    }

    /**
     * Get specific value from session by key.
     *
     * @param string $key The key of a data field.
     *
     * @return mixed
     */
    public function get(string $key)
    {
        $this->assertInit();

        return $this->data['parsed_data'][$key] ?? '';
    }

    /**
     * Parse JSON data and store it into parsed_data field.
     *
     * @return void
     */
    protected function parsedData()
    {
        if (empty($this->data['data'])) {
            $this->data['data'] = '{}';
        }
        $this->data['parsed_data'] = json_decode($this->data['data'], true);
    }

    /**
     * To store data in the session.
     *
     * @param string $key   The key of a data field.
     * @param mixed  $value The value of a data field.
     *
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->assertInit();

        $this->data['parsed_data'][$key] = $value;
    }

    /**
     * To delete data from the session.
     *
     * @param string $key The key of a data field.
     *
     * @return void
     */
    public function remove(string $key): void
    {
        $this->assertInit();

        if (isset($this->data['parsed_data'][$key])) {
            unset($this->data['parsed_data'][$key]);
        }
    }

    /**
     * To determine if an item is present in the session.
     *
     * @param string $key The key of a data field.
     *
     * @return bool
     */
    public function has($key): bool
    {
        $this->assertInit();

        return isset($this->data['parsed_data'][$key]);
    }

    /**
     * Clear all data in the session array.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->assertInit();

        $this->data = [];
    }

    /**
     * Perform session data garbage collection.
     *
     * @param int $expires     The time of expiring.
     * @param int $probability Numerator.
     * @param int $divisor     Denominator.
     *
     * @return bool
     */
    protected function gc(int $expires, int $probability, int $divisor): bool
    {
        $chance = intval($divisor / $probability);
        $hit = rand(1, $chance);

        if ($hit === 1) {
            
            $sessionData = $this->driver->getAll('session');

            if (!empty($sessionData)) {
                foreach ($sessionData as $v) {
                    $lasttime = (int) $v['time'];
    
                    if (time() - $lasttime > $expires) {
                        $this->driver->delete($v['id'], 'session');
                    }
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Reset cookie.
     * 
     * @param bool $psr7 Reset the cookie the PSR-7 way, otherwise native.
     *
     * @return void
     */
    public static function resetCookie(bool $psr7 = true): void
    {
        $sessionHashId = create_session_id();
        $cookieName = '_shieldon';
        $expiredTime = time() + 3600;

        if ($psr7) {
            $expires = date('D, d M Y H:i:s', $expiredTime) . ' GMT';
            $response = get_response()->withHeader(
                'Set-Cookie',
                $cookieName . '=' . $sessionHashId . '; Path=/; Expires=' . $expires
            );
            set_response($response);
        } else {
            setcookie($cookieName, $sessionHashId, $expiredTime, '/');
        }

        self::$id = $sessionHashId;
    }

    /**
     * Create session data structure.
     *
     * @return void
     */
    protected function create(): void
    {
        // Initialize new session data.
        $data['id'] = self::$id;
        $data['ip'] = get_ip();
        $data['time'] = time();
        $data['microtimesamp'] = get_microtimesamp();

        // This field is a JSON string.
        $data['data'] = '{}';
        $data['parsed_data'] = [];

        $this->data = $data;
        $this->save();
    }

    /**
     * Save session data into database.
     *
     * @return void
     */
    public function save(): void
    {
        $this->data['data'] = json_encode($this->data['parsed_data']);

        $this->driver->save(self::$id, $this->data, 'session');
    }

    /**
     * Make sure init run first.
     *
     * @return void
     */
    protected function assertInit(): void
    {
        if (!self::$status) {
            throw new RuntimeException(
                'The init method is supposed to run first.'
            );
        }
    }
}
