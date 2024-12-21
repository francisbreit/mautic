<?php

namespace Mautic\SmsBundle\Integration\Twilio;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\SmsBundle\Sms\TransportInterface;
use Psr\Log\LoggerInterface;
use Twilio\Exceptions\ConfigurationException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

class TwilioTransport implements TransportInterface
{
    private ?Client $client = null;

    public function __construct(
        private Configuration $configuration,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param string $content
     *
     * @return bool|string
     */
    public function sendSms(Lead $lead, $content)
    {
        $number = $lead->getLeadPhoneNumber();

        if (null === $number) {
            return false;
        }

        try {
            // Obtendo o SID da conta e o token de autenticação
            $accountSid = $this->configuration->getAccountSid();
            $authToken = $this->configuration->getAuthToken();

            // URL do webhook do N8N (a URL do webhook será configurada no campo AccountSid)
            $webhookUrl = "{$accountSid}";

            // Dados a serem enviados para o webhook
            $data = [
                'to' => $this->sanitizeNumber($number),
                'message' => $content,
            ];

            // Configurar cURL para enviar ao webhook
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $authToken,  // Passando o AuthToken no cabeçalho
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Verifica se houve erro na resposta do webhook
            if ($httpCode !== 200) {
                throw new \Exception("Webhook responded with HTTP code $httpCode");
            }

            return true;
        } catch (NumberParseException $numberParseException) {
            $this->logger->warning(
                $numberParseException->getMessage(),
                ['exception' => $numberParseException]
            );

            return $numberParseException->getMessage();
        } catch (\Exception $exception) {
            $this->logger->warning(
                $exception->getMessage(),
                ['exception' => $exception]
            );

            return $exception->getMessage();
        }
    }

    /**
     * @param string $number
     *
     * @return string
     *
     * @throws NumberParseException
     */
    private function sanitizeNumber($number)
    {
        $util   = PhoneNumberUtil::getInstance();
        $parsed = $util->parse($number, 'US');

        return $util->format($parsed, PhoneNumberFormat::E164);
    }
}
