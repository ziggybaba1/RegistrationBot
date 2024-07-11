<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Webklex\IMAP\Facades\Client as IMAPClient;

class RegisterUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
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

    public function handle()
    {
        Log::info('Starting the registration process.');

        // Step 1: Start the registration process and extract cookies
        $this->startRegistrationProcess();

        // Step 2: Extract stoken value
        $stoken = $this->getStoken();
        Log::info('Extracted stoken value: ' . $stoken);

        // Step 3: Submit the registration form
        $email = 'test@salemepartners.com';
        $this->submitRegistrationForm($stoken, $email);

        // Step 4: Retrieve the verification code from email
        $verificationCode = $this->getVerificationCodeFromEmail();
        Log::info('Retrieved verification code: ' . $verificationCode);

        // Step 5: Verify OTP
        $this->verifyOTP($verificationCode, $email);

        // Step 6: Submit Captcha
        $response = $this->submitCaptcha();

        Log::info('Registration process completed successfully.');
        return response()->json(['message' => 'Registration completed successfully', 'response' => $response]);
    }

    private function startRegistrationProcess()
    {
        $response = $this->client->request('GET', $this->webUrl, ['cookies' => $this->cookieJar]);
        $this->extractCookies();
        Log::info('Started registration process and extracted cookies.');
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
        Log::info('Extracted cookies: PHPSESSID=' . $this->phpSessionId . ', ctoken=' . $this->cToken);
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

        Log::info('Submitted registration form for email: ' . $email);
    }

    private function getVerificationCodeFromEmail()
    {
        sleep(60); // Wait for the email to be received
        Log::info('Waiting for email to be received.');

        $client = IMAPClient::account('gmail');
        $client->connect();
        $folder = $client->getFolder('INBOX');
        $messages = $folder->query()->unseen()->get();
        Log::info('Fetched unseen emails from inbox.');

        foreach ($messages as $message) {
            $body = $message->getTextBody();
            if (strstr($body, 'Your verification code is')) {
                Log::info('Found verification code in email.');
                $verificationCode = explode('Your verification code is:', $body);
                return $verificationCode[1] ?? "";
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

        Log::info('Verified OTP for email: ' . $email);
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

        Log::info('Submitted Captcha.');
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
