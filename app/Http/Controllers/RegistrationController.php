<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Webklex\IMAP\Facades\Client as IMAPClient;
use Illuminate\Support\Facades\Redis;

class RegistrationController extends Controller
{
    private $client;
    private $webUrl;
    private $cookieJar;
    private $phpSessionId;
    private $cToken;

    public function __construct()
    {
        $this->client = new Client();
        $this->webUrl = env('WEB_URL', 'https://challenge.blackscale.media');
        $this->cookieJar = new CookieJar();
    }

    public function register()
    {
        $this->startRegistrationProcess();
        $stoken = $this->getStoken();

        $email = 'test@salemepartners.com';
        $this->submitRegistrationForm($stoken, $email);

        $verificationCode = $this->getVerificationCodeFromEmail();
        $this->verifyOTP($verificationCode, $email);

        $this->submitCaptcha();

        return response()->json(['message' => 'Registration completed successfully']);
    }

    private function startRegistrationProcess()
    {
        $response = $this->client->request('GET', $this->webUrl, ['cookies' => $this->cookieJar]);
        $this->extractCookies();
    }

    private function extractCookies()
    {
        foreach ($this->cookieJar->toArray() as $cookie) {
            if ($cookie['Name'] === 'PHPSESSID') {
                $this->phpSessionId = $cookie['Value'];
            } elseif ($cookie['Name'] === 'ctoken') {
                $this->cToken = $cookie['Value'];
            }
        }
    }

    private function getStoken()
    {
        $response = $this->client->request('GET', $this->webUrl . '/register.php', ['cookies' => $this->cookieJar]);
        $dom = new DOMDocument();
        @$dom->loadHTML($response->getBody()->getContents());
        $xpath = new DOMXPath($dom);

        return $xpath->evaluate('string(//input[@name="stoken"]/@value)');
    }

    private function submitRegistrationForm($stoken, $email)
    {
        $randomName = 'user' . uniqid();
        $formParams = [
            'stoken' => $stoken,
            'email' => $email,
            'fullname' => $randomName,
            'password' => 'password123',
            'email_signature' => base64_encode($email)
        ];

        $this->client->request('POST', $this->webUrl . '/verify.php', [
            'form_params' => $formParams,
            'cookies' => $this->cookieJar,
            'headers' => $this->getHeaders($this->webUrl . '/register.php')
        ]);
    }

    private function getVerificationCodeFromEmail()
    {
        sleep(60);

        $client = IMAPClient::account('gmail');
        $client->connect();
        $folder = $client->getFolder('INBOX');
        $messages = $folder->query()->unseen()->get();

        foreach ($messages as $message) {
            $body = $message->getTextBody();
            if (preg_match('/Your verification code is: (\d+)/', $body, $matches)) {
                return $matches[1];
            }
        }

        throw new \Exception('Verification code not found in email');
    }

    private function verifyOTP($verificationCode, $email)
    {
        $formParams = [
            'code' => $verificationCode,
            'email' => $email
        ];

        $this->client->request('POST', $this->webUrl . '/captcha.php', [
            'form_params' => $formParams,
            'cookies' => $this->cookieJar,
            'headers' => $this->getHeaders($this->webUrl . '/verify.php')
        ]);
    }

    private function submitCaptcha()
    {
        $formParams = [
            'g-recaptcha-response' => ''
        ];

        $response = $this->client->request('POST', $this->webUrl . '/complete.php', [
            'form_params' => $formParams,
            'cookies' => $this->cookieJar,
            'headers' => $this->getHeaders($this->webUrl . '/captcha.php')
        ]);

        return $response->getBody()->getContents();
    }

    private function getHeaders($referer)
    {
        return [
            'Cookie' => 'PHPSESSID=' . $this->phpSessionId . '; ctoken=' . $this->cToken,
            'Origin' => $this->webUrl,
            'Referer' => $referer
        ];
    }
}
