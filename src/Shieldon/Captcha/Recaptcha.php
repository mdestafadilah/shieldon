<?php declare(strict_types=1);
/*
 * This file is part of the Shieldon package.
 *
 * (c) Terry L. <contact@terryl.in>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shieldon\Captcha;

class Recaptcha implements CaptchaInterface
{
    protected $key = '';
    protected $secret = '';
    protected $version = 'v2';
    protected $lang = 'en';

    protected $googleServiceUrl = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * Constructor.
     * 
     * It will implement default configuration settings here.
     * 
     * @array $config
     *
     * @return void
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $k => $v) {
            if (isset($this->{$k})) {
                $this->{$k} = $v;
            }
        }
    }

    /**
     * Reponse the result from Google service server.
     *
     * @return bool
     */
    public function response(): bool
    {
        if (empty($_POST['g-recaptcha-response'])) {
            return false;
        }

        $flag = false;

        $postData = [
            'secret' => $this->secret,
            'response' => $_POST['g-recaptcha-response'],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->googleServiceUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
        curl_setopt($ch, CURLOPT_POST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
        $ret = curl_exec($ch);
    
        if (curl_errno($ch)) {
            echo 'error:' . curl_error($ch);
        }
        
        if (isset($ret) && $ret != false) {
            $tmp = json_decode($ret);
            if ($tmp->success == true) {
                $flag = true;
            }
        }

        return $flag;
    }

    /**
     * Output a required HTML for reCaptcha v2.
     *
     * @return string
     */
    public function form(): string
    {
        $html = '<script src="https://www.google.com/recaptcha/api.js?hl=' . $this->lang . '"></script>';

        if ('v3' !== $this->version) {
            $html .= '<div class="g-recaptcha" data-sitekey="' . $this->key . '"></div>';
        }
        return $html;
    }
}